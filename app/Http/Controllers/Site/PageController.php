<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeViewResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PageController extends Controller
{
    public function news(): View
    {
        $context = $this->siteContext();
        $perPage = max(1, min(200, (int) ($context['map']['per_page'] ?? config('geoflow.items_per_page', 12))));

        $categories = Category::query()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->withCount([
                'articles as published_count' => function ($q): void {
                    $q->published();
                },
            ])
            ->get();

        $articles = Article::query()
            ->with(['category', 'author'])
            ->published()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $hotArticles = collect();
        if (Schema::hasColumn('articles', 'is_hot')) {
            $hotArticles = Article::query()
                ->with(['category', 'author'])
                ->published()
                ->where('is_hot', true)
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(6)
                ->get();
        }

        return SiteThemeViewResolver::first('news', [
            ...$context,
            'activeNav' => 'news',
            'categories' => $categories,
            'articles' => $articles,
            'hotArticles' => $hotArticles,
            'cardSummaries' => $this->articleSummaries(collect($articles->items())->merge($hotArticles)),
            'pageTitle' => '资讯 - '.$context['siteTitle'],
            'pageDescription' => $context['siteDescription'] !== ''
                ? '资讯 - '.$context['siteDescription']
                : $context['siteTitle'].'资讯内容',
            'canonicalUrl' => route('site.news'),
        ]);
    }

    public function about(): View
    {
        $context = $this->siteContext();

        return SiteThemeViewResolver::first('about', [
            ...$context,
            'activeNav' => 'about',
            'pageTitle' => '关于我们 - '.$context['siteTitle'],
            'pageDescription' => $context['siteDescription'] !== ''
                ? $context['siteDescription']
                : $context['siteTitle'].'品牌介绍',
            'canonicalUrl' => route('site.about'),
        ]);
    }

    public function contact(): View
    {
        $context = $this->siteContext();
        $contactInfo = trim((string) ($context['map']['contact_info'] ?? ''));

        return SiteThemeViewResolver::first('contact', [
            ...$context,
            'activeNav' => 'contact',
            'contactInfo' => $contactInfo,
            'contactInfoLines' => $this->lines($contactInfo),
            'companyAddress' => trim((string) ($context['map']['company_address'] ?? '')),
            'siteRemark' => trim((string) ($context['map']['site_remark'] ?? '')),
            'contactPayments' => $this->parseContactPayments((string) ($context['map']['contact_payments'] ?? '[]')),
            'pageTitle' => '联系我们 - '.$context['siteTitle'],
            'pageDescription' => $context['siteTitle'].'联系方式',
            'canonicalUrl' => route('site.contact'),
        ]);
    }

    /**
     * @return array{map:array<string,string>,siteTitle:string,siteSubtitle:string,siteDescription:string,siteKeywords:string}
     */
    private function siteContext(): array
    {
        $map = SiteSettingsBag::all();

        return [
            'map' => $map,
            'siteTitle' => (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name'))),
            'siteSubtitle' => (string) ($map['site_subtitle'] ?? ''),
            'siteDescription' => (string) ($map['site_description'] ?? config('geoflow.site_description', '')),
            'siteKeywords' => (string) ($map['site_keywords'] ?? config('geoflow.site_keywords', '')),
        ];
    }

    /**
     * @param  Collection<int, Article>  $articles
     * @return array<int, string>
     */
    private function articleSummaries(Collection $articles): array
    {
        $summaries = [];
        foreach ($articles as $article) {
            if ($article instanceof Article) {
                $summaries[$article->id] = ArticleHtmlPresenter::cardSummary($article, 120);
            }
        }

        return $summaries;
    }

    /**
     * @return list<string>
     */
    private function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: []), static fn ($line) => $line !== ''));
    }

    /**
     * @return list<array{type:string,name:string,qr_url:string,account:string}>
     */
    private function parseContactPayments(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $item) {
            if (! is_array($item) || empty($item['enabled'])) {
                continue;
            }

            $items[] = [
                'type' => trim((string) ($item['type'] ?? '')),
                'name' => trim((string) ($item['name'] ?? '')),
                'qr_url' => trim((string) ($item['qr_url'] ?? '')),
                'account' => trim((string) ($item['account'] ?? '')),
            ];
        }

        return $items;
    }
}
