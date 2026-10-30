<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Exceptions\ApiException;
use App\Exceptions\TaskTitleReadinessException;
use App\Http\Requests\Admin\TaskTitleReadinessRequest;
use App\Models\AiModel;
use App\Models\Admin;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Models\Topic;
use App\Services\Api\IdempotencyService;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\TaskTitleReadinessService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use App\Services\Topics\TopicImportRows;
use App\Services\Topics\TopicFreshnessService;
use App\Services\Topics\TopicTaskProgress;
use App\Services\Topics\TopicTaskService;
use App\Support\AdminWeb;
use App\Support\AiConfigurationScope;
use App\Support\TopicAdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * 任务管理页（按 bak/admin/tasks.php 行为迁移）：
 * - GET 展示任务列表与运行态信息
 * - POST 处理切换状态、删除任务
 * - JSON 接口提供批量启动/停止与状态轮询
 */
class TaskController extends Controller
{
    /**
     * @param  TaskLifecycleService  $taskLifecycleService  任务生命周期服务（创建/启动/停止任务）
     */
    public function __construct(
        private readonly TaskLifecycleService $taskLifecycleService,
        private readonly TaskMonitoringQueryService $taskMonitoringQueryService,
        private readonly DistributionOrchestrator $distributionOrchestrator,
        private readonly AiConfigurationScope $aiConfigurationScope,
        private readonly TaskTitleReadinessService $taskTitleReadinessService,
    ) {}

    /**
     * 标题库就绪检查
     * 检查标题余量和任务计划冲突，并隐藏普通管理员无权查看的受保护任务。
     * @Url POST /geo_admin/tasks/title-readiness
     *      登录 是
     *      title_library_id integer 必选 标题库编号
     *      article_limit integer 必选 计划文章数量
     *      is_loop boolean 必选 是否循环使用标题
     *      status string 必选 任务状态
     *      task_id integer 可选 当前任务编号
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-30 01:58:42
     * @UpdateTime: 2026-10-30 01:58:42
     *
     * @Return JsonResponse 标题库余量、冲突和可管理入口
     * @Throws AuthorizationException 普通管理员检查受保护任务时返回 403
     * @Throws ValidationException 请求参数未通过校验时返回验证错误
     */
    public function titleReadiness(TaskTitleReadinessRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $taskId = isset($payload['task_id']) ? (int) $payload['task_id'] : null;
        if ($taskId !== null) {
            $this->assertCanManageHostedTask($taskId);
        }

        $report = $this->taskTitleReadinessService->inspect(
            (int) $payload['title_library_id'],
            (int) $payload['article_limit'],
            (bool) $payload['is_loop'],
            (string) $payload['status'],
            $taskId,
        );

        return response()->json($this->presentTitleReadiness($report));
    }

    /**
     * 任务管理首页：渲染列表与运行面板。
     */
    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        try {
            $overview = $this->taskMonitoringQueryService->buildAdminOverview($admin);
            $tasks = $overview['tasks'];
            $workers = $overview['worker_overview'];
            $queueStats = $overview['queue_overview'];
            $recentJobs = $overview['recent_runs'];
            $trashTasks = $this->taskMonitoringQueryService->trashedTaskHistory($admin);
            $error = null;
        } catch (Throwable $e) {
            $tasks = [];
            $trashTasks = [];
            $workers = [];
            $queueStats = ['pending' => 0, 'running' => 0, 'failed' => 0, 'completed' => 0];
            $recentJobs = [];
            $error = __('admin.tasks.message.query_failed', ['message' => $e->getMessage()]);
        }

        return view('admin.tasks.index', [
            'pageTitle' => __('admin.tasks.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'tasks' => $tasks,
            'trashTasks' => $trashTasks,
            'canRestoreProtectedTasks' => $admin->isSuperAdmin(),
            'workers' => $workers,
            'queueStats' => $queueStats,
            'recentJobs' => $recentJobs,
            'legacyError' => $error,
            'taskI18n' => $this->taskI18n(),
            'taskRealtime' => $this->taskRealtimeConfig(),
        ]);
    }

    /**
     * 切换任务启停状态（active -> stop，paused -> start）。
     */
    public function toggleStatus(Request $request, int $taskId): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        if ($taskId <= 0) {
            return back()->withErrors(__('admin.tasks.message.status_update_failed'));
        }

