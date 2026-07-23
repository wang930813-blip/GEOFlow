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

class BrandDiagnosisRunService
{
    public function __construct(
        private readonly BrandDiagnosisUsagePolicy $usagePolicy,
        private readonly CurrentSite $currentSite,
    ) {}

    /**
     * @param  list<string>  $platforms
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

        if ($reuseQuestions) {
            $reusableRun = $this->latestReusableQuestionRun($admin, $brandName, $siteId);
            $reusableQuestions = $reusableRun instanceof BrandDiagnosisRun
                ? $this->questionsFromRun($reusableRun)
                : collect();
            if ($reusableRun instanceof BrandDiagnosisRun && $reusableQuestions->isNotEmpty()) {
                return DB::transaction(function () use ($admin, $brandName, $platforms, $siteId, $reusableRun, $reusableQuestions): BrandDiagnosisRun {
                    $run = BrandDiagnosisRun::query()->create([
                        'site_id' => $siteId,
                        'owner_admin_id' => (int) $admin->id,
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

        $run = DB::transaction(function () use ($admin, $brandName, $platforms, $siteId): BrandDiagnosisRun {
            return BrandDiagnosisRun::query()->create([
                'site_id' => $siteId,
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
     * @param  array<int|string,string>  $questions
     */
    public function confirm(Admin $admin, BrandDiagnosisRun $sourceRun, array $questions): BrandDiagnosisRun
    {
        $run = DB::transaction(function () use ($admin, $sourceRun, $questions): BrandDiagnosisRun {
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

            $normalizedQuestions = $this->confirmedQuestions($lockedRun->questions, $questions);
            if ($normalizedQuestions->isEmpty()) {
                throw new RuntimeException('请至少保留一个 AI 问题。');
            }

            $decision = $this->usagePolicy->reserve($admin, (int) $lockedRun->site_id);

            if (in_array($status, ['questions_ready', 'awaiting_confirmation'], true)) {
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
                'owner_admin_id' => (int) ($lockedRun->owner_admin_id ?: $admin->id),
                'admin_id' => (int) $admin->id,
                'brand_name' => (string) $lockedRun->brand_name,
                'brand_profile' => (string) ($lockedRun->brand_profile ?? ''),
                'brand_profile_source' => (string) ($lockedRun->brand_profile_source ?? ''),
                'brand_profile_model' => (string) ($lockedRun->brand_profile_model ?? ''),
                'brand_profile_status' => (string) ($lockedRun->brand_profile_status ?? ''),
                'brand_profile_meta' => is_array($lockedRun->brand_profile_meta) ? $lockedRun->brand_profile_meta : null,
                'platforms' => (array) $lockedRun->platforms,
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

    private function latestReusableQuestionRun(Admin $admin, string $brandName, int $siteId): ?BrandDiagnosisRun
    {
        if ($brandName === '') {
            return null;
        }

        return BrandDiagnosisRun::query()
            ->withoutGlobalScope('current_site')
            ->with(['questions' => fn ($query) => $query->orderBy('sort_order')])
            ->where('site_id', $siteId)
            ->where('brand_name', $brandName)
            ->where('brand_profile_source', 'web_search')
            ->where('brand_profile_model', BrandDiagnosisPlatform::label(BrandDiagnosisPlatform::DOUBAO))
            ->where('brand_profile_status', 'success')
            ->whereNotNull('brand_profile')
            ->where('brand_profile', '<>', '')
            ->whereIn('status', ['questions_ready', 'awaiting_confirmation', 'running', 'completed', 'failed'])
            ->where(function ($query) use ($admin): void {
                $query->where('owner_admin_id', (int) $admin->id)
                    ->orWhere(function ($legacyQuery) use ($admin): void {
                        $legacyQuery->whereNull('owner_admin_id')
                            ->where('admin_id', (int) $admin->id);
                    });
            })
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
     * @param  Collection<int,array{id:int,question:string,type:string,core_term:string,sort_order:int}>  $questions
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
                    'question' => (string) $question['question'],
                    'question_type' => (string) $question['type'],
                    'core_term' => (string) ($question['core_term'] ?? ''),
                    'sort_order' => $index + 1,
                    'status' => 'pending',
                ]);
        }
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
