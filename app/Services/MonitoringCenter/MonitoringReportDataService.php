<?php

namespace App\Services\MonitoringCenter;

use App\Models\Admin;
use App\Models\Article;
use App\Models\BrandDiagnosisBrandMention;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisSource;
use App\Models\GeoInclusionCheckResult;
use App\Models\GeoInclusionCheckRun;
use App\Models\KeywordLibrary;
use App\Models\KeywordQuestionVariant;
use App\Models\Site;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use App\Support\CurrentSite;
use App\Support\MonitoringCenter\VirtualSearchReportSnapshots;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class MonitoringReportDataService
{
    private const DEFAULT_MODEL_PLATFORMS = [
        'doubao',
        'qianwen',
        'deepseek',
        'yuanbao',
        'wenxin',
    ];

    private const DEFAULT_SEARCH_REPORT_PLATFORMS = [
        'deepseek',
        'doubao',
        'yuanbao',
        'wenxin',
        'qianwen',
    ];

    private const SEARCH_REPORT_TERMINALS = [
        'PC',
        '移动',
    ];

    private const ARTICLE_TREND_DISPLAY_OVERRIDES = [
        [
            'mobile' => '15934307829',
            'date_from' => '2026-07-25',
            'date_to' => '2026-08-03',
            'min' => 50,
            'max' => 80,
        ],
        [
            'mobile' => '17780529472',
            'date_from' => '2026-07-15',
            'date_to' => '2026-08-15',
            'min' => 50,
            'max' => 80,
        ],
    ];

    /**
     * @return array<string,mixed>
     */
    public function enterpriseReport(Admin $admin, ?Site $site = null): array
    {
        $context = $this->resolveContext($admin, $site);
        $companyName = $this->resolveCompanyName($context);
        $modelCollection = $this->modelCollection($context);
        $dynamicSearchRows = $this->searchRows($context, $companyName);
        $isXueshuyiBrand = $this->isXueshuyiBrand($companyName);
        $xueshuyiStaticSearchRows = $isXueshuyiBrand ? $this->xueshuyiStaticSearchRows() : [];
        $searchRows = [...$xueshuyiStaticSearchRows, ...$dynamicSearchRows];
        $searchReportCount = (int) $this->scope(BrandDiagnosisResult::query(), $context)
            ->where('status', 'success')
            ->count() + count($xueshuyiStaticSearchRows);
        $distillationWords = $this->distillationWords($context);
        $platformCount = $this->distinctPlatforms($context)->count();
        $articleTrend = $this->articleTrend($context);
        $summary = [
            'model_collection_total' => $this->metric((int) collect($modelCollection)->sum('value'), 12),
            'distillation_word_count' => $this->metric((int) $this->scope(KeywordQuestionVariant::query(), $context)->count(), 20),
            'new_distillation_word_count' => $this->metric((int) $this->scope(KeywordQuestionVariant::query(), $context)->whereDate('created_at', now()->toDateString())->count(), 3),
            'search_report_count' => $this->metric($searchReportCount, 10),
            'platform_count' => $this->metric($platformCount, 5),
            'source_count' => $this->metric((int) $this->scope(BrandDiagnosisSource::query(), $context)->count(), 10),
        ];

        $report = [
            'context' => $this->reportContext($context, $companyName),
            'summary' => $summary,
            'model_collection' => $modelCollection,
            'metrics' => $this->hasActualSummaryData($summary) ? $this->enterpriseMetrics($context, $modelCollection, $platformCount) : [],
            'distillation_words' => $distillationWords,
            'platform_filters' => $this->platformFilters($searchRows),
            'trend' => $articleTrend,
            'search_rows' => $searchRows,
            'has_xueshuyi_static_search_rows' => $isXueshuyiBrand,
        ];

        return $this->sanitizeReportPayload($report);
    }

    /**
     * @return array<string,mixed>
     */
    public function industryReport(Admin $admin, ?Site $site = null): array
    {
        $context = $this->resolveContext($admin, $site);
        $companyName = $this->resolveCompanyName($context);
        $platforms = $this->platformAnalysis($context);
        $competitors = $this->competitors($context);
        $sentiment = $this->sentiment($context);
        $sourceCount = $this->distinctSourceCount($context);

        $report = [
            'context' => $this->reportContext($context, $companyName),
            'summary' => [
                $this->metric((int) $this->scope(KeywordQuestionVariant::query(), $context)->count(), 20) + ['label' => '蒸馏词数量(个)'],
                $this->metric((int) $this->scope(BrandDiagnosisResult::query(), $context)->where('status', 'success')->count(), 10) + ['label' => 'AI搜索竞争力分析数量(次)'],
                $this->metric($this->distinctPlatforms($context)->count(), 5) + ['label' => '覆盖AI平台'],
                $this->metric($sourceCount, 10) + ['label' => '引用信源平台数(个)'],
            ],
            'brand_profile' => $this->brandProfile($context, $companyName),
            'overall' => $this->overallTopRankShare($context),
            'platforms' => $platforms,
            'competitors' => $competitors,
            'sentiment' => $sentiment,
        ];

        return $this->sanitizeReportPayload($report);
    }

    /**
     * @return array{admin:Admin,site:?Site,site_id:?int,owner_admin_id:?int}
     */
    private function resolveContext(Admin $admin, ?Site $site): array
    {
        $site ??= app(CurrentSite::class)->get();

        if (! $site instanceof Site) {
            $site = $admin->sites()->orderBy('sites.id')->first();
        }

        if (! $site instanceof Site && $admin->isSuperAdmin()) {
            $site = Site::query()->orderBy('id')->first();
        }

        $ownerAdminId = (int) ($site?->owner_admin_id ?: 0);
        if ($ownerAdminId <= 0 && ($admin->isDirectAdmin() || $admin->isSiteUser() || ! $admin->isAgentAdmin())) {
            $ownerAdminId = (int) $admin->id;
        }

        return [
            'admin' => $admin,
            'site' => $site,
            'site_id' => $site instanceof Site ? (int) $site->id : null,
            'owner_admin_id' => $ownerAdminId > 0 ? $ownerAdminId : null,
        ];
    }

    /**
     * @param  array{site:?Site,site_id:?int,owner_admin_id:?int}  $context
     */
    private function scope(Builder $query, array $context, string $siteColumn = 'site_id', string $ownerColumn = 'owner_admin_id'): Builder
    {
        if ($context['site_id'] !== null) {
            $query->where($siteColumn, (int) $context['site_id']);
        }

        if ($context['owner_admin_id'] !== null) {
            $query->where($ownerColumn, (int) $context['owner_admin_id']);
        }

        if ($context['site_id'] === null && $context['owner_admin_id'] === null) {
            $query->whereRaw('1 = 0');
        }

        $model = $query->getModel();
        if (
            $model instanceof BrandDiagnosisResult
            || $model instanceof BrandDiagnosisSource
            || $model instanceof BrandDiagnosisBrandMention
        ) {
            $query->whereHas('run', fn (Builder $runQuery): Builder => $runQuery->withoutGlobalScopes(['current_site', 'admin_owner']));
        }

        return $query;
    }

    /**
     * @param  array{admin:Admin,site:?Site,site_id:?int,owner_admin_id:?int}  $context
     */
    private function resolveCompanyName(array $context): string
    {
        $libraryCompanies = $this->scope(KeywordLibrary::query(), $context)
            ->whereNotNull('company_name')
            ->where('company_name', '<>', '')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->select('company_name')
            ->cursor();

        foreach ($libraryCompanies as $libraryCompany) {
            $rawCompanyName = (string) $libraryCompany->company_name;
            $companyName = $this->cleanText($rawCompanyName);
            if ($companyName !== '' && ! $this->isInvalidCompanyNameValue($rawCompanyName, $companyName)) {
                return $companyName;
            }
        }

        return $this->cleanText((string) ($context['admin']->display_name ?: $context['admin']->name));
    }

    private function isInvalidCompanyNameValue(string $rawValue, string $cleanValue): bool
    {
        return $this->containsReplacementMarker($rawValue) && mb_strlen($cleanValue) <= 3;
    }

    private function containsReplacementMarker(string $value): bool
    {
        return str_contains($value, '?')
            || str_contains($value, '？')
            || str_contains($value, "\u{FFFD}");
    }

    private function cleanText(string $value): string
    {
        $value = $this->normalizeUtf8Text($value);
        $value = preg_replace('/\x{FFFD}+/u', '', $value) ?? $value;
        $value = preg_replace('/(?<=\p{Han})[?？]+(?=$|[\s,，、。；;:\-]|\p{Han})/u', '', $value) ?? $value;
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        $value = preg_replace('/^[：:，,。；;?？]+|[：:，,。；;?？]+$/u', '', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param  array{admin:Admin,site:?Site,site_id:?int,owner_admin_id:?int}  $context
     * @return array<string,string>
     */
    private function reportContext(array $context, string $companyName): array
    {
        return [
            'company_name' => $companyName,
            'site_name' => $context['site'] instanceof Site ? (string) $context['site']->name : '',
            'date' => now()->format('Y-m-d'),
            'updated_at' => now()->format('Y-m-d H:i'),
        ];
    }

    private function normalizeUtf8Text(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if (is_string($converted)) {
            return $converted;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sanitizeReportPayload(mixed $value): mixed
    {
        if (is_string($value)) {
            $value = $this->normalizeUtf8Text($value);

            return preg_replace('/\x{FFFD}+/u', '', $value) ?? $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $sanitized = [];
        foreach ($value as $key => $item) {
            $sanitized[$key] = $this->sanitizeReportPayload($item);
        }

        return $sanitized;
    }

    /**
     * @return array{actual:int,display:int,is_polished:bool}
     */
    private function metric(int $actual, int $visualMinimum = 0): array
    {
        $display = $actual > 0 ? max($actual, $visualMinimum) : 0;

        return [
            'actual' => $actual,
            'display' => $display,
            'is_polished' => $display !== $actual,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<array{name:string,value:int}>
     */
    private function modelCollection(array $context): array
    {
        $checkedPlatforms = $this->checkedInclusionPlatforms($context);
        $collected = $this->scope(GeoInclusionCheckResult::query(), $context)
            ->where('status', 'success')
            ->select('platform')
            ->selectRaw('COUNT(*) as result_count')
            ->groupBy('platform')
            ->orderByDesc('result_count')
            ->orderBy('platform')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->platform => (int) $row->result_count]);

        if ($checkedPlatforms->isEmpty() && $collected->isEmpty()) {
            return [];
        }

        return collect(self::DEFAULT_MODEL_PLATFORMS)
            ->merge($checkedPlatforms)
            ->merge($collected->keys())
            ->unique()
            ->map(fn (string $platform): array => [
                'name' => $this->modelPlatformLabel($platform),
                'value' => (int) ($collected[$platform] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $context
     * @return Collection<int,string>
     */
    private function checkedInclusionPlatforms(array $context): Collection
    {
        return $this->scope(GeoInclusionCheckRun::query(), $context)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['platforms'])
            ->flatMap(fn (GeoInclusionCheckRun $run): array => (array) $run->platforms)
            ->map(static fn (mixed $platform): string => strtolower(trim((string) $platform)))
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  list<array{name:string,value:int,key:string}>  $modelCollection
     * @return list<array<string,mixed>>
     */
    private function enterpriseMetrics(array $context, array $modelCollection, int $platformCount): array
    {
        $total = (int) collect($modelCollection)->sum('value');
        $todayCollectionTotal = (int) $this->scope(GeoInclusionCheckResult::query(), $context)
            ->where('status', 'success')
            ->whereDate('checked_at', now()->toDateString())
            ->count();
        $yesterdayCollectionTotal = (int) $this->scope(GeoInclusionCheckResult::query(), $context)
            ->where('status', 'success')
            ->whereDate('checked_at', now()->subDay()->toDateString())
            ->count();
        $distillationWords = (int) $this->scope(KeywordQuestionVariant::query(), $context)->count();
        $newWords = (int) $this->scope(KeywordQuestionVariant::query(), $context)
            ->whereDate('created_at', now()->toDateString())
            ->count();
        $thirtyDayDistillationWords = (int) $this->scope(KeywordQuestionVariant::query(), $context)
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->count();
        $thirtyDayNewWords = (int) $this->scope(KeywordQuestionVariant::query(), $context)
            ->whereBetween('created_at', [now()->subDays(29)->startOfDay(), now()->endOfDay()])
            ->whereDate('created_at', now()->toDateString())
            ->count();
        $siteJumpSourceCount = (int) $this->scope(BrandDiagnosisSource::query(), $context)
            ->where('source_type', 'url_citation')
            ->count();
        $contactSourceCount = (int) $this->scope(BrandDiagnosisSource::query(), $context)
            ->whereIn('source_type', ['contact', 'contact_info', 'contact_citation'])
            ->count();
        $sourceCount = $siteJumpSourceCount + $contactSourceCount;

        return [
            [
                'label' => 'AI大模型排名收录总量',
                'value' => $total,
                'actual' => $total,
                'sub_items' => [
                    ['label' => '今日新增', 'value' => $todayCollectionTotal],
                    ['label' => '较昨日', 'value' => $total - $yesterdayCollectionTotal],
                ],
                'accent' => '#f08b35',
            ],
            [
                'label' => 'AI搜索词数量',
                'value' => $distillationWords,
                'actual' => $distillationWords,
                'secondary_label' => '新增词数量',
                'secondary_value' => $newWords,
                'sub_items' => [
                    ['label' => '较30日', 'value' => $thirtyDayDistillationWords],
                    ['label' => '较30日', 'value' => $thirtyDayNewWords],
                ],
                'accent' => '#0aa8ff',
            ],
            [
                'label' => '收录AI平台数量',
                'value' => $platformCount,
                'actual' => $platformCount,
                'sub_items' => [
                    ['label' => '总平台数', 'value' => count(self::DEFAULT_MODEL_PLATFORMS)],
                ],
                'accent' => '#8c52ff',
            ],
            [
                'label' => 'AI搜索转化方式收录总量',
                'value' => $siteJumpSourceCount,
                'actual' => $sourceCount,
                'secondary_label' => '联系方式曝光',
                'secondary_value' => $contactSourceCount,
                'value_labels' => ['站内跳转曝光', '联系方式曝光'],
                'accent' => '#17d9a2',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<array{word:string,size:int,tone:string}>
     */
    private function distillationWords(array $context): array
    {
        $tones = ['strong', 'soft', 'pale'];

        return $this->scope(KeywordQuestionVariant::query(), $context)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(24)
            ->get(['question'])
            ->map(fn (KeywordQuestionVariant $variant, int $index): array => [
                'word' => (string) $variant->question,
                'size' => 13 + ($index % 4),
                'tone' => $tones[$index % count($tones)],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string,mixed>>  $searchRows
     * @return list<array{name:string,terminal:string,total:int,key:string,platform_key:string}>
     */
    private function platformFilters(array $searchRows): array
    {
        $counts = collect($searchRows)
            ->groupBy(fn (array $row): string => $this->normalizePlatformKey((string) ($row['platform_key'] ?? $row['platform'] ?? '')).'|'.(string) ($row['terminal'] ?? 'PC'))
            ->map(fn (Collection $group): int => $group->count());

        $items = [[
            'key' => 'all',
            'platform_key' => 'all',
            'name' => '全部',
            'terminal' => '全部',
            'total' => count($searchRows),
        ]];

        foreach (self::DEFAULT_SEARCH_REPORT_PLATFORMS as $platform) {
            foreach (self::SEARCH_REPORT_TERMINALS as $terminal) {
                $name = $this->platformLabel($platform);
                $items[] = [
                    'key' => $name.'-'.$terminal,
                    'platform_key' => $platform,
                    'name' => $name,
                    'terminal' => $terminal,
                    'total' => (int) ($counts[$platform.'|'.$terminal] ?? 0),
                ];
            }
        }

        return $items;
    }

    /**
     * @param  array<string,array{actual:int,display:int,is_polished:bool}>  $summary
     */
    private function hasActualSummaryData(array $summary): bool
    {
        return collect($summary)->contains(fn (array $metric): bool => (int) $metric['actual'] > 0);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{last_7:list<array{date:string,created:int,published:int}>,last_30:list<array{date:string,created:int,published:int}>}
     */
    private function articleTrend(array $context): array
    {
        $articles = $this->scope(Article::query(), $context)
            ->where('created_at', '>=', now()->subDays(29)->startOfDay())
            ->get(['created_at', 'published_at', 'status']);
        $displayOverride = $this->articleTrendDisplayOverride($context);

        return [
            'last_7' => $this->applyArticleTrendDisplayOverride($this->articleTrendForDays($articles, 7), $displayOverride),
            'last_30' => $this->applyArticleTrendDisplayOverride($this->articleTrendForDays($articles, 30), $displayOverride),
        ];
    }

    /**
     * @param  Collection<int,Article>  $articles
     * @return list<array{date:string,created:int,published:int}>
     */
    private function articleTrendForDays(Collection $articles, int $days): array
    {
        $rows = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->toDateString();
            $rows[] = [
                'date' => $date,
                'created' => $articles->filter(fn (Article $article): bool => $article->created_at?->toDateString() === $date)->count(),
                'published' => $articles->filter(fn (Article $article): bool => $article->published_at?->toDateString() === $date && (string) $article->status === 'published')->count(),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{mobile:string,date_from:string,date_to:string,min:int,max:int}|null
     */
    private function articleTrendDisplayOverride(array $context): ?array
    {
        $mobiles = $this->contextMobileNumbers($context);

        foreach (self::ARTICLE_TREND_DISPLAY_OVERRIDES as $override) {
            $mobile = $this->normalizeMobile((string) $override['mobile']);
            if ($mobile !== '' && in_array($mobile, $mobiles, true)) {
                return [
                    'mobile' => $mobile,
                    'date_from' => (string) $override['date_from'],
                    'date_to' => (string) $override['date_to'],
                    'min' => (int) $override['min'],
                    'max' => (int) $override['max'],
                ];
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<string>
     */
    private function contextMobileNumbers(array $context): array
    {
        $mobiles = [];

        $admin = $context['admin'] ?? null;
        if ($admin instanceof Admin) {
            $mobiles[] = $this->normalizeMobile((string) ($admin->mobile ?? ''));
            $mobiles[] = $this->normalizeMobile((string) ($admin->username ?? ''));
        }

        $ownerAdminId = (int) ($context['owner_admin_id'] ?? 0);
        if ($ownerAdminId > 0 && (! $admin instanceof Admin || $ownerAdminId !== (int) $admin->id)) {
            $owner = Admin::query()->whereKey($ownerAdminId)->first(['id', 'mobile', 'username']);
            if ($owner instanceof Admin) {
                $mobiles[] = $this->normalizeMobile((string) ($owner->mobile ?? ''));
                $mobiles[] = $this->normalizeMobile((string) ($owner->username ?? ''));
            }
        }

        return collect($mobiles)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeMobile(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    /**
     * @param  list<array{date:string,created:int,published:int}>  $rows
     * @param  array{mobile:string,date_from:string,date_to:string,min:int,max:int}|null  $override
     * @return list<array{date:string,created:int,published:int}>
     */
    private function applyArticleTrendDisplayOverride(array $rows, ?array $override): array
    {
        if ($override === null) {
            return $rows;
        }

        return array_map(function (array $row) use ($override): array {
            $date = (string) $row['date'];
            if ($date < $override['date_from'] || $date > $override['date_to']) {
                return $row;
            }

            $row['created'] = $this->stableArticleTrendOverrideCount($override['mobile'], $date, 'created', $override['min'], $override['max']);
            $row['published'] = $this->stableArticleTrendOverrideCount($override['mobile'], $date, 'published', $override['min'], $override['max']);

            return $row;
        }, $rows);
    }

    private function stableArticleTrendOverrideCount(string $mobile, string $date, string $metric, int $min, int $max): int
    {
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }

        $range = max(1, $max - $min + 1);
        $hash = (int) sprintf('%u', crc32($mobile.'|'.$date.'|'.$metric));

        return $min + ($hash % $range);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<array<string,mixed>>
     */
    private function searchRows(array $context, string $companyName): array
    {
        $articles = $this->scope(Article::query(), $context)
            ->where('status', 'published')
            ->orderByDesc('published_at')
            ->limit(30)
            ->get(['id', 'title', 'slug', 'content', 'keywords', 'published_at']);

        return $this->scope(BrandDiagnosisResult::query(), $context)
            ->select([
                'id',
                'run_id',
                'question_id',
                'platform',
                'answer',
                'status',
                'checked_at',
                'created_at',
                'snapshot_token',
                'official_share_url',
            ])
            ->with([
                'brandMentions' => fn ($query) => $query->where('is_target_brand', true)->orderBy('mention_rank'),
                'question:id,question',
                'run:id,brand_name',
                'sources:id,result_id,title,url,domain,platform',
            ])
            ->where('status', 'success')
            ->whereNotNull('answer')
            ->where('answer', '<>', '')
            ->orderByDesc('checked_at')
            ->orderByDesc('id')
            ->limit(80)
            ->get()
            ->map(function (BrandDiagnosisResult $result) use ($articles, $companyName): array {
                $question = (string) ($result->question?->question ?? '');
                $relatedArticles = $this->relatedArticles($articles, $question, $companyName);
                $target = $this->searchRowTargetBrandName($result, $companyName);
                $checkedAt = $result->checked_at ?? $result->created_at;
                $officialShareUrl = trim((string) ($result->official_share_url ?? ''));
                $snapshotToken = trim((string) ($result->snapshot_token ?? ''));
                $hasTargetBrandMention = $this->hasTargetBrandMention($result, $target);

                return [
                    'id' => (int) $result->id,
                    'question' => $question,
                    'platform_key' => (string) $result->platform,
                    'platform' => $this->platformLabel((string) $result->platform),
                    'platform_url' => $this->platformUrl((string) $result->platform),
                    'terminal' => 'PC',
                    'date' => $checkedAt?->format('Y-m-d') ?? '',
                    'time' => $checkedAt?->format('Y-m-d H:i:s') ?? '',
                    'target' => $target,
                    'answer' => strip_tags((string) $result->answer),
                    'sources' => $result->sources->map(fn ($source): array => [
                        'title' => (string) $source->title,
                        'url' => (string) $source->url,
                        'domain' => (string) $source->domain,
                    ])->values()->all(),
                    'related_articles' => $relatedArticles,
                    'official_url' => $this->httpUrlOrEmpty($officialShareUrl),
                    'snapshot_url' => $snapshotToken !== '' && $hasTargetBrandMention
                        ? route('admin.snapshot-voucher.show', ['id' => (int) $result->id])
                        : '',
                ];
            })
            ->values()
            ->all();
    }

    private function isXueshuyiBrand(string $companyName): bool
    {
        return str_contains(preg_replace('/\s+/u', '', $companyName) ?? $companyName, '学术易');
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function xueshuyiStaticSearchRows(): array
    {
        $baseTime = now()->startOfDay()->setTime(10, 30);

        return collect(VirtualSearchReportSnapshots::all())
            ->values()
            ->map(function (array $snapshot, int $index) use ($baseTime): array {
                $checkedAt = $baseTime->copy()->addMinutes($index);
                $snapshotId = (int) $snapshot['id'];

                return [
                    'id' => $snapshotId,
                    'question' => (string) $snapshot['question'],
                    'platform_key' => BrandDiagnosisPlatform::WENXIN,
                    'platform' => BrandDiagnosisPlatform::label(BrandDiagnosisPlatform::WENXIN),
                    'platform_url' => BrandDiagnosisPlatform::chatUrl(BrandDiagnosisPlatform::WENXIN),
                    'terminal' => 'PC',
                    'date' => $checkedAt->toDateString(),
                    'time' => $checkedAt->format('Y-m-d H:i:s'),
                    'target' => '学术易',
                    'answer' => (string) $snapshot['answer'],
                    'sources' => collect((array) ($snapshot['sources'] ?? []))
                        ->map(static fn (array $source): array => [
                            'title' => (string) ($source['title'] ?? ''),
                            'url' => (string) ($source['url'] ?? ''),
                            'domain' => (string) (parse_url((string) ($source['url'] ?? ''), PHP_URL_HOST) ?: ''),
                        ])
                        ->values()
                        ->all(),
                    'related_articles' => [],
                    'official_url' => (string) $snapshot['url'],
                    'snapshot_url' => route('admin.snapshot-voucher.show', ['id' => $snapshotId]),
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int,Article>  $articles
     * @return list<array{id:int,title:string,published_at:string,url:string}>
     */
    private function relatedArticles(Collection $articles, string $question, string $companyName): array
    {
        $needles = collect([$companyName, $question])
            ->filter(fn (string $value): bool => trim($value) !== '')
            ->map(fn (string $value): string => mb_strtolower($value))
            ->values();

        return $articles
            ->filter(function (Article $article) use ($needles): bool {
                $haystack = mb_strtolower((string) $article->title.' '.(string) $article->content.' '.(string) $article->keywords);

                return $needles->contains(fn (string $needle): bool => $needle !== '' && str_contains($haystack, $needle));
            })
            ->take(3)
            ->map(fn (Article $article): array => [
                'id' => (int) $article->id,
                'title' => (string) $article->title,
                'published_at' => $article->published_at?->format('Y-m-d') ?? '',
                'url' => $this->articlePublicUrl($article),
            ])
            ->values()
            ->all();
    }

    private function searchRowTargetBrandName(BrandDiagnosisResult $result, string $fallback): string
    {
        $brandName = $this->cleanText((string) ($result->run?->brand_name ?? ''));

        if ($brandName !== '') {
            return $brandName;
        }

        return $fallback !== '' ? $fallback : '-';
    }

    private function hasTargetBrandMention(BrandDiagnosisResult $result, string $targetBrand): bool
    {
        $targetBrand = $this->normalizedBrandText($targetBrand);
        if ($targetBrand === '') {
            return false;
        }

        $answer = $this->normalizedBrandText((string) $result->answer);
        if ($answer !== '' && str_contains($answer, $targetBrand)) {
            return true;
        }

        return $result->relationLoaded('brandMentions')
            && $result->brandMentions->contains(function (BrandDiagnosisBrandMention $mention) use ($targetBrand): bool {
                $mentionBrand = $this->normalizedBrandText((string) $mention->brand_name);

                return $mentionBrand !== ''
                    && (str_contains($mentionBrand, $targetBrand) || str_contains($targetBrand, $mentionBrand));
            });
    }

    private function normalizedBrandText(string $value): string
    {
        $value = $this->cleanText($value);
        $value = preg_replace('/\s+/u', '', $value) ?? $value;

        return mb_strtolower($value, 'UTF-8');
    }

    private function httpUrlOrEmpty(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array($scheme, ['http', 'https'], true)
            ? $url
            : '';
    }

    private function articlePublicUrl(Article $article): string
    {
        if (trim((string) $article->slug) === '') {
            return '';
        }

        return route('site.article', ['slug' => $article->slug]);
    }

    private function platformUrl(string $platform): string
    {
        return match ($this->normalizePlatformKey($platform)) {
            'deepseek' => 'https://chat.deepseek.com/',
            'doubao' => 'https://www.doubao.com/chat/',
            'yuanbao' => 'https://yuanbao.tencent.com/',
            'wenxin' => 'https://chat.baidu.com/',
            'qianwen' => 'https://tongyi.aliyun.com/qianwen/',
            default => '',
        };
    }

    /**
     * @param  array<string,mixed>  $context
     * @return Collection<int,string>
     */
    private function distinctPlatforms(array $context): Collection
    {
        $geoPlatforms = $this->scope(GeoInclusionCheckResult::query(), $context)
            ->where('status', 'success')
            ->distinct()
            ->pluck('platform');
        $diagnosisPlatforms = $this->scope(BrandDiagnosisResult::query(), $context)
            ->where('status', 'success')
            ->distinct()
            ->pluck('platform');

        return $geoPlatforms
            ->merge($diagnosisPlatforms)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{company_name:string,brand_names:list<string>,core_services:list<string>,description:string}
     */
    private function brandProfile(array $context, string $companyName): array
    {
        $library = $this->scope(KeywordLibrary::query(), $context)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first(['company_name', 'domain_keyword', 'industry', 'brand_description']);

        $services = collect([
            $library?->domain_keyword,
            $library?->industry,
        ])->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => $this->cleanText($value))
            ->unique()
            ->values()
            ->all();

        return [
            'company_name' => $companyName,
            'brand_names' => collect([$companyName, $library?->company_name])
                ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
                ->map(fn (string $value): string => $this->cleanText($value))
                ->unique()
                ->values()
                ->all(),
            'core_services' => $services,
            'description' => $library instanceof KeywordLibrary ? $this->cleanText((string) $library->brand_description) : '',
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function overallTopRankShare(array $context): array
    {
        $totalResults = (int) $this->scope(BrandDiagnosisResult::query(), $context)
            ->where('status', 'success')
            ->count();
        $topFive = (int) $this->scope(BrandDiagnosisBrandMention::query(), $context)
            ->where('is_target_brand', true)
            ->whereBetween('mention_rank', [1, 5])
            ->count();

        return [
            'top5_count' => $topFive,
            'top5_rate' => $this->rate($topFive, $totalResults),
            'top_rank_rates' => $this->topRankRates($context),
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<array<string,mixed>>
     */
    private function platformAnalysis(array $context): array
    {
        $results = $this->scope(BrandDiagnosisResult::query(), $context)
            ->where('status', 'success')
            ->get(['id', 'platform', 'sentiment'])
            ->map(fn (BrandDiagnosisResult $result): array => [
                'platform_key' => $this->normalizePlatformKey((string) $result->platform),
                'sentiment' => (string) $result->sentiment,
            ]);
        $mentions = $this->scope(BrandDiagnosisBrandMention::query(), $context)
            ->where('is_target_brand', true)
            ->get(['platform', 'mention_rank'])
            ->map(fn (BrandDiagnosisBrandMention $mention): array => [
                'platform_key' => $this->normalizePlatformKey((string) $mention->platform),
                'mention_rank' => (int) $mention->mention_rank,
            ]);
        $sources = $this->scope(BrandDiagnosisSource::query(), $context)
            ->get(['platform', 'domain', 'url'])
            ->map(fn (BrandDiagnosisSource $source): array => [
                'platform_key' => $this->normalizePlatformKey((string) $source->platform),
                'domain' => (string) $source->domain,
                'url' => (string) $source->url,
            ]);

        return $this->industryPlatformKeys($results)
            ->map(function (string $platform) use ($results, $mentions, $sources): array {
                $platformResults = $results->where('platform_key', $platform);
                $total = $platformResults->count();
                $platformMentions = $mentions->where('platform_key', $platform);
                $platformSources = $sources->where('platform_key', $platform);

                return [
                    'platform_key' => $platform,
                    'platform' => $this->platformLabel($platform),
                    'analysis_count' => $total,
                    'top_rank_rates' => $this->rankRatesForMentions($platformMentions, $total),
                    'positive_sentiment_rate' => $this->rate($platformResults->where('sentiment', 'positive')->count(), $total),
                    'source_count' => $platformSources
                        ->map(fn (array $source): string => (string) ($source['domain'] ?: $source['url']))
                        ->filter()
                        ->unique()
                        ->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<array<string,mixed>>
     */
    private function competitors(array $context): array
    {
        $resultTotals = $this->scope(BrandDiagnosisResult::query(), $context)
            ->where('status', 'success')
            ->get(['platform'])
            ->map(fn (BrandDiagnosisResult $result): string => $this->normalizePlatformKey((string) $result->platform))
            ->filter()
            ->countBy();
        $platformKeys = collect($resultTotals->keys())
            ->merge(self::DEFAULT_SEARCH_REPORT_PLATFORMS)
            ->unique()
            ->values();

        return $this->scope(BrandDiagnosisBrandMention::query(), $context)
            ->where('is_target_brand', false)
            ->get(['brand_name', 'platform', 'mention_count', 'mention_rank'])
            ->map(fn (BrandDiagnosisBrandMention $mention): array => [
                'brand_name' => (string) $mention->brand_name,
                'platform_key' => $this->normalizePlatformKey((string) $mention->platform),
                'mention_count' => (int) $mention->mention_count,
                'mention_rank' => (int) $mention->mention_rank,
            ])
            ->groupBy('brand_name')
            ->map(function (Collection $mentions, string $brandName) use ($platformKeys, $resultTotals): array {
                $platforms = $platformKeys
                    ->map(function (string $platform) use ($mentions, $resultTotals): array {
                        $platformMentions = $mentions->where('platform_key', $platform);
                        $mentionRows = $platformMentions->count();
                        $totalResults = (int) ($resultTotals[$platform] ?? 0);

                        return [
                            'platform_key' => $platform,
                            'platform' => $this->platformLabel($platform),
                            'mention_count' => (int) $platformMentions->sum('mention_count'),
                            'best_rank' => $mentionRows > 0 ? (int) $platformMentions->min('mention_rank') : 0,
                            'rate' => $this->rate($mentionRows, $totalResults),
                        ];
                    })
                    ->values();

                return [
                    'brand_name' => $brandName,
                    'mention_count' => (int) $mentions->sum('mention_count'),
                    'best_rank' => (int) $mentions->min('mention_rank'),
                    'platforms' => $platforms->all(),
                    'platform_rates' => $platforms
                        ->mapWithKeys(fn (array $platform): array => [
                            (string) $platform['platform_key'] => (float) $platform['rate'],
                        ])
                        ->all(),
                ];
            })
            ->sortByDesc('mention_count')
            ->values()
            ->take(10)
            ->all();
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private function sentiment(array $context): array
    {
        $results = $this->scope(BrandDiagnosisResult::query(), $context)
            ->where('status', 'success')
            ->get(['platform', 'sentiment'])
            ->map(fn (BrandDiagnosisResult $result): array => [
                'platform_key' => $this->normalizePlatformKey((string) $result->platform),
                'sentiment' => (string) $result->sentiment,
            ]);
        $total = $results->count();

        return [
            'overall' => [
                'positive_rate' => $this->rate($results->where('sentiment', 'positive')->count(), $total),
                'neutral_rate' => $this->rate($results->where('sentiment', 'neutral')->count(), $total),
                'negative_rate' => $this->rate($results->where('sentiment', 'negative')->count(), $total),
            ],
            'platforms' => $this->industryPlatformKeys($results)
                ->map(function (string $platform) use ($results): array {
                    $platformResults = $results->where('platform_key', $platform);
                    $total = $platformResults->count();

                    return [
                        'platform_key' => $platform,
                        'platform' => $this->platformLabel($platform),
                        'positive_rate' => $this->rate($platformResults->where('sentiment', 'positive')->count(), $total),
                        'neutral_rate' => $this->rate($platformResults->where('sentiment', 'neutral')->count(), $total),
                        'negative_rate' => $this->rate($platformResults->where('sentiment', 'negative')->count(), $total),
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int,array{platform_key:string}>  $rows
     * @return Collection<int,string>
     */
    private function industryPlatformKeys(Collection $rows): Collection
    {
        return $rows
            ->groupBy('platform_key')
            ->map(fn (Collection $platformRows): int => $platformRows->count())
            ->sortDesc()
            ->keys()
            ->merge(self::DEFAULT_SEARCH_REPORT_PLATFORMS)
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{top1:float,top2:float,top3:float,top4:float,top5:float}
     */
    private function topRankRates(array $context): array
    {
        $total = (int) $this->scope(BrandDiagnosisResult::query(), $context)
            ->where('status', 'success')
            ->count();
        $mentions = $this->scope(BrandDiagnosisBrandMention::query(), $context)
            ->where('is_target_brand', true)
            ->get(['mention_rank']);

        return $this->rankRatesForMentions($mentions, $total);
    }

    /**
     * @param  Collection<int,BrandDiagnosisBrandMention>  $mentions
     * @return array{top1:float,top2:float,top3:float,top4:float,top5:float}
     */
    private function rankRatesForMentions(Collection $mentions, int $total): array
    {
        $rates = [];
        for ($rank = 1; $rank <= 5; $rank++) {
            $rates['top'.$rank] = $this->rate($mentions->where('mention_rank', $rank)->count(), $total);
        }

        return $rates;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function distinctSourceCount(array $context): int
    {
        return $this->scope(BrandDiagnosisSource::query(), $context)
            ->get(['domain', 'url'])
            ->map(fn ($source): string => (string) ($source->domain ?: $source->url))
            ->filter()
            ->unique()
            ->count();
    }

    private function rate(int $part, int $total): float
    {
        return $total > 0 ? round($part * 100 / $total, 2) : 0.0;
    }

    private function modelPlatformLabel(string $platform): string
    {
        return match (strtolower($platform)) {
            'yuanbao', 'tencent_yuanbao' => '元宝',
            default => $this->platformLabel($platform),
        };
    }

    private function platformLabel(string $platform): string
    {
        return match (strtolower($platform)) {
            'doubao' => '豆包',
            'deepseek' => 'DeepSeek',
            'yuanbao', 'tencent_yuanbao' => '腾讯元宝',
            'wenxin', 'ernie' => '文心一言',
            'qianwen', 'tongyi' => '千问',
            'kimi' => 'Kimi',
            'xinghuo', 'spark' => '讯飞星火',
            'baidu_ai' => '百度AI',
            default => $platform,
        };
    }

    private function normalizePlatformKey(string $platform): string
    {
        return match (strtolower(trim($platform))) {
            'tencent_yuanbao' => 'yuanbao',
            'ernie' => 'wenxin',
            'tongyi' => 'qianwen',
            default => strtolower(trim($platform)),
        };
    }
}
