<?php

namespace App\Services\BrandDiagnosis;

use App\Models\BrandDiagnosisRun;
use Illuminate\Support\Collection;

final class BrandDiagnosisLookupPresenter
{
    private const MODULES = BrandDiagnosisLookupService::MODULES;

    public function __construct(private readonly BrandDiagnosisSnapshotPayload $snapshotPayload) {}

    /**
     * @param  array{brand_word:string,data_source:string,match_type:string,run:?BrandDiagnosisRun,generated:?array<string,mixed>}  $result
     * @param  list<string>  $includes
     * @return array<string,mixed>
     */
    public function present(array $result, array $includes, ?string $model = null): array
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
            'brand_performance' => $this->moduleValue('performance', $included, $run ? $this->performance($run, $model) : null),
            'model_results' => $this->moduleValue('model_results', $included, $run ? $this->modelResults($run) : []),
            'ai_sources' => $this->moduleValue('sources', $included, $run ? $this->sources($run) : []),
            'conversation_snapshots' => $this->moduleValue('snapshots', $included, $run ? $this->snapshots($run) : []),
            'competitors' => $this->moduleValue('competitors', $included, $run ? $this->competitors($run) : []),
            'ai_search_platform_analysis' => $this->moduleValue('platform_analysis', $included, $run ? $this->platformAnalysis($run) : []),
            'competitor_visibility' => $this->moduleValue('competitor_visibility', $included, $run ? $this->competitorVisibility($run) : ['platforms' => [], 'rows' => []]),
            'module_status' => [],
        ];

        foreach (self::MODULES as $module) {
            $data['module_status'][$module] = in_array($module, $included, true)
                ? $this->includedStatus($module, $run, $result['data_source'], $model)
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

    private function performance(BrandDiagnosisRun $run, ?string $model): array
    {
        $model = $this->normalizePlatformKey((string) $model);
        $model = $model === '' || $model === 'all' ? null : $model;
        $byModel = $this->modelPerformanceRows($run, $model);

        if ($model !== null) {
            return [
                ...$this->performanceForPlatform($run, $model),
                'by_model' => $byModel,
            ];
        }

        return [
            'model' => 'all',
            'model_label' => '全部平台',
            'score' => (int) $run->brand_score,
            'mention_rate' => (int) $run->mention_rate,
            'average_rank' => $this->formatRank((float) $run->average_rank),
            'mention_count' => (int) $run->mention_count,
            'sentiment_rate' => (int) $run->sentiment_rate,
            'by_model' => $byModel,
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

    private function includedStatus(string $module, ?BrandDiagnosisRun $run, string $source, ?string $model): string
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
            if ($model !== null && $this->successfulResults($run, $model)->isEmpty()) {
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

        if ($module === 'platform_analysis') {
            return $run && $this->successfulResults($run)->isNotEmpty() ? 'included' : 'not_run';
        }

        if ($module === 'competitor_visibility') {
            return $run && $this->competitorMentions($run)->isNotEmpty() ? 'included' : 'not_run';
        }

        if (! $run || ! $run->relationLoaded('questions')) {
            return 'not_available';
        }

        return $run->questions->flatMap(static fn ($question) => $question->results)->isNotEmpty() ? 'included' : 'not_run';
    }

    private function modelPerformanceRows(BrandDiagnosisRun $run, ?string $model): array
    {
        $platforms = $model !== null
            ? collect([$model])
            : $this->platformKeys($run, $this->successfulResults($run), $this->allBrandMentions($run));

        return $platforms
            ->map(fn (string $platform): array => $this->performanceForPlatform($run, $platform))
            ->values()
            ->all();
    }

    private function performanceForPlatform(BrandDiagnosisRun $run, string $platform): array
    {
        return [
            'model' => $platform,
            'model_label' => $this->platformLabel($platform),
            ...$this->performanceMetrics(
                $this->successfulResults($run, $platform),
                $this->targetMentions($run, $platform)
            ),
        ];
    }

    private function performanceMetrics(Collection $results, Collection $targetMentions): array
    {
        $total = max(1, $results->count());
        if ($targetMentions->isEmpty()) {
            $targetResults = $results->filter(fn ($result): bool => (bool) $result->brand_mentioned);
            $mentionRate = (int) round(($targetResults->count() / $total) * 100);
            $mentionCount = (int) $targetResults->sum('mention_count');
            $averageRank = (float) ($targetResults->where('mention_rank', '>', 0)->avg('mention_rank') ?: 0);
            $sentimentRate = $targetResults->count() > 0
                ? (int) round(($targetResults->whereIn('sentiment', ['positive', 'neutral'])->count() / $targetResults->count()) * 100)
                : 0;
        } else {
            $mentionedConversationCount = $targetMentions
                ->pluck('result_id')
                ->unique()
                ->count();
            $mentionRate = (int) round(($mentionedConversationCount / $total) * 100);
            $mentionCount = (int) $targetMentions->sum('mention_count');
            $averageRank = (float) ($targetMentions->where('mention_rank', '>', 0)->avg('mention_rank') ?: 0);
            $sentimentRate = $targetMentions->count() > 0
                ? (int) round(($targetMentions->whereIn('sentiment', ['positive', 'neutral'])->count() / $targetMentions->count()) * 100)
                : 0;
        }

        $rankScore = $averageRank > 0
            ? max(0, 100 - (($averageRank - 1) * 5))
            : 0;
        $score = (int) min(100, round(
            ($mentionRate * 0.75)
            + ($mentionCount * 0.1)
            + ($rankScore * 0.1)
            + ($sentimentRate * 0.05)
        ));

        return [
            'score' => $score,
            'mention_rate' => $mentionRate,
            'average_rank' => $this->formatRank($averageRank),
            'mention_count' => $mentionCount,
            'sentiment_rate' => $sentimentRate,
        ];
    }

    private function platformAnalysis(BrandDiagnosisRun $run): array
    {
        $results = $this->successfulResults($run);
        $mentions = $this->targetMentions($run);
        $sources = $this->allSources($run);

        return $this->platformKeys($run, $results, $mentions, $sources)
            ->map(function (string $platform) use ($results, $mentions, $sources): array {
                $platformResults = $results->filter(fn ($result): bool => $this->normalizePlatformKey((string) $result->platform) === $platform);
                $total = $platformResults->count();
                $platformMentions = $mentions->filter(fn ($mention): bool => $this->normalizePlatformKey((string) $mention->platform) === $platform);
                $platformSources = $sources->filter(fn ($source): bool => $this->normalizePlatformKey((string) $source->platform) === $platform);

                return [
                    'platform_key' => $platform,
                    'platform' => $this->platformLabel($platform),
                    'analysis_count' => $total,
                    'top_rank_rates' => $this->rankRatesForMentions($platformMentions, $total),
                    'positive_sentiment_rate' => $this->rate($platformResults->where('sentiment', 'positive')->count(), $total),
                    'source_count' => $platformSources
                        ->map(fn ($source): string => (string) ($source->domain ?: $source->url))
                        ->filter()
                        ->unique()
                        ->count(),
                ];
            })
            ->values()
            ->all();
    }

    private function competitorVisibility(BrandDiagnosisRun $run): array
    {
        $platforms = collect($this->platformAnalysis($run));
        $platformKeys = $platforms->pluck('platform_key')->values();
        $resultTotals = $this->successfulResults($run)
            ->map(fn ($result): string => $this->normalizePlatformKey((string) $result->platform))
            ->filter()
            ->countBy();

        $rows = $this->competitorMentions($run)
            ->groupBy(fn ($mention): string => $this->normalizeCompetitorName((string) $mention->brand_name))
            ->map(function (Collection $mentions) use ($platformKeys, $resultTotals): array {
                $platformRows = $platformKeys
                    ->map(function (string $platform) use ($mentions, $resultTotals): array {
                        $platformMentions = $mentions->filter(fn ($mention): bool => $this->normalizePlatformKey((string) $mention->platform) === $platform);
                        $mentionRows = $platformMentions->pluck('result_id')->filter()->unique()->count();
                        $totalResults = (int) ($resultTotals[$platform] ?? 0);

                        return [
                            'platform_key' => $platform,
                            'platform' => $this->platformLabel($platform),
                            'mention_count' => (int) $platformMentions->sum('mention_count'),
                            'best_rank' => (int) ($platformMentions->where('mention_rank', '>', 0)->min('mention_rank') ?: 0),
                            'rate' => $this->rate($mentionRows, $totalResults),
                        ];
                    })
                    ->values();

                return [
                    'type' => 'recommended_competitor',
                    'type_label' => '推荐竞品',
                    'brand_name' => trim((string) $mentions->first()->brand_name),
                    'mention_count' => (int) $mentions->sum('mention_count'),
                    'best_rank' => (int) ($mentions->where('mention_rank', '>', 0)->min('mention_rank') ?: 0),
                    'platforms' => $platformRows->all(),
                    'platform_rates' => $platformRows
                        ->mapWithKeys(fn (array $platform): array => [
                            (string) $platform['platform_key'] => (float) $platform['rate'],
                        ])
                        ->all(),
                ];
            })
            ->sort(function (array $left, array $right): int {
                return ((int) $right['mention_count'] <=> (int) $left['mention_count'])
                    ?: (($left['best_rank'] > 0 ? (int) $left['best_rank'] : PHP_INT_MAX)
                        <=> ($right['best_rank'] > 0 ? (int) $right['best_rank'] : PHP_INT_MAX))
                    ?: strcmp((string) $left['brand_name'], (string) $right['brand_name']);
            })
            ->values()
            ->take(10)
            ->all();

        return [
            'platforms' => $platforms
                ->map(fn (array $platform): array => [
                    'platform_key' => (string) $platform['platform_key'],
                    'platform' => (string) $platform['platform'],
                    'analysis_count' => (int) $platform['analysis_count'],
                ])
                ->values()
                ->all(),
            'rows' => $rows,
        ];
    }

    private function successfulResults(BrandDiagnosisRun $run, ?string $platform = null): Collection
    {
        $platform = $this->normalizePlatformKey((string) $platform);
        $results = $run->relationLoaded('results')
            ? $run->results
            : $run->results()
                ->withoutGlobalScopes(['current_site', 'admin_owner'])
                ->get(['id', 'run_id', 'question_id', 'platform', 'brand_mentioned', 'mention_count', 'mention_rank', 'sentiment', 'status', 'checked_at']);

        return $results
            ->filter(fn ($result): bool => (string) $result->status === 'success')
            ->filter(fn ($result): bool => $platform === '' || $this->normalizePlatformKey((string) $result->platform) === $platform)
            ->values();
    }

    private function allBrandMentions(BrandDiagnosisRun $run): Collection
    {
        return $run->relationLoaded('brandMentions')
            ? $run->brandMentions
            : $run->brandMentions()
                ->withoutGlobalScopes(['current_site', 'admin_owner'])
                ->get([
                    'id', 'run_id', 'question_id', 'result_id', 'platform', 'brand_name',
                    'mention_count', 'mention_rank', 'sentiment', 'source_count', 'is_target_brand',
                ]);
    }

    private function targetMentions(BrandDiagnosisRun $run, ?string $platform = null): Collection
    {
        $platform = $this->normalizePlatformKey((string) $platform);

        return $this->allBrandMentions($run)
            ->filter(fn ($mention): bool => (bool) $mention->is_target_brand)
            ->filter(fn ($mention): bool => $platform === '' || $this->normalizePlatformKey((string) $mention->platform) === $platform)
            ->values();
    }

    private function competitorMentions(BrandDiagnosisRun $run): Collection
    {
        return $this->allBrandMentions($run)
            ->filter(fn ($mention): bool => ! (bool) $mention->is_target_brand && trim((string) $mention->brand_name) !== '')
            ->values();
    }

    private function allSources(BrandDiagnosisRun $run): Collection
    {
        return $run->relationLoaded('sources')
            ? $run->sources
            : $run->sources()
                ->withoutGlobalScopes(['current_site', 'admin_owner'])
                ->get(['id', 'run_id', 'question_id', 'result_id', 'platform', 'title', 'url', 'domain', 'source_type']);
    }

    private function platformKeys(BrandDiagnosisRun $run, Collection $results, ?Collection $mentions = null, ?Collection $sources = null): Collection
    {
        $supportedKeys = BrandDiagnosisPlatform::keys();
        $actualKeys = collect((array) $run->platforms)
            ->map(fn (mixed $platform): string => $this->normalizePlatformKey((string) $platform));
        $mentionKeys = ($mentions ?? collect())
            ->map(fn ($mention): string => $this->normalizePlatformKey((string) $mention->platform));
        $sourceKeys = ($sources ?? collect())
            ->map(fn ($source): string => $this->normalizePlatformKey((string) $source->platform));
        $resultKeys = $results
            ->map(fn ($result): string => $this->normalizePlatformKey((string) $result->platform));

        return collect($supportedKeys)
            ->merge($actualKeys)
            ->merge($mentionKeys)
            ->merge($sourceKeys)
            ->merge($resultKeys)
            ->filter()
            ->filter(fn (string $platform): bool => in_array($platform, $supportedKeys, true))
            ->unique()
            ->values();
    }

    private function rankRatesForMentions(Collection $mentions, int $total): array
    {
        $rates = [];
        for ($rank = 1; $rank <= 5; $rank++) {
            $rates['top'.$rank] = $this->rate($mentions->where('mention_rank', $rank)->count(), $total);
        }

        return $rates;
    }

    private function rate(int $part, int $total): float
    {
        return $total > 0 ? round($part * 100 / $total, 2) : 0.0;
    }

    private function platformLabel(string $platform): string
    {
        return match ($this->normalizePlatformKey($platform)) {
            'doubao' => '豆包',
            'deepseek' => 'DeepSeek',
            'qianwen' => '千问',
            'wenxin' => '文心一言',
            default => $platform,
        };
    }

    private function normalizePlatformKey(string $platform): string
    {
        return match (strtolower(trim($platform))) {
            'tongyi' => 'qianwen',
            'ernie' => 'wenxin',
            default => strtolower(trim($platform)),
        };
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
