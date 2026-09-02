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
        $filters = [
            'keyword' => trim((string) $request->query('keyword', '')),
            'industry' => trim((string) $request->query('industry', '')),
            'region' => trim((string) $request->query('region', '')),
            'businessMode' => trim((string) $request->query('business_mode', '')),
            'tag' => trim((string) $request->query('tag', '')),
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
            'filterOptions' => $this->filterOptions(),
            'filters' => $filters,
            'pageTitle' => '产品案例',
            'pageDescription' => '查看 GEO 与 AI 搜索优化产品案例，了解品牌诊断、AI 搜索收录和内容增长的落地效果。',
        ]);
    }

    public function show(string $slug, ProductCaseReportSummaryService $reports): View
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
            'pageTitle' => $case->title,
            'pageDescription' => trim((string) $case->summary) !== '' ? (string) $case->summary : (string) $case->company_name,
        ]);
    }

    /**
     * @param  array{keyword:string,industry:string,region:string,businessMode:string,tag:string}  $filters
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
            $query->where('region', $filters['region']);
        }

        if ($filters['businessMode'] !== '') {
            $query->where('business_mode', $filters['businessMode']);
        }

        if ($filters['tag'] !== '') {
            $query->whereJsonContains('module_tags', $filters['tag']);
        }
    }

    /**
     * @return array{industries:list<string>,regions:list<string>,business_modes:list<string>,tags:list<string>}
     */
    private function filterOptions(): array
    {
        $published = ProductCase::query()->published();

        return [
            'industries' => (clone $published)->where('industry', '<>', '')->distinct()->orderBy('industry')->pluck('industry')->all(),
            'regions' => (clone $published)->where('region', '<>', '')->distinct()->orderBy('region')->pluck('region')->all(),
            'business_modes' => (clone $published)->where('business_mode', '<>', '')->distinct()->orderBy('business_mode')->pluck('business_mode')->all(),
            'tags' => ProductCase::query()
                ->published()
                ->pluck('module_tags')
                ->flatten()
                ->filter(fn ($tag): bool => is_string($tag) && trim($tag) !== '')
                ->map(fn (string $tag): string => trim($tag))
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
