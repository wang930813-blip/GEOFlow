<?php

namespace App\Services\BrandDiagnosis;

use App\Jobs\GenerateBrandDiagnosisQuestionsJob;
use App\Jobs\ProcessBrandDiagnosisJob;
use App\Models\Admin;
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
    public function create(Admin $admin, string $brandName, array $platforms): BrandDiagnosisRun
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

    /**
     * @param  list<string>  $platforms
     * @return list<string>
     */
    private function normalizePlatforms(array $platforms): array
    {
        $allowed = ['doubao', 'deepseek'];
        $normalized = collect($platforms)
            ->map(static fn (mixed $platform): string => strtolower(trim((string) $platform)))
            ->filter(static fn (string $platform): bool => in_array($platform, $allowed, true))
            ->unique()
            ->values()
            ->all();

        return $normalized !== [] ? $normalized : ['doubao'];
    }

    /**
     * @param  Collection<int,\App\Models\BrandDiagnosisQuestion>  $existingQuestions
     * @param  array<int|string,string>  $submittedQuestions
     * @return Collection<int,array{id:int,question:string,type:string,sort_order:int}>
     */
    private function confirmedQuestions(Collection $existingQuestions, array $submittedQuestions): Collection
    {
        return $existingQuestions
            ->map(function ($question) use ($submittedQuestions): array {
                $text = trim((string) ($submittedQuestions[(int) $question->id] ?? $question->question));

                return [
                    'id' => (int) $question->id,
                    'question' => mb_strimwidth($text, 0, 240, '', 'UTF-8'),
                    'type' => (string) $question->question_type,
                    'sort_order' => (int) $question->sort_order,
                ];
            })
            ->filter(static fn (array $question): bool => $question['question'] !== '')
            ->sortBy('sort_order')
            ->values();
    }

    /**
     * @param  Collection<int,array{id:int,question:string,type:string,sort_order:int}>  $questions
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
                    'sort_order' => $index + 1,
                    'status' => 'pending',
                ]);
        }
    }

    /**
     * @param  Collection<int,array{id?:int,question:string,type:string,sort_order:int}>  $questions
     */
    private function createQuestionsForRun(BrandDiagnosisRun $run, Collection $questions): void
    {
        foreach ($questions->values() as $index => $question) {
            $run->questions()->create([
                'site_id' => (int) $run->site_id,
                'owner_admin_id' => (int) ($run->owner_admin_id ?? 0) ?: null,
                'question' => (string) $question['question'],
                'question_type' => (string) $question['type'],
                'sort_order' => $index + 1,
                'status' => 'pending',
            ]);
        }
    }
}
