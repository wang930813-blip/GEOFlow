<?php

namespace App\Services\BrandDiagnosis;

use App\Jobs\GenerateBrandDiagnosisQuestionsJob;
use App\Jobs\ProcessBrandDiagnosisJob;
use App\Models\Admin;
use App\Models\BrandDiagnosisQuestion;
use App\Models\BrandDiagnosisRun;
use App\Support\CurrentSite;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Created by Codex.
 *
 * @Date: 2026-11-03
 *
 * @Time: 11:37:29
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： BrandDiagnosisRunService.php
 *
 * @Description: 创建和推进品牌诊断任务，并将诊断数据归属到当前站点账号。
 */
class BrandDiagnosisRunService
{
    public function __construct(
        private readonly BrandDiagnosisUsagePolicy $usagePolicy,
        private readonly CurrentSite $currentSite,
    ) {}

    /**
     * @Name: create
     *
     * @Description: 在当前站点创建品牌诊断任务；超管切换客户账号后将任务及问题归属到所选站点账号。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Param: Admin $admin 实际执行操作的管理员
     *
     * @Param: string $brandName 品牌名称
     *
     * @Param: list<string> $platforms 参与诊断的平台标识
     *
     * @Param: bool $reuseQuestions 是否复用当前账号的历史问题
     *
     * @Return: BrandDiagnosisRun 新建的诊断任务
     *
     * @Throws: RuntimeException 品牌名称为空或当前站点未初始化时抛出
     */
    public function create(Admin $admin, string $brandName, array $platforms, bool $reuseQuestions = false): BrandDiagnosisRun
    {
        $brandName = trim($brandName);
        if ($brandName === '') {
            throw new RuntimeException('请输入品牌名称。');
        }

        $platforms = $this->normalizePlatforms($platforms);
        $siteId = (int) ($this->currentSite->id() ?? 0);
        if ($siteId <= 0) {
            throw new RuntimeException('当前站点未初始化，请刷新后台后重试。');
        }

        $ownerAdminId = $this->ownerAdminIdForAdmin($admin);

        if ($reuseQuestions) {
            $reusableRun = $this->latestReusableQuestionRun($admin, $brandName, $siteId);
            $reusableQuestions = $reusableRun instanceof BrandDiagnosisRun
                ? $this->questionsFromRun($reusableRun)
                : collect();
            if ($reusableRun instanceof BrandDiagnosisRun && $reusableQuestions->isNotEmpty()) {
                return DB::transaction(function () use ($admin, $brandName, $platforms, $siteId, $ownerAdminId, $reusableRun, $reusableQuestions): BrandDiagnosisRun {
                    $run = BrandDiagnosisRun::query()->create([
                        'site_id' => $siteId,
                        'owner_admin_id' => $ownerAdminId,
                        'admin_id' => (int) $admin->id,
                        'brand_name' => $brandName,
                        'brand_profile' => (string) ($reusableRun->brand_profile ?? ''),
                        'brand_profile_source' => (string) ($reusableRun->brand_profile_source ?? ''),
                        'brand_profile_model' => (string) ($reusableRun->brand_profile_model ?? ''),
                        'brand_profile_status' => (string) ($reusableRun->brand_profile_status ?? ''),
                        'brand_profile_meta' => is_array($reusableRun->brand_profile_meta) ? $reusableRun->brand_profile_meta : null,
                        'platforms' => $platforms,
                        'status' => 'questions_ready',
                        'total_questions' => $reusableQuestions->count(),
                        'completed_questions' => 0,
                        'failed_questions' => 0,
                        'brand_score' => 0,
                        'mention_rate' => 0,
                        'average_rank' => 0,
                        'mention_count' => 0,
                        'sentiment_rate' => 0,
                        'billing_mode' => 'pending_confirmation',
                        'points_cost' => 0,
                        'points_transaction_id' => null,
                        'limit_bypassed' => false,
                        'limit_bypass_reason' => '',
                        'usage_date' => null,
                        'started_at' => null,
                        'completed_at' => null,
                        'error_message' => null,
                    ]);

                    $this->createQuestionsForRun($run, $reusableQuestions);

                    return $run->refresh();
                });
            }
        }

        $run = DB::transaction(function () use ($admin, $brandName, $platforms, $siteId, $ownerAdminId): BrandDiagnosisRun {
            return BrandDiagnosisRun::query()->create([
                'site_id' => $siteId,
                'owner_admin_id' => $ownerAdminId,
                'admin_id' => (int) $admin->id,
                'brand_name' => $brandName,
                'platforms' => $platforms,
                'status' => 'questions_generating',
                'total_questions' => 0,
                'completed_questions' => 0,
                'failed_questions' => 0,
                'billing_mode' => 'pending_confirmation',
                'points_cost' => 0,
                'points_transaction_id' => null,
                'limit_bypassed' => false,
                'limit_bypass_reason' => '',
                'usage_date' => null,
            ]);
        });

        GenerateBrandDiagnosisQuestionsJob::dispatch((int) $run->id)->onQueue('geoflow');

        return $run;
    }

