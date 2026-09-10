<?php

namespace App\Services\BrandDiagnosis;

use App\Models\BrandDiagnosisRun;

final class BrandDiagnosisLookupPresenter
{
    private const MODULES = BrandDiagnosisLookupService::MODULES;

    public function __construct(private readonly BrandDiagnosisSnapshotPayload $snapshotPayload) {}

    /**
     * @param  array{brand_word:string,data_source:string,match_type:string,run:?BrandDiagnosisRun,generated:?array<string,mixed>}  $result
     * @param  list<string>  $includes
     * @return array<string,mixed>
     */
    public function present(array $result, array $includes): array
    {
        $run = $result['run'];
        $generated = $result['generated'];
        $included = array_values($includes);
        $omitted = array_values(array_diff(self::MODULES, $included));

        $data = [
            'requested_brand_word' => $result['brand_word'],
            'data_source' => $result['data_source'],
            'match_type' => $result['match_type'],
            'diagnosis' => $this->diagnosis($run, $result['data_source'], (string) $result['brand_word']),
            'brand_profile' => $this->moduleValue('profile', $included, $run ? $this->storedProfile($run) : $this->generatedProfile($generated)),
            'questions' => $this->moduleValue('questions', $included, $run ? $this->storedQuestions($run) : $this->generatedQuestions($generated)),
            'brand_performance' => $this->moduleValue('performance', $included, $run ? $this->performance($run) : null),
            'model_results' => $this->moduleValue('model_results', $included, $run ? $this->modelResults($run) : []),
            'ai_sources' => $this->moduleValue('sources', $included, $run ? $this->sources($run) : []),
            'conversation_snapshots' => $this->moduleValue('snapshots', $included, $run ? $this->snapshots($run) : []),
            'competitors' => $this->moduleValue('competitors', $included, $run ? $this->competitors($run) : []),
            'module_status' => [],
        ];

        foreach (self::MODULES as $module) {
            $data['module_status'][$module] = in_array($module, $included, true)
                ? $this->includedStatus($module, $run, $result['data_source'])
                : 'omitted';
        }

        return [
            ...$data,
            '_meta' => [
                'included' => $included,
                'omitted' => $omitted,
            ],
        ];
    }

    private function moduleValue(string $module, array $included, mixed $value): mixed
    {
        return in_array($module, $included, true) ? $value : null;
    }

    private function diagnosis(?BrandDiagnosisRun $run, string $source, string $brandWord): array
    {
        if (! $run) {
            return ['brand_name' => $brandWord, 'status' => 'not_run', 'created_at' => '', 'started_at' => '', 'completed_at' => '', 'error_message' => ''];
        }

        return [
            'brand_name' => (string) $run->brand_name,
            'status' => (string) $run->status,
            'created_at' => $run->created_at?->format('Y-m-d H:i:s') ?? '',
            'started_at' => $run->started_at?->format('Y-m-d H:i:s') ?? '',
            'completed_at' => $run->completed_at?->format('Y-m-d H:i:s') ?? '',
            'error_message' => (string) ($run->error_message ?? ''),
        ];
    }

    private function storedProfile(BrandDiagnosisRun $run): array
    {
        return [
            'text' => (string) ($run->brand_profile ?? ''),
            'source' => (string) ($run->brand_profile_source ?? ''),
            'model' => (string) ($run->brand_profile_model ?? ''),
            'status' => (string) ($run->brand_profile_status ?? ''),
            'sources' => $this->profileSources((array) ($run->brand_profile_meta['sources'] ?? [])),
        ];
    }

    private function generatedProfile(?array $generated): array
    {
        $profile = (array) ($generated['profile'] ?? []);

        return [
            'text' => (string) ($profile['profile'] ?? ''),
            'source' => (string) ($profile['source'] ?? ''),
            'model' => (string) ($profile['model'] ?? ''),
            'status' => 'generated',
            'sources' => $this->profileSources((array) ($profile['meta']['sources'] ?? [])),
        ];
    }

