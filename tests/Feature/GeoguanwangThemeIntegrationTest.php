<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
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
            ->assertSee('Template 01 官网模板');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.themes.preview', ['theme' => 'template01']))
            ->assertOk()
            ->assertSee('themes/template01/theme.css', false)
            ->assertSee('themes/template01/theme.js', false)
            ->assertSee('AI-ready official website template');
    }

    public function test_template01_theme_homepage_uses_system_site_data(): void
    {
        $this->putSiteSetting('active_theme', 'template01');
        $this->putSiteSetting('site_name', '策影 GEO');
        $this->putSiteSetting('site_subtitle', 'AI 内容生成与发布平台');
        $this->putSiteSetting('site_description', '策影 GEO 帮助企业围绕 AI 搜索、内容生产和渠道发布建立官网内容体系。');
        $this->putSiteSetting('site_remark', '让品牌信息更容易被用户、搜索引擎和 AI 理解。');
        $this->putSiteSetting('contact_info', "hello@example.test\n400-000-0000");
        $this->putSiteSetting('company_address', '上海市浦东新区示例路 100 号');
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
            ->assertSee('品牌增长')
            ->assertSee('如何建设 AI 友好的企业官网')
            ->assertSee('hello@example.test')
            ->assertSee('400-000-0000')
            ->assertSee('上海市浦东新区示例路 100 号')
            ->assertSee('assets/reference/hero-network.png', false)
            ->assertDontSee('noindex', false);
    }

    private function putSiteSetting(string $key, string $value): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => $key],
            ['setting_value' => $value],
        );
    }
}
