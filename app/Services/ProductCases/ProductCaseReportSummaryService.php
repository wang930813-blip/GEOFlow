<?php

namespace App\Services\ProductCases;

use App\Models\Admin;
use App\Models\BrandDiagnosisResult;
use App\Models\KeywordQuestionVariant;
use App\Models\ProductCase;
use App\Models\Site;
use App\Services\MonitoringCenter\MonitoringReportDataService;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class ProductCaseReportSummaryService
{
    public function __construct(private readonly MonitoringReportDataService $reports) {}

    /**
     * @return list<array{label:string,value:int}>
     */
    public function cardMetrics(ProductCase $case): array
    {
        if ((int) $case->site_id <= 0 || (int) $case->owner_admin_id <= 0) {
            return [];
        }

        $resultQuery = $this->scopeResultQuery(BrandDiagnosisResult::query(), $case)
            ->where('status', 'success');
        $searchReportCount = (int) (clone $resultQuery)->count();
        $platformCount = (int) (clone $resultQuery)->distinct()->count('platform');
        $distillationWordCount = (int) KeywordQuestionVariant::query()
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('site_id', (int) $case->site_id)
            ->where('owner_admin_id', (int) $case->owner_admin_id)
            ->count();

        return array_values(array_filter([
            ['label' => 'AI Platforms', 'value' => $platformCount],
            ['label' => 'Search Reports', 'value' => $searchReportCount],
            ['label' => 'Question Terms', 'value' => $distillationWordCount],
        ], static fn (array $metric): bool => (int) $metric['value'] > 0));
    }

    /**
     * @return array<string,mixed>
     */
    public function detail(ProductCase $case): array
    {
        $site = $case->site;
        $owner = $case->owner;

        if (! $site instanceof Site || ! $owner instanceof Admin) {
            return $this->empty();
        }

        try {
            $enterprise = $this->reports->enterpriseReport($owner, $site);
            $industry = $this->reports->industryReport($owner, $site);
        } catch (Throwable) {
            return $this->empty();
        }

        $summary = (array) ($enterprise['summary'] ?? []);

        return [
            'summary' => [
                'platform_count' => (int) data_get($summary, 'platform_count.display', 0),
                'search_report_count' => (int) data_get($summary, 'search_report_count.display', 0),
                'distillation_word_count' => (int) data_get($summary, 'distillation_word_count.display', 0),
                'source_count' => (int) data_get($summary, 'source_count.display', 0),
                'metrics' => $this->metrics($summary),
            ],
            'platforms' => array_slice((array) ($industry['platforms'] ?? []), 0, 8),
            'search_rows' => array_slice((array) ($enterprise['search_rows'] ?? []), 0, 8),
            'trend' => (array) ($enterprise['trend'] ?? []),
            'brand_profile' => (array) ($industry['brand_profile'] ?? []),
            'overall' => (array) ($industry['overall'] ?? []),
            'competitors' => array_slice((array) ($industry['competitors'] ?? []), 0, 8),
            'sentiment' => (array) ($industry['sentiment'] ?? []),
        ];
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return list<array{label:string,value:int}>
     */
    private function metrics(array $summary): array
    {
        return [
            ['label' => 'AI Platform Coverage', 'value' => (int) data_get($summary, 'platform_count.display', 0)],
            ['label' => 'Search Report Count', 'value' => (int) data_get($summary, 'search_report_count.display', 0)],
            ['label' => 'AI Search Terms', 'value' => (int) data_get($summary, 'distillation_word_count.display', 0)],
            ['label' => 'Citation Sources', 'value' => (int) data_get($summary, 'source_count.display', 0)],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function empty(): array
    {
        return [
            'summary' => [
                'platform_count' => 0,
                'search_report_count' => 0,
                'distillation_word_count' => 0,
                'source_count' => 0,
                'metrics' => [
                    ['label' => 'AI Platform Coverage', 'value' => 0],
                    ['label' => 'Search Report Count', 'value' => 0],
                    ['label' => 'AI Search Terms', 'value' => 0],
                    ['label' => 'Citation Sources', 'value' => 0],
                ],
            ],
            'platforms' => [],
            'search_rows' => [],
            'trend' => [],
            'brand_profile' => [],
            'overall' => [],
            'competitors' => [],
            'sentiment' => [],
        ];
    }

    private function scopeResultQuery(Builder $query, ProductCase $case): Builder
    {
        return $query
            ->withoutGlobalScopes(['current_site', 'admin_owner'])
            ->where('site_id', (int) $case->site_id)
            ->where('owner_admin_id', (int) $case->owner_admin_id)
            ->whereHas('run', fn ($runQuery) => $runQuery->withoutGlobalScopes(['current_site', 'admin_owner']));
    }
}
