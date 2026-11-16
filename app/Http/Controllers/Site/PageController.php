<?php

/**
 * Created by Codex.
 * @Date: 2026-11-12
 * @Time: 10:46:48
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： PageController.php
 * @Description: 展示当前站点的资讯、企业信息及其他公开页面。
 */

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\WebsitePage;
use App\Support\CurrentSite;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeViewResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class PageController extends Controller
{
    /**
     * @Name: news
     * @Description: 查询当前站点已发布文章与分类，并按分类排序值从高到低展示。
     * @Url [GET] /news
     *      登录 否
     *
     *      分页参数：
     *      page integer 可选 页码
     *
     *      筛选参数：
     *      无
     * @Author: cdkay
     * @CreateTime: 2026-11-12 10:46:48
     * @UpdateTime: 2026-11-12 10:46:48
     * @Return View 当前站点资讯页面
     * @Throws \Throwable 站点配置或内容查询失败
     */
    public function news(): View
    {
        $context = $this->siteContext();
        $currentSite = app(CurrentSite::class);
        $siteId = (int) ($currentSite->id() ?? 0);
        $ownerAdminId = (int) ($currentSite->ownerAdminId() ?? 0);
        $perPage = max(1, min(200, (int) ($context['map']['per_page'] ?? config('geoflow.items_per_page', 12))));

        $categories = Category::query()
            ->where('site_id', $siteId)
            ->where('owner_admin_id', $ownerAdminId)
            ->orderByDesc('sort_order')
            ->orderBy('id')
            ->withCount([
                'articles as published_count' => function ($q) use ($siteId, $ownerAdminId): void {
                    $q->where('site_id', $siteId)
                        ->where('owner_admin_id', $ownerAdminId)
                        ->published();
                },
            ])
            ->get();

        $articles = Article::query()
            ->where('site_id', $siteId)
            ->where('owner_admin_id', $ownerAdminId)
            ->with(['category', 'author'])
            ->published()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $hotArticles = collect();
        if (Schema::hasColumn('articles', 'is_hot')) {
            $hotArticles = Article::query()
                ->where('site_id', $siteId)
                ->where('owner_admin_id', $ownerAdminId)
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
        $managedPage = $this->managedPage('about');
        if ($managedPage instanceof View) {
            return $managedPage;
        }
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
        $managedPage = $this->managedPage('contact');
        if ($managedPage instanceof View) {
            return $managedPage;
        }
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
     * @Name: managedPage
     * @Description: 读取当前站点通过网站后台发布的 about 或 contact 单页；不存在时保留原页面回退。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-11 16:49:23
     * @Param string $slug 固定页面 URL 标识
     * @Return View|null 已发布的自定义页面视图；没有自定义内容时返回 null
     * @Throws ModelNotFoundException 单页查询发生数据库错误时抛出
     */
    private function managedPage(string $slug): ?View
    {
        $currentSite = app(CurrentSite::class);
        $siteId = (int) ($currentSite->id() ?? 0);
        $ownerAdminId = (int) ($currentSite->ownerAdminId() ?? 0);
        if ($siteId <= 0 || $ownerAdminId <= 0) {
            return null;
        }

        $websitePage = WebsitePage::query()
            ->where('site_id', $siteId)
            ->where('owner_admin_id', $ownerAdminId)
            ->published()
            ->where('slug', $slug)
            ->first();
        if (! $websitePage instanceof WebsitePage) {
            return null;
        }

        $context = $this->siteContext();
        return SiteThemeViewResolver::first('page', [
            'websitePage' => $websitePage,
            'contentHtml' => ArticleHtmlPresenter::markdownToHtml((string) $websitePage->content),
            ...$context,
            'activeNav' => $slug,
            'pageTitle' => trim((string) $websitePage->meta_title) !== '' ? (string) $websitePage->meta_title : $websitePage->title.' - '.$context['siteTitle'],
            'pageDescription' => trim((string) $websitePage->meta_description) !== ''
                ? (string) $websitePage->meta_description
                : $context['siteTitle'].' - '.$websitePage->title,
            'canonicalUrl' => $slug === 'about' ? route('site.about') : route('site.contact'),
        ]);
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
