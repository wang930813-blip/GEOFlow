<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteArticleMarkdownRenderTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_markdown_renders_gfm_tables_and_normalizes_legacy_image_urls(): void
    {
        $html = ArticleHtmlPresenter::markdownToHtml(<<<'MD'
## 二级标题

### 三级标题

| 指标 | 说明 |
| --- | --- |
| API | 已配置 |

![333.png](/uploads/images/2026/04/demo.png)

- [x] 已完成
MD);

        $this->assertStringContainsString('<h2>二级标题</h2>', $html);
        $this->assertStringContainsString('<h3>三级标题</h3>', $html);
        $this->assertStringContainsString('<div class="article-table-wrap"><table class="article-table">', $html);
        $this->assertStringContainsString('src="/storage/uploads/images/2026/04/demo.png"', $html);
        $this->assertStringNotContainsString('333.png', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
    }

    public function test_article_markdown_repairs_common_ai_spacing_outside_fenced_code_blocks(): void
    {
        $html = ArticleHtmlPresenter::markdownToHtml(<<<'MD'
##标题缺少空格

** 核心结论： **正文紧跟粗体。

**适用建议：**正文同样紧跟粗体。

```markdown
##代码中的标题保持原样
** 代码示例： **正文
```
MD);

        $this->assertStringContainsString('<h2>标题缺少空格</h2>', $html);
        $this->assertStringContainsString('<strong>核心结论：</strong> 正文紧跟粗体。', $html);
        $this->assertStringContainsString('<strong>适用建议：</strong> 正文同样紧跟粗体。', $html);
        $this->assertStringContainsString("##代码中的标题保持原样\n** 代码示例： **正文", html_entity_decode($html));
    }

    public function test_published_article_page_outputs_normalized_image_url(): void
    {
        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $article = Article::query()->create([
            'title' => 'Markdown 渲染测试',
            'slug' => 'markdown-render-test',
            'excerpt' => '',
            'content' => "## 小节\n\n![333.png](uploads/images/2026/04/demo.png)\n\n| A | B |\n| --- | --- |\n| 1 | 2 |",
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_ai_generated' => 1,
            'published_at' => now(),
        ]);

        $this->get(route('site.article', $article->slug))
            ->assertOk()
            ->assertSee('src="/storage/uploads/images/2026/04/demo.png"', false)
            ->assertSee('<table class="article-table">', false)
            ->assertDontSee('333.png', false);
    }

    public function test_homepage_uses_explicit_hot_and_featured_articles(): void
    {
        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        Article::query()->create([
            'title' => '首页热门文章',
            'slug' => 'homepage-hot-article',
            'excerpt' => '热门摘要',
            'content' => '热门正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_hot' => true,
            'published_at' => now(),
        ]);
        Article::query()->create([
            'title' => '首页精选文章',
            'slug' => 'homepage-featured-article',
            'excerpt' => '精选摘要',
            'content' => '精选正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_featured' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('热点')
            ->assertSee('首页热门文章')
            ->assertSee('精选文章')
            ->assertSee('首页精选文章');
    }

    public function test_frontend_theme_loads_external_assets_without_inline_css(): void
    {
        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('js/tailwindcss.play-cdn.js', false)
            ->assertSee('js/lucide.min.js', false)
            ->assertSee('themes/toutiao-news-20260426/theme.css', false)
            ->assertSee('themes/toutiao-news-20260426/theme.js', false)
            ->assertSee('application/ld+json', false)
            ->assertDontSee('cdn.tailwindcss.com', false)
            ->assertDontSee('unpkg.com/lucide', false)
            ->assertDontSee('<style>', false)
            ->assertDontSee('data-hot-carousel]).forEach', false);
    }

    public function test_homepage_renders_configured_carousel_and_sidebar_feed_panel(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_name'],
            ['setting_value' => 'GEOFlow Demo']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_description'],
            ['setting_value' => 'Demo homepage description']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'home_carousel_slides'],
            ['setting_value' => json_encode([
                [
                    'image_url' => 'https://example.com/banner-one.jpg',
                    'title' => 'Banner One',
                    'link_url' => '/article/demo',
                    'enabled' => true,
                ],
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSettingsBag::forget();

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('data-home-poster-carousel', false)
            ->assertSee('https://example.com/banner-one.jpg', false)
            ->assertSee('Banner One')
            ->assertSee('GEOFlow Feed')
            ->assertSee('GEOFlow Demo')
            ->assertSee('Demo homepage description');
    }

    public function test_netease_theme_renders_configured_homepage_carousel(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'active_theme'],
            ['setting_value' => 'netease-news-20260507']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'home_carousel_slides'],
            ['setting_value' => json_encode([
                [
                    'image_url' => 'https://example.com/net-ease-banner.jpg',
                    'title' => 'NetEase Banner',
                    'link_url' => '',
                    'enabled' => true,
                ],
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSettingsBag::forget();

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('data-home-poster-carousel', false)
            ->assertSee('https://example.com/net-ease-banner.jpg', false)
            ->assertSee('NetEase Banner');
    }

    public function test_netease_homepage_article_cards_use_cover_image_before_category_initial(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'active_theme'],
            ['setting_value' => 'netease-news-20260507']
        );
        SiteSettingsBag::forget();

        $category = Category::query()->create([
            'name' => '品牌资讯',
            'slug' => 'brand-news',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);

        Article::query()->create([
            'title' => '有封面文章',
            'slug' => 'homepage-cover-card',
            'excerpt' => '有封面摘要',
            'content' => '有封面正文',
            'cover_image' => 'uploads/images/2026/07/cover.jpg',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_featured' => true,
            'published_at' => now(),
        ]);
        Article::query()->create([
            'title' => '无封面文章',
            'slug' => 'homepage-initial-card',
            'excerpt' => '无封面摘要',
            'content' => '无封面正文',
            'cover_image' => '',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_featured' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('class="ne-thumb has-image"', false)
            ->assertSee('src="/storage/uploads/images/2026/07/cover.jpg"', false)
            ->assertSee('href="'.route('site.article', 'homepage-initial-card').'" class="ne-thumb"', false)
            ->assertSee('品');
    }

    public function test_english_netease_theme_renders_configured_homepage_carousel(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'active_theme'],
            ['setting_value' => 'tdwh-netease-news-en-20260508']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'home_carousel_slides'],
            ['setting_value' => json_encode([
                [
                    'image_url' => 'https://example.com/english-banner.jpg',
                    'title' => 'English Banner',
                    'link_url' => '',
                    'enabled' => true,
                ],
            ], JSON_UNESCAPED_UNICODE)]
        );
        SiteSettingsBag::forget();

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('data-home-poster-carousel', false)
            ->assertSee('https://example.com/english-banner.jpg', false)
            ->assertSee('English Banner');
    }

    public function test_technology_theme_keeps_frontend_article_category_and_seo_contracts(): void
    {
        $themeIds = collect(app(SiteThemeCatalog::class)->all())->pluck('id')->all();
        $this->assertContains('tech-insight-20260819', $themeIds);

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'active_theme'],
            ['setting_value' => 'tech-insight-20260819']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_name'],
            ['setting_value' => 'Tech Contract Site']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_description'],
            ['setting_value' => 'Tech contract SEO description']
        );
        SiteSettingsBag::forget();

        $category = Category::query()->create([
            'name' => 'Tech Category',
            'slug' => 'tech-category',
        ]);
        $author = Author::query()->create([
            'name' => 'Tech Author',
        ]);
        $article = Article::query()->create([
            'title' => 'Tech Contract Article',
            'slug' => 'tech-contract-article',
            'excerpt' => 'Tech contract article excerpt.',
            'content' => "## Contract Heading\n\n| Layer | Status |\n| --- | --- |\n| Article data | Preserved |",
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_featured' => true,
            'is_hot' => true,
            'published_at' => now(),
        ]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('tech-insight-theme', false)
            ->assertSee('themes/tech-insight-20260819/theme.css', false)
            ->assertSee('Tech Contract Site')
            ->assertSee('Tech Contract Article')
            ->assertSee('Tech Category')
            ->assertSee('<meta name="description" content="Tech contract SEO description">', false);

        $this->get(route('site.category', $category->slug))
            ->assertOk()
            ->assertSee('tech-insight-theme', false)
            ->assertSee('Tech Category')
            ->assertSee('Tech Contract Article')
            ->assertSee('href="'.route('site.article', $article->slug).'"', false);

        $this->get(route('site.article', $article->slug))
            ->assertOk()
            ->assertSee('tech-insight-theme', false)
            ->assertSee('Tech Contract Article')
            ->assertSee('Tech Author')
            ->assertSee('<table class="article-table">', false)
            ->assertSee('<link rel="canonical" href="'.route('site.article', $article->slug).'">', false);
    }

    public function test_technology_theme_homepage_uses_banner_brand_intro_and_content_grid(): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'active_theme'],
            ['setting_value' => 'tech-insight-20260819']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_name'],
            ['setting_value' => 'Tech Insight Corp']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_description'],
            ['setting_value' => 'A concise technology homepage for knowledge-driven publishing.']
        );
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'site_keywords'],
            ['setting_value' => 'AI内容,技术官网,品牌发布']
        );
        SiteSettingsBag::forget();

        $category = Category::query()->create([
            'name' => 'Product Updates',
            'slug' => 'product-updates',
        ]);
        $author = Author::query()->create([
            'name' => 'Editorial Team',
        ]);
        Article::query()->create([
            'title' => 'Release Notes for Tech Insight',
            'slug' => 'release-notes-tech-insight',
            'excerpt' => 'A short update for the homepage flow.',
            'content' => "## Release Notes\n\n| Item | Status |\n| --- | --- |\n| Homepage | Updated |",
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_featured' => true,
            'published_at' => now(),
        ]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('tx-banner-carousel', false)
            ->assertSee('tx-brand-intro', false)
            ->assertSee('tx-content-grid', false)
            ->assertSee('tech-banner-service.png', false)
            ->assertSee('tech-banner-future.png', false)
            ->assertSee('Tech Insight Corp')
            ->assertSee('A concise technology homepage for knowledge-driven publishing.')
            ->assertSee('AI内容')
            ->assertSee('Release Notes for Tech Insight');
    }
}
