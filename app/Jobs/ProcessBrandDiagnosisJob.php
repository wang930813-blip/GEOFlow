<?php

namespace App\Jobs;

use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandDiagnosisMetricsCalculator;
use App\Services\BrandDiagnosis\DoubaoBrandDiagnosisClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessBrandDiagnosisJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $runId) {}

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'brand-diagnosis',
            'brand-diagnosis-run:'.$this->runId,
        ];
    }

    public function handle(): void
    {
        $run = BrandDiagnosisRun::query()
            ->withoutGlobalScope('current_site')
            ->with(['questions' => fn ($query) => $query->orderBy('sort_order')])
            ->whereKey($this->runId)
            ->first();

        if (! $run) {
            return;
        }

        $run->update([
            'status' => 'running',
            'started_at' => $run->started_at ?? now(),
            'completed_at' => null,
            'error_message' => null,
        ]);

        if ($run->questions->isEmpty()) {
            try {
                $questions = app(DoubaoBrandDiagnosisClient::class)->generateQuestions(
                    (string) $run->brand_name,
                    max(1, (int) config('brand_diagnosis.question_count', 5))
                );

                foreach ($questions as $index => $question) {
                    $run->questions()->create([
                        'site_id' => (int) $run->site_id,
                        'question' => (string) $question['question'],
                        'question_type' => (string) $question['type'],
                        'sort_order' => $index + 1,
                        'status' => 'pending',
                    ]);
                }

                $run->update(['total_questions' => count($questions)]);
                $run->load(['questions' => fn ($query) => $query->orderBy('sort_order')]);
            } catch (Throwable $exception) {
                $run->update([
                    'status' => 'failed',
                    'total_questions' => 0,
                    'completed_questions' => 0,
                    'failed_questions' => 0,
                    'error_message' => $exception->getMessage(),
                    'completed_at' => now(),
                ]);

                return;
            }
        }

        foreach ($run->questions as $question) {
            foreach ((array) $run->platforms as $platform) {
                if ((string) $platform !== 'doubao') {
                    continue;
                }

                try {
                    $clientResponse = app(DoubaoBrandDiagnosisClient::class)->ask(
                        (string) $run->brand_name,
                        (string) $question->question
                    );

                    $metrics = app(BrandDiagnosisMetricsCalculator::class);
                    $mentionCount = $metrics->mentionCount($clientResponse->answer, (string) $run->brand_name);
                    $result = $question->results()->updateOrCreate(
                        ['platform' => 'doubao'],
                        [
                            'site_id' => (int) $run->site_id,
                            'run_id' => (int) $run->id,
                            'answer' => $clientResponse->answer,
                            'brand_mentioned' => $mentionCount > 0,
                            'mention_count' => $mentionCount,
                            'mention_rank' => $metrics->mentionRank($clientResponse->answer, (string) $run->brand_name),
                            'sentiment' => $metrics->classifySentiment($clientResponse->answer),
                            'status' => 'success',
                            'error_message' => null,
                            'raw_response' => $clientResponse->rawResponse,
                            'meta' => $clientResponse->meta,
                            'checked_at' => now(),
                        ]
                    );

                    $result->sources()->delete();
                    foreach ($clientResponse->sources as $source) {
                        $result->sources()->create([
                            'site_id' => (int) $run->site_id,
                            'run_id' => (int) $run->id,
                            'question_id' => (int) $question->id,
                            'platform' => 'doubao',
                            'title' => (string) $source['title'],
                            'url' => (string) $source['url'],
                            'domain' => $this->domainFromUrl((string) $source['url']),
                            'source_type' => (string) $source['type'],
                            'meta' => (array) ($source['meta'] ?? []),
                        ]);
                    }

                    $question->update(['status' => 'completed']);
                } catch (Throwable $exception) {
                    $question->results()->updateOrCreate(
                        ['platform' => 'doubao'],
                        [
                            'site_id' => (int) $run->site_id,
                            'run_id' => (int) $run->id,
                            'answer' => null,
                            'brand_mentioned' => false,
                            'mention_count' => 0,
                            'mention_rank' => 0,
                            'sentiment' => 'neutral',
                            'status' => 'failed',
                            'error_message' => $exception->getMessage(),
                            'raw_response' => null,
                            'meta' => ['client' => 'doubao'],
                            'checked_at' => now(),
                        ]
                    );
                    $question->update(['status' => 'failed']);
                }
            }
        }

        $run->refresh();
        app(BrandDiagnosisMetricsCalculator::class)->refreshRun($run);
        $run->refresh();

        $status = (int) $run->failed_questions >= (int) $run->total_questions ? 'failed' : 'completed';
        $run->update([
            'status' => $status,
            'completed_at' => now(),
        ]);
    }

    private function domainFromUrl(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: '');
    }
}