    private function profileSources(array $sources): array
    {
        return collect($sources)->filter(static fn (mixed $source): bool => is_array($source))->map(static fn (array $source): array => [
            'title' => (string) ($source['title'] ?? $source['url'] ?? ''),
            'url' => (string) ($source['url'] ?? ''),
            'domain' => (string) ($source['domain'] ?? parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST) ?? ''),
        ])->filter(fn (array $source): bool => $this->snapshotPayload->isHttpUrl($source['url']))->values()->all();
    }

    private function storedQuestions(BrandDiagnosisRun $run): array
    {
        return $run->questions->sortBy('sort_order')->map(static fn ($question): array => [
            'id' => (int) $question->id,
            'question' => (string) $question->question,
            'type' => (string) $question->question_type,
            'core_term' => (string) ($question->core_term ?? ''),
            'sort_order' => (int) $question->sort_order,
            'status' => (string) $question->status,
        ])->values()->all();
    }

    private function generatedQuestions(?array $generated): array
    {
        return collect((array) ($generated['questions'] ?? []))->values()->map(static fn (array $question, int $index): array => [
            'id' => null,
            'question' => (string) ($question['question'] ?? ''),
            'type' => (string) ($question['type'] ?? ''),
            'core_term' => (string) ($question['core_term'] ?? ''),
            'sort_order' => $index + 1,
            'status' => 'generated',
        ])->all();
    }

    private function performance(BrandDiagnosisRun $run): array
    {
        return [
            'score' => (int) $run->brand_score,
            'mention_rate' => (int) $run->mention_rate,
            'average_rank' => $this->formatRank((float) $run->average_rank),
            'mention_count' => (int) $run->mention_count,
            'sentiment_rate' => (int) $run->sentiment_rate,
        ];
    }

    private function modelResults(BrandDiagnosisRun $run): array
    {
        return $run->questions->flatMap(fn ($question) => $question->results->map(fn ($result): array => [
            'question_id' => (int) $question->id,
            'platform' => (string) $result->platform,
            'status' => (string) $result->status,
            'answer' => $this->snapshotPayload->displayAnswer((string) ($result->answer ?? '')),
            'brand_mentioned' => (bool) $result->brand_mentioned,
            'mention_count' => (int) $result->mention_count,
            'mention_rank' => (int) $result->mention_rank,
            'sentiment' => (string) $result->sentiment,
            'error_message' => (string) ($result->error_message ?? ''),
            'checked_at' => $result->checked_at?->format('Y-m-d H:i:s') ?? '',
        ]))->values()->all();
    }

    private function sources(BrandDiagnosisRun $run): array
    {
        return $run->sources->map(static fn ($source): array => [
            'question_id' => (int) $source->question_id,
            'result_id' => (int) $source->result_id,
            'platform' => (string) $source->platform,
            'title' => (string) $source->title,
            'url' => (string) $source->url,
            'domain' => (string) $source->domain,
            'source_type' => (string) $source->source_type,
        ])->filter(fn (array $source): bool => $this->snapshotPayload->isHttpUrl($source['url']))->values()->all();
    }

    private function snapshots(BrandDiagnosisRun $run): array
    {
        return $run->questions->flatMap(fn ($question) => $question->results->map(function ($result) use ($question): array {
            $payload = (array) ($result->snapshot_payload ?? []);

            return [
                'result_id' => (int) $result->id,
                'question_id' => (int) $question->id,
                'platform' => (string) $result->platform,
                'question' => (string) $question->question,
                'answer' => $this->snapshotPayload->displayAnswer((string) ($payload['answer'] ?? $result->answer ?? '')),
                'sources' => $this->snapshotSources((array) ($payload['sources'] ?? [])),
                'status' => (string) $result->status,
                'checked_at' => $result->checked_at?->format('Y-m-d H:i:s') ?? '',
            ];
        }))->values()->all();
    }

    private function snapshotSources(array $sources): array
    {
        return collect($sources)
            ->filter(static fn (mixed $source): bool => is_array($source))
            ->map(static fn (array $source): array => [
                'title' => (string) ($source['title'] ?? $source['url'] ?? ''),
                'url' => (string) ($source['url'] ?? ''),
                'domain' => (string) ($source['domain'] ?? parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST) ?? ''),
            ])
            ->filter(fn (array $source): bool => $this->snapshotPayload->isHttpUrl($source['url']))
            ->values()
            ->all();
    }

    private function includedStatus(string $module, ?BrandDiagnosisRun $run, string $source): string
    {
        if ($source === 'generated_not_stock') {
            return in_array($module, ['profile', 'questions'], true) ? 'included' : 'not_run';
        }
        if ($module === 'profile') {
            return $run && trim((string) ($run->brand_profile ?? '')) !== '' ? 'included' : 'not_available';
        }
        if ($module === 'questions') {
            return $run && $run->relationLoaded('questions') && $run->questions->isNotEmpty() ? 'included' : 'not_available';
        }
        if ($module === 'performance') {
            if (! $run || ! in_array((string) $run->status, ['completed', 'failed', 'running'], true)) {
                return 'not_run';
            }
            if ((string) $run->status === 'running'
                && (int) $run->brand_score === 0
                && (int) $run->mention_rate === 0
                && (int) $run->mention_count === 0
                && (int) $run->sentiment_rate === 0) {
                return 'not_run';
            }

            return 'included';
        }

        if ($module === 'sources') {
            if (! $run || ! $run->relationLoaded('sources')) {
                return 'not_available';
            }

            return $run->sources->isNotEmpty() ? 'included' : 'not_run';
        }

        if ($module === 'competitors') {
            if (! $run || ! $run->relationLoaded('brandMentions')) {
                return 'not_available';
            }

            return $run->brandMentions->contains(fn ($mention): bool => ! (bool) $mention->is_target_brand)
                ? 'included'
                : 'not_run';
        }

        if (! $run || ! $run->relationLoaded('questions')) {
            return 'not_available';
        }

        return $run->questions->flatMap(static fn ($question) => $question->results)->isNotEmpty() ? 'included' : 'not_run';
    }

    private function formatRank(float $rank): string
    {
        return $rank <= 0 ? '0' : rtrim(rtrim(number_format($rank, 2, '.', ''), '0'), '.');
    }

    private function competitors(BrandDiagnosisRun $run): array
    {
        return $run->brandMentions
            ->filter(fn ($mention): bool => ! (bool) $mention->is_target_brand && trim((string) $mention->brand_name) !== '')
            ->groupBy(fn ($mention): string => $this->normalizeCompetitorName((string) $mention->brand_name))
            ->map(function ($mentions): array {
                $sentimentScore = (int) $mentions->sum(function ($mention): int {
                    $weight = max(1, (int) $mention->mention_count);

                    return match (strtolower((string) $mention->sentiment)) {
                        'positive' => $weight,
                        'negative' => -$weight,
                        default => 0,
                    };
                });

                return [
                    'brand_name' => trim((string) $mentions->first()->brand_name),
                    'mention_count' => (int) $mentions->sum('mention_count'),
                    'best_rank' => (int) ($mentions->where('mention_rank', '>', 0)->min('mention_rank') ?: 0),
                    'source_count' => (int) $mentions->sum('source_count'),
                    'sentiment' => $sentimentScore > 0 ? 'positive' : ($sentimentScore < 0 ? 'negative' : 'neutral'),
                ];
            })
            ->sort(function (array $left, array $right): int {
                return ((int) $right['mention_count'] <=> (int) $left['mention_count'])
                    ?: (($left['best_rank'] > 0 ? (int) $left['best_rank'] : PHP_INT_MAX)
                        <=> ($right['best_rank'] > 0 ? (int) $right['best_rank'] : PHP_INT_MAX))
                    ?: ((int) $right['source_count'] <=> (int) $left['source_count'])
                    ?: strcmp((string) $left['brand_name'], (string) $right['brand_name']);
            })
            ->values()
            ->all();
    }

    private function normalizeCompetitorName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)), 'UTF-8');
    }
}
