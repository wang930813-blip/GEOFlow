<?php

namespace App\Support\Site;

use App\Models\Article;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class Template01Data
{
    /**
     * @param  array<string, mixed>  $viewData
     * @return array<string, mixed>
     */
    public static function fromView(array $viewData): array
    {
        /** @var array<string, string> $settings */
        $settings = is_array($viewData['map'] ?? null)
            ? $viewData['map']
            : SiteSettingsBag::all();

        $siteName = self::clean((string) ($viewData['siteTitle'] ?? $viewData['siteName'] ?? $settings['site_name'] ?? config('geoflow.site_name', config('app.name'))));
        $siteSubtitle = self::clean((string) ($viewData['siteSubtitle'] ?? $settings['site_subtitle'] ?? ''));
        $siteDescription = self::clean((string) ($viewData['siteDescription'] ?? $settings['site_description'] ?? config('geoflow.site_description', '')));
        $siteRemark = self::clean((string) ($viewData['siteRemark'] ?? $settings['site_remark'] ?? ''));
        $siteLogo = self::clean((string) ($viewData['siteLogo'] ?? $settings['site_logo'] ?? ''));
        $copyright = self::clean((string) ($viewData['footerCopyright'] ?? $settings['copyright_info'] ?? ''));
        $contactInfo = self::clean((string) ($viewData['contactInfo'] ?? $settings['contact_info'] ?? ''));
        $companyAddress = self::clean((string) ($viewData['companyAddress'] ?? $settings['company_address'] ?? ''));

        $brandTagline = $siteSubtitle !== '' ? $siteSubtitle : ($siteDescription !== '' ? $siteDescription : '以清晰的信息呈现服务内容与沟通方式。');
        $footerText = $siteRemark !== '' ? $siteRemark : ($copyright !== '' ? $copyright : $brandTagline);

        $serviceItems = self::productItems((string) ($settings['site_products'] ?? '[]'));

        return [
            'site' => [
                'brand_name' => $siteName,
                'logo' => $siteLogo,
                'brand_tagline' => $brandTagline,
                'footer_text' => $footerText,
                'copyright' => $copyright,
            ],
            'home' => [
                'hero' => [
                    'eyebrow' => '欢迎访问',
                    'title' => '让信息更清晰，让沟通更直接',
                    'description' => $siteDescription !== '' ? $siteDescription : $brandTagline,
                    'primary_action' => ['label' => '查看产品服务', 'url' => route('site.products')],
                    'secondary_action' => ['label' => '联系我们', 'url' => route('site.contact')],
                ],
                'introduction' => [
                    'title' => '关于品牌',
                    'content' => self::paragraph($siteDescription !== '' ? $siteDescription : $brandTagline),
                ],
            ],
            'about' => [
                'title' => '关于我们',
                'summary' => $siteDescription !== '' ? $siteDescription : '在这里了解品牌背景、业务方向与服务理念。',
                'content' => self::paragraph($siteDescription !== '' ? $siteDescription : $brandTagline),
            ],
            'products_services' => [
                'title' => '产品服务',
                'summary' => $serviceItems->isNotEmpty()
                    ? ''
                    : '',
                'items' => $serviceItems,
            ],
            'contact' => [
                'title' => '联系我们',
                'summary' => $siteRemark !== '' ? $siteRemark : '欢迎通过公开联系方式与我们取得联系。',
                'phone' => self::phoneFrom($contactInfo),
                'email' => self::emailFrom($contactInfo),
                'address' => $companyAddress !== '' ? $companyAddress : '暂无公开地址。',
                'business_hours' => '暂无公开营业时间。',
                'lines' => self::lines($contactInfo),
            ],
            'seo' => [
                'home' => [
                    'title' => $siteName.' | 官方网站',
                    'description' => $siteDescription !== '' ? $siteDescription : $brandTagline,
                ],
                'about' => [
                    'title' => '关于我们 | '.$siteName,
                    'description' => $siteDescription !== '' ? $siteDescription : $brandTagline,
                ],
                'products_services' => [
                    'title' => '产品服务 | '.$siteName,
                    'description' => '查看'.$siteName.'提供的产品与服务内容。',
                ],
                'contact' => [
                    'title' => '联系我们 | '.$siteName,
                    'description' => '查看'.$siteName.'已确认的公开联系方式。',
                ],
            ],
            'articles' => self::articles($viewData['articles'] ?? []),
            'featured_articles' => self::articles($viewData['featuredArticles'] ?? []),
            'hot_articles' => self::articles($viewData['hotArticles'] ?? []),
        ];
    }

    /**
     * @param  mixed  $value
     * @return Collection<int, mixed>
     */
    public static function collectValues(mixed $value): Collection
    {
        if ($value instanceof LengthAwarePaginator) {
            return collect($value->items());
        }

        if ($value instanceof Collection) {
            return $value->values();
        }

        if (is_array($value)) {
            return collect($value)->values();
        }

        return collect();
    }

    /**
     * @return Collection<int, array{service_id:string,name:string,summary:string,details:string,url:string,image_url:string}>
     */
    private static function productItems(string $raw): Collection
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return collect();
        }

        return collect($decoded)
            ->filter(static fn (mixed $item): bool => is_array($item) && ! empty($item['enabled']))
            ->map(static function (mixed $item): array {
                $name = self::clean((string) data_get($item, 'name', ''));
                $summary = self::clean((string) data_get($item, 'summary', ''));
                $details = self::clean((string) data_get($item, 'details', ''));
                $linkUrl = self::clean((string) data_get($item, 'link_url', ''));
                $imageUrl = self::clean((string) data_get($item, 'image_url', ''));
                $fallbackCopy = $summary !== '' ? $summary : $name;

                return [
                    'service_id' => Str::slug($name),
                    'name' => $name,
                    'summary' => $fallbackCopy,
                    'details' => self::paragraph($details !== '' ? $details : $fallbackCopy),
                    'url' => $linkUrl,
                    'image_url' => $imageUrl,
                ];
            })
            ->filter(static fn (array $item): bool => $item['name'] !== '')
            ->values();
    }

    /**
     * @param  mixed  $value
     * @return Collection<int, Article>
     */
    private static function articles(mixed $value): Collection
    {
        return self::collectValues($value)
            ->filter(static fn (mixed $item): bool => $item instanceof Article)
            ->values();
    }

    /**
     * @return list<string>
     */
    private static function lines(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: []), static fn (string $line): bool => $line !== ''));
    }

    private static function emailFrom(string $value): string
    {
        foreach (self::lines($value) as $line) {
            if (filter_var($line, FILTER_VALIDATE_EMAIL)) {
                return $line;
            }
        }

        return '暂无公开邮箱。';
    }

    private static function phoneFrom(string $value): string
    {
        foreach (self::lines($value) as $line) {
            if (filter_var($line, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            if (preg_match('/[0-9]{3,}/', $line) === 1) {
                return $line;
            }
        }

        return '暂无公开电话。';
    }

    private static function paragraph(string $value): string
    {
        return '<p>'.e($value).'</p>';
    }

    private static function clean(string $value): string
    {
        return trim($value);
    }
}
