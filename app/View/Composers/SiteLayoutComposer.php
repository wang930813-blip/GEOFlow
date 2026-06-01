<?php

namespace App\View\Composers;

use App\Models\Category;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * 为前台 Blade 布局注入站点名称、分类导航等公共变量。
 */
final class SiteLayoutComposer
{
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

        $categories = collect();
        if (Schema::hasTable('categories')) {
            $categories = Category::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->withCount([
                    'articles as published_count' => function ($q): void {
                        $q->published();
                    },
                ])
                ->get();
        }

        $view->with([
            'siteName' => $siteName,
            'siteLogo' => $siteLogo,
            'siteFavicon' => $siteFavicon,
            'footerCopyright' => $copyright,
            'headAnalyticsCode' => $analyticsCode,
            'navCategories' => $categories,
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
