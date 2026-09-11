<?php

namespace App\Services\ProductCases;

use App\Models\BrandDiagnosisBrandMention;
use App\Models\BrandDiagnosisQuestion;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Models\BrandDiagnosisSource;
use App\Models\ProductCase;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use Illuminate\Support\Collection;

class ProductCaseReportSummaryService
{
    /**
     * @return list<array{label:string,value:int}>
     */
    public function cardMetrics(ProductCase $case): array
    {
        $report = $this->detail($case);

        return array_values(array_filter(
            (array) data_get($report, 'summary.metrics', []),
            static fn (array $metric): bool => (int) ($metric['value'] ?? 0) > 0
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function detail(ProductCase $case): array
    {
        $data = $this->loadDiagnosisData($case);
        if ($data === null) {
            return $this->empty();
        }

        /** @var Collection<int,BrandDiagnosisQuestion> $questions */
        $questions = $data['questions'];
        /** @var Collection<int,BrandDiagnosisResult> $results */
        $results = $data['results'];
        /** @var Collection<int,BrandDiagnosisSource> $sources */
        $sources = $data['sources'];
        /** @var Collection<int,BrandDiagnosisBrandMention> $mentions */
        $mentions = $data['mentions'];

        $platformKeys = $this->platformKeys($results);
        $sourceCount = $this->sourceCount($sources);
        $targetMentions = $mentions->where('is_target_brand', true);
        if ($targetMentions->isEmpty()) {
            $targetMentions = $this->fallbackTargetMentions($results, $case);
        }

        $summary = [
            'platform_count' => $results->pluck('platform')->map(fn (string $platform): string => $this->normalizePlatform($platform))->filter()->unique()->count(),
            'search_report_count' => $results->count(),
            'distillation_word_count' => $questions->count(),
            'source_count' => $sourceCount,
        ];

        return [
            'summary' => $summary + ['metrics' => $this->metrics($summary)],
            'platforms' => $this->platforms($platformKeys, $results, $targetMentions, $sources),
            'search_rows' => $this->searchRows($results, $questions, $case),
            'trend' => $this->trend($data['run'], $results),
            'brand_profile' => $this->brandProfile($data['run'], $case),
            'overall' => $this->overall($results, $targetMentions),
            'competitors' => $this->competitors($platformKeys, $results, $mentions),
            'sentiment' => $this->sentiment($platformKeys, $results),
        ];
    }

    /**
     * @return array{
     *     run:BrandDiagnosisRun,
     *     questions:Collection<int,BrandDiagnosisQuestion>,
     *     results:Collection<int,BrandDiagnosisResult>,
     *     sources:Collection<int,BrandDiagnosisSource>,
     *     mentions:Collection<int,BrandDiagnosisBrandMention>
     * }|null
     */
    private function loadDiagnosisData(ProductCase $case): ?array
    {
        $siteId = (int) $case->site_id;
        $ownerAdminId = (int) $case->owner_admin_id;
        $brandName = trim((string) $case->company_name);
        if ($siteId <= 0 || $ownerAdminId <= 0 || $brandName === '') {
            return null;
        }

        $run = BrandDiagnosisRun::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('site_id', $siteId)
            ->where('owner_admin_id', $ownerAdminId)
            ->where('brand_name', $brandName)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();
        if (! $run instanceof BrandDiagnosisRun) {
            return null;
        }

        $questions = BrandDiagnosisQuestion::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('run_id', (int) $run->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $results = BrandDiagnosisResult::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('run_id', (int) $run->id)
            ->where('status', 'success')
            ->orderBy('id')
            ->get();
        $sources = BrandDiagnosisSource::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('run_id', (int) $run->id)
            ->orderBy('id')
            ->get();
        $mentions = BrandDiagnosisBrandMention::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('run_id', (int) $run->id)
            ->orderBy('id')
            ->get();

        return compact('run', 'questions', 'results', 'sources', 'mentions');
    }

    /**
     * @param  Collection<int,BrandDiagnosisResult>  $results
     * @return list<string>
     */
    private function platformKeys(Collection $results): array
    {
        return collect(BrandDiagnosisPlatform::keys())
            ->merge($results->pluck('platform')->map(fn (string $platform): string => $this->normalizePlatform($platform)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int,BrandDiagnosisResult>  $results
     * @param  Collection<int,BrandDiagnosisBrandMention>|Collection<int,array<string,mixed>>  $targetMentions
     * @param  Collection<int,BrandDiagnosisSource>  $sources
     * @return list<array<string,mixed>>
     */
    private function platforms(array $platformKeys, Collection $results, Collection $targetMentions, Collection $sources): array
    {
        return collect($platformKeys)->map(function (string $platform) use ($results, $targetMentions, $sources): array {
            $platformResults = $results->filter(fn (BrandDiagnosisResult $result): bool => $this->normalizePlatform((string) $result->platform) === $platform);
            $platformMentions = $targetMentions->filter(fn (BrandDiagnosisBrandMention|array $mention): bool => $this->normalizePlatform((string) data_get($mention, 'platform', '')) === $platform);
            $platformSources = $sources->filter(fn (BrandDiagnosisSource $source): bool => $this->normalizePlatform((string) $source->platform) === $platform);
            $total = $platformResults->count();

            return [
                'platform_key' => $platform,
                'platform' => $this->platformLabel($platform),
                'analysis_count' => $total,
                'top_rank_rates' => $this->rankRates($platformMentions, $total),
                'positive_sentiment_rate' => $this->rate($platformResults->where('sentiment', 'positive')->count(), $total),
                'source_count' => $platformSources
                    ->map(fn (BrandDiagnosisSource $source): string => trim((string) ($source->domain ?: $source->url)))
                    ->filter()
                    ->unique()
                    ->count(),
            ];
        })->all();
    }

    /**
     * @param  Collection<int,BrandDiagnosisResult>  $results
     * @param  Collection<int,BrandDiagnosisQuestion>  $questions
     * @return list<array<string,mixed>>
     */
    private function searchRows(Collection $results, Collection $questions, ProductCase $case): array
    {
        $questionsById = $questions->keyBy('id');

        return $results->map(function (BrandDiagnosisResult $result) use ($questionsById, $case): array {
            $question = $questionsById->get((int) $result->question_id);

            return [
                'question' => $question instanceof BrandDiagnosisQuestion
                    ? (string) $question->question
                    : '品牌诊断问题',
                'platform' => $this->platformLabel((string) $result->platform),
                'platform_key' => $this->normalizePlatform((string) $result->platform),
                'target' => (string) ($case->company_name ?: $case->title),
                'answer' => (string) ($result->answer ?? ''),
            ];
        })->values()->all();
    }

    /**
     * @param  BrandDiagnosisRun  $run
     * @param  Collection<int,BrandDiagnosisResult>  $results
     * @return list<array{date:string,value:int}>
     */
    private function trend(BrandDiagnosisRun $run, Collection $results): array
    {
        $date = $run->completed_at?->toDateString() ?: now()->toDateString();

        return [['date' => $date, 'value' => $results->count()]];
    }

    /**
     * @param  BrandDiagnosisRun  $run
     * @return array{company_name:string,brand_names:list<string>,core_services:list<string>,description:string}
     */
    private function brandProfile(BrandDiagnosisRun $run, ProductCase $case): array
    {
        $companyName = trim((string) ($case->company_name ?: $run->brand_name ?: $case->title));
        $services = collect([
            $case->industry,
            $case->region !== '' ? $case->region.'服务' : '',
        ])->flatMap(static fn (mixed $value): array => is_string($value)
            ? preg_split('/\s*[\/／、,，]\s*/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: []
            : [])
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'company_name' => $companyName,
            'brand_names' => [$companyName],
            'core_services' => $services,
            'description' => trim((string) $run->brand_profile) !== ''
                ? (string) $run->brand_profile
                : (string) $case->summary,
        ];
    }

    /**
     * @param  Collection<int,BrandDiagnosisResult>  $results
     * @param  Collection<int,BrandDiagnosisBrandMention>|Collection<int,array<string,mixed>>  $targetMentions
     * @return array{top5_count:int,top5_rate:float,top_rank_rates:array<string,float>}
     */
    private function overall(Collection $results, Collection $targetMentions): array
    {
        $total = $results->count();
        $topFive = $targetMentions
            ->filter(fn (BrandDiagnosisBrandMention|array $mention): bool => $this->rank($mention) >= 1 && $this->rank($mention) <= 5)
            ->count();

        return [
            'top5_count' => $topFive,
            'top5_rate' => $this->rate($topFive, $total),
            'top_rank_rates' => $this->rankRates($targetMentions, $total),
        ];
    }

    /**
     * @param  Collection<int,BrandDiagnosisResult>  $results
     * @param  Collection<int,BrandDiagnosisBrandMention>  $mentions
     * @return list<array<string,mixed>>
     */
    private function competitors(array $platformKeys, Collection $results, Collection $mentions): array
    {
        $resultTotals = $results
            ->groupBy(fn (BrandDiagnosisResult $result): string => $this->normalizePlatform((string) $result->platform))
            ->map->count();
        $competitorMentions = $mentions->where('is_target_brand', false);

        return $competitorMentions
            ->groupBy(fn (BrandDiagnosisBrandMention $mention): string => trim((string) $mention->brand_name))
            ->filter(fn (Collection $brandMentions, string $brandName): bool => $brandName !== '')
            ->map(function (Collection $brandMentions, string $brandName) use ($platformKeys, $resultTotals): array {
                $platforms = collect($platformKeys)->map(function (string $platform) use ($brandMentions, $resultTotals): array {
                    $platformMentions = $brandMentions->filter(fn (BrandDiagnosisBrandMention $mention): bool => $this->normalizePlatform((string) $mention->platform) === $platform);
                    $ranks = $platformMentions->map(fn (BrandDiagnosisBrandMention $mention): int => (int) $mention->mention_rank)->filter(fn (int $rank): bool => $rank > 0);

                    return [
                        'platform_key' => $platform,
                        'platform' => $this->platformLabel($platform),
                        'mention_count' => (int) $platformMentions->sum('mention_count'),
                        'best_rank' => $ranks->isNotEmpty() ? (int) $ranks->min() : 0,
                        'rate' => $this->rate($platformMentions->count(), (int) ($resultTotals[$platform] ?? 0)),
                    ];
                })->values();

                $brandRanks = $brandMentions
                    ->map(fn (BrandDiagnosisBrandMention $mention): int => (int) $mention->mention_rank)
                    ->filter(fn (int $rank): bool => $rank > 0);

                return [
                    'brand_name' => $brandName,
                    'mention_count' => (int) $brandMentions->sum('mention_count'),
                    'best_rank' => $brandRanks->isNotEmpty() ? (int) $brandRanks->min() : 0,
                    'platforms' => $platforms->all(),
                    'platform_rates' => $platforms->mapWithKeys(fn (array $platform): array => [
                        (string) $platform['platform_key'] => (float) $platform['rate'],
                    ])->all(),
                ];
            })
            ->sortByDesc('mention_count')
            ->values()
            ->take(10)
            ->all();
    }

    /**
     * @param  Collection<int,BrandDiagnosisResult>  $results
     * @return array{overall:array<string,float>,platforms:list<array<string,mixed>>}
     */
    private function sentiment(array $platformKeys, Collection $results): array
    {
        $overall = [
            'positive_rate' => $this->rate($results->where('sentiment', 'positive')->count(), $results->count()),
            'neutral_rate' => $this->rate($results->where('sentiment', 'neutral')->count(), $results->count()),
            'negative_rate' => $this->rate($results->where('sentiment', 'negative')->count(), $results->count()),
        ];

        $platforms = collect($platformKeys)->map(function (string $platform) use ($results): array {
            $platformResults = $results->filter(fn (BrandDiagnosisResult $result): bool => $this->normalizePlatform((string) $result->platform) === $platform);
            $total = $platformResults->count();

            return [
                'platform_key' => $platform,
                'platform' => $this->platformLabel($platform),
                'positive_rate' => $this->rate($platformResults->where('sentiment', 'positive')->count(), $total),
                'neutral_rate' => $this->rate($platformResults->where('sentiment', 'neutral')->count(), $total),
                'negative_rate' => $this->rate($platformResults->where('sentiment', 'negative')->count(), $total),
            ];
        })->values()->all();

        return ['overall' => $overall, 'platforms' => $platforms];
    }

    /**
     * @param  Collection<int,BrandDiagnosisSource>  $sources
     */
    private function sourceCount(Collection $sources): int
    {
        return $sources
            ->map(fn (BrandDiagnosisSource $source): string => trim((string) ($source->domain ?: $source->url)))
            ->filter()
            ->unique()
            ->count();
    }

    /**
     * @param  Collection<int,BrandDiagnosisResult>  $results
     * @return Collection<int,array<string,mixed>>
     */
    private function fallbackTargetMentions(Collection $results, ProductCase $case): Collection
    {
        return $results
            ->filter(fn (BrandDiagnosisResult $result): bool => (bool) $result->brand_mentioned)
            ->map(fn (BrandDiagnosisResult $result): array => [
                'platform' => (string) $result->platform,
                'mention_count' => (int) $result->mention_count,
                'mention_rank' => (int) $result->mention_rank,
                'sentiment' => (string) $result->sentiment,
                'brand_name' => (string) $case->company_name,
                'is_target_brand' => true,
            ])
            ->values();
    }

    /**
     * @param  Collection<int,BrandDiagnosisBrandMention>|Collection<int,array<string,mixed>>  $mentions
     * @return array<string,float>
     */
    private function rankRates(Collection $mentions, int $total): array
    {
        $rates = [];
        for ($rank = 1; $rank <= 5; $rank++) {
            $rates['top'.$rank] = $this->rate(
                $mentions->filter(fn (BrandDiagnosisBrandMention|array $mention): bool => $this->rank($mention) === $rank)->count(),
                $total
            );
        }

        return $rates;
    }

    private function rank(BrandDiagnosisBrandMention|array $mention): int
    {
        return (int) data_get($mention, 'mention_rank', 0);
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return list<array{label:string,value:int}>
     */
    private function metrics(array $summary): array
    {
        return [
            ['label' => 'AI 平台覆盖', 'value' => (int) ($summary['platform_count'] ?? 0)],
            ['label' => '搜索报表数量', 'value' => (int) ($summary['search_report_count'] ?? 0)],
            ['label' => 'AI 搜索词数量', 'value' => (int) ($summary['distillation_word_count'] ?? 0)],
            ['label' => '引用来源数量', 'value' => (int) ($summary['source_count'] ?? 0)],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function empty(): array
    {
        $summary = [
            'platform_count' => 0,
            'search_report_count' => 0,
            'distillation_word_count' => 0,
            'source_count' => 0,
        ];

        return [
            'summary' => $summary + ['metrics' => $this->metrics($summary)],
            'platforms' => [],
            'search_rows' => [],
            'trend' => [],
            'brand_profile' => [],
            'overall' => [
                'top5_count' => 0,
                'top5_rate' => 0.0,
                'top_rank_rates' => ['top1' => 0.0, 'top2' => 0.0, 'top3' => 0.0, 'top4' => 0.0, 'top5' => 0.0],
            ],
            'competitors' => [],
            'sentiment' => [
                'overall' => ['positive_rate' => 0.0, 'neutral_rate' => 0.0, 'negative_rate' => 0.0],
                'platforms' => [],
            ],
        ];
    }

    private function normalizePlatform(string $platform): string
    {
        return match (strtolower(trim($platform))) {
            'tencent_yuanbao' => 'yuanbao',
            'ernie' => 'wenxin',
            'tongyi' => 'qianwen',
            default => strtolower(trim($platform)),
        };
    }

    private function platformLabel(string $platform): string
    {
        return match ($this->normalizePlatform($platform)) {
            'doubao' => '豆包',
            'deepseek' => 'DeepSeek',
            'qianwen' => '千问',
            'wenxin' => '文心一言',
            'yuanbao' => '腾讯元宝',
            default => $platform,
        };
    }

    private function rate(int $part, int $total): float
    {
        return $total > 0 ? round($part * 100 / $total, 2) : 0.0;
    }
}