    /**
     * @param  list<string>  $platforms
     */
    public function createForApi(Admin $admin, string $brandName, array $platforms, string $apiTaskKey): BrandDiagnosisRun
    {
        $brandName = trim($brandName);
        $apiTaskKey = trim($apiTaskKey);
        if ($brandName === '') {
            throw new RuntimeException('请输入品牌名称。');
        }
        if ($apiTaskKey === '') {
            throw new RuntimeException('API 任务 ID 不能为空。');
        }

        $platforms = $this->normalizePlatforms($platforms);

        $run = DB::transaction(function () use ($admin, $brandName, $platforms, $apiTaskKey): BrandDiagnosisRun {
            return BrandDiagnosisRun::query()->create([
                'site_id' => null,
                'api_task_key' => $apiTaskKey,
                'owner_admin_id' => (int) $admin->id,
                'admin_id' => (int) $admin->id,
                'brand_name' => $brandName,
                'platforms' => $platforms,
                'status' => 'questions_generating',
                'total_questions' => 0,
                'completed_questions' => 0,
                'failed_questions' => 0,
                'billing_mode' => 'pending_confirmation',
                'points_cost' => 0,
                'points_transaction_id' => null,
                'limit_bypassed' => false,
                'limit_bypass_reason' => '',
                'usage_date' => null,
            ]);
        });

        GenerateBrandDiagnosisQuestionsJob::dispatch((int) $run->id)->onQueue('geoflow');

        return $run;
    }

    /**
     * @return array{run_id:int,created_at:string,questions:list<array{question:string,type:string,core_term:string,sort_order:int}>}|null
     */
    public function reusableQuestionPreview(Admin $admin, string $brandName): ?array
    {
        $siteId = (int) ($this->currentSite->id() ?? 0);
        if ($siteId <= 0) {
            return null;
        }

        $run = $this->latestReusableQuestionRun($admin, trim($brandName), $siteId);
        if (! $run instanceof BrandDiagnosisRun) {
            return null;
        }

        return [
            'run_id' => (int) $run->id,
            'created_at' => $run->created_at?->format('Y-m-d H:i:s') ?? '',
            'questions' => $this->questionsFromRun($run)->values()->all(),
        ];
    }

