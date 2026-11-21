<?php

namespace App\Jobs;

use App\Models\BrandDiagnosisQuestion;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandDiagnosisMetricsCalculator;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use App\Services\BrandDiagnosis\BrandDiagnosisSnapshotPayload;
use App\Services\BrandDiagnosis\DoubaoBrandDiagnosisClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessBrandDiagnosisJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1200;

    public int $uniqueFor = 7200;

    public function __construct(public readonly int $runId, public readonly ?int $questionId = null)
    {
        $this->timeout = max(600, (int) config('brand_diagnosis.job_timeout', 1200));
    }

    public function uniqueId(): string
    {
        return 'brand-diagnosis:'.$this->runId.':'.($this->questionId ?? 'dispatch');
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
            ->whereKey($this->runId)
            ->first();

        if (! $run) {
            return;
        }

        $platforms = collect((array) $run->platforms)
            ->map(static fn (string $platform): string => strtolower(trim($platform)))
            ->filter(static fn (string $platform): bool => BrandDiagnosisPlatform::isSupported($platform))
            ->unique()
            ->values()
            ->all();
        $questionsQuery = $run->questions()->orderBy('sort_order');
        if ($this->questionId !== null) {
            $questionsQuery->whereKey($this->questionId);
        }
        $questions = $questionsQuery->get();

        if ($this->questionId === null) {
            $run->update([
                'status' => 'running',
                'started_at' => $run->started_at ?? now(),
                'completed_at' => null,
                'error_message' => null,
                'total_questions' => $questions->count(),
                'completed_questions' => 0,
                'failed_questions' => 0,
            ]);

            if ($questions->isEmpty() || $platforms === []) {
                $run->update([
                    'status' => 'failed',
                    'error_message' => $questions->isEmpty()
                        ? '请先生成并确认 AI 问题后再开始诊断。'
                        : '本次诊断没有选择可用模型。',
                    'completed_at' => now(),
                ]);

                return;
            }

            foreach ($questions as $question) {
                self::dispatch((int) $run->id, (int) $question->id)->onQueue('geoflow');
            }

            return;
        }

        if ($questions->isEmpty() || $platforms === []) {
            $this->completeRunIfSettled($run, $platforms);

            return;
        }

        $question = $questions->first();
        $question->load(['results' => fn ($query) => $query->whereIn('platform', $platforms)]);

        foreach ($platforms as $platform) {
            $existingResult = $question->results->firstWhere('platform', $platform);
            if ($existingResult instanceof BrandDiagnosisResult && (string) $existingResult->status === 'success') {
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
                $mentionCount = is_array($targetMention) ? (int) $targetMention['mention_count'] : 0;
                $mentionRank = is_array($targetMention) ? (int) $targetMention['mention_rank'] : 0;
                $sentiment = is_array($targetMention)
                    ? (string) $targetMention['sentiment']
                    : $metrics->classifySentiment($clientResponse->answer);
                $result = $question->results()->updateOrCreate(
                    ['platform' => $platform],
                    [
                        'site_id' => $this->siteId($run->site_id),
                        'owner_admin_id' => (int) ($run->owner_admin_id ?? 0) ?: null,
                        'run_id' => (int) $run->id,
                        'answer' => $this->cleanExternalText($clientResponse->answer),
                        'brand_mentioned' => $mentionCount > 0,
                        'mention_count' => $mentionCount,
                        'mention_rank' => $mentionRank,
                        'sentiment' => $sentiment,
                        'status' => 'success',
                        'error_message' => null,
                        'raw_response' => $this->cleanExternalValue($clientResponse->rawResponse),
                        'meta' => $this->cleanExternalValue($clientResponse->meta),
                        'checked_at' => now(),
                    ]
                );

                $result->sources()->delete();
                foreach ($clientResponse->sources as $source) {
                    $result->sources()->create([
                        'site_id' => $this->siteId($run->site_id),
                        'owner_admin_id' => (int) ($run->owner_admin_id ?? 0) ?: null,
                        'run_id' => (int) $run->id,
                        'question_id' => (int) $question->id,
                        'result_id' => (int) $result->id,
                        'platform' => $platform,
                        'title' => $this->cleanExternalText((string) $source['title']),
                        'url' => $this->cleanExternalText((string) $source['url']),
                        'domain' => $this->domainFromUrl($this->cleanExternalText((string) $source['url'])),
                        'source_type' => $this->cleanExternalText((string) $source['type']),
                        'meta' => $this->cleanExternalValue((array) ($source['meta'] ?? [])),
                    ]);
                }
                $this->replaceBrandMentions($result, $brandMentions, (string) $run->brand_name, count($clientResponse->sources));
                app(BrandDiagnosisSnapshotPayload::class)->captureIfMissing($result);
            } catch (Throwable $exception) {
                $this->recordPlatformFailure($run, $question, $platform, $exception->getMessage());
            }
        }

        $successfulPlatforms = $question->results()
            ->whereIn('platform', $platforms)
            ->where('status', 'success')
            ->exists();
        $question->update(['status' => $successfulPlatforms ? 'completed' : 'failed']);

        $run->refresh();
        app(BrandDiagnosisMetricsCalculator::class)->refreshRun($run);
        $run->refresh();
        $this->completeRunIfSettled($run, $platforms);
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

        $message = $this->cleanExternalText((string) $exception?->getMessage());
        if (! is_string($message) || trim($message) === '') {
            $message = $exception ? class_basename($exception) : '品牌诊断任务执行失败';
        }
        $message = mb_strimwidth($message, 0, 1000, '...', 'UTF-8');

        if ($this->questionId === null) {
            $run->update([
                'status' => 'failed',
                'error_message' => $message,
                'completed_at' => now(),
            ]);

            return;
        }

        $question = $run->questions()->whereKey($this->questionId)->first();
        if (! $question) {
            return;
        }

        foreach ($platforms = collect((array) $run->platforms)
            ->map(static fn (string $platform): string => strtolower(trim($platform)))
            ->filter(static fn (string $platform): bool => BrandDiagnosisPlatform::isSupported($platform))
            ->unique()
            ->values()
            ->all() as $platform) {
            $this->recordPlatformFailure($run, $question, $platform, $message);
        }

        $question->update([
            'status' => $question->results()->whereIn('platform', $platforms)->where('status', 'success')->exists()
                ? 'completed'
                : 'failed',
        ]);

        $run->refresh();
        app(BrandDiagnosisMetricsCalculator::class)->refreshRun($run);
        $run->refresh();
        $this->completeRunIfSettled($run, $platforms);
    }

    private function recordPlatformFailure(BrandDiagnosisRun $run, BrandDiagnosisQuestion $question, string $platform, string $message): void
    {
        $existingResult = $question->results()->where('platform', $platform)->first();
        if ($existingResult instanceof BrandDiagnosisResult && (string) $existingResult->status === 'success') {
            return;
        }

        $question->results()->updateOrCreate(
            ['platform' => $platform],
            [
                'site_id' => $this->siteId($run->site_id),
                'owner_admin_id' => (int) ($run->owner_admin_id ?? 0) ?: null,
                'run_id' => (int) $run->id,
                'answer' => null,
                'brand_mentioned' => false,
                'mention_count' => 0,
                'mention_rank' => 0,
                'sentiment' => 'neutral',
                'status' => 'failed',
                'error_message' => $this->cleanExternalText($message),
                'raw_response' => null,
                'meta' => ['client' => $platform],
                'checked_at' => now(),
            ]
        );
    }

    /**
     * @param  list<string>  $platforms
     */
    private function completeRunIfSettled(BrandDiagnosisRun $run, array $platforms): void
    {
        $expectedResults = (int) $run->questions()->count() * count($platforms);
        if ($expectedResults <= 0) {
            return;
        }

        $settledResults = $run->results()
            ->whereIn('platform', $platforms)
            ->whereIn('status', ['success', 'failed'])
            ->count();
        if ($settledResults < $expectedResults) {
            return;
        }

        $run->refresh();
        app(BrandDiagnosisMetricsCalculator::class)->refreshRun($run);
        $run->refresh();
        $run->update([
            'status' => (int) $run->failed_questions >= (int) $run->total_questions ? 'failed' : 'completed',
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

    private function cleanExternalText(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (! mb_check_encoding($value, 'UTF-8')) {
            $cleaned = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
            $value = $cleaned !== false ? $cleaned : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        $withoutControls = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        return trim($withoutControls ?? $value);
    }

    private function cleanExternalValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->cleanExternalText($value);
        }

        if (! is_array($value)) {
            return $value;
        }

        $cleaned = [];
        foreach ($value as $key => $item) {
            $cleanKey = is_string($key) ? $this->cleanExternalText($key) : $key;
            $cleaned[$cleanKey] = $this->cleanExternalValue($item);
        }

        return $cleaned;
    }

    /**
     * @param  list<array{brand:string,mention_count:int,mention_rank:int,sentiment:string,evidence:string,source_count?:int,meta?:array<string,mixed>}>  $brandMentions
     */
    private function replaceBrandMentions(BrandDiagnosisResult $result, array $brandMentions, string $targetBrandName, int $sourceCount): void
    {
        $result->brandMentions()->delete();

        foreach ($brandMentions as $mention) {
            $result->brandMentions()->create([
                'site_id' => $this->siteId($result->site_id),
                'owner_admin_id' => (int) ($result->owner_admin_id ?? 0) ?: null,
                'run_id' => (int) $result->run_id,
                'question_id' => (int) $result->question_id,
                'platform' => (string) $result->platform,
                'brand_name' => $this->cleanExternalText((string) $mention['brand']),
                'mention_count' => max(1, (int) $mention['mention_count']),
                'mention_rank' => max(0, (int) $mention['mention_rank']),
                'sentiment' => $this->cleanExternalText((string) $mention['sentiment']),
                'source_count' => max((int) ($mention['source_count'] ?? 0), $sourceCount),
                'is_target_brand' => app(BrandDiagnosisMetricsCalculator::class)->isSameBrand((string) $mention['brand'], $targetBrandName),
                'evidence' => $this->cleanExternalText((string) ($mention['evidence'] ?? '')),
                'meta' => $this->cleanExternalValue((array) ($mention['meta'] ?? [])),
            ]);
        }
    }

    private function siteId(mixed $siteId): ?int
    {
        return $siteId !== null ? (int) $siteId : null;
    }
}
