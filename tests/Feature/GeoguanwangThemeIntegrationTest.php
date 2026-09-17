<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Site;
use App\Models\SiteSetting;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoguanwangThemeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_template01_theme_is_available_in_admin_theme_selection(): void
    {
        $admin = Admin::query()->create([
            'username' => 'template01_theme_admin',
            'password' => 'secret-123',
            'email' => 'template01-theme-admin@example.com',
            'display_name' => 'Template 01 Theme Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee('value="template01"', false)
            ->assertSee('中性企业官网模板 001');

        $previewResponse = $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.themes.preview', ['theme' => 'template01']))
            ->assertOk()
            ->assertSee('themes/template01/theme.css', false)
            ->assertSee('themes/template01/theme.js', false)
            ->assertSee('让信息更清晰，让沟通更直接');

        foreach (['about', 'products', 'news', 'contact'] as $page) {
            $previewResponse->assertSee(route('admin.site-settings.themes.preview', [
                'theme' => 'template01',
                'preview_page' => $page,
            ]));
        }
    }

    public function test_template01_preview_keeps_theme_and_selected_site_across_all_pages(): void
    {
        $admin = Admin::query()->create([
            'username' => 'template01_preview_admin',
            'password' => 'secret-123',
            'email' => 'template01-preview-admin@example.com',
            'display_name' => 'Template 01 Preview Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => 'Template 01 Preview Site',
            'status' => 'active',
        ]);
        $site->members()->attach($admin->id, ['role' => 'owner']);

        foreach ([
            'site_name' => '预览专属品牌',
            'site_description' => '这段资料只属于后台当前选择的站点。',
            'site_products' => json_encode([[
                'name' => '预览产品服务',
                'summary' => '用于验证产品页面能够在主题预览中正常打开。',
                'details' => '',
                'image_url' => '',
                'link_url' => '',
                'enabled' => true,
            ]], JSON_UNESCAPED_UNICODE),
        ] as $key => $value) {
            SiteSetting::query()->create([
                'site_id' => $site->id,
                'owner_admin_id' => $admin->id,
                'setting_key' => $key,
                'setting_value' => $value,
            ]);
        }

        $category = Category::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $admin->id,
            'name' => '预览资讯',
            'slug' => 'preview-news',
        ]);
        $author = Author::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $admin->id,
            'name' => '预览编辑',
        ]);
        $article = Article::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $admin->id,
            'title' => '预览主题文章',
            'slug' => 'preview-theme-article',
            'excerpt' => '验证文章详情页继续使用预览主题。',
            'content' => '预览主题文章正文。',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_featured' => true,
            'is_hot' => true,
            'published_at' => now(),
        ]);

        $previewRoute = static fn (string $page, array $query = []): string => route(
            'admin.site-settings.themes.preview',
            ['theme' => 'template01', 'preview_page' => $page, ...$query]
        );

        $homeResponse = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get($previewRoute('home'));

        $homeResponse
            ->assertOk()
            ->assertSee('themes/template01/theme.css', false)
            ->assertSee('预览专属品牌')
            ->assertSee($previewRoute('about'))
            ->assertSee($previewRoute('products'))
            ->assertSee($previewRoute('news'))
            ->assertSee($previewRoute('contact'));

        foreach ([
            'about' => '从真实资料开始',
            'products' => '预览产品服务',
            'news' => '资讯与动态',
            'contact' => '通过公开渠道与我们联系',
        ] as $page => $expectedText) {
            $this->actingAs($admin, 'admin')
                ->withSession(['current_site_id' => $site->id])
                ->get($previewRoute($page))
                ->assertOk()
                ->assertSee('themes/template01/theme.css', false)
                ->assertSee('预览专属品牌')
                ->assertSee($expectedText);
        }

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get($previewRoute('news'))
            ->assertSee($previewRoute('news', ['source' => 'featured']))
            ->assertSee($previewRoute('article', ['slug' => $article->slug]));

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get($previewRoute('article', ['slug' => $article->slug]))
            ->assertOk()
            ->assertSee('themes/template01/theme.css', false)
            ->assertSee('预览主题文章正文');

        $this->assertDatabaseMissing('site_settings', [
            'site_id' => $site->id,
            'setting_key' => 'active_theme',
        ]);
    }

    public function test_products_public_page_has_a_safe_default_view_when_active_theme_has_no_products_template(): void
    {
        $this->get(route('site.products'))
            ->assertOk()
            ->assertSee('产品服务');
    }

    public function test_template01_theme_uses_shared_site_data_across_public_pages(): void
    {
        $this->putSiteSetting('active_theme', 'template01');
        $this->putSiteSetting('site_name', '策影 GEO');
        $this->putSiteSetting('site_subtitle', 'AI 内容生成与发布平台');
        $this->putSiteSetting('site_description', '策影 GEO 帮助企业围绕 AI 搜索、内容生产和渠道发布建立官网内容体系。');
        $this->putSiteSetting('site_remark', '让品牌信息更容易被用户、搜索引擎和 AI 理解。');
        $this->putSiteSetting('contact_info', "hello@example.test\n400-000-0000");
        $this->putSiteSetting('company_address', '上海市浦东新区示例路 100 号');
        $this->putSiteSetting('site_products', json_encode([
            [
                'name' => 'AI 搜索顾问服务',
                'summary' => '围绕品牌官网、AI 搜索可见性和内容资产做系统规划。',
                'details' => '提供品牌内容结构梳理、官网信息表达优化和 AI 可读性建议。',
                'image_url' => '/storage/products/ai-search-consulting.jpg',
                'link_url' => '/products/ai-search-consulting',
                'enabled' => true,
            ],
        ], JSON_UNESCAPED_UNICODE));
        SiteSettingsBag::forget();

        $category = Category::query()->create([
            'name' => '品牌增长',
            'slug' => 'brand-growth',
            'description' => '围绕品牌建设、官网内容和 AI 搜索可见性展开。',
        ]);
        $author = Author::query()->create([
            'name' => 'GEO 编辑部',
        ]);
        Article::query()->create([
            'title' => '如何建设 AI 友好的企业官网',
            'slug' => 'ai-friendly-official-website',
            'excerpt' => '围绕官网结构、内容事实和 AI 可抓取正文建设企业官网。',
            'content' => '官网需要稳定输出品牌事实、服务介绍、资讯内容和联系方式。',
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
            ->assertSee('themes/template01/theme.css', false)
            ->assertSee('themes/template01/theme.js', false)
            ->assertSee('策影 GEO')
            ->assertSee('AI 内容生成与发布平台')
            ->assertSee('策影 GEO 帮助企业围绕 AI 搜索')
            ->assertSee('让品牌信息更容易被用户')
            ->assertSee('AI 搜索顾问服务')
            ->assertSee('如何建设 AI 友好的企业官网')
            ->assertSee('hello@example.test')
            ->assertSee('400-000-0000')
            ->assertSee('上海市浦东新区示例路 100 号')
            ->assertSee('assets/reference/hero-network.png', false)
            ->assertDontSee('AI-ready official website template')
            ->assertDontSee('noindex', false);

        $this->get(route('site.about'))
            ->assertOk()
            ->assertSee('themes/template01/theme.css', false)
            ->assertSee('关于我们')
            ->assertSee('策影 GEO 帮助企业围绕 AI 搜索');

        $this->get(route('site.products'))
            ->assertOk()
            ->assertSee('themes/template01/theme.css', false)
            ->assertSee('产品服务')
            ->assertSee('AI 搜索顾问服务')
            ->assertSee('围绕品牌官网、AI 搜索可见性和内容资产做系统规划。')
            ->assertSee('/storage/products/ai-search-consulting.jpg', false)
            ->assertDontSee('品牌增长');

        $this->get(route('site.news'))
            ->assertOk()
            ->assertSee('themes/template01/theme.css', false)
            ->assertSee('资讯与动态')
            ->assertDontSee('data-news-list', false);

        $this->get(route('site.contact'))
            ->assertOk()
            ->assertSee('themes/template01/theme.css', false)
            ->assertSee('联系我们')
            ->assertSee('hello@example.test')
            ->assertSee('上海市浦东新区示例路 100 号');

        $this->get(route('site.article', ['slug' => 'ai-friendly-official-website']))
            ->assertOk()
            ->assertSee('themes/template01/theme.css', false)
            ->assertSee('如何建设 AI 友好的企业官网')
            ->assertSee('官网需要稳定输出品牌事实');
    }

    public function test_template01_hides_empty_frontend_placeholders_and_uses_site_logo(): void
    {
        $this->putSiteSetting('active_theme', 'template01');
        $this->putSiteSetting('site_name', '策影 GEO');
        $this->putSiteSetting('site_logo', 'https://cdn.example.test/brand-logo.png');
        $this->putSiteSetting('site_subtitle', 'AI 内容生成与发布平台');
        $this->putSiteSetting('site_description', '策影 GEO 帮助企业建立官网内容体系。');
        $this->putSiteSetting('site_remark', '让品牌信息更容易被理解。');
        $this->putSiteSetting('contact_info', "hello@example.test\n400-000-0000");
        $this->putSiteSetting('company_address', '上海市浦东新区示例路 100 号');
        SiteSettingsBag::forget();

        Category::query()->create([
            'name' => '科技',
            'slug' => 'tech',
            'description' => '科技资讯分类不是产品配置。',
        ]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('src="https://cdn.example.test/brand-logo.png"', false)
            ->assertDontSee('data-marquee', false)
            ->assertDontSee('品牌介绍</b>', false)
            ->assertDontSee('信息清晰呈现')
            ->assertDontSee('联系入口')
            ->assertDontSee('沟通直接到达')
            ->assertDontSee('暂无服务内容')
            ->assertDontSee('产品或服务内容更新后将在这里展示。');

        $this->get(route('site.products'))
            ->assertOk()
            ->assertSee('暂无产品')
            ->assertDontSee('科技')
            ->assertDontSee('暂无产品或服务内容。')
            ->assertDontSee('暂无产品服务')
            ->assertDontSee('产品或服务内容更新后将在这里展示。');

        $this->get(route('site.contact'))
            ->assertOk()
            ->assertSee('contact-info-grid', false)
            ->assertSee('contact-info-card', false)
            ->assertSee('hello@example.test')
            ->assertSee('400-000-0000')
            ->assertSee('上海市浦东新区示例路 100 号')
            ->assertDontSee('暂无公开营业时间。');
    }

    public function test_template01_article_sections_are_rendered_by_blade_without_dynamic_feed_hooks(): void
    {
        $this->putSiteSetting('active_theme', 'template01');
        SiteSettingsBag::forget();

        $category = Category::query()->create([
            'name' => 'News',
            'slug' => 'news',
            'description' => 'News category.',
        ]);
        $author = Author::query()->create([
            'name' => 'Editor',
        ]);
        $article = Article::query()->create([
            'title' => 'Blade Only Article',
            'slug' => 'blade-only-article',
            'excerpt' => 'Rendered by the Blade template.',
            'content' => 'The article body is already present in the server rendered HTML.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_featured' => true,
            'is_hot' => true,
            'published_at' => now(),
        ]);
        Article::query()->create([
            'title' => 'Latest Only Article',
            'slug' => 'latest-only-article',
            'excerpt' => 'Only visible in the latest list.',
            'content' => 'This article is not featured.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_featured' => false,
            'is_hot' => false,
            'published_at' => now()->subDay(),
        ]);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('Blade Only Article')
            ->assertDontSee('data-news-list', false)
            ->assertDontSee('data-news-source', false);

        $this->get(route('site.news'))
            ->assertOk()
            ->assertSee('Blade Only Article')
            ->assertSee('Latest Only Article')
            ->assertDontSee('data-news-list', false)
            ->assertDontSee('data-news-source', false)
            ->assertDontSee('data-news-filter', false);

        $this->get(route('site.news', ['source' => 'featured']))
            ->assertOk()
            ->assertSee('Blade Only Article')
            ->assertDontSee('Latest Only Article')
            ->assertDontSee('data-news-list', false);

        $this->get(route('site.article', ['slug' => $article->slug]))
            ->assertOk()
            ->assertSee('The article body is already present in the server rendered HTML.')
            ->assertDontSee('data-article-detail', false);
    }

    public function test_template01_public_article_json_contract_matches_latest_001(): void
    {
        $this->putSiteSetting('active_theme', 'template01');
        SiteSettingsBag::forget();

        $category = Category::query()->create([
            'name' => '品牌增长',
            'slug' => 'brand-growth',
            'description' => '围绕品牌建设、官网内容和 AI 搜索可见性展开。',
        ]);
        $author = Author::query()->create([
            'name' => 'GEO 编辑部',
        ]);
        Article::query()->create([
            'title' => '如何建设 AI 友好的企业官网',
            'slug' => 'ai-friendly-official-website',
            'excerpt' => '围绕官网结构、内容事实和 AI 可抓取正文建设企业官网。',
            'content' => "## 官网建设\n\n官网需要稳定输出品牌事实、服务介绍、资讯内容和联系方式。",
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'is_featured' => true,
            'is_hot' => true,
            'published_at' => now()->setTimezone('UTC'),
        ]);

        $this->getJson('/api/articles?source=latest&page=1&limit=6')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.items.0.slug', 'ai-friendly-official-website')
            ->assertJsonPath('data.items.0.category.slug', 'brand-growth')
            ->assertJsonPath('data.pagination.page', 1)
            ->assertJsonPath('data.pagination.limit', 6)
            ->assertJsonPath('data.pagination.total', 1);

        $this->getJson('/api/articles/ai-friendly-official-website')
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.article.slug', 'ai-friendly-official-website')
            ->assertJsonPath('data.article.category.name', '品牌增长')
            ->assertJsonFragment(['content_html' => '<h2>官网建设</h2>
<p>官网需要稳定输出品牌事实、服务介绍、资讯内容和联系方式。</p>']);

        $this->getJson('/api/articles/missing-article')
            ->assertNotFound()
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('error.code', 'article_not_found');
    }

    private function putSiteSetting(string $key, string $value): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => $key],
            ['setting_value' => $value],
        );
    }
}