    /**
     * @Name: confirm
     *
     * @Description: 确认诊断问题并启动执行；账号切换后修正任务与问题的所有者，同时保留实际操作人。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Param: Admin $admin 实际执行操作的管理员
     *
     * @Param: BrandDiagnosisRun $sourceRun 待确认的诊断任务
     *
     * @Param: array<int|string,string> $questions 用户确认后的问题内容
     *
     * @Param: list<string>|null $platforms 用户选择的平台；null 时保留任务原平台
     *
     * @Return: BrandDiagnosisRun 已确认并启动的诊断任务
     *
     * @Throws: RuntimeException 任务状态或问题内容不允许确认时抛出
     *
     * @Throws: BrandDiagnosisLimitExceededException 当前账号诊断额度不足时抛出
     */
    public function confirm(Admin $admin, BrandDiagnosisRun $sourceRun, array $questions, ?array $platforms = null): BrandDiagnosisRun
    {
        $run = DB::transaction(function () use ($admin, $sourceRun, $questions, $platforms): BrandDiagnosisRun {
            $lockedRun = BrandDiagnosisRun::query()
                ->withoutGlobalScope('current_site')
                ->with(['questions' => fn ($query) => $query->orderBy('sort_order')->lockForUpdate()])
                ->whereKey((int) $sourceRun->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedRun) {
                throw new RuntimeException('诊断记录不存在，请刷新后重试。');
            }

            $status = (string) $lockedRun->status;
            if (! in_array($status, ['questions_ready', 'awaiting_confirmation', 'completed', 'failed'], true)) {
                throw new RuntimeException('当前记录正在处理中，请勿重复提交诊断。');
            }
            if ($lockedRun->questions->isEmpty()) {
                throw new RuntimeException('当前记录还没有可确认的 AI 问题，请先重新生成问题。');
            }

            $ownerAdminId = $this->ownerAdminIdForAdmin(
                $admin,
                (int) ($lockedRun->owner_admin_id ?: $admin->id)
            );
            $lockedRun->owner_admin_id = $ownerAdminId;

            $normalizedQuestions = $this->confirmedQuestions($lockedRun->questions, $questions);
            if ($normalizedQuestions->isEmpty()) {
                throw new RuntimeException('请至少保留一个 AI 问题。');
            }

            // 管理后台确认时使用用户本次重新选择的模型；MCP 旧协议未传模型时才保留原模型列表。
            $selectedPlatforms = $this->normalizePlatforms($platforms ?? (array) $lockedRun->platforms);
            $decision = $this->usagePolicy->reserve($admin, (int) $lockedRun->site_id);

            if (in_array($status, ['questions_ready', 'awaiting_confirmation'], true)) {
                $this->updateQuestionsForRun($lockedRun, $normalizedQuestions);
                $lockedRun->update([
                    'platforms' => $selectedPlatforms,
                    'owner_admin_id' => $ownerAdminId,
                    'status' => 'running',
                    'total_questions' => $normalizedQuestions->count(),
                    'completed_questions' => 0,
                    'failed_questions' => 0,
                    'brand_score' => 0,
                    'mention_rate' => 0,
                    'average_rank' => 0,
                    'mention_count' => 0,
                    'sentiment_rate' => 0,
                    'billing_mode' => $decision->billingMode,
                    'points_cost' => 0,
                    'points_transaction_id' => null,
                    'limit_bypassed' => $decision->limitBypassed,
                    'limit_bypass_reason' => $decision->limitBypassReason,
                    'usage_date' => now()->toDateString(),
                    'started_at' => now(),
                    'completed_at' => null,
                    'error_message' => null,
                ]);

                return $lockedRun->refresh();
            }

            $newRun = BrandDiagnosisRun::query()->create([
                'site_id' => (int) $lockedRun->site_id,
                'owner_admin_id' => $ownerAdminId,
                'admin_id' => (int) $admin->id,
                'brand_name' => (string) $lockedRun->brand_name,
                'brand_profile' => (string) ($lockedRun->brand_profile ?? ''),
                'brand_profile_source' => (string) ($lockedRun->brand_profile_source ?? ''),
                'brand_profile_model' => (string) ($lockedRun->brand_profile_model ?? ''),
                'brand_profile_status' => (string) ($lockedRun->brand_profile_status ?? ''),
                'brand_profile_meta' => is_array($lockedRun->brand_profile_meta) ? $lockedRun->brand_profile_meta : null,
                'platforms' => $selectedPlatforms,
                'status' => 'running',
                'total_questions' => $normalizedQuestions->count(),
                'completed_questions' => 0,
                'failed_questions' => 0,
                'brand_score' => 0,
                'mention_rate' => 0,
                'average_rank' => 0,
                'mention_count' => 0,
                'sentiment_rate' => 0,
                'billing_mode' => $decision->billingMode,
                'points_cost' => 0,
                'points_transaction_id' => null,
                'limit_bypassed' => $decision->limitBypassed,
                'limit_bypass_reason' => $decision->limitBypassReason,
                'usage_date' => now()->toDateString(),
                'started_at' => now(),
                'completed_at' => null,
                'error_message' => null,
            ]);

            $this->createQuestionsForRun($newRun, $normalizedQuestions);

            return $newRun;
        });

        ProcessBrandDiagnosisJob::dispatch((int) $run->id)->onQueue('geoflow');

        return $run;
    }

