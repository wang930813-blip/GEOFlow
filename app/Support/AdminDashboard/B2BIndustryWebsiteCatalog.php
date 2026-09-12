<?php

namespace App\Support\AdminDashboard;

use Illuminate\Support\Collection;

final class B2BIndustryWebsiteCatalog
{
    /**
     * @return list<array{key: string, name: string, logo: string, website_url: string}>
     */
    public function all(): array
    {
        return [
            [
                'key' => 'alibaba',
                'name' => 'Alibaba.com 阿里国际',
                'logo' => 'assets/b2b-sites/alibaba.svg',
                'website_url' => 'https://www.alibaba.com',
            ],
            [
                'key' => 'thomasnet',
                'name' => 'Thomasnet',
                'logo' => 'assets/b2b-sites/thomasnet.svg',
                'website_url' => 'https://www.thomasnet.com',
            ],
            [
                'key' => 'kompass',
                'name' => 'Kompass 康帕斯',
                'logo' => 'assets/b2b-sites/kompass.svg',
                'website_url' => 'https://www.kompass.com',
            ],
            [
                'key' => 'directindustry',
                'name' => 'DirectIndustry',
                'logo' => 'assets/b2b-sites/directindustry.svg',
                'website_url' => 'https://www.directindustry.com',
            ],
            [
                'key' => 'europages',
                'name' => 'Europages',
                'logo' => 'assets/b2b-sites/europages.svg',
                'website_url' => 'https://www.europages.com',
            ],
            [
                'key' => 'globalspec',
                'name' => 'GlobalSpec',
                'logo' => 'assets/b2b-sites/globalspec.svg',
                'website_url' => 'https://www.globalspec.com',
            ],
            [
                'key' => 'wlw',
                'name' => 'WLW-IndustryStock',
                'logo' => 'assets/b2b-sites/wlw-industrystock.svg',
                'website_url' => 'https://www.wlw.de',
            ],
            [
                'key' => 'made-in-china',
                'name' => 'Made-in-China 中国制造网',
                'logo' => 'assets/b2b-sites/made-in-china.svg',
                'website_url' => 'https://www.made-in-china.com',
            ],
            [
                'key' => 'amazon-business',
                'name' => 'Amazon Business',
                'logo' => 'assets/b2b-sites/amazon-business.svg',
                'website_url' => 'https://www.amazon.com/business',
            ],
            [
                'key' => 'global-sources',
                'name' => 'Global Sources 环球资源',
                'logo' => 'assets/b2b-sites/global-sources.svg',
                'website_url' => 'https://www.globalsources.com',
            ],
        ];
    }

    public function exists(string $key): bool
    {
        return Collection::make($this->all())->contains(fn (array $website): bool => $website['key'] === $key);
    }
}
