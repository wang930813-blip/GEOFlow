<?php

namespace App\Services\ProductCases;

use App\Models\Admin;
use App\Models\BrandDiagnosisBrandMention;
use App\Models\BrandDiagnosisQuestion;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Models\BrandDiagnosisSource;
use App\Models\ProductCase;
use App\Models\Site;
use App\Services\MonitoringCenter\MonitoringReportDataService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

class ProductCaseReportSummaryService
{
    private const SHOWCASE_PLATFORM_COUNT = 9;

    private const SEARCH_ROWS_PER_PAGE = 10;

    /**
     * @var list<string>
     */
    private const DOMESTIC_PLATFORM_KEYS = [
        'doubao',
        'deepseek',
        'qianwen',
        'wenxin',
        'yuanbao',
    ];

    public function __construct(private readonly MonitoringReportDataService $monitoringReports) {}

    /**
     * @return list<array{label:string,value:int}>
     */
    public function cardMetrics(ProductCase $case): array
    {
        $report = $this->detail($case);

        return $this->cardMetricsFromReport($report);
    }

    /**
     * @param  array<string,mixed>  $report
     * @return list<array{label:string,value:int}>
     */
    public function cardMetricsFromReport(array $report): array
    {
        return array_values(array_filter(
            (array) data_get($report, 'summary.metrics', []),
            static fn (array $metric): bool => (int) ($metric['value'] ?? 0) > 0
        ));
    }

    /**
     * @param  array<string,mixed>  $report
     */
    public function performanceScoreFromReport(array $report): int
    {
        return (int) data_get($report, 'summary.performance_score', 0);
    }

    /**
     * @return array<string,mixed>
     */
    public function detail(ProductCase $case, int $searchPage = 1): array
    {
        $seedRun = $this->seedRun($case);

        if ($seedRun instanceof BrandDiagnosisRun) {
            return $this->detailFromDiagnosisRun($case, $seedRun, $searchPage);
        }

        return $this->detailFromMonitoringReports($case, $searchPage);
    }

    /**
     * @return array<string,mixed>
     */
    private function detailFromDiagnosisRun(ProductCase $case, BrandDiagnosisRun $run, int $searchPage = 1): array
    {
        $data = $this->diagnosisDataForRun($run);
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
            $targetMentions = $this->fallbackTargetMentions(
                $results,
                trim((string) ($data['run']->brand_name ?: $case->company_name))
            );
        }

        $summary = [
            'platform_count' => $results->pluck('platform')->map(fn (string $platform): string => $this->normalizePlatform($platform))->filter()->unique()->count(),
            'search_report_count' => $results->count(),
            'distillation_word_count' => $questions->count(),
            'source_count' => $sourceCount,
        ];
        $displaySummary = $this->displaySummary($case, $summary, $results, $questions, $sources, $targetMentions);
        $searchPagination = $this->paginateSearchRows(
            collect($this->searchRows(
                $results,
                $questions,
                trim((string) ($data['run']->brand_name ?: $case->company_name ?: $case->title))
            )),
            $searchPage
        );