    public function confirmForApi(BrandDiagnosisRun $sourceRun): BrandDiagnosisRun
    {
        $run = DB::transaction(function () use ($sourceRun): BrandDiagnosisRun {
            $lockedRun = BrandDiagnosisRun::query()
                ->withoutGlobalScope('current_site')
                ->with(['questions' => fn ($query) => $query->orderBy('sort_order')->lockForUpdate()])
                ->whereKey((int) $sourceRun->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedRun) {
                throw new RuntimeException('诊断记录不存在，请刷新后重试。');
            }
            if (trim((string) $lockedRun->api_task_key) === '') {
                throw new RuntimeException('当前记录不是开放 API 诊断任务。');
            }

            $status = (string) $lockedRun->status;
            if (! in_array($status, ['questions_ready', 'awaiting_confirmation'], true)) {
                throw new RuntimeException('当前记录正在处理中，请勿重复提交诊断。');
            }
            if ($lockedRun->questions->isEmpty()) {
                throw new RuntimeException('当前记录还没有可确认的 AI 问题，请先重新生成问题。');
            }

            $submittedQuestions = $lockedRun->questions
                ->mapWithKeys(static fn (BrandDiagnosisQuestion $question): array => [(int) $question->id => (string) $question->question])
                ->all();
            $normalizedQuestions = $this->confirmedQuestions($lockedRun->questions, $submittedQuestions);
            if ($normalizedQuestions->isEmpty()) {
                throw new RuntimeException('请至少保留一个 AI 问题。');
            }

            $this->updateQuestionsForRun($lockedRun, $normalizedQuestions);
            $lockedRun->update([
                'status' => 'running',
                'total_questions' => $normalizedQuestions->count(),
                'completed_questions' => 0,
                'failed_questions' => 0,
                'brand_score' => 0,
                'mention_rate' => 0,
                'average_rank' => 0,
                'mention_count' => 0,
                'sentiment_rate' => 0,
                'billing_mode' => 'open_api',
                'points_cost' => 0,
                'points_transaction_id' => null,
                'limit_bypassed' => true,
                'limit_bypass_reason' => 'open_api',
                'usage_date' => now()->toDateString(),
                'started_at' => now(),
                'completed_at' => null,
                'error_message' => null,
            ]);

            return $lockedRun->refresh();
        });

        ProcessBrandDiagnosisJob::dispatch((int) $run->id)->onQueue('geoflow');

        return $run;
    }

    /**
     * @param  list<string>  $platforms
     * @return list<string>
     */
    private function normalizePlatforms(array $platforms): array
    {
        $normalized = collect($platforms)
            ->map(static fn (mixed $platform): string => strtolower(trim((string) $platform)))
            ->filter(static fn (string $platform): bool => BrandDiagnosisPlatform::isSupported($platform))
            ->unique()
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : ['doubao'];
    }

    /**
     * @Name: latestReusableQuestionRun
     *
     * @Description: 查找同一站点和账号下可复用的品牌问题，兼容旧数据的操作人归属记录。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Param: Admin $admin 实际执行操作的管理员
     *
     * @Param: string $brandName 品牌名称
     *
     * @Param: int $siteId 当前站点 ID
     *
     * @Return: ?BrandDiagnosisRun 可复用的问题任务；没有可复用任务时返回 null
     *
     * @Throws: 无
     */
    private function latestReusableQuestionRun(Admin $admin, string $brandName, int $siteId): ?BrandDiagnosisRun
    {
        if ($brandName === '') {
            return null;
        }

        $query = BrandDiagnosisRun::query()
            ->withoutGlobalScope('current_site')
            ->with(['questions' => fn ($query) => $query->orderBy('sort_order')])
            ->where('site_id', $siteId)
            ->where('brand_name', $brandName)
            ->where('brand_profile_source', 'web_search')
            ->where('brand_profile_model', BrandDiagnosisPlatform::label(BrandDiagnosisPlatform::DOUBAO))
            ->where('brand_profile_status', 'success')
            ->whereNotNull('brand_profile')
            ->where('brand_profile', '<>', '')
            ->whereIn('status', ['questions_ready', 'awaiting_confirmation', 'running', 'completed', 'failed']);

        if (! $this->currentSite->isAccountScopeEnabled()) {
            $ownerAdminId = $this->ownerAdminIdForAdmin($admin);
            $query->where(function ($ownerQuery) use ($admin, $ownerAdminId): void {
                if ($ownerAdminId === null) {
                    $ownerQuery->whereNull('owner_admin_id');
                } else {
                    $ownerQuery->where('owner_admin_id', $ownerAdminId);
                }

                $ownerQuery->orWhere(function ($legacyQuery) use ($admin): void {
                    $legacyQuery->whereNull('owner_admin_id')
                        ->where('admin_id', (int) $admin->id);
                });
            });
        }

        return $query
            ->whereHas('questions')
            ->latest('id')
            ->first();
    }

    /**
     * @return Collection<int,array{question:string,type:string,core_term:string,sort_order:int}>
     */
    private function questionsFromRun(BrandDiagnosisRun $run): Collection
    {
        $labeler = app(BrandDiagnosisQuestionLabeler::class);

        return $run->questions
            ->sortBy('sort_order')
            ->map(static function ($question) use ($labeler): array {
                $text = mb_strimwidth(trim((string) $question->question), 0, 240, '', 'UTF-8');

                return [
                    'question' => $text,
                    'type' => $labeler->questionType($text, (string) $question->question_type),
                    'core_term' => $labeler->coreTerm($text, (string) ($question->core_term ?? '')),
                    'sort_order' => (int) $question->sort_order,
                ];
            })
            ->filter(static fn (array $question): bool => $question['question'] !== '')
            ->values();
    }

