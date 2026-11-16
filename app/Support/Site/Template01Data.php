<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-16
 *
 * @Time: 15:36:35
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： Template01Data.php
 *
 * @Description: 将 GEOFlow 当前站点视图变量映射为标准官网主题公共数据。
 */

namespace App\Support\Site;

use Illuminate\Support\HtmlString;
use Traversable;

final class Template01Data
{
    /**
     * @Name: fromView
     *
     * @Description: 从当前请求已提供的租户站点资料、SEO、产品、文章摘要和联系信息构建只读公共数据；不读取其他租户或示例数据。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 10:52:20
     *
     * @UpdateTime: 2026-11-16 15:36:35
     *
     * @Param array<string, mixed> $variables 当前 Blade 视图可见变量
     *
     * @Return SiteThemeData 官网主题公共数据
     *
     * @Throws 无
     */
    public static function fromView(array $variables): SiteThemeData
    {
        $map = is_array($variables['map'] ?? null) ? $variables['map'] : [];
        if ($map === []) {
            $map = SiteSettingsBag::all();
        }
        $siteTitle = self::firstNonEmpty(
            $variables['siteTitle'] ?? null,
            $variables['siteName'] ?? null,
            $map['site_name'] ?? null,
            config('geoflow.site_name', config('app.name'))
        );
        $siteSubtitle = self::firstNonEmpty($variables['siteSubtitle'] ?? null, $map['site_subtitle'] ?? null);
        $siteDescription = self::firstNonEmpty($variables['siteDescription'] ?? null, $map['site_description'] ?? null);
        $contactInfo = self::firstNonEmpty($variables['contactInfo'] ?? null, $variables['contact_info'] ?? null, $map['contact_info'] ?? null);
        $contactLines = self::stringList($variables['contactInfoLines'] ?? null);
        if ($contactLines === []) {
            $contactLines = self::lines($contactInfo);
        }

        $activeNav = self::firstNonEmpty($variables['activeNav'] ?? null, 'home');
        $pageTitle = self::firstNonEmpty($variables['pageTitle'] ?? null);
        $pageDescription = self::firstNonEmpty($variables['pageDescription'] ?? null);
        $aboutTitle = $activeNav === 'about' && $pageTitle !== '' ? $pageTitle : '关于我们 - '.$siteTitle;
        $productsTitle = $activeNav === 'products' && $pageTitle !== '' ? $pageTitle : '产品服务 - '.$siteTitle;
        $contactTitle = $activeNav === 'contact' && $pageTitle !== '' ? $pageTitle : '联系我们 - '.$siteTitle;
        $aboutContent = self::managedPageContent($variables, 'about');
        $contactContent = self::managedPageContent($variables, 'contact');
        $homeImage = self::firstSlideImage($variables['homepageCarouselSlides'] ?? []);
        $visualEditor = app(WebsiteVisualEditorConfig::class)->state(
            SiteThemeViewResolver::activeThemeId(),
            $map
        );
        $editorFields = $visualEditor['fields'];
        $heroTitle = self::editorField($editorFields, 'hero_title', self::firstNonEmpty($siteSubtitle, $siteTitle));
        $heroDescription = self::editorField($editorFields, 'hero_description', $siteDescription);
        $introductionTitle = self::editorField(
            $editorFields,
            'introduction_title',
            self::firstNonEmpty($map['introduction_title'] ?? null, $siteSubtitle, $siteTitle)
        );
        $introductionContent = self::editorField(
            $editorFields,
            'introduction_content',
            self::firstNonEmpty($map['introduction_content'] ?? null, $siteDescription)
        );
        $servicesTitle = self::editorField(
            $editorFields,
            'services_title',
            self::firstNonEmpty($map['products_services_title'] ?? null, '产品服务')
        );
        $servicesSummary = self::editorField($editorFields, 'services_summary', self::firstNonEmpty($pageDescription, $siteDescription));
        $navigationRoutes = [
            'home' => 'site.home',
            'about' => 'site.about',
            'products' => 'site.products.index',
            'news' => 'site.news',
            'contact' => 'site.contact',
        ];
        $activeNavigation = match ($activeNav) {
            'article' => 'news',
            'products_services' => 'products',
            default => $activeNav,
        };
        $navigationItems = [];
        $editorPreview = request()->query('site_editor_preview') === '1';
        foreach ($visualEditor['navigation'] as $navigationItem) {
            if (! $navigationItem['visible'] && ! $editorPreview) {
                continue;
            }
            $navigationId = (string) $navigationItem['id'];
            if (! isset($navigationRoutes[$navigationId])) {
                continue;
            }
            $navigationItems[] = [
                'id' => $navigationId,
                'label' => (string) $navigationItem['label'],
                'url' => route($navigationRoutes[$navigationId]),
                'active' => $activeNavigation === $navigationId,
            ];
        }

        return new SiteThemeData([
            'site' => [
                'brand_name' => $siteTitle,
                'logo' => self::firstNonEmpty($variables['siteLogo'] ?? null, $map['site_logo'] ?? null),
                'brand_tagline' => $siteSubtitle,
                'footer_text' => self::firstNonEmpty($variables['siteRemark'] ?? null, $map['site_remark'] ?? null, $siteDescription),
                'copyright' => self::firstNonEmpty($variables['footerCopyright'] ?? null, $map['copyright_info'] ?? null),
            ],
            'navigation' => [
                'items' => $navigationItems,
            ],
            'home' => [
                'hero' => [
                    'title' => $heroTitle,
                    'description' => $heroDescription,
                    'image' => $homeImage !== '' ? ['src' => $homeImage, 'alt' => $siteTitle] : null,
                    'action' => null,
                    'primary_action' => [
                        'label' => '查看产品服务',
                        'target_page' => 'products',
                    ],
                ],
                'brand_strip' => [
                    'label_1' => self::editorField($editorFields, 'brand_label_1', '策略梳理'),
                    'label_2' => self::editorField($editorFields, 'brand_label_2', '产品体验'),
                    'label_3' => self::editorField($editorFields, 'brand_label_3', '内容协作'),
                ],
                'introduction' => [
                    'title' => $introductionTitle,
                    'content' => new HtmlString(nl2br(e($introductionContent))),
                ],
                'services' => [
                    'title' => $servicesTitle,
                    'summary' => $servicesSummary,
                ],
                'workflow' => [
                    'title' => self::editorField($editorFields, 'workflow_title', ''),
                    'summary' => self::editorField($editorFields, 'workflow_summary', ''),
                ],
                'about_band' => [
                    'title' => self::editorField($editorFields, 'about_band_title', '关于我们'),
                    'summary' => self::editorField($editorFields, 'about_band_summary', $siteDescription),
                ],
                'contact_band' => [
                    'title' => self::editorField($editorFields, 'contact_heading', '联系我们'),
                    'summary' => self::editorField($editorFields, 'contact_description', $siteDescription),
                ],
                'news' => [
                    'title' => self::editorField($editorFields, 'news_title', '近期资讯'),
                    'summary' => self::editorField($editorFields, 'news_summary', ''),
                ],
            ],
            'about' => [
                'title' => self::firstNonEmpty($map['about_title'] ?? null, '关于我们'),
                'summary' => self::firstNonEmpty($pageDescription, $siteDescription),
                'content' => $aboutContent,
                'image' => null,
            ],
            'products_services' => [
                'title' => $servicesTitle,
                'summary' => $servicesSummary,
                'items' => self::products($variables['products'] ?? []),
            ],
            'contact' => [
                'title' => self::firstNonEmpty($map['contact_title'] ?? null, '联系我们'),
                'summary' => self::firstNonEmpty($pageDescription, $siteDescription),
                'phone' => self::firstNonEmpty($map['contact_phone'] ?? null, self::contactValue($contactLines, '/^\s*(?:电话|联系电话|手机)\s*[:：]\s*(.+?)\s*$/u')),
                'email' => self::firstNonEmpty($map['contact_email'] ?? null, self::contactValue($contactLines, '/^\s*(?:邮箱|电子邮箱|邮件)\s*[:：]\s*(.+?)\s*$/u')),
                'address' => self::firstNonEmpty($variables['companyAddress'] ?? null, $map['company_address'] ?? null),
                'business_hours' => self::firstNonEmpty($map['business_hours'] ?? null),
                'lines' => $contactLines,
                'content' => $contactContent,
            ],
            'seo' => [
                'home' => [
                    'title' => self::firstNonEmpty($activeNav === 'home' ? $pageTitle : null, $siteTitle),
                    'description' => self::firstNonEmpty($activeNav === 'home' ? $pageDescription : null, $siteDescription),
                ],
                'about' => [
                    'title' => $aboutTitle,
                    'description' => self::firstNonEmpty($activeNav === 'about' ? $pageDescription : null, $siteDescription),
                ],
                'products_services' => [
                    'title' => $productsTitle,
                    'description' => self::firstNonEmpty($activeNav === 'products' ? $pageDescription : null, $siteDescription),
                ],
                'contact' => [
                    'title' => $contactTitle,
                    'description' => self::firstNonEmpty($activeNav === 'contact' ? $pageDescription : null, $siteDescription),
                ],
            ],
            'visual_editor' => [
                'section_order' => $visualEditor['section_order'],
                'hidden_sections' => $visualEditor['hidden_sections'],
                'fields' => $visualEditor['fields'],
                'navigation_order' => $visualEditor['navigation_order'],
                'hidden_navigation' => $visualEditor['hidden_navigation'],
                'navigation_labels' => $visualEditor['navigation_labels'],
            ],
        ]);
    }

