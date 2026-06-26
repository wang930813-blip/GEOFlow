<?php

namespace App\Jobs;

use App\Events\Admin\KeywordLibraryInclusionUpdated;
use App\Models\GeoInclusionCheckResult;
use App\Models\GeoInclusionCheckRun;
use App\Models\Keyword;
use App\Models\KeywordQuestionVariant;
use App\Services\GeoFlow\AiSearchPlatformChecker;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessGeoInclusionCheckJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(
        public readonly int $runId,
        public readonly int $keywordId,
        public readonly int $questionVariantId,
        public readonly string $platform,
    ) {}

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'geo-inclusion',
            'geo-inclusion-run:'.$this->runId,
            'keyword:'.$this->keywordId,
            'platform:'.$this->platform,
        ];
    }

    /**
     * @return array<int,int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(AiSearchPlatformChecker $checker): void
    {
        $run = GeoInclusionCheckRun::query()->whereKey($this->runId)->first();
        if (! $run || (string) $run->status === 'paused') {
            return;
        }

        $questionVariant = KeywordQuestionVariant::query()->whereKey($this->questionVariantId)->firstOrFail();
        $keyword = Keyword::query()->with('library')->whereKey($this->keywordId)->firstOrFail();
        $library = $keyword->library()->firstOrFail();
        $question = (string) $questionVariant->question;

        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
        ]);

        try {
            $response = $checker->check($this->platform, $question, $library, $keyword);

            GeoInclusionCheckResult::query()->updateOrCreate(
                [
                    'run_id' => (int) $run->id,
                    'question_variant_id' => (int) $questionVariant->id,
                    'platform' => $this->platform,
                ],
                [
                    'site_id' => (int) ($run->site_id ?? $library->site_id ?? 0) ?: null,
                    'owner_admin_id' => (int) ($run->owner_admin_id ?? $library->owner_admin_id ?? 0) ?: null,
                    'keyword_library_id' => (int) $library->id,
                    'keyword_id' => (int) $keyword->id,
                    'question' => $question,
                    'answer' => $response->answer,
                    'keyword_hit' => $response->keywordHit,
                    'brand_hit' => $response->brandHit,
                    'status' => $response->status,
                    'error_message' => $response->errorMessage,
                    'meta' => $response->meta,
                    'checked_at' => now(),
                ]
            );

            $this->markProgress((int) $run->id, failed: false);
        } catch (Throwable $exception) {
            if ($this->shouldRetryTransientAiFailure($exception)) {
                $this->release($this->retryDelaySeconds());

                return;
            }

            GeoInclusionCheckResult::query()->updateOrCreate(
                [
                    'run_id' => (int) $run->id,
                    'question_variant_id' => (int) $questionVariant->id,
                    'platform' => $this->platform,
                ],
                [
                    'site_id' => (int) ($run->site_id ?? $library->site_id ?? 0) ?: null,
                    'owner_admin_id' => (int) ($run->owner_admin_id ?? $library->owner_admin_id ?? 0) ?: null,
                    'keyword_library_id' => (int) $library->id,
                    'keyword_id' => (int) $keyword->id,
                    'question' => $question,
                    'answer' => null,
                    'keyword_hit' => false,
                    'brand_hit' => false,
                    'status' => 'failed',
                    'error_message' => $exception->getMessage(),
                    'meta' => ['checker' => 'job'],
                    'checked_at' => now(),
                ]
            );

            $this->markProgress((int) $run->id, failed: true);
        }
    }

    private function shouldRetryTransientAiFailure(Throwable $exception): bool
    {
        if ($this->attempts() >= $this->tries) {
            return false;
        }

        return $this->isTransientAiFailure($exception);
    }

    private function retryDelaySeconds(): int
    {
        $delays = $this->backoff();
        $attemptIndex = max(0, $this->attempts() - 1);

        return (int) ($delays[$attemptIndex] ?? end($delays) ?: 30);
    }

    private function isTransientAiFailure(Throwable $exception): bool
    {
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            $message = mb_strtolower($current->getMessage(), 'UTF-8');

            if (
                str_contains($message, 'is overloaded')
                || str_contains($message, 'provider overloaded')
                || str_contains($message, 'rate limited')
                || str_contains($message, 'rate limit')
                || str_contains($message, 'too many requests')
                || str_contains($message, '429')
                || str_contains($message, '503')
            ) {
                return true;
            }
        }

        return false;
    }

    private function markProgress(int $runId, bool $failed): void
    {
        $run = GeoInclusionCheckRun::query()->whereKey($runId)->first();
        if (! $run) {
            return;
        }
        if ((string) $run->status === 'paused') {
            return;
        }

        $completedChecks = (int) $run->completed_checks + 1;
        $failedChecks = (int) $run->failed_checks + ($failed ? 1 : 0);
        $status = $completedChecks >= (int) $run->total_checks ? 'completed' : 'running';

        $run->update([
            'completed_checks' => $completedChecks,
            'failed_checks' => $failedChecks,
            'status' => $status,
            'completed_at' => $status === 'completed' ? now() : null,
        ]);

        try {
            event(new KeywordLibraryInclusionUpdated(
                libraryId: (int) $run->keyword_library_id,
                runId: (int) $run->id,
                status: $status,
                completedChecks: $completedChecks,
                totalChecks: (int) $run->total_checks,
                failedChecks: $failedChecks,
            ));
        } catch (Throwable) {
            // Realtime updates are best-effort; never fail the check job because WebSocket is unavailable.
        }
    }
}