    /**
     * @param  Collection<int,BrandDiagnosisQuestion>  $existingQuestions
     * @param  array<int|string,string>  $submittedQuestions
     * @return Collection<int,array{id:int,question:string,type:string,core_term:string,sort_order:int}>
     */
    private function confirmedQuestions(Collection $existingQuestions, array $submittedQuestions): Collection
    {
        $labeler = app(BrandDiagnosisQuestionLabeler::class);

        return $existingQuestions
            ->map(function ($question) use ($submittedQuestions, $labeler): array {
                $text = trim((string) ($submittedQuestions[(int) $question->id] ?? $question->question));
                $text = mb_strimwidth($text, 0, 240, '', 'UTF-8');

                return [
                    'id' => (int) $question->id,
                    'question' => $text,
                    'type' => $labeler->questionType($text, (string) $question->question_type),
                    'core_term' => $labeler->coreTerm($text, (string) ($question->core_term ?? '')),
                    'sort_order' => (int) $question->sort_order,
                ];
            })
            ->filter(static fn (array $question): bool => $question['question'] !== '')
            ->sortBy('sort_order')
            ->values();
    }

    /**
     * @Name: updateQuestionsForRun
     *
     * @Description: 更新诊断问题文本与排序，并同步其站点及账号所有者，确保子记录跟随任务归属。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Param: BrandDiagnosisRun $run 所属诊断任务
     *
     * @Param: Collection<int,array{id:int,question:string,type:string,core_term:string,sort_order:int}> $questions 确认后的问题集合
     *
     * @Return: void
     *
     * @Throws: 无
     */
    private function updateQuestionsForRun(BrandDiagnosisRun $run, Collection $questions): void
    {
        $run->results()->delete();
        $ids = $questions->pluck('id')->map(static fn (int $id): int => $id)->all();
        $run->questions()
            ->whereNotIn('id', $ids)
            ->delete();

        foreach ($questions->values() as $index => $question) {
            $run->questions()
                ->whereKey((int) $question['id'])
                ->update([
                    'site_id' => $run->site_id !== null ? (int) $run->site_id : null,
                    'owner_admin_id' => (int) ($run->owner_admin_id ?? 0) ?: null,
                    'question' => (string) $question['question'],
                    'question_type' => (string) $question['type'],
                    'core_term' => (string) ($question['core_term'] ?? ''),
                    'sort_order' => $index + 1,
                    'status' => 'pending',
                ]);
        }
    }

    /**
     * @Name: ownerAdminIdForAdmin
     *
     * @Description: 解析诊断数据所属账号；超管显式切换后使用站点所有者，其他请求保留数据原有账号。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 11:20:14
     *
     * @UpdateTime: 2026-11-03 11:37:29
     *
     * @Param: Admin $admin 实际执行操作的管理员
     *
     * @Param: ?int $fallbackOwnerAdminId 未启用账号范围时使用的已有所有者 ID
     *
     * @Return: ?int 诊断数据所属账号 ID
     *
     * @Throws: 无
     */
    private function ownerAdminIdForAdmin(Admin $admin, ?int $fallbackOwnerAdminId = null): ?int
    {
        if ($this->currentSite->isAccountScopeEnabled()) {
            return $this->currentSite->ownerAdminId();
        }

        $ownerAdminId = (int) ($fallbackOwnerAdminId ?? $admin->id);

        return $ownerAdminId > 0 ? $ownerAdminId : null;
    }

    /**
     * @param  Collection<int,array{id?:int,question:string,type:string,core_term?:string,sort_order:int}>  $questions
     */
    private function createQuestionsForRun(BrandDiagnosisRun $run, Collection $questions): void
    {
        foreach ($questions->values() as $index => $question) {
            $run->questions()->create([
                'site_id' => $run->site_id !== null ? (int) $run->site_id : null,
                'owner_admin_id' => (int) ($run->owner_admin_id ?? 0) ?: null,
                'question' => (string) $question['question'],
                'question_type' => (string) $question['type'],
                'core_term' => (string) ($question['core_term'] ?? ''),
                'sort_order' => $index + 1,
                'status' => 'pending',
            ]);
        }
    }
}
