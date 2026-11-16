<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\Site;
use App\Models\WebsitePage;
use App\Models\WebsiteProduct;
use App\Support\CurrentSite;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Http\Response;

final class SearchOptimizationController extends Controller
{
    /**
     * @Name: sitemap
     * @Description: 输出本站首页、分类、已发布文章、产品和单页的站点地图。
     * @Url [GET] /sitemap.xml
     *      登录 否
     * @Author: cdkay
     * @CreateTime: 2026-11-11 10:46:30
     * @UpdateTime: 2026-11-13 15:39:51
     * @Param CurrentSite $currentSite 当前域名解析后的站点
     * @Return Response XML 站点地图
     * @Throws 无
     */
    public function sitemap(CurrentSite $currentSite): Response
    {
        $site = $currentSite->get();
        $urls = [];
        if ($site instanceof Site) {
            $urls[] = [route('site.home'), $site->updated_at];
            $urls[] = [route('site.news'), $site->updated_at];
            $urls[] = [route('site.products.index'), $site->updated_at];
            $urls[] = [route('site.about'), $site->updated_at];
            $urls[] = [route('site.contact'), $site->updated_at];
            $tenant = fn ($query) => $query
                ->where('site_id', (int) $site->id)
                ->where('owner_admin_id', (int) $site->owner_admin_id);
            foreach (Category::query()->where($tenant)->get(['slug', 'created_at']) as $category) {
                $urls[] = [route('site.category', $category->slug), $category->created_at];
            }
            foreach (Article::query()->where($tenant)->published()->limit(10000)->get(['slug', 'updated_at', 'published_at']) as $article) {
                $urls[] = [route('site.article', $article->slug), $article->updated_at ?: $article->published_at];
            }
            foreach (WebsiteProduct::query()->where($tenant)->published()->limit(10000)->get(['slug', 'updated_at']) as $product) {
                $urls[] = [route('site.products.show', $product->slug), $product->updated_at];
            }
            foreach (WebsitePage::query()->where($tenant)->published()->limit(10000)->get(['slug', 'updated_at']) as $page) {
                $url = match ((string) $page->slug) {
                    'about' => route('site.about'),
                    'contact' => route('site.contact'),
                    default => route('site.page', $page->slug),
                };
                $urls[] = [$url, $page->updated_at];
            }
        }
        $entries = collect($urls)->map(function (array $item): string {
            [$url, $date] = $item;
            $lastmod = $date instanceof \DateTimeInterface ? '<lastmod>'.$date->format('Y-m-d').'</lastmod>' : '';
            return '<url><loc>'.$this->xml((string) $url).'</loc>'.$lastmod.'</url>';
        })->implode('');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$entries.'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    /**
     * @Name: xml
     * @Description: 对站点地图文本执行 XML 1.0 转义，防止特殊字符破坏文档结构。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 11:57:46
     * @UpdateTime: 2026-11-11 11:57:46
     * @Param string $value 待转义文本
     * @Return string 可安全嵌入 XML 节点的文本
     * @Throws 无
     */
    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /**
     * @Name: robots
     * @Description: 输出限制后台及管理应用抓取的 robots.txt，并声明当前站点地图地址。
     * @Url [GET] /robots.txt
     *      登录 否
     * @Author: cdkay
     * @CreateTime: 2026-11-11 11:57:46
     * @UpdateTime: 2026-11-11 11:57:46
     * @Param CurrentSite $currentSite 当前域名解析后的站点
     * @Return Response robots.txt 文本
     * @Throws 无
     */
    public function robots(CurrentSite $currentSite): Response
    {
        $body = implode(PHP_EOL, [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /geo_admin',
            'Disallow: /monitor',
            'Disallow: /website',
            '',
        ]);
        if ($currentSite->get() instanceof Site) {
            $body .= 'Sitemap: '.route('site.sitemap').PHP_EOL;
        }

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * @Name: llms
     * @Description: 输出当前官网简介、入口和已发布文章、产品及单页，供检索系统读取。
     * @Url [GET] /llms.txt
     *      登录 否
     * @Author: cdkay
     * @CreateTime: 2026-11-11 11:57:46
     * @UpdateTime: 2026-11-16 10:09:36
     * @Param CurrentSite $currentSite 当前域名解析后的站点
     * @Return Response llms.txt 文本
     * @Throws 无
     */
    public function llms(CurrentSite $currentSite): Response
    {
        $site = $currentSite->get();
        $settings = SiteSettingsBag::all();
        $name = trim((string) ($settings['organization_name'] ?? $settings['site_name'] ?? $site?->name ?? '网站'));
        $description = trim((string) ($settings['organization_description'] ?? $settings['site_description'] ?? ''));
        $lines = ['# '.$name, ''];
        if ($description !== '') {
            $lines[] = $description;
            $lines[] = '';
        }
        if ($site instanceof Site) {
            $lines[] = '## 官网';
            $lines[] = '- [首页]('.route('site.home').')';
            $lines[] = '- [文章资讯]('.route('site.news').')';
            $lines[] = '- [产品中心]('.route('site.products.index').')';
            $lines[] = '- [关于我们]('.route('site.about').')';
            $lines[] = '- [联系我们]('.route('site.contact').')';
            $lines[] = '';
            $tenant = fn ($query) => $query
                ->where('site_id', (int) $site->id)
                ->where('owner_admin_id', (int) $site->owner_admin_id);
            $lines[] = '## 已发布产品';
            $products = WebsiteProduct::query()->where($tenant)->published()->orderByDesc('is_featured')->orderByDesc('sort_order')->orderByDesc('updated_at')->limit(100)->get(['title', 'slug', 'summary', 'meta_description']);
            foreach ($products as $product) {
                $title = str_replace(['[', ']'], ['&#91;', '&#93;'], (string) $product->title);
                $summary = trim((string) ($product->meta_description ?: $product->summary));
                $lines[] = '- ['.$title.']('.route('site.products.show', $product->slug).')'.($summary !== '' ? ': '.str_replace(PHP_EOL, ' ', $summary) : '');
            }
            $lines[] = '';
            $lines[] = '## 已发布独立页面';
            $pages = WebsitePage::query()->where($tenant)->published()->orderByDesc('sort_order')->orderBy('title')->limit(100)->get(['title', 'slug', 'meta_description']);
            foreach ($pages as $page) {
                $url = match ((string) $page->slug) {
                    'about' => route('site.about'),
                    'contact' => route('site.contact'),
                    default => route('site.page', $page->slug),
                };
                $title = str_replace(['[', ']'], ['&#91;', '&#93;'], (string) $page->title);
                $summary = trim((string) $page->meta_description);
                $lines[] = '- ['.$title.']('.$url.')'.($summary !== '' ? ': '.str_replace(PHP_EOL, ' ', $summary) : '');
            }
            $lines[] = '';
            $lines[] = '## 已发布文章';
            $articles = Article::query()->where($tenant)->published()->orderByDesc('published_at')->limit(100)->get(['title', 'slug', 'meta_description', 'excerpt']);
            foreach ($articles as $article) {
                $title = str_replace(['[', ']'], ['&#91;', '&#93;'], (string) $article->title);
                $summary = trim((string) ($article->meta_description ?: $article->excerpt));
                $lines[] = '- ['.$title.']('.route('site.article', $article->slug).')'.($summary !== '' ? ': '.str_replace(PHP_EOL, ' ', $summary) : '');
            }
        }

        return response(implode(PHP_EOL, $lines).PHP_EOL, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
