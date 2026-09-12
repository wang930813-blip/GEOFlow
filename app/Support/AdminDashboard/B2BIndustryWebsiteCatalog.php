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
                'logo' => 'https://www.google.com/s2/favicons?domain=alibaba.com&sz=128',
                'website_url' => 'https://www.alibaba.com',
            ],
            [
                'key' => 'thomasnet',
                'name' => 'Thomasnet',
                'logo' => 'https://www.google.com/s2/favicons?domain=thomasnet.com&sz=128',
                'website_url' => 'https://www.thomasnet.com',
            ],
            [
                'key' => 'kompass',
                'name' => 'Kompass 康帕斯',
                'logo' => 'https://www.google.com/s2/favicons?domain=kompass.com&sz=128',
                'website_url' => 'https://www.kompass.com',
            ],
            [
                'key' => 'directindustry',
                'name' => 'DirectIndustry',
                'logo' => 'https://www.google.com/s2/favicons?domain=directindustry.com&sz=128',
                'website_url' => 'https://www.directindustry.com',
            ],
            [
                'key' => 'europages',
                'name' => 'Europages',
                'logo' => 'https://www.google.com/s2/favicons?domain=europages.com&sz=128',
                'website_url' => 'https://www.europages.com',
            ],
            [
                'key' => 'globalspec',
                'name' => 'GlobalSpec',
                'logo' => 'https://www.google.com/s2/favicons?domain=globalspec.com&sz=128',
                'website_url' => 'https://www.globalspec.com',
            ],
            [
                'key' => 'wlw',
                'name' => 'WLW-IndustryStock',
                'logo' => 'https://www.google.com/s2/favicons?domain=wlw.de&sz=128',
                'website_url' => 'https://www.wlw.de',
            ],
            [
                'key' => 'made-in-china',
                'name' => 'Made-in-China 中国制造网',
                'logo' => 'https://www.google.com/s2/favicons?domain=made-in-china.com&sz=128',
                'website_url' => 'https://www.made-in-china.com',
            ],
            [
                'key' => 'amazon-business',
                'name' => 'Amazon Business',
                'logo' => 'https://www.google.com/s2/favicons?domain=amazon.com&sz=128',
                'website_url' => 'https://www.amazon.com/business',
            ],
            [
                'key' => 'global-sources',
                'name' => 'Global Sources 环球资源',
                'logo' => 'https://www.google.com/s2/favicons?domain=globalsources.com&sz=128',
                'website_url' => 'https://www.globalsources.com',
            ],
        ];
    }

    public function exists(string $key): bool
    {
        return Collection::make($this->all())->contains(fn (array $website): bool => $website['key'] === $key);
    }
}
