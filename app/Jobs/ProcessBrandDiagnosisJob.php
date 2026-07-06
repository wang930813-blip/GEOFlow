<?php

namespace App\Jobs;

use App\Models\BrandDiagnosisRun;
use App\Models\BrandDiagnosisResult;
use App\Services\BrandDiagnosis\BrandDiagnosisMetricsCalculator;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use App\Services\BrandDiagnosis\DoubaoBrandDiagnosisClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessBrandDiagnosisJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public function __construct(public readonly int $runId)
    {
        $this->timeout = max(600, (int) config('brand_diagnosis.job_timeout', 1200));
    }

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
            $run->update([
                'status' => 'failed',
                'total_questions' => 0,
                'completed_questions' => 0,
                'failed_questions' => 0,
                'error_message' => '请先生成并确认 AI 问题后再开始诊断。',
                'completed_at' => now(),
            ]);

            return;
        }

        foreach ($run->questions as $question) {
            foreach ((array) $run->platforms as $platform) {
                $platform = strtolower(trim((string) $platform));
                if (! BrandDiagnosisPlatform::isSupported($platform)) {
                    continue;
                }

                try {
                    $clientResponse = app(DoubaoBrandDiagnosisClient::class)->ask(
                        (string) $run->brand_name,
                        (string) $question->question,
                        $platform
                    );

                    $metrics = app(BrandDiagnosisMetricsCalculator::class);
                    $brandMentions = $metrics->normalizeBrandMentions(
                        $clientResponse->brandMentions,
                        $clientResponse->answer,
                        (string) $run->brand_name,
                        $this->sourceEvidenceText($clientResponse->sources),
                        (string) $question->question
                    );
                    $targetMention = collect($brandMentions)
                        ->first(fn (array $mention): bool => $metrics->isSameBrand((string) $mention['brand'], (string) $run->brand_name));
                    $mentionCount = is_array($targetMention)
                        ? (int) $targetMention['mention_count']
                        : 0;
                    $mentionRank = is_array($targetMention)
                        ? (int) $targetMention['mention_rank']
                        : 0;
                    $sentiment = is_array($targetMention)
                        ? (string) $targetMention['sentiment']
                        : $metrics->classifySentiment($clientResponse->answer);
                    $result = $question->results()->updateOrCreate(
                        ['platform' => $platform],
                        [
                            'site_id' => (int) $run->site_id,
                            'owner_admin_id' => (int) ($run->owner_admin_id ?? 0) ?: null,
                            'run_id' => (int) $run->id,
                            'answer' => $clientResponse->answer,
                            'brand_mentioned' => $mentionCount > 0,
                            'mention_count' => $mentionCount,
                            'mention_rank' => $mentionRank,
                            'sentiment' => $sentiment,
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
                            'owner_admin_id' => (int) ($run->owner_admin_id ?? 0) ?: null,
                            'run_id' => (int) $run->id,
                            'question_id' => (int) $question->id,
                            'result_id' => (int) $result->id,
                            'platform' => $platform,
                            'title' => (string) $source['title'],
                            'url' => (string) $source['url'],
                            'domain' => $this->domainFromUrl((string) $source['url']),
                            'source_type' => (string) $source['type'],
                            'meta' => (array) ($source['meta'] ?? []),
                        ]);
                    }
                    $this->replaceBrandMentions($result, $brandMentions, (string) $run->brand_name, count($clientResponse->sources));

                    $question->update(['status' => 'completed']);
                } catch (Throwable $exception) {
                    $question->results()->updateOrCreate(
                        ['platform' => $platform],
                        [
                            'site_id' => (int) $run->site_id,
                            'owner_admin_id' => (int) ($run->owner_admin_id ?? 0) ?: null,
                            'run_id' => (int) $run->id,
                            'answer' => null,
                            'brand_mentioned' => false,
                            'mention_count' => 0,
                            'mention_rank' => 0,
                            'sentiment' => 'neutral',
                            'status' => 'failed',
                            'error_message' => $exception->getMessage(),
                            'raw_response' => null,
                            'meta' => ['client' => $platform],
                            'checked_at' => now(),
                        ]
                    );
                    if ($question->results()->where('status', 'success')->doesntExist()) {
                        $question->update(['status' => 'failed']);
                    }
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

    public function failed(?Throwable $exception): void
    {
        $run = BrandDiagnosisRun::query()
            ->withoutGlobalScope('current_site')
            ->whereKey($this->runId)
            ->first();

        if (! $run) {
            return;
        }

        $message = $exception?->getMessage();
        if (! is_string($message) || trim($message) === '') {
            $message = $exception ? class_basename($exception) : '品牌诊断任务执行失败';
        }

        $run->update([
            'status' => 'failed',
            'error_message' => mb_strimwidth($message, 0, 1000, '...', 'UTF-8'),
            'completed_at' => now(),
        ]);
    }

    private function domainFromUrl(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_HOST) ?: '');
    }

    /**
     * @param  list<array{title:string,url:string,type:string,meta?:array<string,mixed>}>  $sources
     */
    private function sourceEvidenceText(array $sources): string
    {
        return collect($sources)
            ->map(static function (array $source): string {
                $text = collect([
                    (string) ($source['title'] ?? ''),
                    (string) data_get($source, 'meta.summary', ''),
                    (string) data_get($source, 'meta.snippet', ''),
                    (string) data_get($source, 'meta.content', ''),
                ])
                    ->filter(static fn (string $value): bool => trim($value) !== '')
                    ->implode(' ');

                return trim((string) preg_replace('/\s+/u', ' ', $text));
            })
            ->filter(static fn (string $value): bool => trim($value) !== '')
            ->implode(BrandDiagnosisMetricsCalculator::SOURCE_UNIT_SEPARATOR);
    }

    /**
     * @param  list<array{brand:string,mention_count:int,mention_rank:int,sentiment:string,evidence:string,source_count?:int,meta?:array<string,mixed>}>  $brandMentions
     */
    private function replaceBrandMentions(BrandDiagnosisResult $result, array $brandMentions, string $targetBrandName, int $sourceCount): void
    {
        $result->brandMentions()->delete();

        foreach ($brandMentions as $mention) {
            $result->brandMentions()->create([
                'site_id' => (int) $result->site_id,
                'owner_admin_id' => (int) ($result->owner_admin_id ?? 0) ?: null,
                'run_id' => (int) $result->run_id,
                'question_id' => (int) $result->question_id,
                'platform' => (string) $result->platform,
                'brand_name' => (string) $mention['brand'],
                'mention_count' => max(1, (int) $mention['mention_count']),
                'mention_rank' => max(0, (int) $mention['mention_rank']),
                'sentiment' => (string) $mention['sentiment'],
                'source_count' => max((int) ($mention['source_count'] ?? 0), $sourceCount),
                'is_target_brand' => app(BrandDiagnosisMetricsCalculator::class)->isSameBrand((string) $mention['brand'], $targetBrandName),
                'evidence' => (string) ($mention['evidence'] ?? ''),
                'meta' => (array) ($mention['meta'] ?? []),
            ]);
        }
    }
}
