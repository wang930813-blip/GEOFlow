<?php

namespace App\Http\Controllers;

use App\Models\ProductCase;
use App\Services\ProductCases\ProductCaseReportSummaryService;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;

class ProductCaseController extends Controller
{
    public function index(Request $request, ProductCaseReportSummaryService $reports): View
    {
        $caseRoutes = $this->routeNames($request);
        $filters = [
            'keyword' => trim((string) $request->query('keyword', '')),
            'industry' => trim((string) $request->query('industry', '')),
            'region' => trim((string) $request->query('region', '')),
        ];

        $query = ProductCase::query()
            ->published()
            ->with(['site:id,name,owner_admin_id']);

        $this->applyFilters($query, $filters);

        $rankedCases = $query
            ->orderByDesc('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (ProductCase $case) use ($reports): array {
                $report = $reports->detail($case);

                return [
                    'case' => $case,
                    'report' => $report,
                    'score' => $reports->performanceScoreFromReport($report),
                    'sort_order' => (int) $case->sort_order,
                    'published_at' => (int) ($case->published_at?->getTimestamp() ?? 0),
                    'id' => (int) $case->id,
                ];
            })
            ->sort(function (array $left, array $right): int {
                foreach (['score', 'sort_order', 'published_at', 'id'] as $key) {
                    $comparison = ((int) $right[$key]) <=> ((int) $left[$key]);
                    if ($comparison !== 0) {
                        return $comparison;
                    }
                }

                return 0;
            })
            ->values();

        $page = max(1, (int) $request->query('page', 1));
        $perPage = 12;
        $pageItems = $rankedCases->forPage($page, $perPage)->values();
        $cases = new LengthAwarePaginator(
            $pageItems->pluck('case')->values(),
            $rankedCases->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        $caseMetrics = $pageItems
            ->mapWithKeys(fn (array $item): array => [
                (int) $item['case']->id => $reports->cardMetricsFromReport((array) $item['report']),
            ])
            ->all();

        return view('product-cases.index', [
            'cases' => $cases,
            'caseMetrics' => $caseMetrics,
            'caseRoutes' => $caseRoutes,
            'filterOptions' => $this->filterOptions(),
            'filters' => $filters,
            'pageTitle' => '产品案例',
            'pageDescription' => '查看 GEO 与 AI 搜索优化产品案例，了解品牌诊断、AI 搜索收录和内容增长的落地效果。',
        ]);
    }

    public function show(Request $request, string $slug, ProductCaseReportSummaryService $reports): View
    {
        $case = ProductCase::query()
            ->published()
            ->with(['site:id,name,owner_admin_id', 'owner:id,username,display_name'])
            ->where('slug', $slug)
            ->firstOrFail();

        $case->increment('view_count');

        $searchPage = max(1, (int) $request->query('search_page', 1));

        return view('product-cases.show', [
            'case' => $case,
            'contentHtml' => ArticleHtmlPresenter::markdownToHtml((string) $case->content),
            'report' => $reports->detail($case, $searchPage),
            'caseRoutes' => $this->routeNames($request),
            'pageTitle' => $case->title,
            'pageDescription' => trim((string) $case->summary) !== '' ? (string) $case->summary : (string) $case->company_name,
        ]);
    }

    /**
     * @param  array{keyword:string,industry:string,region:string}  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($filters['keyword'] !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($filters['keyword'], 'UTF-8')).'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(company_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(summary) LIKE ?', [$like]);
            });
        }

        if ($filters['industry'] !== '') {
            $query->where('industry', $filters['industry']);
        }

        if ($filters['region'] !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($filters['region'], 'UTF-8')).'%';
            $query->whereRaw('LOWER(region) LIKE ?', [$like]);
        }

    }

    /**
     * @return array{industries:list<string>,regions:list<string>}
     */
    private function filterOptions(): array
    {
        return [
            'industries' => ProductCase::industryOptions(),
            'regions' => ProductCase::regionOptions(),
        ];
    }

    /**
     * @return array{index:string,show:string,home:string}
     */
    private function routeNames(Request $request): array
    {
        $currentRouteName = (string) ($request->route()?->getName() ?? '');

        if (str_starts_with($currentRouteName, 'admin.product-case-library.')) {
            return [
                'index' => 'admin.product-case-library.index',
                'show' => 'admin.product-case-library.show',
                'home' => 'admin.dashboard',
            ];
        }

        return [
            'index' => 'product-cases.index',
            'show' => 'product-cases.show',
            'home' => 'site.home',
        ];
    }
}
