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
                'key' => 'linkedin',
                'name' => 'LinkedIn',
                'logo' => 'assets/b2b-sites/linkedin.png',
                'website_url' => 'https://www.linkedin.com/',
            ],
            [
                'key' => 'medium',
                'name' => 'Medium',
                'logo' => 'assets/b2b-sites/medium.png',
                'website_url' => 'https://medium.com/',
            ],
            [
                'key' => 'quora',
                'name' => 'Quora',
                'logo' => 'assets/b2b-sites/quora.png',
                'website_url' => 'https://www.quora.com/',
            ],
            [
                'key' => 'reddit',
                'name' => 'Reddit',
                'logo' => 'assets/b2b-sites/reddit.png',
                'website_url' => 'https://www.reddit.com/',
            ],
            [
                'key' => 'product-hunt',
                'name' => 'Product Hunt',
                'logo' => 'assets/b2b-sites/product-hunt.png',
                'website_url' => 'https://www.producthunt.com/',
            ],
            [
                'key' => 'indie-hackers',
                'name' => 'Indie Hackers',
                'logo' => 'assets/b2b-sites/indie-hackers.png',
                'website_url' => 'https://www.indiehackers.com/',
            ],
            [
                'key' => 'hashnode',
                'name' => 'Hashnode',
                'logo' => 'assets/b2b-sites/hashnode.png',
                'website_url' => 'https://hashnode.com/',
            ],
            [
                'key' => 'dev-community',
                'name' => 'DEV Community',
                'logo' => 'assets/b2b-sites/dev-community.png',
                'website_url' => 'https://dev.to/',
            ],
            [
                'key' => 'github-pages',
                'name' => 'GitHub Pages',
                'logo' => 'assets/b2b-sites/github-pages.png',
                'website_url' => 'https://pages.github.com/',
            ],
            [
                'key' => 'hacker-news',
                'name' => 'Hacker News',
                'logo' => 'assets/b2b-sites/hacker-news.png',
                'website_url' => 'https://news.ycombinator.com/',
            ],
            [
                'key' => 'crunchbase',
                'name' => 'Crunchbase',
                'logo' => 'assets/b2b-sites/crunchbase.png',
                'website_url' => 'https://www.crunchbase.com/',
            ],
            [
                'key' => 'g2',
                'name' => 'G2',
                'logo' => 'assets/b2b-sites/g2.png',
                'website_url' => 'https://www.g2.com/',
            ],
            [
                'key' => 'capterra',
                'name' => 'Capterra',
                'logo' => 'assets/b2b-sites/capterra.png',
                'website_url' => 'https://www.capterra.com/',
            ],
            [
                'key' => 'clutch',
                'name' => 'Clutch',
                'logo' => 'assets/b2b-sites/clutch.png',
                'website_url' => 'https://clutch.co/',
            ],
            [
                'key' => 'goodfirms',
                'name' => 'GoodFirms',
                'logo' => 'assets/b2b-sites/goodfirms.png',
                'website_url' => 'https://www.goodfirms.co/',
            ],
            [
                'key' => 'trustpilot',
                'name' => 'Trustpilot',
                'logo' => 'assets/b2b-sites/trustpilot.png',
                'website_url' => 'https://www.trustpilot.com/',
            ],
            [
                'key' => 'sourceforge',
                'name' => 'SourceForge',
                'logo' => 'assets/b2b-sites/sourceforge.png',
                'website_url' => 'https://sourceforge.net/',
            ],
            [
                'key' => 'wellfound',
                'name' => 'Wellfound',
                'logo' => 'assets/b2b-sites/wellfound.png',
                'website_url' => 'https://wellfound.com/',
            ],
            [
                'key' => 'stack-overflow',
                'name' => 'Stack Overflow',
                'logo' => 'assets/b2b-sites/stack-overflow.png',
                'website_url' => 'https://stackoverflow.com/',
            ],
            [
                'key' => 'stack-exchange',
                'name' => 'Stack Exchange',
                'logo' => 'assets/b2b-sites/stack-exchange.png',
                'website_url' => 'https://stackexchange.com/',
            ],
        ];
    }

    public function exists(string $key): bool
    {
        return Collection::make($this->all())->contains(fn (array $website): bool => $website['key'] === $key);
    }
}
