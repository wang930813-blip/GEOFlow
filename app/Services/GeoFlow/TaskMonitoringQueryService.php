<?php

namespace App\Services\GeoFlow;

use App\Models\Admin;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\WorkerHeartbeat;
use App\Services\Topics\TopicTaskProgress;
use App\Support\AdminDataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 任务监控查询编排服务。
 *
 * 双层真相源：
 * - 业务真相：task_runs / tasks（任务进度、错误语义、业务完成结果）
 * - 监控真相：Horizon/Redis（队列 pending/running/failed）
 */
class TaskMonitoringQueryService
{
    public function __construct(
        private readonly HorizonMetricsAdapter $horizonMetrics,
        private readonly AdminDataScope $adminDataScope,
    ) {}

    /**
     * 管理后台任务页完整数据。
     *
     * @return array{
     *     tasks:list<array<string,mixed>>,
     *     queue_overview:array{pending:int,running:int,failed:int,completed:int},
     *     worker_overview:list<array<string,mixed>>,
     *     recent_runs:list<array<string,mixed>>
     * }
     */
    public function buildAdminOverview(?Admin $admin = null): array
    {
        $tasks = $this->listTaskMonitoringRows($admin);

        return [
            'tasks' => $tasks,
            'queue_overview' => $this->horizonMetrics->queueOverview('geoflow'),
            'worker_overview' => $this->workerOverview(),
            'recent_runs' => $this->recentRuns($admin),
        ];
    }

    /**
     * 用于前端状态刷新快照（兼容现有任务页按钮逻辑）。
     *
     * @return list<array<string,mixed>>
     */
    public function buildTaskSnapshot(?Admin $admin = null): array
    {
        return $this->listTaskMonitoringRows($admin);
    }

    /**
     * API 场景：分页任务列表（包含 task_progress/queue_overview）。
     *
     * @param  array<string,mixed>  $filters
     * @return array{
     *     items:list<array<string,mixed>>,
     *     pagination:array{page:int,per_page:int,total:int,total_pages:int}
     * }
     */
    public function listTasksPaginated(
        int $page = 1,
        int $perPage = 20,
        array $filters = [],
        ?Admin $admin = null,
    ): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $query = $this->visibleTaskQuery($admin)
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', (string) $filters['status']))
            ->when(! empty($filters['search']), fn ($q) => $q->where('name', 'like', '%'.trim((string) $filters['search']).'%'))
            ->orderByDesc('created_at');

        $total = (clone $query)->count();
        /** @var Collection<int, Task> $rows */
        $rows = $query->forPage($page, $perPage)->get();

