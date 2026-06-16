<?php

namespace App\Jobs;

use App\Models\BrandDiagnosisRun;
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

        $run->update([
            'status' => 'questions_generating',
            'error_message' => null,
            'started_at' => null,
            'completed_at' => null,
        ]);

        try {
            $questions = app(DoubaoBrandDiagnosisClient::class)->generateQuestionPool(
                (string) $run->brand_name,
                max(1, (int) config('brand_diagnosis.question_count', 5)),
                (array) $run->platforms
            );

            $run->questions()->delete();
            foreach ($questions as $index => $question) {
                $run->questions()->create([
                    'site_id' => (int) $run->site_id,
                    'owner_admin_id' => (int) ($run->owner_admin_id ?? 0) ?: null,
                    'question' => (string) $question['question'],
                    'question_type' => (string) $question['type'],
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