    /**
     * @Name: editorField
     *
     * @Description: 优先返回站点装修中明确保存的字段值，包括空字符串；未配置时使用现有主题数据。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 14:09:11
     *
     * @UpdateTime: 2026-11-16 14:09:11
     *
     * @Param array<string,string> $fields 当前主题可编辑字段
     * @Param string $field 字段标识
     * @Param string $fallback 原主题显示内容
     *
     * @Return string 用于主题渲染的安全文本
     *
     * @Throws 无
     */
    private static function editorField(array $fields, string $field, string $fallback): string
    {
        return array_key_exists($field, $fields) ? trim($fields[$field]) : $fallback;
    }

    /**
     * @Name: products
     *
     * @Description: 将当前控制器提供的分页产品转换为主题公共字段，不包含原始 Markdown 正文以避免未清洗内容进入 raw HTML 输出。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 10:52:20
     *
     * @UpdateTime: 2026-11-16 10:52:20
     *
     * @Param mixed $source 控制器提供的产品分页器、集合或数组
     *
     * @Return list<array<string, mixed>> 当前站点的公开产品摘要
     *
     * @Throws 无
     */
    private static function products(mixed $source): array
    {
        if (is_object($source) && method_exists($source, 'getCollection')) {
            $source = $source->getCollection();
        }

        if ($source instanceof Traversable) {
            $source = iterator_to_array($source, false);
        }

        if (! is_array($source)) {
            return [];
        }

        $items = [];
        foreach ($source as $product) {
            $title = self::firstNonEmpty(data_get($product, 'title'));
            $slug = self::firstNonEmpty(data_get($product, 'slug'));
            if ($title === '' || $slug === '') {
                continue;
            }

            $image = self::firstNonEmpty(data_get($product, 'cover_image'));
            $items[] = [
                'service_id' => $slug,
                'name' => $title,
                'summary' => self::firstNonEmpty(data_get($product, 'summary')),
                'details' => '',
                'image' => $image !== '' ? ['src' => $image, 'alt' => $title] : null,
                'url' => route('site.products.show', ['slug' => $slug]),
            ];
        }

        return $items;
    }

