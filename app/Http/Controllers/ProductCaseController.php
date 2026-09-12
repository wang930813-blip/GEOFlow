<?php

namespace App\Http\Controllers;

use App\Models\ProductCase;
use App\Services\ProductCases\ProductCaseReportSummaryService;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductCaseController extends Controller
{
    public function index(Request $request, ProductCaseReportSummaryService $reports): View
    {
        $caseRoutes = $this->routeNames($request);
        $filters = [
            'keyword' => trim((string) $request->query('keyword', '')),
            'industry' => ProductCase::normalizeIndustryLabel((string) $request->query('industry', '')),
            'region' => ProductCase::normalizeRegionLabel((string) $request->query('region', '')),
        ];

        $query = ProductCase::query()
            ->published()
            ->with(['site:id,name,owner_admin_id']);

        $this->applyFilters($query, $filters);

        $cases = $query
            ->orderByDesc('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        $caseMetrics = [];
        foreach ($cases as $case) {
            if ($case instanceof ProductCase) {
                $caseMetrics[(int) $case->id] = $reports->cardMetrics($case);
            }
        }

        return view('product-cases.index', [
            'cases' => $cases,
            'caseMetrics' => $caseMetrics,
            'caseRoutes' => $caseRoutes,
            'filterOptions' => $this->filterOptions(),
            'filters' => $filters,
            'pageTitle' => 'Product Cases',
            'pageDescription' => 'Explore GEO and AI search optimization case studies, including brand diagnostics, AI answer visibility, and content growth outcomes.',
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

        return view('product-cases.show', [
            'case' => $case,
            'contentHtml' => ArticleHtmlPresenter::markdownToHtml((string) $case->content),
            'report' => $reports->detail($case),
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
            $query->whereIn('industry', ProductCase::industryStorageValues($filters['industry']));
        }

        if ($filters['region'] !== '') {
            $query->whereIn('region', ProductCase::regionStorageValues($filters['region']));
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