        return [
            'summary' => $summary + [
                'display' => $displaySummary,
                'performance_score' => (int) $displaySummary['performance_score'],
                'metrics' => $this->metrics($displaySummary),
            ],
            'platforms' => $this->platforms($platformKeys, $results, $targetMentions, $sources),
            'search_rows' => $searchPagination['items'],
            'search_pagination' => $searchPagination['meta'],
            'trend' => $this->trend($data['run'], $results),
            'brand_profile' => $this->brandProfile($data['run'], $case),
            'overall' => $this->overall($results, $targetMentions),
            'competitors' => $this->competitors($platformKeys, $results, $mentions),
            'sentiment' => $this->sentiment($platformKeys, $results),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function detailFromMonitoringReports(ProductCase $case, int $searchPage = 1): array
    {
        $site = $this->reportSite($case);
        $owner = $this->reportOwner($case, $site);

        if (! $site instanceof Site || ! $owner instanceof Admin) {
            return $this->empty();
        }

        try {
            $enterprise = $this->monitoringReports->enterpriseReport($owner, $site);
            $industry = $this->monitoringReports->industryReport($owner, $site);
        } catch (Throwable) {
            return $this->empty();
        }

        $summary = (array) ($enterprise['summary'] ?? []);
        $displaySummary = [
            'platform_count' => (int) data_get($summary, 'platform_count.display', 0),
            'search_report_count' => (int) data_get($summary, 'search_report_count.display', 0),
            'distillation_word_count' => (int) data_get($summary, 'distillation_word_count.display', 0),
            'source_count' => (int) data_get($summary, 'source_count.display', 0),
        ];
        $performanceScore = $this->performanceScoreFromSummary($displaySummary);
        $searchPagination = $this->paginateSearchRows(
            collect((array) ($enterprise['search_rows'] ?? [])),
            $searchPage
        );

        return [
            'summary' => $displaySummary + [
                'display' => $displaySummary + ['performance_score' => $performanceScore],
                'performance_score' => $performanceScore,
                'metrics' => $this->metricsFromMonitoringSummary($summary),
            ],
            'platforms' => (array) ($industry['platforms'] ?? []),
            'search_rows' => $searchPagination['items'],
            'search_pagination' => $searchPagination['meta'],
            'trend' => (array) ($enterprise['trend'] ?? []),
            'brand_profile' => (array) ($industry['brand_profile'] ?? []),
            'overall' => (array) ($industry['overall'] ?? []),
            'competitors' => (array) ($industry['competitors'] ?? []),
            'sentiment' => (array) ($industry['sentiment'] ?? []),
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
    private function diagnosisDataForRun(BrandDiagnosisRun $run): ?array
    {
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

    private function seedRun(ProductCase $case): ?BrandDiagnosisRun
    {
        $siteId = (int) $case->site_id;
        $ownerAdminId = (int) $case->owner_admin_id;
        $brandName = trim((string) $case->company_name);
        if ($siteId <= 0 || $ownerAdminId <= 0 || $brandName === '') {
            return null;
        }

        $run = $this->completedRunQuery($siteId, $ownerAdminId)
            ->whereRaw('LOWER(TRIM(brand_name)) = LOWER(TRIM(?))', [$brandName])
            ->where('billing_mode', ProductCaseDemoDataService::BILLING_MODE)
            ->first();

        return $run instanceof BrandDiagnosisRun ? $run : null;
    }

    private function completedRunQuery(int $siteId, int $ownerAdminId): Builder
    {
        return BrandDiagnosisRun::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('site_id', $siteId)
            ->where('owner_admin_id', $ownerAdminId)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->orderByDesc('id');
    }

    private function reportSite(ProductCase $case): ?Site
    {
        if ($case->relationLoaded('site') && $case->site instanceof Site) {
            return $case->site;
        }

        $siteId = (int) $case->site_id;

        return $siteId > 0 ? Site::query()->whereKey($siteId)->first() : null;
    }

    private function reportOwner(ProductCase $case, ?Site $site): ?Admin
    {
        if ($site instanceof Site && (int) $site->owner_admin_id > 0) {
            return Admin::query()->whereKey((int) $site->owner_admin_id)->first();
        }

        if ($case->relationLoaded('owner') && $case->owner instanceof Admin) {
            return $case->owner;
        }

        $ownerAdminId = (int) $case->owner_admin_id;

        return $ownerAdminId > 0 ? Admin::query()->whereKey($ownerAdminId)->first() : null;
    }

    /**
     * @param  Collection<int,BrandDiagnosisResult>  $results
     * @return list<string>
     */
    private function platformKeys(Collection $results): array
    {
        $platforms = $results
            ->pluck('platform')
            ->map(fn (string $platform): string => $this->normalizePlatform($platform))
            ->filter()
            ->unique()
            ->values();

        $visible = collect(self::DOMESTIC_PLATFORM_KEYS)
            ->filter(fn (string $platform): bool => $platforms->contains($platform))
            ->values();

        $hasOtherAi = $platforms
            ->reject(fn (string $platform): bool => in_array($platform, self::DOMESTIC_PLATFORM_KEYS, true))
            ->isNotEmpty();

        if ($hasOtherAi) {
            $visible->push('other_ai');
        }

        return $visible->all();
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
            $matchesPlatform = fn (string $value): bool => $this->platformMatchesBucket($value, $platform);
            $platformResults = $results->filter(fn (BrandDiagnosisResult $result): bool => $matchesPlatform((string) $result->platform));
            $platformMentions = $targetMentions->filter(fn (BrandDiagnosisBrandMention|array $mention): bool => $matchesPlatform((string) data_get($mention, 'platform', '')));
            $platformSources = $sources->filter(fn (BrandDiagnosisSource $source): bool => $matchesPlatform((string) $source->platform));
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
    private function searchRows(Collection $results, Collection $questions, string $targetBrandName): array
    {
        $questionsById = $questions->keyBy('id');

        return $results->map(function (BrandDiagnosisResult $result) use ($questionsById, $targetBrandName): array {
            $question = $questionsById->get((int) $result->question_id);

            return [
                'question' => $question instanceof BrandDiagnosisQuestion
                    ? (string) $question->question
                    : '品牌诊断问题',
                'platform' => $this->platformLabel((string) $result->platform),
                'platform_key' => $this->normalizePlatform((string) $result->platform),
                'target' => $targetBrandName,
                'answer' => (string) ($result->answer ?? ''),
            ];
        })->values()->all();
    }

    /**
     * @param  Collection<int,BrandDiagnosisResult>  $results
     * @return list<array{date:string,value:int}>
     */
    private function trend(BrandDiagnosisRun $run, Collection $results): array
    {
        $date = $run->completed_at?->toDateString() ?: now()->toDateString();

        return [['date' => $date, 'value' => $results->count()]];
    }

    /**
     * @return array{company_name:string,brand_names:list<string>,core_services:list<string>,description:string}
     */
    private function brandProfile(BrandDiagnosisRun $run, ProductCase $case): array
    {
        $companyName = trim((string) ($run->brand_name ?: $case->company_name ?: $case->title));
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
                    $platformMentions = $brandMentions->filter(fn (BrandDiagnosisBrandMention $mention): bool => $this->platformMatchesBucket((string) $mention->platform, $platform));
                    $ranks = $platformMentions->map(fn (BrandDiagnosisBrandMention $mention): int => (int) $mention->mention_rank)->filter(fn (int $rank): bool => $rank > 0);
                    $total = $platform === 'other_ai'
                        ? $resultTotals
                            ->reject(fn (int $count, string $resultPlatform): bool => in_array($resultPlatform, self::DOMESTIC_PLATFORM_KEYS, true))
                            ->sum()
                        : (int) ($resultTotals[$platform] ?? 0);

                    return [
                        'platform_key' => $platform,
                        'platform' => $this->platformLabel($platform),
                        'mention_count' => (int) $platformMentions->sum('mention_count'),
                        'best_rank' => $ranks->isNotEmpty() ? (int) $ranks->min() : 0,
                        'rate' => $this->rate($platformMentions->count(), (int) $total),
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
            $platformResults = $results->filter(fn (BrandDiagnosisResult $result): bool => $this->platformMatchesBucket((string) $result->platform, $platform));
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
    private function fallbackTargetMentions(Collection $results, string $brandName): Collection
    {
        return $results
            ->filter(fn (BrandDiagnosisResult $result): bool => (bool) $result->brand_mentioned)
            ->map(fn (BrandDiagnosisResult $result): array => [
                'platform' => (string) $result->platform,
                'mention_count' => (int) $result->mention_count,
                'mention_rank' => (int) $result->mention_rank,
                'sentiment' => (string) $result->sentiment,
                'brand_name' => $brandName,
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
     * @param  array<string,mixed>  $summary
     * @return list<array{label:string,value:int}>
     */
    private function metricsFromMonitoringSummary(array $summary): array
    {
        return [
            ['label' => 'AI 平台覆盖', 'value' => (int) data_get($summary, 'platform_count.display', 0)],
            ['label' => '搜索报表数量', 'value' => (int) data_get($summary, 'search_report_count.display', 0)],
            ['label' => 'AI 搜索词数量', 'value' => (int) data_get($summary, 'distillation_word_count.display', 0)],
            ['label' => '引用来源数量', 'value' => (int) data_get($summary, 'source_count.display', 0)],
        ];
    }

    /**
     * @param  array{platform_count:int,search_report_count:int,distillation_word_count:int,source_count:int}  $summary
     */
    private function performanceScoreFromSummary(array $summary): int
    {
        $score = ((int) $summary['platform_count'] * 20)
            + ((int) $summary['search_report_count'] * 2)
            + (int) $summary['distillation_word_count']
            + (int) round(((int) $summary['source_count']) * 1.5);

        return $score > 0 ? (int) min(999, max(100, $score)) : 0;
    }

    /**
     * @param  array<string,mixed>  $summary
     * @param  Collection<int,BrandDiagnosisResult>  $results
     * @param  Collection<int,BrandDiagnosisQuestion>  $questions
     * @param  Collection<int,BrandDiagnosisSource>  $sources
     * @param  Collection<int,BrandDiagnosisBrandMention>|Collection<int,array<string,mixed>>  $targetMentions
     * @return array{platform_count:int,search_report_count:int,distillation_word_count:int,source_count:int,performance_score:int}
     */
    private function displaySummary(
        ProductCase $case,
        array $summary,
        Collection $results,
        Collection $questions,
        Collection $sources,
        Collection $targetMentions
    ): array {
        $seed = $this->caseSeed($case);
        $rawReportCount = (int) ($summary['search_report_count'] ?? 0);
        $rawQuestionCount = $questions->count();
        $rawSourceRows = $sources->count();
        $rawPlatformCount = $results
            ->pluck('platform')
            ->map(fn (string $platform): string => $this->normalizePlatform($platform))
            ->filter()
            ->unique()
            ->count();
        $reportCount = $this->prettyCount(
            ($rawReportCount * 5)
            + ($rawPlatformCount * 45),
            $seed,
            260,
            2600
        );
        $wordCount = $this->prettyCount(
            ($rawQuestionCount * 28)
            + ($rawPlatformCount * 24),
            $seed,
            130,
            1200
        );
        $sourceCount = $this->prettyCount(
            ($rawSourceRows * 3)
            + ((int) ($summary['source_count'] ?? 0) * 19),
            $seed,
            420,
            4800
        );
        $topFiveRate = $this->rate(
            $targetMentions
                ->filter(fn (BrandDiagnosisBrandMention|array $mention): bool => $this->rank($mention) >= 1 && $this->rank($mention) <= 5)
                ->count(),
            max(1, $results->count())
        );

        $performanceScore = (int) min(999, max(100, round(
            420
            + (self::SHOWCASE_PLATFORM_COUNT * 12)
            + ($reportCount * 0.42)
            + ($wordCount * 0.2)
            + ($sourceCount * 0.16)
            + ($topFiveRate * 1.1)
        )));

        return [
            'platform_count' => self::SHOWCASE_PLATFORM_COUNT,
            'search_report_count' => $reportCount,
            'distillation_word_count' => $wordCount,
            'source_count' => $sourceCount,
            'performance_score' => $performanceScore,
        ];
    }

    private function prettyCount(int $base, int $seed, int $floor, int $ceiling): int
    {
        $variation = 18 + ($seed % 64);

        return min($ceiling, max(100, $floor + $base + $variation));
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
            'summary' => $summary + [
                'display' => $summary + ['performance_score' => 0],
                'performance_score' => 0,
                'metrics' => $this->metrics($summary + ['performance_score' => 0]),
            ],
            'platforms' => [],
            'search_rows' => [],
            'search_pagination' => [
                'current_page' => 1,
                'per_page' => self::SEARCH_ROWS_PER_PAGE,
                'total' => 0,
                'last_page' => 1,
                'from' => 0,
                'to' => 0,
                'has_previous' => false,
                'has_next' => false,
            ],
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
            'openai' => 'chatgpt',
            'anthropic' => 'claude',
            'xai' => 'grok',
            default => strtolower(trim($platform)),
        };
    }

    private function platformMatchesBucket(string $platform, string $bucket): bool
    {
        $normalized = $this->normalizePlatform($platform);

        if ($bucket === 'other_ai') {
            return $normalized !== '' && ! in_array($normalized, self::DOMESTIC_PLATFORM_KEYS, true);
        }

        return $normalized === $bucket;
    }

    private function platformLabel(string $platform): string
    {
        return match ($this->normalizePlatform($platform)) {
            'other_ai' => '其他 AI',
            'doubao' => '豆包',
            'deepseek' => 'DeepSeek',
            'qianwen' => '千问',
            'wenxin' => '文心一言',
            'yuanbao' => '腾讯元宝',
            default => trim($platform) !== '' ? '其他 AI' : 'AI 平台',
        };
    }

    private function caseSeed(ProductCase $case): int
    {
        $value = (string) ($case->slug ?: $case->company_name ?: $case->title ?: $case->id);

        return (int) hexdec(substr(hash('sha256', $value), 0, 8));
    }

    private function rate(int $part, int $total): float
    {
        return $total > 0 ? round($part * 100 / $total, 2) : 0.0;
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array{items:list<array<string,mixed>>,meta:array{current_page:int,per_page:int,total:int,last_page:int,from:int,to:int,has_previous:bool,has_next:bool}}
     */
    private function paginateSearchRows(Collection $rows, int $page): array
    {
        $total = $rows->count();
        $perPage = self::SEARCH_ROWS_PER_PAGE;
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min($lastPage, max(1, $page));
        $from = $total > 0 ? (($currentPage - 1) * $perPage) + 1 : 0;
        $to = $total > 0 ? min($currentPage * $perPage, $total) : 0;

        return [
            'items' => $rows->forPage($currentPage, $perPage)->values()->all(),
            'meta' => [
                'current_page' => $currentPage,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => $lastPage,
                'from' => $from,
                'to' => $to,
                'has_previous' => $currentPage > 1,
                'has_next' => $currentPage < $lastPage,
            ],
        ];
    }
}
