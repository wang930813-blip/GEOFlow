<?php

namespace App\Services\BrandDiagnosis;

use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use Illuminate\Support\Collection;
use Throwable;

class BrandDiagnosisMentionBackfillService
{
    /**
     * @return array{processed:int,updated:int,skipped:int,failed:int,errors:list<string>}
     */
    public function backfillRun(BrandDiagnosisRun $run, bool $onlyMissing = true, ?string $platform = null): array
    {
        $results = $run->results()
            ->where('status', 'success')
            ->when($platform !== null, fn ($query) => $query->where('platform', $platform))
            ->with(['question', 'sources', 'brandMentions'])
            ->orderBy('id')
            ->get();

        $stats = [
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($results as $result) {
            $stats['processed']++;

            if ($onlyMissing && $result->brandMentions->isNotEmpty()) {
                $stats['skipped']++;

                continue;
            }

            try {
                $updated = $this->backfillResult($result, $run);
                $updated ? $stats['updated']++ : $stats['skipped']++;
            } catch (Throwable $exception) {
                $stats['failed']++;
                $stats['errors'][] = sprintf(
                    'result #%d %s: %s',
                    (int) $result->id,
                    (string) $result->platform,
                    $exception->getMessage()
                );
            }
        }

        app(BrandDiagnosisMetricsCalculator::class)->refreshRun($run->refresh());

        return $stats;
    }

    public function backfillResult(BrandDiagnosisResult $result, BrandDiagnosisRun $run): bool
    {
        $answer = trim((string) $result->answer);
        if ($answer === '') {
            return false;
        }

        $client = app(DoubaoBrandDiagnosisClient::class);
        $rawMentions = $client->extractBrandMentionsFromRawResponse((array) ($result->raw_response ?? []));
        if ($rawMentions === []) {
            $rawMentions = $client->extractBrandMentionsFromEvidence(
                $answer,
                $this->sourcesPayload($result->sources),
                (string) $result->platform
            );
        }

        $metrics = app(BrandDiagnosisMetricsCalculator::class);
        $brandMentions = $metrics->normalizeBrandMentions(
            $rawMentions,
            $answer,
            (string) $run->brand_name,
            $this->sourceEvidenceText($this->sourcesPayload($result->sources)),
            (string) ($result->question?->question ?? '')
        );

        if ($brandMentions === []) {
            return false;
        }

        $targetMention = collect($brandMentions)
            ->first(fn (array $mention): bool => $metrics->isSameBrand((string) $mention['brand'], (string) $run->brand_name));

        $result->update([
            'brand_mentioned' => is_array($targetMention),
            'mention_count' => is_array($targetMention) ? (int) $targetMention['mention_count'] : 0,
            'mention_rank' => is_array($targetMention) ? (int) $targetMention['mention_rank'] : 0,
            'sentiment' => is_array($targetMention) ? (string) $targetMention['sentiment'] : $metrics->classifySentiment($answer),
            'meta' => array_merge((array) ($result->meta ?? []), [
                'mention_backfill' => [
                    'status' => 'updated',
                    'updated_at' => now()->toISOString(),
                    'source' => 'raw_response_or_evidence',
                ],
            ]),
        ]);

        $this->replaceBrandMentions($result, $brandMentions, (string) $run->brand_name, $result->sources->count());

        return true;
    }

    /**
     * @param  Collection<int,\App\Models\BrandDiagnosisSource>  $sources
     * @return list<array{title:string,url:string,type:string,meta:array<string,mixed>}>
     */
    private function sourcesPayload(Collection $sources): array
    {
        return $sources
            ->map(static fn ($source): array => [
                'title' => (string) $source->title,
                'url' => (string) $source->url,
                'type' => (string) $source->source_type,
                'meta' => (array) ($source->meta ?? []),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{title:string,url:string,type:string,meta:array<string,mixed>}>  $sources
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
