<?php

/**
 * Created by Codex.
 * @Date: 2026-11-12
 * @Time: 10:46:48
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： SiteLayoutComposer.php
 * @Description: 向官网布局注入站点资料、分类导航和独立页面导航。
 */

namespace App\View\Composers;

use App\Models\Category;
use App\Models\WebsitePage;
use App\Models\WebsiteProduct;
use App\Support\CurrentSite;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * 为前台 Blade 布局注入站点名称、分类导航等公共变量。
 */
final class SiteLayoutComposer
{
    /**
     * @Name: compose
     * @Description: 注入当前站点的公开资料，以及按排序值从高到低排列的分类和单页导航。
     * @Author: cdkay
     * @CreateTime: 2026-11-12 10:46:48
     * @UpdateTime: 2026-11-16 10:09:36
     * @Param View $view 当前官网布局视图
     * @Return void 无返回值
     * @Throws \Throwable 站点配置或分类数据读取失败
     */
    public function compose(View $view): void
    {
        $map = SiteSettingsBag::all();
        $siteName = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteLogo = (string) ($map['site_logo'] ?? '');
        $siteFavicon = (string) ($map['site_favicon'] ?? '');
        $copyright = (string) ($map['copyright_info'] ?? '');
        $analyticsCode = (string) ($map['analytics_code'] ?? '');
        $contactInfo = (string) ($map['contact_info'] ?? '');
        $companyAddress = (string) ($map['company_address'] ?? '');
        $siteRemark = (string) ($map['site_remark'] ?? '');
        $contactPayments = $this->parseContactPayments((string) ($map['contact_payments'] ?? '[]'));
        $currentSite = app(CurrentSite::class);
        $siteId = (int) ($currentSite->id() ?? 0);
        $ownerAdminId = (int) ($currentSite->ownerAdminId() ?? 0);

        $categories = collect();
        if (Schema::hasTable('categories')) {
            $categories = Category::query()
                ->when($siteId > 0, fn ($query) => $query->where('site_id', $siteId))
                ->when($ownerAdminId > 0, fn ($query) => $query->where('owner_admin_id', $ownerAdminId))
                ->orderByDesc('sort_order')
                ->orderBy('id')
                ->withCount([
                    'articles as published_count' => function ($q) use ($siteId, $ownerAdminId): void {
                        $q->when($siteId > 0, fn ($query) => $query->where('site_id', $siteId))
                            ->when($ownerAdminId > 0, fn ($query) => $query->where('owner_admin_id', $ownerAdminId))
                            ->published();
                    },
                    'products as published_product_count' => function ($q) use ($siteId, $ownerAdminId): void {
                        $q->when($siteId > 0, fn ($query) => $query->where('site_id', $siteId))
                            ->when($ownerAdminId > 0, fn ($query) => $query->where('owner_admin_id', $ownerAdminId))
                            ->published();
                    },
                ])
                ->get();
        }

        $navPages = collect();
        if ($siteId > 0 && $ownerAdminId > 0 && Schema::hasTable('website_pages')) {
            $navPages = WebsitePage::query()
                ->where('site_id', $siteId)
                ->where('owner_admin_id', $ownerAdminId)
                ->published()
                ->where('show_in_navigation', true)
                ->whereNotIn('slug', ['about', 'contact'])
                ->orderByDesc('sort_order')
                ->orderBy('title')
                ->get(['id', 'title', 'slug']);
        }

        $hasWebsiteProducts = $siteId > 0 && $ownerAdminId > 0 && Schema::hasTable('website_products')
            ? WebsiteProduct::query()
                ->where('site_id', $siteId)
                ->where('owner_admin_id', $ownerAdminId)
                ->published()
                ->exists()
            : false;

        $view->with([
            'siteName' => $siteName,
            'siteLogo' => $siteLogo,
            'siteFavicon' => $siteFavicon,
            'footerCopyright' => $copyright,
            'headAnalyticsCode' => $analyticsCode,
            'navCategories' => $categories,
            'navPages' => $navPages,
            'hasWebsiteProducts' => $hasWebsiteProducts,
            'contactInfo' => $contactInfo,
            'companyAddress' => $companyAddress,
            'siteRemark' => $siteRemark,
            'contactPayments' => $contactPayments,
        ]);
    }

    /**
     * @return array<int, array{type:string,name:string,qr_url:string,account:string}>
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