    /**
     * @Name: managedPageContent
     *
     * @Description: 仅允许将官网单页控制器已清洗的 HTML 正文绑定到对应关于或联系页面字段。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 10:52:20
     *
     * @UpdateTime: 2026-11-16 10:52:20
     *
     * @Param array<string, mixed> $variables 当前视图变量
     * @Param string $expectedSlug 允许绑定的单页 URL 标识
     *
     * @Return string 已清洗的单页 HTML 或空字符串
     *
     * @Throws 无
     */
    private static function managedPageContent(array $variables, string $expectedSlug): string
    {
        if (trim((string) data_get($variables, 'websitePage.slug', '')) !== $expectedSlug) {
            return '';
        }

        $content = $variables['contentHtml'] ?? '';

        return is_string($content) ? $content : '';
    }

    /**
     * @Name: firstSlideImage
     *
     * @Description: 从控制器提供的首页轮播数据中取首张有效站点图片作为主题公共图片字段。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 10:52:20
     *
     * @UpdateTime: 2026-11-16 10:52:20
     *
     * @Param mixed $slides 首页轮播数据
     *
     * @Return string 首张有效图片地址或空字符串
     *
     * @Throws 无
     */
    private static function firstSlideImage(mixed $slides): string
    {
        if (! is_array($slides)) {
            return '';
        }

        foreach ($slides as $slide) {
            $image = self::firstNonEmpty(is_array($slide) ? ($slide['image_url'] ?? null) : null);
            if ($image !== '') {
                return $image;
            }
        }

        return '';
    }

