<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 16:49:23
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteContentPublicController.php
 * @Description: 向官网访客展示当前站点已发布的产品目录、产品详情和自定义单页。
 */

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\WebsitePage;
use App\Models\WebsiteProduct;
use App\Support\CurrentSite;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeViewResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WebsiteContentPublicController extends Controller
{
    /**
     * @Name: products
     * @Description: 展示当前官网已发布产品，可按分类筛选且不读取其他租户数据。
     * @Url [GET] /products
     *      登录 否
     *
     *      筛选参数：
     *      category string 可选 分类 URL 标识
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-13 15:39:51
     * @Param Request $request 官网访客请求
     * @Param CurrentSite $currentSite 当前站点上下文
     * @Return View 当前站点公开产品目录页
     * @Throws ModelNotFoundException 当前请求没有可用站点
     */
    public function products(Request $request, CurrentSite $currentSite): View
    {
        $siteId = (int) ($currentSite->id() ?? 0);
        $ownerAdminId = (int) ($currentSite->ownerAdminId() ?? 0);
        abort_if($siteId <= 0 || $ownerAdminId <= 0, 404);

        $categorySlug = trim((string) $request->query('category', ''));
        $selectedCategory = $categorySlug !== ''
            ? Category::query()
                ->where('site_id', $siteId)
                ->where('owner_admin_id', $ownerAdminId)
                ->where('slug', $categorySlug)
                ->first()
            : null;
        $query = WebsiteProduct::query()
            ->where('site_id', $siteId)
            ->where('owner_admin_id', $ownerAdminId)
            ->published()
            ->with('category')
            ->orderByDesc('is_featured')
            ->orderByDesc('sort_order')
            ->orderByDesc('updated_at');
        if ($selectedCategory instanceof Category) {
            $query->where('category_id', (int) $selectedCategory->id);
        }

        $map = SiteSettingsBag::all();
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));

        return SiteThemeViewResolver::first('products.index', [
            'products' => $query->paginate(12)->withQueryString(),
            'categories' => Category::query()
                ->where('site_id', $siteId)
                ->where('owner_admin_id', $ownerAdminId)
                ->whereHas('products', fn ($productQuery) => $productQuery
                    ->where('site_id', $siteId)
                    ->where('owner_admin_id', $ownerAdminId)
                    ->published())
                ->orderByDesc('sort_order')
                ->orderBy('name')
                ->get(),
            'selectedCategory' => $selectedCategory,
            'activeNav' => 'products',
            'siteTitle' => $siteTitle,
            'pageTitle' => ($selectedCategory?->name ?? '产品中心').' - '.$siteTitle,
            'pageDescription' => $selectedCategory?->description ?: $siteTitle.'产品介绍与产品信息',
            'canonicalUrl' => route('site.products.index', $selectedCategory instanceof Category ? ['category' => $selectedCategory->slug] : []),
        ]);
    }

    /**
     * @Name: product
     * @Description: 按当前站点 URL 标识读取已发布产品并安全渲染 Markdown 正文。
     * @Url [GET] /product/{slug}
     *      登录 否
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-11 16:49:23
     * @Param CurrentSite $currentSite 当前站点上下文
     * @Param string $slug 产品 URL 标识
     * @Return View 官网产品详情页
     * @Throws ModelNotFoundException 产品不存在、未发布或不属于当前站点
     */
    public function product(CurrentSite $currentSite, string $slug): View
    {
        $siteId = (int) ($currentSite->id() ?? 0);
        $ownerAdminId = (int) ($currentSite->ownerAdminId() ?? 0);
        abort_if($siteId <= 0 || $ownerAdminId <= 0, 404);
        $product = WebsiteProduct::query()
            ->where('site_id', $siteId)
            ->where('owner_admin_id', $ownerAdminId)
            ->published()
            ->with('category')
            ->where('slug', $slug)
            ->firstOrFail();
        $map = SiteSettingsBag::all();
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));

        return SiteThemeViewResolver::first('products.show', [
            'product' => $product,
            'contentHtml' => ArticleHtmlPresenter::markdownToHtml((string) $product->content),
            'siteTitle' => $siteTitle,
            'pageTitle' => trim((string) $product->meta_title) !== '' ? (string) $product->meta_title : $product->title.' - '.$siteTitle,
            'pageDescription' => trim((string) $product->meta_description) !== ''
                ? (string) $product->meta_description
                : (trim((string) $product->summary) !== '' ? (string) $product->summary : $siteTitle.'产品介绍'),
            'canonicalUrl' => route('site.products.show', $product->slug),
            'activeNav' => 'products',
        ]);
    }

    /**
     * @Name: page
     * @Description: 按当前站点 URL 标识展示已发布单页并输出其独立 SEO 元数据。
     * @Url [GET] /page/{slug}
     *      登录 否
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:49:23
     * @UpdateTime: 2026-11-11 16:49:23
     * @Param CurrentSite $currentSite 当前站点上下文
     * @Param string $slug 单页 URL 标识
     * @Return View 官网单页详情页
     * @Throws ModelNotFoundException 页面不存在、未发布或不属于当前站点
     */
    public function page(CurrentSite $currentSite, string $slug): View
    {
        $siteId = (int) ($currentSite->id() ?? 0);
        $ownerAdminId = (int) ($currentSite->ownerAdminId() ?? 0);
        abort_if($siteId <= 0 || $ownerAdminId <= 0, 404);
        $websitePage = WebsitePage::query()
            ->where('site_id', $siteId)
            ->where('owner_admin_id', $ownerAdminId)
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();
        $map = SiteSettingsBag::all();
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));

        return SiteThemeViewResolver::first('page', [
            'websitePage' => $websitePage,
            'contentHtml' => ArticleHtmlPresenter::markdownToHtml((string) $websitePage->content),
            'siteTitle' => $siteTitle,
            'pageTitle' => trim((string) $websitePage->meta_title) !== '' ? (string) $websitePage->meta_title : $websitePage->title.' - '.$siteTitle,
            'pageDescription' => trim((string) $websitePage->meta_description) !== ''
                ? (string) $websitePage->meta_description
                : $siteTitle.' - '.$websitePage->title,
            'canonicalUrl' => route('site.page', $websitePage->slug),
            'activeNav' => $websitePage->slug,
        ]);
    }
}
