<?php

namespace App\Jobs;

use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use App\Services\BrandDiagnosis\BrandProfileResolver;
use App\Services\BrandDiagnosis\DoubaoBrandDiagnosisClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateBrandDiagnosisQuestionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $runId)
    {
        $this->timeout = max(180, min(600, (int) config('brand_diagnosis.job_timeout', 1200)));
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'brand-diagnosis',
            'brand-diagnosis-questions',
            'brand-diagnosis-run:'.$this->runId,
        ];
    }

    public function handle(): void
    {
        $run = BrandDiagnosisRun::query()
            ->withoutGlobalScope('current_site')
            ->whereKey($this->runId)
            ->first();

        if (! $run) {
            return;
        }

        $storedProfileMeta = (array) $run->brand_profile_meta;
        $diagnosisOptions = (array) ($storedProfileMeta['diagnosis_options'] ?? []);
        $questionCount = min(30, max(1, (int) ($diagnosisOptions['question_count'] ?? config('brand_diagnosis.question_count', 10))));
        $brandProfileMeta = [];
        if ($diagnosisOptions !== []) {
            $brandProfileMeta['diagnosis_options'] = $diagnosisOptions;
        }

        $run->update([
            'status' => 'questions_generating',
            'brand_profile' => null,
            'brand_profile_source' => '',
            'brand_profile_model' => '',
            'brand_profile_status' => '',
            'brand_profile_meta' => $brandProfileMeta !== [] ? $brandProfileMeta : null,
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        try {
            $brandProfile = app(BrandProfileResolver::class)->resolve($run, (array) $run->platforms);
            if ((string) ($brandProfile['status'] ?? '') !== 'success') {
                $reason = trim((string) data_get($brandProfile, 'meta.reason', ''));
                throw new \RuntimeException($reason !== ''
                    ? '品牌资料检索失败：'.$reason
                    : '未检索到可用品牌介绍，请更换更明确的品牌词后重试。');
            }
            $diagnosisClient = app(DoubaoBrandDiagnosisClient::class);
            $brandProfileMeta = array_merge($brandProfileMeta, (array) ($brandProfile['meta'] ?? []));
            $planningPlatform = BrandDiagnosisPlatform::normalize(
                (string) ($brandProfileMeta['platform'] ?? ((array) $run->platforms)[0] ?? BrandDiagnosisPlatform::DOUBAO)
            );
            $coreTerms = $diagnosisClient->extractBrandCoreTerms(
                (string) $run->brand_name,
                (string) $brandProfile['profile'],
                5,
                $planningPlatform
            );
            $brandProfileMeta['diagnosis_options'] = array_merge(
                (array) ($brandProfileMeta['diagnosis_options'] ?? []),
                ['question_count' => $questionCount]
            );
            $brandProfileMeta['core_terms'] = $coreTerms;
            $brandProfileMeta['core_terms_model'] = $planningPlatform;

            $run->update([
                'brand_profile' => $brandProfile['profile'],
                'brand_profile_source' => $brandProfile['source'],
                'brand_profile_model' => $brandProfile['model'],
                'brand_profile_status' => $brandProfile['status'],
                'brand_profile_meta' => $brandProfileMeta,
            ]);

            $questions = $diagnosisClient->generateQuestionsFromCoreTerms(
                (string) $run->brand_name,
                $questionCount,
                (string) $brandProfile['profile'],
                $coreTerms,
                $planningPlatform
            );

            $run->questions()->delete();
            foreach ($questions as $index => $question) {
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

            $run->update([
                'status' => 'questions_ready',
                'total_questions' => count($questions),
                'completed_questions' => 0,
                'failed_questions' => 0,
                'brand_score' => 0,
                'mention_rate' => 0,
                'average_rank' => 0,
                'mention_count' => 0,
                'sentiment_rate' => 0,
                'error_message' => null,
            ]);

            if (trim((string) $run->api_task_key) !== '') {
                AutoConfirmBrandDiagnosisRunJob::dispatch((int) $run->id)->onQueue('geoflow');
            }
        } catch (Throwable $exception) {
            $this->markFailed($run, $exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = BrandDiagnosisRun::query()
            ->withoutGlobalScope('current_site')
            ->whereKey($this->runId)
            ->first();

        if (! $run) {
            return;
        }

        $this->markFailed($run, $exception);
    }

    private function markFailed(BrandDiagnosisRun $run, ?Throwable $exception): void
    {
        $message = $exception?->getMessage();
        if (! is_string($message) || trim($message) === '') {
            $message = $exception ? class_basename($exception) : '品牌诊断问题生成失败';
        }

        $run->update([
            'status' => 'failed',
            'error_message' => mb_strimwidth($message, 0, 1000, '...', 'UTF-8'),
            'completed_at' => now(),
        ]);
    }
}
