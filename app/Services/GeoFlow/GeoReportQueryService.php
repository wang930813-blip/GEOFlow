<?php

namespace App\Services\GeoFlow;

use App\Models\GeoInclusionCheckResult;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GeoReportQueryService
{
    /**
     * @return array{
     *   overview: array<string,int|float>,
     *   platforms: list<array<string,int|float|string>>,
     *   keywordRanking: list<array<string,int|float|string>>,
     *   projectRanking: list<array<string,int|float|string>>,
     *   trend: list<array{date:string,checks:int,keyword_hits:int,brand_hits:int,keyword_hit_rate:float,brand_hit_rate:float}>
     * }
     */
    public function build(): array
    {
        return [
            'overview' => $this->buildOverview(),
            'platforms' => $this->buildPlatformDistribution(),
            'keywordRanking' => $this->buildKeywordRanking(),
            'projectRanking' => $this->buildProjectRanking(),
            'trend' => $this->buildTrend(),
        ];
    }

    /**
     * @return array<string,int|float>
     */
    private function buildOverview(): array
    {
        $checks = (int) GeoInclusionCheckResult::query()->count();
        $keywordHits = (int) GeoInclusionCheckResult::query()->where('keyword_hit', true)->count();
        $brandHits = (int) GeoInclusionCheckResult::query()->where('brand_hit', true)->count();

        return [
            'projects' => (int) KeywordLibrary::query()->count(),
            'keywords' => (int) Keyword::query()->count(),
            'checks' => $checks,
            'keyword_hits' => $keywordHits,
            'brand_hits' => $brandHits,
            'keyword_hit_rate' => $this->rate($keywordHits, $checks),
            'brand_hit_rate' => $this->rate($brandHits, $checks),
        ];
    }

    /**
     * @return list<array<string,int|float|string>>
     */
    private function buildPlatformDistribution(): array
    {
        return GeoInclusionCheckResult::query()
            ->select('platform')
            ->selectRaw('COUNT(*) as checks')
            ->selectRaw($this->booleanSumExpression('keyword_hit').' as keyword_hits')
            ->selectRaw($this->booleanSumExpression('brand_hit').' as brand_hits')
            ->groupBy('platform')
            ->orderByDesc('checks')
            ->orderBy('platform')
            ->get()
            ->map(function ($row): array {
                $checks = (int) ($row->checks ?? 0);
                $keywordHits = (int) ($row->keyword_hits ?? 0);
                $brandHits = (int) ($row->brand_hits ?? 0);

                return [
                    'platform' => (string) $row->platform,
                    'checks' => $checks,
                    'keyword_hits' => $keywordHits,
                    'brand_hits' => $brandHits,
                    'keyword_hit_rate' => $this->rate($keywordHits, $checks),
                    'brand_hit_rate' => $this->rate($brandHits, $checks),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,int|float|string>>
     */
    private function buildKeywordRanking(): array
    {
        return GeoInclusionCheckResult::query()
            ->join('keywords', 'geo_inclusion_check_results.keyword_id', '=', 'keywords.id')
            ->select('keywords.keyword')
            ->selectRaw('COUNT(*) as checks')
            ->selectRaw($this->booleanSumExpression('geo_inclusion_check_results.keyword_hit').' as keyword_hits')
            ->selectRaw($this->booleanSumExpression('geo_inclusion_check_results.brand_hit').' as brand_hits')
            ->groupBy('keywords.id', 'keywords.keyword')
            ->orderByDesc('brand_hits')
            ->orderByDesc('keyword_hits')
            ->orderByDesc('checks')
            ->limit(10)
            ->get()
            ->map(function ($row): array {
                $checks = (int) ($row->checks ?? 0);
                $keywordHits = (int) ($row->keyword_hits ?? 0);
                $brandHits = (int) ($row->brand_hits ?? 0);

                return [
                    'keyword' => (string) $row->keyword,
                    'checks' => $checks,
                    'keyword_hits' => $keywordHits,
                    'brand_hits' => $brandHits,
                    'keyword_hit_rate' => $this->rate($keywordHits, $checks),
                    'brand_hit_rate' => $this->rate($brandHits, $checks),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string,int|float|string>>
     */
    private function buildProjectRanking(): array
    {
        return GeoInclusionCheckResult::query()
            ->join('keyword_libraries', 'geo_inclusion_check_results.keyword_library_id', '=', 'keyword_libraries.id')
            ->select('keyword_libraries.name', 'keyword_libraries.company_name')
            ->selectRaw('COUNT(*) as checks')
            ->selectRaw($this->booleanSumExpression('geo_inclusion_check_results.keyword_hit').' as keyword_hits')
            ->selectRaw($this->booleanSumExpression('geo_inclusion_check_results.brand_hit').' as brand_hits')
            ->groupBy('keyword_libraries.id', 'keyword_libraries.name', 'keyword_libraries.company_name')
            ->orderByDesc('brand_hits')
            ->orderByDesc('checks')
            ->limit(10)
            ->get()
            ->map(function ($row): array {
                $checks = (int) ($row->checks ?? 0);
                $keywordHits = (int) ($row->keyword_hits ?? 0);
                $brandHits = (int) ($row->brand_hits ?? 0);

                return [
                    'project' => (string) $row->name,
                    'brand' => (string) ($row->company_name ?? ''),
                    'checks' => $checks,
                    'keyword_hit_rate' => $this->rate($keywordHits, $checks),
                    'brand_hit_rate' => $this->rate($brandHits, $checks),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{date:string,checks:int,keyword_hits:int,brand_hits:int,keyword_hit_rate:float,brand_hit_rate:float}>
     */
    private function buildTrend(): array
    {
        $start = now()->subDays(6)->startOfDay();
        $dateExpression = $this->dateExpression();
        $rows = GeoInclusionCheckResult::query()
            ->where('checked_at', '>=', $start)
            ->selectRaw($dateExpression.' as date_key')
            ->selectRaw('COUNT(*) as checks')
            ->selectRaw($this->booleanSumExpression('keyword_hit').' as keyword_hits')
            ->selectRaw($this->booleanSumExpression('brand_hit').' as brand_hits')
            ->groupByRaw($dateExpression)
            ->get()
            ->keyBy('date_key');

        $trend = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $row = $rows->get($date);
            $checks = (int) ($row->checks ?? 0);
            $keywordHits = (int) ($row->keyword_hits ?? 0);
            $brandHits = (int) ($row->brand_hits ?? 0);
            $trend[] = [
                'date' => $date,
                'checks' => $checks,
                'keyword_hits' => $keywordHits,
                'brand_hits' => $brandHits,
                'keyword_hit_rate' => $this->rate($keywordHits, $checks),
                'brand_hit_rate' => $this->rate($brandHits, $checks),
            ];
        }

        return $trend;
    }

    private function dateExpression(): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', checked_at)"
            : "to_char(checked_at, 'YYYY-MM-DD')";
    }

    private function booleanSumExpression(string $column): string
    {
        return 'SUM(CASE WHEN '.$column.' THEN 1 ELSE 0 END)';
    }

    private function rate(int $hits, int $total): float
    {
        return $total > 0 ? round(($hits * 100) / $total, 1) : 0.0;
    }
}
