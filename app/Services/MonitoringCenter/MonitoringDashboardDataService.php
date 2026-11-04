<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-02
 *
 * @Time: 17:37:32
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： MonitoringDashboardDataService.php
 *
 * @Description: 为新版监测中心整理现有监测报告和按品牌搜索词筛选的诊断数据。
 */

namespace App\Services\MonitoringCenter;

use App\Models\Admin;
use App\Models\BrandDiagnosisBrandMention;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Models\BrandDiagnosisSource;
use App\Models\Site;
use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use App\Support\CurrentSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class MonitoringDashboardDataService
{
    public function __construct(
        private readonly MonitoringReportDataService $reportDataService
    ) {}

    /**
     * 获取新版监测中心所需的全部只读数据。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:37:32
     *
     * @UpdateTime: 2026-11-03 14:50:35
     *
     * @Param: Admin $admin 当前已认证管理员
     *
     * @Param: Site|null $site 当前站点
     *
     * @Param: array<string,mixed> $filters 诊断记录、平台、问题和日期筛选条件
     *
     * @Return: array<string,mixed> 监测报告、品牌诊断和账号上下文
     *
     * @Throws: 无
     */
    public function build(Admin $admin, ?Site $site = null, array $filters = []): array
    {
        $site = $this->resolveSite($admin, $site);
        $enterprise = $this->reportDataService->enterpriseReport($admin, $site);
        $industry = $this->reportDataService->industryReport($admin, $site);
        $ownerAdminId = $this->ownerAdminId($admin, $site);
        $startDate = is_string($filters['start_date'] ?? null) ? $filters['start_date'] : null;
        $endDate = is_string($filters['end_date'] ?? null) ? $filters['end_date'] : null;
        $records = $this->diagnosisRecords($site, $ownerAdminId, $startDate, $endDate);
        $runId = is_numeric($filters['run_id'] ?? null) ? (int) $filters['run_id'] : null;
        $recordIds = collect($records)->pluck('id')->all();
        if (! is_int($runId) || ! in_array($runId, $recordIds, true)) {
            $runId = isset($records[0]['id']) ? (int) $records[0]['id'] : null;
        }

        $platform = is_string($filters['platform'] ?? null) ? trim($filters['platform']) : '';
        $questionId = is_numeric($filters['question_id'] ?? null) ? (int) $filters['question_id'] : null;
        $latestDiagnosis = $this->latestDiagnosis(
            $site,
            $ownerAdminId,
            $runId,
            $platform !== '' ? $platform : null,
            $questionId
        );
        $diagnosisFilterOptions = (array) ($latestDiagnosis['filter_options'] ?? []);
        $selectedFilters = (array) ($latestDiagnosis['selected_filters'] ?? []);
        if ($latestDiagnosis !== null) {
            unset($latestDiagnosis['filter_options'], $latestDiagnosis['selected_filters']);
        }

        return [
            'context' => $enterprise['context'] ?? [],
            'enterprise' => $enterprise,
            'industry' => $industry,
            'diagnosis_records' => $records,
            'filters' => [
                'run_id' => $latestDiagnosis['id'] ?? null,
                'platform' => $selectedFilters['platform'] ?? '',
                'question_id' => $selectedFilters['question_id'] ?? null,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'platforms' => $diagnosisFilterOptions['platforms'] ?? [],
                'questions' => $diagnosisFilterOptions['questions'] ?? [],
            ],
            'latest_diagnosis' => $latestDiagnosis,
        ];
    }

    /**
     * 解析管理员可访问的当前站点，保证 API 登录没有会话站点时仍使用同一数据范围。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:37:32
     *
     * @UpdateTime: 2026-11-02 18:05:28
     *
     * @Param: Admin $admin 当前已认证管理员
     *
     * @Param: Site|null $site 请求级站点
     *
     * @Return: Site|null 可访问站点
     *
     * @Throws: 无
     */
    private function resolveSite(Admin $admin, ?Site $site): ?Site
    {
        $site ??= app(CurrentSite::class)->get();

        if ($site instanceof Site) {
            return $site;
        }

        $site = $admin->sites()->orderBy('sites.id')->first();
        if ($site instanceof Site) {
            return $site;
        }

        return $admin->isSuperAdmin()
            ? Site::query()->orderBy('id')->first()
            : null;
    }

    /**
     * 读取当前站点和管理员范围内符合日期条件的已完成诊断记录。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-03 14:59:24
     *
     * @UpdateTime: 2026-11-03 14:50:35
     *
     * @Param: Site|null $site 当前站点
     *
     * @Param: int $ownerAdminId 数据所有者管理员 ID
     *
     * @Return: list<array<string,mixed>> 已完成诊断记录和摘要指标
     *
     * @Throws: 无
     */
    private function diagnosisRecords(?Site $site, int $ownerAdminId, ?string $startDate, ?string $endDate): array
    {
        if (! $site instanceof Site && $ownerAdminId <= 0) {
            return [];
        }

        return $this->runQuery($site, $ownerAdminId)
            ->where('status', 'completed')
            ->whereNotNull('brand_name')
            ->where('brand_name', '<>', '')
            ->when($startDate !== null, fn (Builder $query): Builder => $query->whereDate('completed_at', '>=', $startDate))
            ->when($endDate !== null, fn (Builder $query): Builder => $query->whereDate('completed_at', '<=', $endDate))
            ->orderByDesc('completed_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get([
                'id', 'brand_name', 'created_at', 'completed_at', 'brand_score', 'mention_rate',
                'average_rank', 'mention_count', 'sentiment_rate', 'total_questions', 'completed_questions',
            ])
            ->map(fn (BrandDiagnosisRun $run): array => [
                'id' => (int) $run->id,
                'brand' => (string) $run->brand_name,
                'created_at' => $run->created_at?->format('Y-m-d H:i:s') ?? '',
                'completed_at' => $run->completed_at?->format('Y-m-d H:i:s') ?? '',
                'summary' => [
                    'brand_score' => (int) ($run->brand_score ?? 0),
                    'mention_rate' => (int) ($run->mention_rate ?? 0),
                    'average_rank' => (float) ($run->average_rank ?? 0),
                    'mention_count' => (int) ($run->mention_count ?? 0),
                    'sentiment_rate' => (int) ($run->sentiment_rate ?? 0),
                    'total_questions' => (int) ($run->total_questions ?? 0),
                    'completed_questions' => (int) ($run->completed_questions ?? 0),
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * @Name: ownerAdminId
     * @Description: 根据已解析站点和登录账号计算无站点时使用的所有者范围。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-03 14:43:48
     * @UpdateTime: 2026-11-03 14:50:35
     * @Param: Admin $admin 当前已认证管理员
     * @Param: Site|null $site 当前站点
     * @Return int 诊断数据所有者 ID
     * @Throws: 无
     */
    private function ownerAdminId(Admin $admin, ?Site $site): int
    {
        $ownerAdminId = (int) ($site?->owner_admin_id ?: 0);
        if ($ownerAdminId <= 0 && ($admin->isDirectAdmin() || $admin->isSiteUser() || ! $admin->isAgentAdmin())) {
            $ownerAdminId = (int) $admin->id;
        }

        return $ownerAdminId;
    }

    /**
     * 读取当前站点和管理员范围内指定诊断记录，并按平台和问题筛选明细。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:37:32
     *
     * @UpdateTime: 2026-11-03 14:59:24
     *
     * @Param: Site|null $site 当前站点
     *
     * @Param: int $ownerAdminId 数据所有者管理员 ID
     *
     * @Param: int|null $runId 用户选择的诊断记录 ID
     *
     * @Param: string|null $platformKey 用户选择的平台键
     *
     * @Param: int|null $questionId 用户选择的问题 ID
     *
     * @Return: array<string,mixed>|null 品牌诊断摘要和明细
     *
     * @Throws: 无
     */
    private function latestDiagnosis(
        ?Site $site,
        int $ownerAdminId,
        ?int $runId,
        ?string $platformKey,
        ?int $questionId
    ): ?array
    {
        if ((! $site instanceof Site && $ownerAdminId <= 0) || $runId === null) {
            return null;
        }

        $run = $this->runQuery($site, $ownerAdminId)
            ->whereKey($runId)
            ->where('status', 'completed')
            ->with([
                'questions' => fn ($query) => $query
                    ->withoutGlobalScopes(['current_site', 'admin_owner'])
                    ->orderBy('sort_order')
                    ->with([
                        'results' => fn ($resultQuery) => $resultQuery
                            ->withoutGlobalScopes(['current_site', 'admin_owner'])
                            ->with([
                                'sources' => fn ($sourceQuery) => $sourceQuery->withoutGlobalScopes(['current_site', 'admin_owner']),
                                'brandMentions' => fn ($mentionQuery) => $mentionQuery->withoutGlobalScopes(['current_site', 'admin_owner']),
                            ]),
                    ]),
            ])
            ->first();

        if (! $run instanceof BrandDiagnosisRun) {
            return null;
        }

        $questionOptions = $run->questions
            ->map(fn ($question): array => [
                'id' => (int) $question->id,
                'question' => (string) $question->question,
            ])
            ->values()
            ->all();
        $allResults = $run->questions
            ->flatMap(fn ($question): Collection => $question->results)
            ->values();
        $platformOptions = $allResults
            ->map(fn (BrandDiagnosisResult $result): array => [
                'key' => $this->platformKey((string) $result->platform),
                'label' => BrandDiagnosisPlatform::label((string) $result->platform),
            ])
            ->unique('key')
            ->values()
            ->all();
        $availablePlatformKeys = collect($platformOptions)->pluck('key')->all();
        $availableQuestionIds = collect($questionOptions)->pluck('id')->all();
        $selectedPlatform = is_string($platformKey) && in_array($platformKey, $availablePlatformKeys, true)
            ? $platformKey
            : '';
        $selectedQuestionId = is_int($questionId) && in_array($questionId, $availableQuestionIds, true)
            ? $questionId
            : null;
        $selectedQuestions = $run->questions
            ->when($selectedQuestionId !== null, fn (Collection $questions): Collection => $questions
                ->filter(fn ($question): bool => (int) $question->id === $selectedQuestionId))
            ->values();
        $results = $selectedQuestions
            ->flatMap(fn ($question): Collection => $question->results)
            ->when($selectedPlatform !== '', fn (Collection $items): Collection => $items
                ->filter(fn (BrandDiagnosisResult $result): bool => $this->platformKey((string) $result->platform) === $selectedPlatform))
            ->values();

        return [
            'id' => (int) $run->id,
            'brand' => (string) $run->brand_name,
            'status' => (string) $run->status,
            'created_at' => $run->created_at?->format('Y-m-d H:i:s') ?? '',
            'completed_at' => $run->completed_at?->format('Y-m-d H:i:s') ?? '',
            'summary' => $this->summary($results, $selectedQuestions),
            'platforms' => $this->platforms($results),
            'questions' => $this->questions($selectedQuestions, $selectedPlatform),
            'sources' => $this->sources($results),
            'conversations' => $this->conversations($selectedQuestions, $selectedPlatform),
            'citations' => $this->citations($results),
            'filter_options' => [
                'platforms' => $platformOptions,
                'questions' => $questionOptions,
            ],
            'selected_filters' => [
                'platform' => $selectedPlatform,
                'question_id' => $selectedQuestionId,
            ],
        ];
    }

    /**
     * 按现有站点和管理员所有者范围查询品牌诊断任务。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:37:32
     *
     * @UpdateTime: 2026-11-03 14:08:08
     *
     * @Param: Site|null $site 当前站点
     *
     * @Param: int $ownerAdminId 所有者管理员 ID
     *
     * @Return: Builder<BrandDiagnosisRun> 品牌诊断查询
     *
     * @Throws: 无
     */
    private function runQuery(?Site $site, int $ownerAdminId): Builder
    {
        return BrandDiagnosisRun::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->when($site instanceof Site, fn (Builder $query): Builder => $query->where('site_id', (int) $site->id))
            ->when(! ($site instanceof Site) && $ownerAdminId > 0, fn (Builder $query): Builder => $query->where('owner_admin_id', $ownerAdminId));
    }

    /**
     * @Name: summary
     * @Description: 按当前选择的平台和问题重算诊断卡片指标，不混入未筛选的结果。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-03 14:59:24
     * @UpdateTime: 2026-11-03 14:59:24
     * @Param: Collection<int,BrandDiagnosisResult> $results 当前筛选后的结果
     * @Param: Collection<int,BrandDiagnosisQuestion> $questions 当前诊断的问题
     * @Return: array<string,int|float> 当前筛选条件下的诊断指标
     * @Throws: 无
     */
    private function summary(Collection $results, Collection $questions): array
    {
        $successfulResults = $results->where('status', 'success');
        $targetMentions = $successfulResults
            ->flatMap(fn (BrandDiagnosisResult $result): Collection => $result->brandMentions)
            ->filter(fn (BrandDiagnosisBrandMention $mention): bool => (bool) $mention->is_target_brand);
        $totalResults = max(1, $successfulResults->count());

        if ($targetMentions->isEmpty()) {
            $targetResults = $successfulResults->where('brand_mentioned', true);
            $mentionRate = (int) round($targetResults->count() * 100 / $totalResults);
            $mentionCount = (int) $targetResults->sum('mention_count');
            $averageRank = (float) ($targetResults->where('mention_rank', '>', 0)->avg('mention_rank') ?: 0);
            $sentimentRate = $targetResults->isNotEmpty()
                ? (int) round($targetResults->whereIn('sentiment', ['positive', 'neutral'])->count() * 100 / $targetResults->count())
                : 0;
        } else {
            $mentionedResultCount = $targetMentions->pluck('result_id')->filter()->unique()->count();
            $mentionRate = (int) round($mentionedResultCount * 100 / $totalResults);
            $mentionCount = (int) $targetMentions->sum('mention_count');
            $averageRank = (float) ($targetMentions->where('mention_rank', '>', 0)->avg('mention_rank') ?: 0);
            $sentimentRate = (int) round($targetMentions->whereIn('sentiment', ['positive', 'neutral'])->count() * 100 / $targetMentions->count());
        }

        $rankScore = $averageRank > 0 ? max(0, 100 - (($averageRank - 1) * 5)) : 0;
        $brandScore = (int) min(100, round(
            ($mentionRate * 0.75)
            + ($mentionCount * 0.1)
            + ($rankScore * 0.1)
            + ($sentimentRate * 0.05)
        ));

        return [
            'brand_score' => $brandScore,
            'mention_rate' => $mentionRate,
            'average_rank' => round($averageRank, 2),
            'mention_count' => $mentionCount,
            'sentiment_rate' => $sentimentRate,
            'total_questions' => $questions->count(),
            'completed_questions' => $successfulResults->pluck('question_id')->filter()->unique()->count(),
        ];
    }

    /**
     * 汇总最近诊断的平台对话、提及率、平均排名和情绪数据。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:37:32
     *
     * @UpdateTime: 2026-11-02 18:05:28
     *
     * @Param: Collection<int,BrandDiagnosisResult> $results 诊断结果
     *
     * @Return: list<array<string,mixed>> 平台指标
     *
     * @Throws: 无
     */
    private function platforms(Collection $results): array
    {
        return $results
            ->where('status', 'success')
            ->groupBy(fn (BrandDiagnosisResult $result): string => $this->platformKey((string) $result->platform))
            ->map(function (Collection $platformResults, string $platform): array {
                $mentions = $platformResults
                    ->flatMap(fn (BrandDiagnosisResult $result): Collection => $result->brandMentions)
                    ->filter(fn (BrandDiagnosisBrandMention $mention): bool => (bool) $mention->is_target_brand);
                $total = $platformResults->count();
                $mentionedResultCount = $mentions->isNotEmpty()
                    ? $mentions->pluck('result_id')->filter()->unique()->count()
                    : $platformResults->where('brand_mentioned', true)->count();
                $ranked = $mentions->pluck('mention_rank')->filter(fn ($rank): bool => (int) $rank > 0);

                return [
                    'platform_key' => $platform,
                    'platform' => BrandDiagnosisPlatform::label($platform),
                    'total' => $total,
                    'mentioned' => $mentionedResultCount,
                    'mention_rate' => $total > 0 ? (int) round($mentionedResultCount * 100 / $total) : 0,
                    'average_rank' => $ranked->isNotEmpty() ? round((float) $ranked->avg(), 2) : 0,
                    'positive_rate' => $total > 0
                        ? (int) round($platformResults->where('sentiment', 'positive')->count() * 100 / $total)
                        : 0,
                    'neutral_rate' => $total > 0
                        ? (int) round($platformResults->where('sentiment', 'neutral')->count() * 100 / $total)
                        : 0,
                    'negative_rate' => $total > 0
                        ? (int) round($platformResults->where('sentiment', 'negative')->count() * 100 / $total)
                        : 0,
                ];
            })
            ->sortByDesc('total')
            ->values()
            ->all();
    }

    /**
     * 输出当前筛选的问题及所选平台回答状态。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:37:32
     *
     * @UpdateTime: 2026-11-03 14:59:24
     *
     * @Param: Collection<int,BrandDiagnosisQuestion> $questions 当前诊断的问题
     *
     * @Param: string $platformKey 用户选择的平台键，空字符串表示全部平台
     *
     * @Return: list<array<string,mixed>> 问题摘要
     *
     * @Throws: 无
     */
    private function questions(Collection $questions, string $platformKey): array
    {
        return $questions->map(function ($question) use ($platformKey): array {
            $results = $question->results
                ->when($platformKey !== '', fn (Collection $items): Collection => $items
                    ->filter(fn (BrandDiagnosisResult $result): bool => $this->platformKey((string) $result->platform) === $platformKey));
            $successfulResults = $results->where('status', 'success');
            $mentionedResultIds = $successfulResults
                ->filter(function (BrandDiagnosisResult $result): bool {
                    $targetMentions = $result->brandMentions->where('is_target_brand', true);

                    return $targetMentions->isNotEmpty() || (bool) $result->brand_mentioned;
                })
                ->pluck('id')
                ->all();
            $platformRates = $successfulResults
                ->groupBy(fn (BrandDiagnosisResult $result): string => $this->platformKey((string) $result->platform))
                ->map(function (Collection $platformResults): int {
                    $targetMentionRows = $platformResults
                        ->flatMap(fn (BrandDiagnosisResult $result): Collection => $result->brandMentions)
                        ->filter(fn (BrandDiagnosisBrandMention $mention): bool => (bool) $mention->is_target_brand);
                    $mentioned = $targetMentionRows->isNotEmpty()
                        ? $targetMentionRows->pluck('result_id')->filter()->unique()->count()
                        : $platformResults->where('brand_mentioned', true)->count();

                    return $platformResults->isNotEmpty()
                        ? (int) round($mentioned * 100 / $platformResults->count())
                        : 0;
                })
                ->all();

            return [
                'id' => (int) $question->id,
                'rank' => (int) $question->sort_order,
                'question' => (string) $question->question,
                'status' => (string) $question->status,
                'result_count' => $successfulResults->count(),
                'mention_rate' => $successfulResults->isNotEmpty()
                    ? (int) round(count($mentionedResultIds) * 100 / $successfulResults->count())
                    : 0,
                'platform_rates' => $platformRates,
            ];
        })->values()->all();
    }

    /**
     * 聚合诊断回答引用的来源域名。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:37:32
     *
     * @UpdateTime: 2026-11-02 18:05:28
     *
     * @Param: Collection<int,BrandDiagnosisResult> $results 诊断结果
     *
     * @Return: list<array<string,mixed>> 来源明细
     *
     * @Throws: 无
     */
    private function sources(Collection $results): array
    {
        $rows = $results
            ->flatMap(fn (BrandDiagnosisResult $result): Collection => $result->sources)
            ->filter(fn (BrandDiagnosisSource $source): bool => trim((string) ($source->url ?? $source->domain ?? '')) !== '');
        $total = max(1, $rows->count());

        return $rows
            ->groupBy(function (BrandDiagnosisSource $source): string {
                $domain = trim((string) $source->domain);

                return $domain !== '' ? $domain : (string) parse_url((string) $source->url, PHP_URL_HOST);
            })
            ->map(function (Collection $sourceRows, string $domain) use ($total): array {
                $first = $sourceRows->first();

                return [
                    'name' => (string) ($first?->title ?: $domain),
                    'domain' => $domain,
                    'type' => (string) ($first?->source_type ?: '内容'),
                    'citations' => $sourceRows->count(),
                    'share' => round($sourceRows->count() * 100 / $total, 1),
                    'url' => (string) ($first?->url ?? ''),
                ];
            })
            ->sortByDesc('citations')
            ->values()
            ->take(50)
            ->all();
    }

    /**
     * 输出当前筛选的问题和平台对应的对话记录。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:37:32
     *
     * @UpdateTime: 2026-11-03 14:59:24
     *
     * @Param: Collection<int,BrandDiagnosisQuestion> $questions 当前诊断的问题
     *
     * @Param: string $platformKey 用户选择的平台键，空字符串表示全部平台
     *
     * @Return: list<array<string,mixed>> 对话记录
     *
     * @Throws: 无
     */
    private function conversations(Collection $questions, string $platformKey): array
    {
        return $questions
            ->flatMap(function ($question) use ($platformKey): Collection {
                return $question->results
                    ->when($platformKey !== '', fn (Collection $items): Collection => $items
                        ->filter(fn (BrandDiagnosisResult $result): bool => $this->platformKey((string) $result->platform) === $platformKey))
                    ->map(function (BrandDiagnosisResult $result) use ($question): array {
                    return [
                        'question' => (string) $question->question,
                        'platform' => BrandDiagnosisPlatform::label((string) $result->platform),
                        'platform_key' => $this->platformKey((string) $result->platform),
                        'status' => (string) $result->status,
                        'time' => $result->checked_at?->format('Y-m-d H:i') ?? $result->created_at?->format('Y-m-d H:i') ?? '',
                        'rate' => $result->brand_mentioned ? 100 : 0,
                        'answer' => strip_tags((string) $result->answer),
                    ];
                });
            })
            ->sortByDesc('time')
            ->values()
            ->take(100)
            ->all();
    }

    /**
     * 输出可在作品引用页面展示的来源引用记录。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:37:32
     *
     * @UpdateTime: 2026-11-02 18:05:28
     *
     * @Param: Collection<int,BrandDiagnosisResult> $results 诊断结果
     *
     * @Return: list<array<string,mixed>> 引用记录
     *
     * @Throws: 无
     */
    private function citations(Collection $results): array
    {
        return $results
            ->flatMap(function (BrandDiagnosisResult $result): Collection {
                return $result->sources->map(fn (BrandDiagnosisSource $source): array => [
                    'title' => (string) ($source->title ?: $source->url),
                    'category' => (string) ($source->source_type ?: '内容'),
                    'platform' => BrandDiagnosisPlatform::label((string) $result->platform),
                    'url' => (string) $source->url,
                    'time' => $result->checked_at?->format('Y-m-d H:i') ?? '',
                ]);
            })
            ->values()
            ->take(100)
            ->all();
    }

    /**
     * @Name: platformKey
     *
     * @Description: 统一元宝、文心一言和通义千问的平台键，便于监测中心分组展示。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:37:32
     *
     * @UpdateTime: 2026-11-02 18:05:28
     *
     * @Param: string $platform 品牌诊断平台标识
     *
     * @Return: string 新版监测中心平台键
     *
     * @Throws: 无
     */
    private function platformKey(string $platform): string
    {
        return match (strtolower(trim($platform))) {
            'tencent_yuanbao' => 'yuanbao',
            'ernie' => 'wenxin',
            'tongyi' => 'qianwen',
            default => strtolower(trim($platform)),
        };
    }
}