        try {
            $currentStatus = (string) $request->input('status', 'paused');
            if ($currentStatus === 'active') {
                $this->taskLifecycleService->stopTask($taskId, $admin->isSuperAdmin(), $admin);

                return back()->with('message', __('admin.tasks.message.paused_stopped'));
            }

            $task = Task::query()->findOrFail($taskId);
            if ($task->content_type === 'topic') {
                app(TopicTaskService::class)->start($task, TopicAdminContext::actor(), false);

                return back()->with('message', __('admin.tasks.message.activated'));
            }

            $this->taskLifecycleService->startTask($taskId, false, $admin->isSuperAdmin(), $admin, $admin);

            return back()->with('message', __('admin.tasks.message.activated'));
        } catch (TaskTitleReadinessException $e) {
            $report = $e->getDetails()['title_readiness'] ?? [];

            return back()
                ->with('title_readiness_report', $this->presentTitleReadiness($report))
                ->withErrors($e->getMessage());
        } catch (ApiException $e) {
            return back()->withInput()->withErrors($e->getDetails()['field_errors'] ?? $e->getMessage());
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.tasks.message.status_update_failed'));
        }
    }

    /**
     * 删除单个任务（含关联数据级联清理）。
     */
    public function destroyTask(Request $request, int $taskId): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        if ($taskId <= 0) {
            return back()->withErrors(__('admin.tasks.message.status_update_failed'));
        }

        try {
            $this->taskLifecycleService->deleteTask(
                $taskId,
                $admin->isSuperAdmin(),
                (int) $admin->id,
            );

            return back()->with('message', __('admin.tasks.message.delete_success'));
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.tasks.message.delete_failed', ['message' => $e->getMessage()]));
        }
    }

    /**
     * 恢复当前管理员可见范围内的已删除任务，并保持恢复后的任务处于暂停状态。
     * @Url POST /geo_admin/tasks/{taskId}/restore
     *      登录 是
     *      trash_sequence int 必选 回收站条目序列
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-29 18:51:01
     * @UpdateTime: 2026-10-29 18:51:01
     *
     * @Return RedirectResponse 返回任务列表并提示恢复结果
     * @Throws ValidationException 回收站序列缺失或格式错误时触发
     */
    public function restoreTask(Request $request, int $taskId): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $payload = $request->validate([
            'trash_sequence' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $this->taskLifecycleService->restoreTask(
                taskId: $taskId,
                trashSequence: (int) $payload['trash_sequence'],
                canManageProtectedTask: $admin->isSuperAdmin(),
                auditAdminId: (int) $admin->id,
                viewer: $admin,
            );

            return redirect()->route('admin.tasks.index')->with('message', __('admin.tasks.trash.message.restore_success'));
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(__('admin.tasks.trash.message.restore_failed'));
        }
    }

    /**
     * 任务创建页（先接入可用创建链路，后续继续做 1:1 细节对齐）。
     */
    public function create(Request $request): View
    {
        $this->abortIfAgentAdmin($request);

        if ($request->input('content_type') === 'topic' || old('content_type') === 'topic') {
            return $this->topicForm($request);
        }

        $formOptions = $this->loadTaskFormOptions();
        $prefilledTitleLibraryId = $this->resolvePrefilledTitleLibraryId($request, $formOptions['titleLibraries']);
        $prefilledFixedTitleId = $this->resolvePrefilledFixedTitleId($request, $prefilledTitleLibraryId, $formOptions['titles']);

        // 创建页选项与 tasks.php 数据口径一致（库/模型/作者/分类）。
        return view('admin.tasks.form', [
            'pageTitle' => __('admin.task_create.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'formOptions' => $formOptions,
            'hasCategories' => ! empty($formOptions['categories']),
            'categoryCreateUrl' => route('admin.categories.create'),
            'isEdit' => false,
            'taskForm' => $prefilledTitleLibraryId > 0 ? array_filter([
                'title_library_id' => (string) $prefilledTitleLibraryId,
                'fixed_title_id' => $prefilledFixedTitleId > 0 ? (string) $prefilledFixedTitleId : null,
            ], static fn ($value): bool => $value !== null) : null,
            'taskId' => null,
        ]);
    }

    /**
     * 创建任务（对应上游 task-create.php 的提交逻辑）。
     */
    public function store(Request $request): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);

        if ($request->input('content_type') === 'topic') {
            return $this->saveTopicTask($request);
        }

        if (! Category::query()->exists()) {
            return redirect()
                ->route('admin.categories.create')
                ->withErrors(__('admin.task_create.error.no_categories_configured'));
        }

        $payload = $this->validateTaskForm($request);
        $taskData = $this->buildTaskPayload($request, $payload);
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        try {
            $createdTask = $this->taskLifecycleService->createTask(
                $taskData,
                auditAdminId: (int) $admin->id,
                responseViewer: $admin,
            );
            $createdTaskId = (int) ($createdTask['id'] ?? 0);
            if ($createdTaskId) {
                $this->distributionOrchestrator->syncTaskChannels(
                    Task::query()->whereKey((int) $createdTaskId)->firstOrFail(),
                    $this->selectedDistributionChannelIds($request)
                );
            }
        } catch (TaskTitleReadinessException $e) {
            $report = $e->getDetails()['title_readiness'] ?? [];

            return back()
                ->withInput()
                ->with('title_readiness_report', $this->presentTitleReadiness($report))
                ->withErrors($e->getMessage());
        } catch (ApiException $e) {
            return back()->withInput()->withErrors($e->getDetails()['field_errors'] ?? $e->getMessage());
        } catch (Throwable $e) {
            // 保留输入并回显服务层错误，便于在页面直接修正。
            return back()->withInput()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('admin.tasks.index')
            ->with('message', __('admin.task_create.message.created'));
    }

    /**
     * 任务编辑页：与创建页共用同一 Blade 模板。
     */
    public function edit(Request $request, int $taskId): View|RedirectResponse
    {
        $this->abortIfAgentAdmin($request);

        $topicTask = Task::query()->find($taskId);
        if ($topicTask?->content_type === 'topic') {
            return $this->topicForm($request, $topicTask);
        }

        try {
            $task = $this->taskLifecycleService->getTask($taskId, $request->user('admin'));
        } catch (Throwable $e) {
            return redirect()->route('admin.tasks.index')->withErrors($e->getMessage());
        }

        $formOptions = $this->loadTaskFormOptions();

        return view('admin.tasks.form', [
            'pageTitle' => __('admin.task_edit.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'formOptions' => $formOptions,
            'hasCategories' => ! empty($formOptions['categories']),
            'categoryCreateUrl' => route('admin.categories.create'),
            'isEdit' => true,
            'taskId' => $taskId,
            'taskForm' => [
                'task_name' => (string) ($task['name'] ?? ''),
                'title_library_id' => (string) ($task['title_library_id'] ?? ''),
                'fixed_title_id' => (string) (($task['fixed_title_id'] ?? '') ?: ''),
                'prompt_id' => (string) ($task['prompt_id'] ?? ''),
                'ai_model_id' => (string) ($task['ai_model_id'] ?? ''),
                'author_id' => (string) (($task['author_id'] ?? 0) ?: 0),
                'image_library_id' => (string) (($task['image_library_id'] ?? '') ?: ''),
                'image_mode' => (string) ($task['image_mode'] ?? 'library'),
                'image_count' => (string) ($task['image_count'] ?? 0),
                'ai_image_model_id' => (string) (($task['ai_image_model_id'] ?? '') ?: ''),
                'ai_quality_enabled' => (int) ($task['ai_quality_enabled'] ?? 0),
                'ai_quality_retrieval_mode' => (string) ($task['ai_quality_retrieval_mode'] ?? 'chunk'),
                'ai_quality_prompt_id' => (string) (($task['ai_quality_prompt_id'] ?? '') ?: ''),
                'ai_quality_model_id' => (string) (($task['ai_quality_model_id'] ?? '') ?: ''),
                'ai_quality_pass_score' => (string) ($task['ai_quality_pass_score'] ?? 85),
                'ai_quality_manual_override_min_score' => (string) ($task['ai_quality_manual_override_min_score'] ?? 70),
                'ai_quality_knowledge_base_ids' => $task['ai_quality_knowledge_base_ids'] ?? [],
                'knowledge_base_id' => (string) (($task['knowledge_base_id'] ?? '') ?: ''),
                'fixed_category_id' => (string) (($task['fixed_category_id'] ?? '') ?: ''),
                'status' => (string) ($task['status'] ?? 'active'),
                'article_limit' => (string) ($task['article_limit'] ?? 10),
                'draft_limit' => (string) ($task['draft_limit'] ?? 10),
                'publish_interval' => (string) max(1, (int) (($task['publish_interval'] ?? 3600) / 60)),
                'category_mode' => (string) ($task['category_mode'] ?? 'smart'),
                'model_selection_mode' => (string) ($task['model_selection_mode'] ?? 'fixed'),
                'need_review' => (int) ($task['need_review'] ?? 0),
                'is_loop' => (int) ($task['is_loop'] ?? 1),
                'auto_keywords' => (int) ($task['auto_keywords'] ?? 1),
                'auto_description' => (int) ($task['auto_description'] ?? 1),
                'publish_scope' => (string) ($task['publish_scope'] ?? 'local_and_distribution'),
                'next_publish_at' => (string) ($task['next_publish_at'] ?? ''),
                'scheduled_publish_enabled' => (string) ($task['publish_scope'] ?? 'local_and_distribution') === 'local_only' && ! empty($task['next_publish_at']) ? 1 : 0,
                'scheduled_publish_at' => $this->formatDateTimeLocal((string) ($task['next_publish_at'] ?? '')),
                'distribution_channel_ids' => $this->taskDistributionChannelIds($taskId),
            ],
        ]);
    }

    /**
     * 更新任务：与创建流程共享同一套字段校验与映射逻辑。
     */
    public function update(Request $request, int $taskId): RedirectResponse
    {
        $this->abortIfAgentAdmin($request);

        $existingTask = Task::query()->find($taskId);
        if ($existingTask?->content_type === 'topic') {
            if ($request->filled('content_type') && $request->input('content_type') !== 'topic') {
                throw ValidationException::withMessages(['content_type' => '任务类型保持不变，可以复制创建文章任务。']);
            }

            return $this->saveTopicTask($request, $existingTask);
        }
        if ($request->filled('content_type') && $request->input('content_type') !== 'article') {
            throw ValidationException::withMessages(['content_type' => '任务类型保持不变，请创建新任务。']);
        }

        if (! Category::query()->exists()) {
            return redirect()
                ->route('admin.categories.create')
                ->withErrors(__('admin.task_create.error.no_categories_configured'));
        }

        $payload = $this->validateTaskForm($request);
        $taskData = $this->buildTaskPayload($request, $payload);
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        try {
            $this->taskLifecycleService->updateTask(
                $taskId,
                $taskData,
                $admin->isSuperAdmin(),
                (int) $admin->id,
                responseViewer: $admin,
            );
            $task = Task::query()->whereKey($taskId)->firstOrFail();
            $this->distributionOrchestrator->syncTaskChannels($task, $this->selectedDistributionChannelIds($request));
        } catch (TaskTitleReadinessException $e) {
            $report = $e->getDetails()['title_readiness'] ?? [];

            return back()
                ->withInput()
                ->with('title_readiness_report', $this->presentTitleReadiness($report))
                ->withErrors($e->getMessage());
        } catch (ApiException $e) {
            return back()->withInput()->withErrors($e->getDetails()['field_errors'] ?? $e->getMessage());
        } catch (Throwable $e) {
            return back()->withInput()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('admin.tasks.index')
            ->with('message', __('admin.task_edit.message.update_success'));
    }

    /**
     * 任务监控快照接口：返回任务状态与队列面板数据。
     */
    public function healthCheck(Request $request): JsonResponse
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        try {
            $overview = $this->taskMonitoringQueryService->buildAdminOverview($admin);

            return response()->json([
                'success' => true,
                'tasks' => $overview['tasks'],
                'queue_overview' => $overview['queue_overview'],
                'worker_overview' => $overview['worker_overview'],
                'recent_runs' => $overview['recent_runs'],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @Name: workers
     * @Description: 分页展示 worker 心跳，并将关联任务限制在当前管理员可见范围内。
     * @Author: cdkay
     * @CreateTime: 2026-10-29 17:39:53
     * @UpdateTime: 2026-10-29 17:39:53
     * @Param: Request $request 当前后台请求
     * @Return: View worker 状态列表
     * @Throws: AuthorizationException 当前会话不是后台管理员时返回 403
     */
    public function workers(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        return view('admin.tasks.workers', [
            'pageTitle' => __('admin.tasks.worker.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'workers' => $this->taskMonitoringQueryService->paginateWorkers(
                max(1, $request->integer('page', 1)),
                10,
                $admin,
            ),
        ]);
    }

    /**
     * @Name: jobs
     * @Description: 分页展示管理员可见的任务执行记录，并支持聚焦单条记录。
     * @Author: cdkay
     * @CreateTime: 2026-10-29 17:39:53
     * @UpdateTime: 2026-10-29 17:39:53
     * @Param: Request $request 当前后台请求
     * @Return: View 任务执行记录列表
     * @Throws: AuthorizationException 当前会话不是后台管理员时返回 403
     */
    public function jobs(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $runId = max(0, $request->integer('run_id'));
        $focusedRunId = $runId > 0 ? $runId : null;

        return view('admin.tasks.jobs', [
            'pageTitle' => __('admin.tasks.jobs.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'jobs' => $this->taskMonitoringQueryService->paginateRecentRuns(
                max(1, $request->integer('page', 1)),
                10,
                $focusedRunId,
                $admin,
            ),
            'focusedRunId' => $focusedRunId,
        ]);
    }

    /**
     * 兼容旧接口：批量启动/停止单任务。
     */
    public function batchAction(Request $request): JsonResponse
    {
        $this->abortIfAgentAdmin($request);
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        // 批量接口仅允许 start/stop 两个动作，避免无效写入。
        $payload = $request->validate([
            'task_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'string', 'in:start,stop'],
        ]);

        try {
            $taskId = (int) $payload['task_id'];
            if ($payload['action'] === 'start') {
                $task = Task::query()->findOrFail($taskId);
                $result = $task->content_type === 'topic'
                    ? app(TopicTaskService::class)->start($task, TopicAdminContext::actor(), true)->toArray()
                    : $this->taskLifecycleService->startTask($taskId, true, $admin->isSuperAdmin(), $admin, $admin);
            } else {
                $result = $this->taskLifecycleService->stopTask($taskId, $admin->isSuperAdmin(), $admin);
            }

            return response()->json([
                'success' => true,
                'message' => 'ok',
                'data' => $result,
            ]);
        } catch (TaskTitleReadinessException $e) {
            $details = $e->getDetails();
            if (is_array($details['title_readiness'] ?? null)) {
                $details['title_readiness'] = $this->presentTitleReadiness($details['title_readiness']);
            }

            return response()->json([
                'success' => false,
                'error' => $e->getErrorCode(),
                'message' => $e->getMessage(),
                'details' => $details,
            ], 409);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadTasks(): array
    {
        $admin = request()->user('admin');

        return $this->taskMonitoringQueryService->buildTaskSnapshot($admin instanceof Admin ? $admin : null);
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string,int>, 2: list<array<string,mixed>>}
     */
    private function loadRuntimePanels(): array
    {
        $admin = request()->user('admin');
        $overview = $this->taskMonitoringQueryService->buildAdminOverview($admin instanceof Admin ? $admin : null);

        return [
            $overview['worker_overview'],
            $overview['queue_overview'],
            $overview['recent_runs'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function taskI18n(): array
    {
        // 将页面所需文案统一下发给前端脚本，避免 JS 内硬编码文本。
        return [
            'stopBatch' => __('admin.tasks.action.stop_batch'),
            'startBatch' => __('admin.tasks.action.start_batch'),
            'createdOfLimitLabel' => __('admin.tasks.label.created_of_limit', ['created' => '__CREATED__', 'limit' => '__LIMIT__']),
            'topicCreatedOfLimit' => '已生成 __CREATED__ / __LIMIT__ 个专题',
            'topicPublished' => '已公开 __COUNT__ 个专题',
            'topicDrafts' => '工作稿 __COUNT__ 个专题',
            'topicPublishInterval' => '专题发布间隔',
            'topicLimitReached' => '专题目标已达',
            'draftArticlesLabel' => __('admin.tasks.label.draft_articles', ['count' => '__COUNT__']),
            'createdArticlesLabel' => __('admin.tasks.label.created_articles', ['count' => '__COUNT__']),
            'publishedArticlesLabel' => __('admin.tasks.label.published_articles', ['count' => '__COUNT__']),
            'loopTimesLabel' => __('admin.tasks.label.loop_times', ['count' => '__COUNT__']),
            'secondsSuffix' => __('admin.common.seconds'),
            'minutesSuffix' => __('admin.common.minutes'),
            'hoursSuffix' => __('admin.common.hours'),
            'daysSuffix' => __('admin.common.days'),
            'completed' => __('admin.tasks.status.completed'),
            'waiting' => __('admin.tasks.status.waiting'),
            'waitingPublish' => __('admin.tasks.status.waiting_publish'),
            'waitingReview' => __('admin.tasks.status.waiting_review'),
            'draftPoolFull' => __('admin.tasks.status.draft_pool_full'),
            'limitReached' => __('admin.tasks.status.limit_reached'),
            'queued' => __('admin.tasks.status.pending'),
            'running' => __('admin.tasks.status.running'),
            'nextRunAt' => __('admin.tasks.label.next_run_at', ['time' => '__TIME__']),
            'publishIntervalMinutes' => __('admin.tasks.label.publish_interval_minutes', ['count' => '__COUNT__']),
            'retryingWithAttempts' => __('admin.tasks.label.retrying_with_attempts', ['current' => '__CURRENT__', 'max' => '__MAX__']),
            'pendingRunning' => __('admin.tasks.label.pending_running', ['pending' => '__PENDING__', 'running' => '__RUNNING__']),
            'estimated' => __('admin.tasks.label.estimated', ['time' => '__TIME__']),
            'latestReason' => __('admin.tasks.label.latest_reason', ['message' => '__MESSAGE__']),
            'emptyContent' => __('admin.tasks.failure.empty_content'),
            'emptyContentDetail' => __('admin.tasks.failure.empty_content_detail'),
            'contentTooShort' => __('admin.tasks.failure.content_too_short'),
            'contentTooShortDetail' => __('admin.tasks.failure.content_too_short_detail'),
            'titleExhausted' => __('admin.tasks.failure.title_exhausted'),
            'titleExhaustedDetail' => __('admin.tasks.failure.title_exhausted_detail'),
            'taskPaused' => __('admin.tasks.failure.paused'),
            'taskPausedDetail' => __('admin.tasks.failure.paused_detail'),
            'modelTimeout' => __('admin.tasks.failure.model_timeout'),
            'modelTimeoutDetail' => __('admin.tasks.failure.model_timeout_detail', ['seconds' => '__SECONDS__']),
            'recentFailed' => __('admin.tasks.failure.recent_failed'),
            'syncFailed' => __('admin.tasks.message.status_update_failed'),
            'confirmStart' => __('admin.tasks.confirm.start', ['name' => '__NAME__']),
            'confirmStop' => __('admin.tasks.confirm.stop', ['name' => '__NAME__']),
            'starting' => __('admin.tasks.action.starting'),
            'stopping' => __('admin.tasks.action.stopping'),
            'startFailed' => __('admin.tasks.message.start_failed', ['message' => '__MESSAGE__']),
            'stopFailed' => __('admin.tasks.message.stop_failed', ['message' => '__MESSAGE__']),
            'requestFailed' => __('admin.tasks.message.request_failed', ['message' => '__MESSAGE__']),
            'taskQueued' => __('admin.tasks.message.task_queued', ['name' => '__NAME__']),
            'taskStopped' => __('admin.tasks.message.task_stopped', ['name' => '__NAME__']),
            'enabledStatus' => __('admin.tasks.status.enabled'),
            'disabledStatus' => __('admin.tasks.status.disabled'),
            'noRunnable' => __('admin.tasks.message.no_runnable'),
            'confirmRunAll' => __('admin.tasks.confirm.run_all'),
            'bulkSubmitted' => __('admin.tasks.message.bulk_submitted', ['success' => '__SUCCESS__', 'total' => '__TOTAL__']),
            'bulkSubmittedPartial' => __('admin.tasks.message.bulk_submitted_partial', ['success' => '__SUCCESS__', 'total' => '__TOTAL__']),
            'activating' => __('admin.tasks.action.activating'),
            'pausing' => __('admin.tasks.action.pausing'),
            'confirmActivate' => __('admin.tasks.confirm.activate'),
            'confirmPause' => __('admin.tasks.confirm.pause'),
        ];
    }

    /**
     * @return array{enabled:bool,key:string,host:string,port:int,scheme:string}
     */
    private function taskRealtimeConfig(): array
    {
        $reverbApp = config('reverb.apps.apps.0', []);
        $host = (string) (config('reverb.servers.reverb.hostname') ?: config('app.url'));
        $parsedHost = parse_url($host, PHP_URL_HOST);

        return [
            'enabled' => (string) config('broadcasting.default') === 'reverb',
            'key' => (string) ($reverbApp['key'] ?? ''),
            'host' => $parsedHost ? (string) $parsedHost : (string) $host,
            'port' => (int) (config('reverb.apps.apps.0.options.port') ?: 443),
            'scheme' => (string) (config('reverb.apps.apps.0.options.scheme') ?: 'https'),
        ];
    }

    /**
     * @Name: topicForm
     * @Description: 准备专题任务表单数据，并验证批量快照、站点和绑定专题的访问范围。
     * @Author: cdkay
     * @CreateTime: 2026-10-29 17:04:08
     * @UpdateTime: 2026-10-29 17:04:08
     * @Param: Request $request 当前后台请求
     * @Param: Task|null $task 待编辑的专题任务
     * @Return View 专题任务表单
     */
    private function topicForm(Request $request, ?Task $task = null): View
    {
        $site = TopicAdminContext::site($request, $task?->target_site_key);
        $batchKey = $request->input('fromBatch', old('batch_snapshot_key'));
        $batchTransfer = null;
        $batchTransferError = null;

        if (! $task && is_string($batchKey)) {
            $snapshot = $request->session()->get('topic_batch_transfer.'.$batchKey);
            if (is_array($snapshot)) {
                abort_unless(
                    (int) ($snapshot['owner_admin_id'] ?? 0) === (int) TopicAdminContext::actor()->id
                    && ($snapshot['site'] ?? null) === $site,
                    404
                );
                if (now()->lessThan(Carbon::parse($snapshot['expires_at'] ?? '1970-01-01')) && ! empty($snapshot['rows'])) {
                    $batchTransfer = $snapshot + ['key' => $batchKey];
                }
            }
            if (! $batchTransfer) {
                $batchTransferError = '批量输入已过期或没有有效标题，请返回批量新建重新检查。';
            }
        }

        $formOptions = $this->loadTaskFormOptions();
        $boundTopics = Topic::query()->where('site_key', $site)->orderBy('title')->get(['id', 'title']);
        $fromTopic = $request->integer('fromTopic');
        if ($fromTopic > 0) {
            abort_unless($boundTopics->contains('id', $fromTopic), 404);
        }

        return view('admin.tasks.topic', [
            'pageTitle' => $task ? '编辑专题任务' : '创建专题任务',
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'formOptions' => $formOptions,
            'task' => $task,
            'topicProgress' => $task ? (app(TopicTaskProgress::class)->forTasks([(int) $task->id])[$task->id] ?? []) : [],
            'sites' => TopicAdminContext::sites(),
            'site' => $site,
            'boundTopics' => $boundTopics,
            'fromTopic' => $fromTopic,
            'batchTransfer' => $batchTransfer,
            'batchTransferError' => $batchTransferError,
            'requestKey' => (string) Str::uuid(),
        ]);
    }

    /**
     * @Name: saveTopicTask
     * @Description: 校验并保存专题任务，处理幂等创建和批量标题导入，更新时保留任务类型。
     * @Author: cdkay
     * @CreateTime: 2026-10-29 17:04:08
     * @UpdateTime: 2026-10-29 17:04:08
     * @Param: Request $request 专题任务表单请求
     * @Param: Task|null $task 待更新的专题任务
     * @Return RedirectResponse 保存结果重定向
     */
    private function saveTopicTask(Request $request, ?Task $task = null): RedirectResponse
    {
        $settings = $request->input('topic_settings', []);
        if (! is_array($settings)) {
            $settings = [];
        }
        foreach (['category_ids', 'bound_topic_ids', 'fallback_model_ids'] as $key) {
            $settings[$key] ??= [];
        }
        $request->merge(['topic_settings' => $settings]);

        $data = $request->validate([
            'content_type' => ['required', 'in:topic'],
            'name' => ['required', 'string', 'max:200'],
            'target_site_key' => ['required', 'string', 'max:80'],
            'title_library_id' => ['nullable', 'integer', 'exists:title_libraries,id'],
            'ai_model_id' => ['nullable', 'integer', 'exists:ai_models,id'],
            'topic_limit' => ['required', 'integer', 'between:1,99999'],
            'interval_value' => ['required', 'integer', 'min:1'],
            'interval_unit' => ['required', 'in:second,minute,hour,day'],
            'topic_config_version' => [$task ? 'required' : 'nullable', 'integer', 'min:1'],
            'status' => ['required', 'in:paused,active'],
            'topic_settings' => ['nullable', 'array:after,template_key,target_count,rules,category_ids,bound_topic_ids,update_existing,auto_maintain,protect_manual,fallback_model_ids,freshness,matching_rules,title_overrides,title_ids'],
            'request_key' => ['nullable', 'uuid'],
            'batch_snapshot_key' => ['nullable', 'uuid'],
            'topic_settings.category_ids' => ['nullable', 'array', 'max:100'],
            'topic_settings.category_ids.*' => ['integer', 'exists:categories,id'],
            'topic_settings.bound_topic_ids' => ['nullable', 'array', 'max:100'],
            'topic_settings.bound_topic_ids.*' => ['integer', 'exists:topics,id'],
            'topic_settings.fallback_model_ids' => ['nullable', 'array', 'max:2'],
            'topic_settings.fallback_model_ids.*' => ['integer', 'exists:ai_models,id'],
        ] + TopicFreshnessService::rules('topic_settings.freshness'));

        $data['publish_interval'] = (int) $data['interval_value'] * [
            'second' => 1,
            'minute' => 60,
            'hour' => 3600,
            'day' => 86400,
        ][$data['interval_unit']];
        foreach (['auto_maintain', 'protect_manual', 'update_existing'] as $flag) {
            $data['topic_settings'][$flag] = $request->boolean('topic_settings.'.$flag);
        }
        foreach (['category_ids', 'bound_topic_ids', 'fallback_model_ids'] as $key) {
            $data['topic_settings'][$key] = $request->input('topic_settings.'.$key, []);
        }

        $actor = TopicAdminContext::actor();
        if ($task) {
            app(TopicTaskService::class)->save($data, $actor, $task);
        } else {
            $snapshot = null;
            if (! empty($data['batch_snapshot_key'])) {
                $snapshot = $request->session()->get('topic_batch_transfer.'.$data['batch_snapshot_key']);
                if (
                    ! is_array($snapshot)
                    || empty($snapshot['rows'])
                    || now()->greaterThanOrEqualTo(Carbon::parse($snapshot['expires_at'] ?? '1970-01-01'))
                ) {
                    throw ValidationException::withMessages(['batch_snapshot_key' => '批量标题已过期，请返回批量新建重新检查后保存。']);
                }
                abort_unless(
                    (int) ($snapshot['owner_admin_id'] ?? 0) === (int) $actor->id
                    && ($snapshot['site'] ?? null) === $data['target_site_key'],
                    404
                );
            }

            $key = $data['request_key'] ?? (string) Str::uuid();
            $payload = array_diff_key($data, ['request_key' => true]);
            $operationRequest = Request::create($request->url(), 'POST', $payload);
            $operationRequest->headers->set('X-Idempotency-Key', $key);
            IdempotencyService::executeJson(
                $operationRequest,
                'admin.topic_tasks.create:'.$actor->id,
                function () use ($payload, $actor, $snapshot): JsonResponse {
                    $input = $payload;
                    if ($snapshot) {
                        $library = ! empty($input['title_library_id'])
                            ? TitleLibrary::query()->lockForUpdate()->findOrFail($input['title_library_id'])
                            : TitleLibrary::query()->create([
                                'name' => mb_substr($input['name'], 0, 170).' 标题库',
                                'description' => '从专题批量创建导入',
                                'title_count' => 0,
                            ]);
                        $overrides = [];
                        $titleIds = [];
                        foreach ($snapshot['rows'] as $row) {
                            $binding = app(TopicImportRows::class)->taskTitle($row, $input['topic_settings'] ?? []);
                            $title = ! empty($binding['overrides']['topic_title'])
                                ? Title::query()
                                    ->where('library_id', $library->id)
                                    ->whereRaw('lower(title) = ?', [mb_strtolower(Title::normalizeText($binding['library_title']), 'UTF-8')])
                                    ->orderBy('id')
                                    ->first()
                                : null;
                            $title ??= Title::query()->firstOrCreate(
                                [
                                    'library_id' => $library->id,
                                    'title_fingerprint' => Title::fingerprintFor($binding['library_title']),
                                ],
                                ['title' => $binding['library_title'], 'is_ai_generated' => 0, 'used_count' => 0]
                            );
                            $titleIds[] = (int) $title->id;
                            if (! empty($binding['overrides'])) {
                                $overrides[$title->id] = $binding['overrides'];
                            }
                        }
                        $library->update(['title_count' => $library->titles()->count()]);
                        $input['title_library_id'] = $library->id;
                        $input['topic_settings']['title_overrides'] = $overrides;
                        $input['topic_settings']['title_ids'] = $titleIds;
                    }

                    $created = app(TopicTaskService::class)->save($input, $actor);

                    return response()->json(['task_id' => $created->id], 201);
                },
                fingerprintContext: ['actor_id' => (int) $actor->id, 'batch_snapshot' => $snapshot]
            );
        }

        return redirect()->route('admin.tasks.index')->with('message', '专题任务已保存。')->with('topic_task_saved', true);
    }

    /**
     * @return array{
     *     titleLibraries: list<array{id:int,name:string,count:int,used:int,available:int,manage_url:string}>,
     *     titles: list<array{id:int,library_id:int,title:string,keyword:string}>,
     *     prompts: list<array{id:int,name:string}>,
     *     aiModels: list<array{id:int,name:string}>,
     *     imageLibraries: list<array{id:int,name:string,count:int}>,
     *     knowledgeBases: list<array{id:int,name:string}>,
     *     authors: list<array{id:int,name:string}>,
     *     categories: list<array{id:int,name:string}>,
     *     distributionChannels: list<array{id:int,name:string,domain:string}>,
     * }
     * @UpdateTime: 2026-10-30 02:15:49
     */
    private function loadTaskFormOptions(): array
    {
        // 直接附带标题总数和可用数，避免 Blade 层再次查询。
        $titleLibraries = TitleLibrary::query()
            ->select(['id', 'name'])
            ->with(['keywordLibrary:id,name,company_name'])
            ->selectRaw('(SELECT COUNT(*) FROM titles WHERE titles.library_id = title_libraries.id) AS title_count')
            ->selectRaw('(SELECT COUNT(*) FROM titles WHERE titles.library_id = title_libraries.id AND (titles.used_count IS NULL OR titles.used_count <= 0)) AS available_title_count')
            ->orderByDesc('id')
            ->get()
            ->map(static function (TitleLibrary $row): array {
                $total = (int) ($row->title_count ?? 0);
                $available = (int) ($row->available_title_count ?? 0);

                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'count' => $total,
                    'used' => max(0, $total - $available),
                    'available' => $available,
                    'manage_url' => route('admin.title-libraries.detail', ['libraryId' => (int) $row->id]),
                    'keyword_library_name' => (string) ($row->keywordLibrary?->name ?? ''),
                    'company_name' => (string) ($row->keywordLibrary?->company_name ?? ''),
                ];
            })
            ->all();

        $titles = Title::query()
            ->select(['id', 'library_id', 'title', 'keyword'])
            ->orderBy('library_id')
            ->orderBy('id')
            ->get()
            ->map(static fn (Title $row): array => [
                'id' => (int) $row->id,
                'library_id' => (int) $row->library_id,
                'title' => (string) $row->title,
                'keyword' => (string) ($row->keyword ?? ''),
            ])
            ->all();

        $admin = request()->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $prompts = $this->aiConfigurationScope->applyConsumerScope(
            Prompt::query()->withoutGlobalScope('current_site'),
            $admin,
            'prompts.owner_admin_id'
        )
            ->select(['id', 'name'])
            ->where('type', 'content')
            ->whereNull('site_id')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Prompt $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $aiQualityPrompts = $this->aiConfigurationScope->applyConsumerScope(
            Prompt::query()->withoutGlobalScope('current_site'),
            $admin,
            'prompts.owner_admin_id'
        )
            ->select(['id', 'name'])
            ->where('type', 'quality_check')
            ->whereNull('site_id')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Prompt $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $aiModels = $this->aiConfigurationScope->applyConsumerScope(
            AiModel::query()->withoutGlobalScope('current_site'),
            $admin,
            'ai_models.owner_admin_id'
        )
            ->select(['id', 'name'])
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (AiModel $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $aiImageModels = $this->aiConfigurationScope->applyConsumerScope(
            AiModel::query()->withoutGlobalScope('current_site'),
            $admin,
            'ai_models.owner_admin_id'
        )
            ->select(['id', 'name'])
            ->where('status', 'active')
            ->where('model_type', 'image')
            ->orderBy('failover_priority')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (AiModel $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        // 兼容上游展示：图库名称 + 图片数量。
        $imageLibraries = ImageLibrary::query()
            ->select(['id', 'name'])
            ->selectRaw('(SELECT COUNT(*) FROM images WHERE images.library_id = image_libraries.id) AS image_count')
            ->orderBy('name')
            ->get()
            ->map(static function (ImageLibrary $row): array {
                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'count' => (int) ($row->image_count ?? 0),
                ];
            })
            ->all();

        $knowledgeBases = KnowledgeBase::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(static fn (KnowledgeBase $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $authors = Author::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(static fn (Author $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $categories = Category::query()
            ->select(['id', 'name'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (Category $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $distributionChannels = DistributionChannel::query()
            ->select(['id', 'name', 'domain'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(static fn (DistributionChannel $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'domain' => (string) $row->domain,
            ])
            ->all();

        return [
            'titleLibraries' => $titleLibraries,
            'titles' => $titles,
            'prompts' => $prompts,
            'aiQualityPrompts' => $aiQualityPrompts,
            'aiModels' => $aiModels,
            'aiImageModels' => $aiImageModels,
            'imageLibraries' => $imageLibraries,
            'knowledgeBases' => $knowledgeBases,
            'authors' => $authors,
            'categories' => $categories,
            'distributionChannels' => $distributionChannels,
        ];
    }

    /**
     * @param  list<array{id:int,name:string}>  $titleLibraries
     */
    private function resolvePrefilledTitleLibraryId(Request $request, array $titleLibraries): int
    {
        $titleLibraryId = (int) $request->query('title_library_id', 0);
        if ($titleLibraryId <= 0) {
            return 0;
        }

        foreach ($titleLibraries as $library) {
            if ((int) ($library['id'] ?? 0) === $titleLibraryId) {
                return $titleLibraryId;
            }
        }

        return 0;
    }

    /**
     * @param  list<array{id:int,library_id:int,title:string,keyword:string}>  $titles
     */
    private function resolvePrefilledFixedTitleId(Request $request, int $titleLibraryId, array $titles): int
    {
        $fixedTitleId = (int) $request->query('fixed_title_id', $request->query('title_id', 0));
        if ($fixedTitleId <= 0 || $titleLibraryId <= 0) {
            return 0;
        }

        foreach ($titles as $title) {
            if ((int) ($title['id'] ?? 0) === $fixedTitleId && (int) ($title['library_id'] ?? 0) === $titleLibraryId) {
                return $fixedTitleId;
            }
        }

        return 0;
    }

    /**
     * @return array{
     *     task_name: string,
     *     title_library_id: int,
     *     fixed_title_id: int|null,
     *     prompt_id: int,
     *     ai_model_id: int,
     *     author_id: int|null,
     *     image_library_id: int|null,
     *     image_count: int|null,
     *     knowledge_base_id: int|null,
     *     fixed_category_id: int|null,
     *     status: string,
     *     article_limit: int|null,
     *     draft_limit: int|null,
     *     publish_interval: int|null,
     *     category_mode: string|null,
     *     model_selection_mode: string|null,
     *     scheduled_publish_enabled: bool|null,
     *     scheduled_publish_at: string|null,
     *     ai_quality_knowledge_base_ids: list<int>|null
     * }
     */
    private function validateTaskForm(Request $request): array
    {
        return $request->validate([
            'task_name' => ['required', 'string', 'max:200'],
            'title_library_id' => ['required', 'integer', 'min:1'],
            'fixed_title_id' => ['nullable', 'integer', 'min:1'],
            'prompt_id' => ['required', 'integer', 'min:1'],
            'ai_model_id' => ['required', 'integer', 'min:1'],
            'author_id' => ['nullable', 'integer', 'min:0'],
            'image_mode' => ['nullable', 'string', 'in:none,library,ai'],
            'image_library_id' => ['nullable', 'integer', 'min:1'],
            'image_count' => ['nullable', 'integer', 'min:0', 'max:5'],
            'ai_image_model_id' => ['nullable', 'integer', 'min:1'],
            'knowledge_base_id' => ['nullable', 'integer', 'min:1'],
            'ai_quality_enabled' => ['nullable', 'boolean'],
            'ai_quality_retrieval_mode' => ['nullable', 'string', 'in:atomic_first,chunk,knowledge_broad'],
            'ai_quality_prompt_id' => ['nullable', 'integer', 'min:1'],
            'ai_quality_model_id' => ['nullable', 'integer', 'min:1'],
            'ai_quality_pass_score' => ['nullable', 'integer', 'min:1', 'max:100'],
            'ai_quality_manual_override_min_score' => ['nullable', 'integer', 'min:0', 'max:99'],
            'ai_quality_knowledge_base_ids' => ['nullable', 'array', 'max:5'],
            'ai_quality_knowledge_base_ids.*' => ['integer', 'min:1'],
            'fixed_category_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:active,paused'],
            'article_limit' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'draft_limit' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'publish_interval' => ['nullable', 'integer', 'min:1'],
            'category_mode' => ['nullable', 'string', 'in:smart,fixed,random'],
            'model_selection_mode' => ['nullable', 'string', 'in:fixed,smart_failover'],
            'publish_scope' => ['nullable', 'string', 'in:local_and_distribution,distribution_only,local_only'],
            'scheduled_publish_enabled' => ['nullable', 'boolean'],
            'scheduled_publish_at' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $request->boolean('scheduled_publish_enabled') && (string) $request->input('publish_scope') === 'local_only'),
                'date',
            ],
            'distribution_channel_ids' => ['nullable', 'array'],
            'distribution_channel_ids.*' => ['integer', 'min:1'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function buildTaskPayload(Request $request, array $payload): array
    {
        $categoryMode = (string) ($payload['category_mode'] ?? 'smart');
        if ($categoryMode === 'random') {
            $categoryMode = 'smart';
        }
        $publishScope = (string) ($payload['publish_scope'] ?? 'local_and_distribution');
        $scheduledPublishEnabled = $publishScope === 'local_only'
            && $request->boolean('scheduled_publish_enabled')
            && trim((string) ($payload['scheduled_publish_at'] ?? '')) !== '';
        $nextPublishAt = null;
        if ($scheduledPublishEnabled) {
            $nextPublishAt = Carbon::parse((string) $payload['scheduled_publish_at'])->toDateTimeString();
        }
        $needReview = $scheduledPublishEnabled ? 0 : ($request->boolean('need_review') ? 1 : 0);

        $taskData = [
            'name' => (string) $payload['task_name'],
            'title_library_id' => (int) $payload['title_library_id'],
            'fixed_title_id' => isset($payload['fixed_title_id']) ? (int) $payload['fixed_title_id'] : null,
            'image_mode' => (string) ($payload['image_mode'] ?? 'library'),
            'image_library_id' => isset($payload['image_library_id']) ? (int) $payload['image_library_id'] : null,
            'image_count' => (int) ($payload['image_count'] ?? 0),
            'ai_image_model_id' => isset($payload['ai_image_model_id']) ? (int) $payload['ai_image_model_id'] : null,
            'prompt_id' => (int) $payload['prompt_id'],
            'ai_model_id' => (int) $payload['ai_model_id'],
            'author_id' => isset($payload['author_id']) && (int) $payload['author_id'] > 0 ? (int) $payload['author_id'] : null,
            'knowledge_base_id' => isset($payload['knowledge_base_id']) ? (int) $payload['knowledge_base_id'] : null,
            'fixed_category_id' => isset($payload['fixed_category_id']) ? (int) $payload['fixed_category_id'] : null,
            'status' => (string) $payload['status'],
            'publish_scope' => $publishScope,
            'next_publish_at' => $nextPublishAt,
            'article_limit' => (int) ($payload['article_limit'] ?? 10),
            'draft_limit' => (int) ($payload['draft_limit'] ?? 10),
            'publish_interval' => max(1, (int) ($payload['publish_interval'] ?? 60)) * 60,
            'need_review' => $needReview,
            'is_loop' => $request->boolean('is_loop') ? 1 : 0,
            'category_mode' => $categoryMode,
            'model_selection_mode' => (string) ($payload['model_selection_mode'] ?? 'fixed'),
            'auto_keywords' => $request->boolean('auto_keywords') ? 1 : 0,
            'auto_description' => $request->boolean('auto_description') ? 1 : 0,
            'ai_quality_enabled' => $request->boolean('ai_quality_enabled') ? 1 : 0,
            'ai_quality_retrieval_mode' => (string) ($payload['ai_quality_retrieval_mode'] ?? 'chunk'),
            'ai_quality_prompt_id' => isset($payload['ai_quality_prompt_id']) ? (int) $payload['ai_quality_prompt_id'] : null,
            'ai_quality_model_id' => isset($payload['ai_quality_model_id']) ? (int) $payload['ai_quality_model_id'] : null,
            'ai_quality_pass_score' => (int) ($payload['ai_quality_pass_score'] ?? 85),
            'ai_quality_manual_override_min_score' => (int) ($payload['ai_quality_manual_override_min_score'] ?? 70),
        ];

        if (! $request->boolean('ai_quality_enabled')) {
            $taskData['knowledge_base_ids'] = [];
        } elseif (array_key_exists('ai_quality_knowledge_base_ids', $payload)) {
            $taskData['knowledge_base_ids'] = collect($payload['ai_quality_knowledge_base_ids'] ?? [])
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();
        }

        return $taskData;
    }

    /**
     * @return list<int>
     */
    private function selectedDistributionChannelIds(Request $request): array
    {
        return collect($request->input('distribution_channel_ids', []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function taskDistributionChannelIds(int $taskId): array
    {
        $task = Task::query()->whereKey($taskId)->first();
        if (! $task) {
            return [];
        }

        return $task->distributionChannels()
            ->pluck('distribution_channels.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @Name: presentTitleReadiness
     * @Description: 本地化标题库就绪报告，并附加可访问的管理链接；报告冲突项会先按当前管理员权限脱敏。
     * @Author: cdkay
     * @CreateTime: 2026-10-30 01:58:42
     * @UpdateTime: 2026-10-30 01:58:42
     * @Param: array<string,mixed> $report 标题库就绪检查结果
     * @Return: array<string,mixed> 含本地化文案和管理链接的检查结果
     */
    private function presentTitleReadiness(array $report): array
    {
        $report = $this->redactProtectedTitleReadinessConflicts($report);
        $library = is_array($report['library'] ?? null) ? $report['library'] : [];
        $task = is_array($report['task'] ?? null) ? $report['task'] : [];
        $replace = [
            'name' => (string) ($library['name'] ?? ''),
            'total' => (int) ($library['total'] ?? 0),
            'used' => (int) ($library['used'] ?? 0),
            'available' => (int) ($library['available'] ?? 0),
            'remaining' => (int) ($task['remaining'] ?? 0),
            'shortage' => (int) ($report['shortage'] ?? 0),
            'max' => (int) ($report['suggested_article_limit'] ?? 0),
        ];

        $report['issues'] = collect($report['issues'] ?? [])->map(
            static function (array $issue) use ($replace): array {
                $code = (string) ($issue['code'] ?? 'request_failed');
                $key = 'admin.task_create.readiness.issue.'.$code;
                $suggestionIndexes = [1, 2, 3];
                if ($replace['max'] < 1 && $code === 'title_library_exhausted') {
                    $suggestionIndexes = [1, 2];
                } elseif ($replace['max'] < 1 && $code === 'title_library_shortage') {
                    $suggestionIndexes = [1, 3];
                }

                return $issue + [
                    'title' => __($key.'.title', $replace),
                    'message' => __($key.'.message', $replace),
                    'impact' => __($key.'.impact', $replace),
                    'suggestions' => collect($suggestionIndexes)
                        ->map(static fn (int $index): string => __($key.'.suggestion_'.$index, $replace))
                        ->filter(static fn (string $value): bool => $value !== '' && ! str_contains($value, 'admin.task_create.readiness.'))
                        ->values()
                        ->all(),
                ];
            }
        )->values()->all();

        $libraryId = (int) ($library['id'] ?? 0);
        $taskId = (int) ($task['id'] ?? 0);
        $report['manage_url'] = $libraryId > 0
            ? route('admin.title-libraries.detail', ['libraryId' => $libraryId])
            : null;
        $report['edit_url'] = $taskId > 0
            ? route('admin.tasks.edit', ['taskId' => $taskId])
            : null;
        $report['summary'] = __('admin.task_create.readiness.summary', $replace);
        $report['recommendation'] = (int) ($report['suggested_article_limit'] ?? 0) >= 1
            ? __('admin.task_create.readiness.recommendation', $replace)
            : __('admin.task_create.readiness.recommendation_without_limit', $replace);
        $report['paused_hint'] = ($task['status'] ?? '') === 'paused'
            ? __('admin.task_create.readiness.paused_hint')
            : null;

        return $report;
    }

    /**
     * @Name: redactProtectedTitleReadinessConflicts
     * @Description: 从普通管理员的共享标题库冲突清单中剔除托管站点和非主站专题任务，避免泄露跨业务任务信息。
     * @Author: cdkay
     * @CreateTime: 2026-10-30 01:58:42
     * @UpdateTime: 2026-10-30 01:58:42
     * @Param: array<string,mixed> $report 标题库就绪检查结果
     * @Return: array<string,mixed> 已按管理员权限脱敏的检查结果
     */
    private function redactProtectedTitleReadinessConflicts(array $report): array
    {
        $conflicts = is_array($report['conflicts'] ?? null) ? $report['conflicts'] : [];
        if ($conflicts === [] || auth('admin')->user()?->isSuperAdmin() === true) {
            return $report;
        }

        $taskIds = collect($conflicts)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->values();
        $protectedTaskIds = Task::query()
            ->whereIn('id', $taskIds)
            ->where(fn ($query) => $query->whereHas('distributionChannels', fn ($channels) => $channels->where(
                'channel_type', DistributionChannel::TYPE_HOSTED_SITE
            ))->orWhere(fn ($topics) => $topics->where('content_type', 'topic')->where('target_site_key', '!=', 'primary')))
            ->pluck('id')
            ->mapWithKeys(static fn ($id): array => [(int) $id => true]);

        $report['conflicts'] = collect($conflicts)
            ->reject(static fn (array $conflict): bool => $protectedTaskIds->has((int) ($conflict['id'] ?? 0)))
            ->values()
            ->all();
        $report['redacted_conflict_count'] = $protectedTaskIds->count();

        return $report;
    }

    /**
     * @Name: assertCanManageHostedTask
     * @Description: 阻止普通管理员检查托管站点或非主站专题任务的标题库状态。
     * @Author: cdkay
     * @CreateTime: 2026-10-30 01:58:42
     * @UpdateTime: 2026-10-30 01:58:42
     * @Param: int $taskId 任务编号
     * @Return: void 权限不足时中止请求并返回 403
     * @Throws: AuthorizationException 普通管理员访问受保护任务时触发
     */
    private function assertCanManageHostedTask(int $taskId): void
    {
        if (auth('admin')->user()?->isSuperAdmin() === true) {
            return;
        }

        $hasHostedChannel = Task::query()
            ->whereKey($taskId)
            ->where(fn ($query) => $query->whereHas('distributionChannels', fn ($channels) => $channels->where(
                'channel_type', DistributionChannel::TYPE_HOSTED_SITE
            ))->orWhere(fn ($topics) => $topics->where('content_type', 'topic')->where('target_site_key', '!=', 'primary')))
            ->exists();
        abort_if($hasHostedChannel, 403);
    }

    private function formatDateTimeLocal(string $value): string
    {
        if (trim($value) === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d\TH:i');
        } catch (Throwable) {
            return '';
        }
    }

    private function abortIfAgentAdmin(Request $request): void
    {
        $admin = $request->user('admin');
        abort_if($admin instanceof Admin && $admin->isAgentAdmin(), 403);
    }
}