    /**
     * @Name: contactValue
     *
     * @Description: 从已配置联系信息中按固定标签提取字段值，未匹配时不推断或编造联系方式。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 10:52:20
     *
     * @UpdateTime: 2026-11-16 10:52:20
     *
     * @Param list<string> $lines 联系信息行
     * @Param string $pattern 固定字段的标签匹配表达式
     *
     * @Return string 匹配的联系值或空字符串
     *
     * @Throws 无
     */
    private static function contactValue(array $lines, string $pattern): string
    {
        foreach ($lines as $line) {
            if (preg_match($pattern, $line, $matches) === 1) {
                return trim((string) ($matches[1] ?? ''));
            }
        }

        return '';
    }

    /**
     * @Name: lines
     *
     * @Description: 将多行联系信息整理为去空白的非空文本行。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 10:52:20
     *
     * @UpdateTime: 2026-11-16 10:52:20
     *
     * @Param string $value 原始联系信息
     *
     * @Return list<string> 联系信息行
     *
     * @Throws 无
     */
    private static function lines(string $value): array
    {
        $lines = preg_split('/\r\n|\r|\n/u', $value) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
    }

    /**
     * @Name: stringList
     *
     * @Description: 校验并规范化控制器已提供的字符串列表，忽略非法值和空白项。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 10:52:20
     *
     * @UpdateTime: 2026-11-16 10:52:20
     *
     * @Param mixed $value 原始列表
     *
     * @Return list<string> 非空字符串列表
     *
     * @Throws 无
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $line): string => is_scalar($line) ? trim((string) $line) : '',
            $value
        ), static fn (string $line): bool => $line !== ''));
    }

    /**
     * @Name: firstNonEmpty
     *
     * @Description: 按优先级返回第一个非空标量文本，拒绝将数组或对象强制转成展示字符串。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-16 10:52:20
     *
     * @UpdateTime: 2026-11-16 10:52:20
     *
     * @Param mixed ...$values 候选字段值
     *
     * @Return string 首个有效文本或空字符串
     *
     * @Throws 无
     */
    private static function firstNonEmpty(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }
}