        return [
            'items' => $this->decorateTasks($rows)->values()->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil(max(1, $total) / $perPage),
            ],
        ];
    }

    /**
     * API 场景：单任务监控详情。
     *
     * @return array<string,mixed>
     */
    public function getTaskMonitoringDetail(int $taskId, ?Admin $admin = null): array
    {
        $task = $this->visibleTaskQuery($admin)->whereKey($taskId)->firstOrFail();
        $decorated = $this->decorateTasks(collect([$task]))->first();

        return is_array($decorated) ? $decorated : [];
    }

    /**
     * @Name: trashedTaskHistory
     * @Description: 查询当前管理员有权访问且仍在保留期内的已删除任务，供任务页回收站显示。
     * @Author: cdkay
     * @CreateTime: 2026-10-29 18:51:01
     * @UpdateTime: 2026-10-29 18:51:01
     * @Param: Admin|null admin 当前管理后台用户
     * @Param: int limit 返回记录数上限
     * @Return: list<array<string,mixed>> 已删除任务及恢复所需的回收站序列
     * @Throws: \Illuminate\Database\QueryException 数据库查询失败时抛出
     */
    public function trashedTaskHistory(?Admin $admin = null, int $limit = 50): array
    {
        if (! Schema::hasTable('task_trash_entries')
            || ! Schema::hasColumn('task_trash_entries', 'sequence')) {
            return [];
        }

        $columns = [
            'tasks.id',
            'tasks.name',
            'tasks.deleted_at',
            'task_trash_entries.sequence as trash_sequence',
        ];
        if (Schema::hasColumn('task_trash_entries', 'requires_super_admin_restore')) {
            $columns[] = 'task_trash_entries.requires_super_admin_restore';
        }

        return $this->visibleTaskQuery($admin)
            ->onlyTrashed()
            ->join('task_trash_entries', 'task_trash_entries.task_id', '=', 'tasks.id')
            ->where('task_trash_entries.deleted_at', '>', now()->subDays(Task::TRASH_RETENTION_DAYS))
            ->orderByDesc('task_trash_entries.sequence')
            ->limit(max(1, min(200, $limit)))
            ->get($columns)
            ->map(static fn (Task $task): array => [
                'id' => (int) $task->id,
                'name' => (string) $task->name,
                'deleted_at' => $task->deleted_at?->toDateTimeString(),
                'trash_sequence' => (int) $task->trash_sequence,
                'requires_super_admin_restore' => (bool) ($task->requires_super_admin_restore ?? false),
            ])
            ->all();
    }

    /**
     * @Name: findTrashedTaskForAdmin
     * @Description: 以当前管理员的数据范围查询软删除任务，并可在恢复事务内锁定记录。
     * @Author: cdkay
     * @CreateTime: 2026-10-29 18:51:01
     * @UpdateTime: 2026-10-29 18:51:01
     * @Param: int taskId 任务主键
     * @Param: Admin|null admin 当前管理后台用户
     * @Param: bool lockForUpdate 是否对任务行加排他锁
     * @Return: Task|null 可见的软删除任务，不存在或越权时返回 null
     * @Throws: \Illuminate\Database\QueryException 数据库查询失败时抛出
     */
    public function findTrashedTaskForAdmin(int $taskId, ?Admin $admin = null, bool $lockForUpdate = false): ?Task
    {
        $query = $this->visibleTaskQuery($admin)
            ->onlyTrashed()
            ->whereKey($taskId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listTaskMonitoringRows(?Admin $admin = null): array
    {
        /** @var Collection<int, Task> $tasks */
        $tasks = $this->visibleTaskQuery($admin)
            ->orderByDesc('created_at')
            ->get();

        return $this->decorateTasks($tasks)->values()->all();
    }

    private function visibleTaskQuery(?Admin $admin = null): Builder
    {
        $query = Task::query();

        if ($admin instanceof Admin) {
            $this->adminDataScope->applySiteScope($query, $admin);
            if ($admin->isSiteUser() || $admin->isDirectAdmin()) {
                $this->adminDataScope->applyOwnerScope($query, $admin);
            }
        }

        return $query;
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, array<string,mixed>>
     */
    private function decorateTasks(Collection $tasks): Collection
    {
        if ($tasks->isEmpty()) {
            return collect([]);
        }

        // 一次性收集 task_id，后续所有聚合都基于该集合批量查询，避免 N+1。
        $taskIds = $tasks->pluck('id')->map(fn ($id) => (int) $id)->all();
        $topicStats = app(TopicTaskProgress::class)->forTasks($taskIds);
        $qualityKnowledgeBaseIds = Schema::hasTable('task_knowledge_bases')
            ? DB::table('task_knowledge_bases')
                ->whereIn('task_id', $taskIds)
                ->orderBy('sort_order')
                ->get(['task_id', 'knowledge_base_id'])
                ->groupBy('task_id')
                ->map(static fn (Collection $rows): array => $rows
                    ->map(static fn (object $row): int => (int) $row->knowledge_base_id)
                    ->values()
                    ->all())
            : collect();

        // 文章统计（业务真相）：总文章数 + 已发布数。
        $articleStats = DB::table('articles')
            ->selectRaw("
                task_id,
                COUNT(*) AS total_articles,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_articles,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft_articles,
                SUM(CASE WHEN status = 'draft' AND review_status IN ('approved','auto_approved') THEN 1 ELSE 0 END) AS publishable_drafts
            ")
            ->whereIn('task_id', $taskIds)
            ->whereNull('deleted_at')
            ->groupBy('task_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->task_id => [
                    'total_articles' => (int) ($row->total_articles ?? 0),
                    'published_articles' => (int) ($row->published_articles ?? 0),
                    'draft_articles' => (int) ($row->draft_articles ?? 0),
                    'publishable_drafts' => (int) ($row->publishable_drafts ?? 0),
                ],
            ]);

        // 分发统计（文章维度）：用于任务列表快速暴露远程同步结果。
        $distributionStats = DB::table('article_distributions')
            ->join('articles', 'articles.id', '=', 'article_distributions.article_id')
            ->selectRaw("
                articles.task_id,
                COUNT(*) AS distribution_total_count,
                SUM(CASE WHEN article_distributions.status = 'synced' THEN 1 ELSE 0 END) AS distribution_synced_count,
                SUM(CASE WHEN article_distributions.status = 'failed' THEN 1 ELSE 0 END) AS distribution_failed_count
            ")
            ->whereIn('articles.task_id', $taskIds)
            ->whereNull('articles.deleted_at')
            ->groupBy('articles.task_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->task_id => [
                    'distribution_total_count' => (int) ($row->distribution_total_count ?? 0),
                    'distribution_synced_count' => (int) ($row->distribution_synced_count ?? 0),
                    'distribution_failed_count' => (int) ($row->distribution_failed_count ?? 0),
                ],
            ]);

        // 运行统计（业务真相）：pending/running/completed/failed+cancelled 数量。
        // 说明：这里把 cancelled 归入 failed_jobs，用于任务页“失败”概览展示。
        $runStats = TaskRun::query()
            ->selectRaw("
                task_id,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_jobs,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS running_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_jobs,
                SUM(CASE WHEN status IN ('failed','cancelled') THEN 1 ELSE 0 END) AS failed_jobs
            ")
            ->whereIn('task_id', $taskIds)
            ->groupBy('task_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->task_id => [
                    'pending_jobs' => (int) ($row->pending_jobs ?? 0),
                    'running_jobs' => (int) ($row->running_jobs ?? 0),
                    'completed_jobs' => (int) ($row->completed_jobs ?? 0),
                    'failed_jobs' => (int) ($row->failed_jobs ?? 0),
                ],
            ]);

        // 最近一条执行记录：用于回填最新状态、错误信息、重试次数等字段。
        $latestRuns = TaskRun::query()
            ->whereIn('task_id', $taskIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('task_id')
            ->map(static fn (Collection $group): ?TaskRun => $group->first());

        // 显示名称映射：减少后续 map 内重复查询。
        $titleNames = DB::table('title_libraries')
            ->whereIn('id', $tasks->pluck('title_library_id')->filter()->all())
            ->pluck('name', 'id');

        $modelNames = DB::table('ai_models')
            ->whereIn('id', $tasks->pluck('ai_model_id')->filter()->all())
            ->pluck('name', 'id');

        return $tasks->map(function (Task $task) use ($topicStats, $articleStats, $distributionStats, $runStats, $latestRuns, $titleNames, $modelNames, $qualityKnowledgeBaseIds): array {
            $taskId = (int) $task->id;
            $articles = $articleStats->get($taskId, ['total_articles' => 0, 'published_articles' => 0, 'draft_articles' => 0, 'publishable_drafts' => 0]);
            $distributions = $distributionStats->get($taskId, ['distribution_total_count' => 0, 'distribution_synced_count' => 0, 'distribution_failed_count' => 0]);
            $runs = $runStats->get($taskId, ['pending_jobs' => 0, 'running_jobs' => 0, 'completed_jobs' => 0, 'failed_jobs' => 0]);
            /** @var TaskRun|null $latestRun */
            $latestRun = $latestRuns->get($taskId);

            // batch_status 是任务页按钮与状态徽标的关键字段：
            // running > pending > paused(idle) > failed/cancelled > waiting。
            $batchStatus = $this->resolveBatchStatus($task, $runs, $latestRun, $articles);
            // 错误信息优先取最近 run 的 error_message，其次退回 tasks.last_error_message。
            $batchErrorMessage = (string) ($latestRun?->error_message ?: ($task->last_error_message ?? ''));

            return [
                'id' => $taskId,
                'name' => (string) $task->name,
                'content_type' => (string) ($task->content_type ?: 'article'),
                'target_site_key' => (string) ($task->target_site_key ?? 'primary'),
                'topic_limit' => (int) ($task->topic_limit ?? 10),
                'topic_settings' => $task->topic_settings ?? [],
                'topic_config_version' => (int) ($task->topic_config_version ?? 1),
                'status' => (string) ($task->status ?? 'paused'),
                'publish_scope' => (string) ($task->publish_scope ?? 'local_and_distribution'),
                'title_library_id' => $this->nullableInt($task->title_library_id),
                'fixed_title_id' => $this->nullableInt($task->fixed_title_id),
                'prompt_id' => $this->nullableInt($task->prompt_id),
                'ai_model_id' => $this->nullableInt($task->ai_model_id),
                'knowledge_base_id' => $this->nullableInt($task->knowledge_base_id),
                'author_id' => $this->nullableInt($task->author_id),
                'image_library_id' => $this->nullableInt($task->image_library_id),
                'image_mode' => (string) ($task->image_mode ?? 'library'),
                'image_count' => (int) ($task->image_count ?? 0),
                'ai_image_model_id' => $this->nullableInt($task->ai_image_model_id),
                'ai_quality_enabled' => (bool) ($task->ai_quality_enabled ?? false),
                'ai_quality_retrieval_mode' => (string) ($task->ai_quality_retrieval_mode ?? 'chunk'),
                'ai_quality_timeout_sampling_enabled' => (bool) ($task->ai_quality_timeout_sampling_enabled ?? false),
                'ai_quality_auto_optimize_enabled' => (bool) ($task->ai_quality_auto_optimize_enabled ?? false),
                'ai_quality_optimization_level' => (string) ($task->ai_quality_optimization_level ?? 'excellent_80'),
                'ai_quality_prompt_id' => $this->nullableInt($task->ai_quality_prompt_id),
                'ai_quality_model_id' => $this->nullableInt($task->ai_quality_model_id),
                'ai_quality_pass_score' => (int) ($task->ai_quality_pass_score ?? 85),
                'ai_quality_manual_override_min_score' => (int) ($task->ai_quality_manual_override_min_score ?? 70),
                'ai_quality_knowledge_base_ids' => $qualityKnowledgeBaseIds->get($taskId, []),
                'need_review' => (int) ($task->need_review ?? 1),
                'auto_keywords' => (int) ($task->auto_keywords ?? 1),
                'auto_description' => (int) ($task->auto_description ?? 1),
                'is_loop' => (int) ($task->is_loop ?? 0),
                'category_mode' => (string) ($task->category_mode ?? 'smart'),
                'fixed_category_id' => $this->nullableInt($task->fixed_category_id),
                'title_library_name' => (string) ($titleNames[(int) ($task->title_library_id ?? 0)] ?? ''),
                'ai_model_name' => (string) ($modelNames[(int) ($task->ai_model_id ?? 0)] ?? ''),
                'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
                'created_at' => $task->created_at?->toDateTimeString(),
                'updated_at' => $task->updated_at?->toDateTimeString(),
                'loop_count' => (int) ($task->loop_count ?? 0),
                'created_count' => $task->content_type === 'topic'
                    ? (int) ($topicStats[$taskId]['created_topics'] ?? 0)
                    : (int) ($task->created_count ?? 0),
                'published_count' => $task->content_type === 'topic'
                    ? (int) ($topicStats[$taskId]['published_topics'] ?? 0)
                    : (int) ($task->published_count ?? 0),
                'article_limit' => $task->content_type === 'topic'
                    ? (int) ($task->topic_limit ?? 10)
                    : (int) ($task->article_limit ?? $task->draft_limit ?? 10),
                'draft_limit' => (int) ($task->draft_limit ?? 10),
                'publish_interval' => (int) ($task->publish_interval ?? 3600),
                'batch_status' => $batchStatus,
                'batch_error_message' => trim($batchErrorMessage),
                'batch_last_run' => $task->last_run_at?->toDateTimeString(),
                'last_error_at' => $task->last_error_at?->toDateTimeString(),
                'next_run_at' => $task->next_run_at?->toDateTimeString(),
                'next_publish_at' => $task->next_publish_at?->toDateTimeString(),
                'schedule_enabled' => (int) ($task->schedule_enabled ?? 1),
                'total_articles' => (int) $articles['total_articles'],
                'total_topics' => $task->content_type === 'topic'
                    ? (int) ($topicStats[$taskId]['created_topics'] ?? 0)
                    : 0,
                'published_topics' => $task->content_type === 'topic'
                    ? (int) ($topicStats[$taskId]['published_topics'] ?? 0)
                    : 0,
                'published_articles' => (int) $articles['published_articles'],
                'draft_articles' => (int) $articles['draft_articles'],
                'publishable_drafts' => (int) $articles['publishable_drafts'],
                'distribution_total_count' => (int) $distributions['distribution_total_count'],
                'distribution_synced_count' => (int) $distributions['distribution_synced_count'],
                'distribution_failed_count' => (int) $distributions['distribution_failed_count'],
                'pending_jobs' => (int) $runs['pending_jobs'],
                'running_jobs' => (int) $runs['running_jobs'],
                'batch_success_count' => (int) $runs['completed_jobs'],
                'batch_error_count' => (int) $runs['failed_jobs'],
                'latest_job_status' => (string) ($latestRun?->status ?? 'idle'),
                'latest_attempt_count' => (int) (($latestRun?->meta['attempt_count'] ?? 0)),
                'latest_max_attempts' => (int) (($latestRun?->meta['max_attempts'] ?? 0)),
                // 新契约字段：业务层进度（文章维度），用于“任务成果”视图。
                'task_progress' => [
                    'content_type' => (string) ($task->content_type ?: 'article'),
                    ...($task->content_type === 'topic'
                        ? [...($topicStats[$taskId] ?? []), 'topic_limit' => (int) ($task->topic_limit ?? 10)]
                        : []),
                    'created_articles' => (int) $articles['total_articles'],
                    'published_articles' => (int) $articles['published_articles'],
                    'draft_articles' => (int) $articles['draft_articles'],
                    'article_limit' => (int) ($task->article_limit ?? $task->draft_limit ?? 10),
                    'draft_limit' => (int) ($task->draft_limit ?? 10),
                    'last_run_at' => $task->last_run_at?->toDateTimeString(),
                    'last_error_message' => trim((string) ($task->last_error_message ?? '')),
                ],
                // 新契约字段：任务级队列视图（来自 task_runs 聚合，不是全局 Redis 队列长度）。
                'queue_overview' => [
                    'pending' => (int) $runs['pending_jobs'],
                    'running' => (int) $runs['running_jobs'],
                    'failed' => (int) $runs['failed_jobs'],
                    'completed' => (int) $runs['completed_jobs'],
                    'latest_status' => (string) ($latestRun?->status ?? 'idle'),
                ],
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $runStats
     */
    private function resolveBatchStatus(Task $task, array $runStats, ?TaskRun $latestRun, array $articleStats): string
    {
        if ((int) ($runStats['running_jobs'] ?? 0) > 0) {
            return 'running';
        }

        if ((int) ($runStats['pending_jobs'] ?? 0) > 0) {
            return 'pending';
        }

        if (($task->status ?? 'paused') === 'completed') {
            return 'completed';
        }

        if (($task->status ?? 'paused') === 'paused') {
            return 'idle';
        }

        $articleLimit = (int) ($task->article_limit ?? $task->draft_limit ?? 10);
        $createdCount = (int) ($task->created_count ?? 0);
        $draftLimit = (int) ($task->draft_limit ?? 10);
        $draftCount = (int) ($articleStats['draft_articles'] ?? 0);
        $publishableDrafts = (int) ($articleStats['publishable_drafts'] ?? 0);

        if ($createdCount >= $articleLimit && $draftCount <= 0) {
            return 'limit_reached';
        }

        if ($publishableDrafts > 0) {
            return 'waiting_publish';
        }

        if ($createdCount < $articleLimit && $draftCount >= $draftLimit) {
            return 'draft_pool_full';
        }

        if ($createdCount >= $articleLimit) {
            return 'waiting_review';
        }

        $latestStatus = (string) ($latestRun?->status ?? '');
        $latestError = trim((string) ($latestRun?->error_message ?: ($task->last_error_message ?? '')));
        if (in_array($latestStatus, ['failed', 'cancelled'], true) && $latestError !== '') {
            return $latestStatus;
        }

        return 'waiting';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function workerOverview(): array
    {
        try {
            return WorkerHeartbeat::query()
                ->select(['worker_id', 'status', 'last_seen_at'])
                ->orderByDesc('last_seen_at')
                ->limit(5)
                ->get()
                ->map(static fn (WorkerHeartbeat $row): array => [
                    'worker_id' => (string) $row->worker_id,
                    'status' => (string) $row->status,
                    'current_job_id' => null,
                    'last_seen_at' => $row->last_seen_at?->toDateTimeString(),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function recentRuns(?Admin $admin = null): array
    {
        $query = TaskRun::query()
            ->select(['id', 'task_id', 'status', 'error_message', 'created_at'])
            ->with(['task:id,name'])
            ->when($admin instanceof Admin, function (Builder $query) use ($admin): void {
                $query->whereIn('task_id', $this->visibleTaskQuery($admin)->select('tasks.id'));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5);

        return $query->get()
            ->map(static fn (TaskRun $row): array => [
                'id' => (int) $row->id,
                'task_id' => (int) $row->task_id,
                'status' => (string) $row->status,
                'error_message' => (string) ($row->error_message ?? ''),
                'updated_at' => $row->created_at?->toDateTimeString(),
                'task_name' => (string) ($row->task?->name ?? ''),
            ])
            ->all();
    }

    /**
     * @Name: paginateWorkers
     * @Description: 分页读取 worker 心跳，并仅附加当前管理员可见任务的执行上下文。
     * @Author: cdkay
     * @CreateTime: 2026-10-29 17:39:53
     * @UpdateTime: 2026-10-29 17:53:17
     * @Param: int $page 当前页码
     * @Param: int $perPage 每页数量
     * @Param: Admin|null $admin 当前后台管理员
     * @Return: LengthAwarePaginator worker 分页及呈现字段
     * @Throws: QueryException worker 心跳表查询失败时抛出
     */
    public function paginateWorkers(int $page = 1, int $perPage = 10, ?Admin $admin = null): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        if (! Schema::hasTable('worker_heartbeats')) {
            return new LengthAwarePaginator([], 0, $perPage, $page, [
                'path' => request()->url(),
                'query' => request()->query(),
            ]);
        }

        $workers = WorkerHeartbeat::query()
            ->select(['worker_id', 'status', 'last_seen_at', 'meta'])
            ->orderByDesc('last_seen_at')
            ->paginate($perPage, ['*'], 'page', $page);
        $runIds = $workers->getCollection()
            ->map(static fn (WorkerHeartbeat $worker): ?int => isset($worker->meta['task_run_id'])
                ? (int) $worker->meta['task_run_id']
                : null)
            ->filter()
            ->unique()
            ->values();
        $runs = TaskRun::query()
            ->whereIn('id', $runIds)
            ->when($admin instanceof Admin, fn (Builder $query) => $query->whereIn(
                'task_id',
                $this->visibleTaskQuery($admin)->select('tasks.id')
            ))
            ->with([
                'task' => fn ($query) => $query->select(['id', 'name']),
                'article' => fn ($query) => $query->withTrashed()->select(['id', 'title', 'deleted_at']),
                'topic' => fn ($query) => $query->withTrashed()->select(['id', 'title', 'deleted_at']),
            ])
            ->get(['id', 'task_id', 'article_id', 'topic_id', 'content_type', 'status'])
            ->keyBy('id');
        $staleAfterSeconds = max(30, (int) config('geoflow.worker_stale_seconds', 120));
        $presented = $workers->getCollection()->map(static function (WorkerHeartbeat $worker) use ($runs, $staleAfterSeconds): array {
            $meta = is_array($worker->meta) ? $worker->meta : [];
            $runId = isset($meta['task_run_id']) ? (int) $meta['task_run_id'] : null;
            /** @var TaskRun|null $run */
            $run = $runId ? $runs->get($runId) : null;
            $stale = $worker->last_seen_at === null
                || $worker->last_seen_at->lessThan(now()->subSeconds($staleAfterSeconds));
            $status = $stale ? 'stale' : (string) $worker->status;
            $taskName = (string) ($run?->task?->name ?? '');

            return [
                'worker_id' => (string) $worker->worker_id,
                'status' => $status,
                'status_label' => __('admin.tasks.worker.status.'.(in_array($status, ['running', 'idle', 'stale'], true) ? $status : 'unknown')),
                'summary' => $stale
                    ? __('admin.tasks.worker.summary_stale')
                    : ($taskName !== ''
                        ? __('admin.tasks.worker.summary_busy', ['task' => $taskName])
                        : __('admin.tasks.worker.summary_idle')),
                'is_stale' => $stale,
                'current_job_id' => $run ? (int) $run->id : null,
                'task_id' => $run?->task_id ? (int) $run->task_id : null,
                'task_name' => $taskName,
                'task_deleted' => $run?->task_id
                    ? $run->task === null
                    : false,
                'content_type' => (string) ($run?->content_type ?: 'article'),
                'topic_id' => $run?->topic_id ? (int) $run->topic_id : null,
                'topic_title' => (string) ($run?->topic?->title ?? ''),
                'article_id' => $run?->article_id ? (int) $run->article_id : null,
                'article_title' => (string) ($run?->article?->title ?? ''),
                'article_deleted' => $run?->article_id
                    ? $run->article === null || $run->article->trashed()
                    : false,
                'memory_mb' => isset($meta['memory_mb']) ? (float) $meta['memory_mb'] : null,
                'peak_memory_mb' => isset($meta['peak_memory_mb']) ? (float) $meta['peak_memory_mb'] : null,
                'last_seen_at' => $worker->last_seen_at?->toDateTimeString(),
                'last_seen_human' => $worker->last_seen_at?->diffForHumans() ?? __('admin.tasks.worker.never_seen'),
            ];
        });

        return $workers->setCollection($presented);
    }

    /**
     * @Name: paginateRecentRuns
     * @Description: 分页读取 task_runs，限制管理员的数据范围并呈现文章或专题的历史状态。
     * @Author: cdkay
     * @CreateTime: 2026-10-29 17:39:53
     * @UpdateTime: 2026-10-29 17:53:17
     * @Param: int $page 当前页码
     * @Param: int $perPage 每页数量
     * @Param: int|null $runId 可选的执行记录 ID
     * @Param: Admin|null $admin 当前后台管理员
     * @Return: LengthAwarePaginator 执行记录分页及呈现字段
     * @Throws: QueryException 执行记录查询失败时抛出
     */
    public function paginateRecentRuns(
        int $page = 1,
        int $perPage = 10,
        ?int $runId = null,
        ?Admin $admin = null,
    ): LengthAwarePaginator {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $runs = TaskRun::query()
            ->select(['id', 'task_id', 'status', 'article_id', 'topic_id', 'content_type', 'error_message', 'duration_ms', 'meta', 'started_at', 'finished_at', 'created_at'])
            ->when($runId !== null, fn (Builder $query) => $query->whereKey($runId))
            ->when($admin instanceof Admin, fn (Builder $query) => $query->whereIn(
                'task_id',
                $this->visibleTaskQuery($admin)->select('tasks.id')
            ))
            ->with([
                'task' => fn ($query) => $query->select(['id', 'name', 'target_site_key']),
                'article' => fn ($query) => $query->withTrashed()->select(['id', 'title', 'deleted_at']),
                'topic' => fn ($query) => $query->withTrashed()->select(['id', 'title', 'deleted_at']),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return $runs->setCollection($runs->getCollection()->map(function (TaskRun $run): array {
            $meta = is_array($run->meta) ? $run->meta : [];
            $isTopic = $run->content_type === 'topic';
            $title = $isTopic
                ? (string) ($run->topic?->title ?? '')
                : (string) ($run->article?->title ?? '');
            $status = (string) $run->status;
            $statusKey = in_array($status, ['pending', 'running', 'completed', 'failed', 'cancelled'], true)
                ? $status
                : 'unknown';
            $taskName = (string) ($run->task?->name ?? __('admin.tasks.jobs.unknown_task'));
            $summary = $status === 'completed' && $title !== ''
                ? __($isTopic ? 'ai-task.topic_runtime.completed_with_topic' : 'admin.tasks.jobs.summary.completed_with_article', [
                    'task' => $taskName,
                    'article' => $title,
                    'topic' => $title,
                ])
                : __('admin.tasks.jobs.summary.'.$statusKey, ['task' => $taskName]);
            $explanation = $status === 'failed'
                ? __($isTopic ? 'ai-task.topic_runtime.failed_before_topic' : 'admin.tasks.jobs.failed_before_article', [
                    'reason' => self::legacyFailureReason((string) ($run->error_message ?? '')),
                ])
                : __($isTopic && $status === 'running'
                    ? 'ai-task.topic_runtime.running_explanation'
                    : 'admin.tasks.jobs.explanation.'.$statusKey);

            return [
                'id' => (int) $run->id,
                'task_id' => (int) $run->task_id,
                'task_name' => $taskName,
                'task_deleted' => $run->task_id ? $run->task === null : false,
                'status' => $status,
                'status_label' => __('admin.tasks.jobs.status.'.$statusKey),
                'summary' => $summary,
                'explanation' => $explanation,
                'topic_id' => $run->topic_id ? (int) $run->topic_id : null,
                'topic_deleted' => (bool) ($run->topic?->trashed() ?? false),
                'topic_title' => (string) ($run->topic?->title ?? ''),
                'target_site_key' => (string) ($run->task?->target_site_key ?? 'primary'),
                'content_type' => $run->content_type ?: 'article',
                'article_id' => $run->article_id ? (int) $run->article_id : null,
                'article_title' => $title,
                'article_deleted' => $run->article_id ? $run->article === null || $run->article->trashed() : false,
                'duration_ms' => (int) ($run->duration_ms ?? 0),
                'attempt_count' => (int) ($meta['attempt_count'] ?? 0),
                'max_attempts' => (int) ($meta['max_attempts'] ?? 0),
                'job_type' => (string) ($meta['job_type'] ?? 'generate_article'),
                'started_at' => $run->started_at?->toDateTimeString(),
                'finished_at' => $run->finished_at?->toDateTimeString(),
                'updated_at' => $run->created_at?->toDateTimeString(),
            ];
        }));
    }

    private static function legacyFailureReason(string $errorMessage): string
    {
        return match (true) {
            str_contains($errorMessage, 'AI返回空正文') => __('admin.tasks.failure.empty_content_detail'),
            str_contains($errorMessage, '正文过短') => __('admin.tasks.failure.content_too_short_detail'),
            str_contains($errorMessage, '没有可用的标题') => __('admin.tasks.failure.title_exhausted_detail'),
            str_contains($errorMessage, 'Operation timed out'), str_contains($errorMessage, '请求超时') => __('admin.tasks.failure.timeout_plain'),
            default => __('admin.tasks.failure.generic_plain'),
        };
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
