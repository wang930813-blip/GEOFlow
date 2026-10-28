<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Site;
use App\Models\SiteSetting;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDomainResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_frontend_resolves_current_site_from_request_host(): void
    {
        $alpha = $this->createSiteWithArticle('Alpha Site', 'alpha.example.test', 'Alpha Article', 'alpha-article');
        $beta = $this->createSiteWithArticle('Beta Site', 'beta.example.test', 'Beta Article', 'beta-article');

        SiteSetting::query()->create([
            'site_id' => $alpha->id,
            'setting_key' => 'site_name',
            'setting_value' => 'Alpha Public Name',
        ]);
        SiteSetting::query()->create([
            'site_id' => $beta->id,
            'setting_key' => 'site_name',
            'setting_value' => 'Beta Public Name',
        ]);

        $this->get('http://alpha.example.test/')
            ->assertOk()
            ->assertSee('Alpha Public Name')
            ->assertSee('Alpha Article')
            ->assertDontSee('Beta Article')
            ->assertDontSee('Beta Public Name');
    }

    public function test_frontend_assets_are_root_relative_on_custom_domain(): void
    {
        $this->createSiteWithArticle('Alpha Site', 'alpha.example.test', 'Alpha Article', 'alpha-article');

        $this->get('http://alpha.example.test/')
            ->assertOk()
            ->assertSee('src="/js/tailwindcss.play-cdn.js"', false)
            ->assertSee('href="/assets/css/style.css?', false)
            ->assertSee('href="/themes/toutiao-news-20260426/theme.css?', false)
            ->assertSee('href="/assets/css/custom.css?', false)
            ->assertSee('src="/js/lucide.min.js"', false)
            ->assertSee('src="/assets/js/main.js"', false)
            ->assertSee('src="/themes/toutiao-news-20260426/theme.js"', false)
            ->assertDontSee('http://localhost:18080/js', false)
            ->assertDontSee('http://127.0.0.1/js', false);
    }

    public function test_frontend_returns_not_found_for_unbound_host_when_domains_exist(): void
    {
        $this->createSiteWithArticle('Alpha Site', 'alpha.example.test', 'Alpha Article', 'alpha-article');

        $this->get('http://missing.example.test/')
            ->assertNotFound();
    }

    public function test_frontend_resolves_a_customer_owned_domain_without_changing_system_domain(): void
    {
        $site = $this->createSiteWithArticle(
            'Customer Domain Site',
            'generated.geo.xinzhidi.cn',
            'Customer Domain Article',
            'customer-domain-article'
        );

        $site->update(['custom_domain' => 'www.example.com']);

        $this->assertSame('generated.geo.xinzhidi.cn', (string) $site->fresh()->domain);
        $this->get('https://www.example.com/')
            ->assertOk()
            ->assertSee('Customer Domain Article');
    }

    public function test_enabled_custom_https_redirects_plain_http_to_https(): void
    {
        $site = $this->createSiteWithArticle(
            'HTTPS Site',
            'generated.geo.xinzhidi.cn',
            'HTTPS Article',
            'https-article'
        );
        $site->update([
            'custom_domain' => 'www.example.com',
            'custom_https_enabled' => true,
        ]);

        $this->get('http://www.example.com/article/https-article')
            ->assertRedirect('https://www.example.com/article/https-article');
    }

    /**
     * @Name: test_failed_automatic_deployment_does_not_redirect_to_https
     *
     * @Description: 自动部署未成功时保持 HTTP 访问，避免跳转到尚未配置证书的入口。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-28 16:42:03
     *
     * @UpdateTime: 2026-10-28 16:42:03
     *
     * @Return void 请求返回站点页面而不是 HTTPS 重定向
     *
     * @Throws AssertionFailedError HTTPS 跳转条件错误时抛出
     */
    public function test_failed_automatic_deployment_does_not_redirect_to_https(): void
    {
        config(['geoflow.customer_domain_deployer.enabled' => true]);
        $site = $this->createSiteWithArticle(
            'Failed HTTPS Site',
            'generated.geo.xinzhidi.cn',
            'Failed HTTPS Article',
            'failed-https-article'
        );
        $site->update([
            'custom_domain' => 'failed.example.com',
            'custom_https_enabled' => true,
            'custom_domain_deployment_status' => 'failed',
        ]);

        $this->get('http://failed.example.com/article/failed-https-article')
            ->assertOk()
            ->assertSee('Failed HTTPS Article');
    }

    /**
     * @Name: test_active_automatic_deployment_redirects_plain_http_to_https
     *
     * @Description: 自动部署完成后继续将客户域名的明文请求跳转至 HTTPS。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-28 16:42:03
     *
     * @UpdateTime: 2026-10-28 16:42:03
     *
     * @Return void 请求重定向至同路径 HTTPS 地址
     *
     * @Throws AssertionFailedError HTTPS 成功状态没有启用重定向时抛出
     */
    public function test_active_automatic_deployment_redirects_plain_http_to_https(): void
    {
        config(['geoflow.customer_domain_deployer.enabled' => true]);
        $site = $this->createSiteWithArticle(
            'Active HTTPS Site',
            'generated.geo.xinzhidi.cn',
            'Active HTTPS Article',
            'active-https-article'
        );
        $site->update([
            'custom_domain' => 'active.example.com',
            'custom_https_enabled' => true,
            'custom_domain_deployment_status' => 'active',
        ]);

        $this->get('http://active.example.com/article/active-https-article')
            ->assertRedirect('https://active.example.com/article/active-https-article');
    }

    /**
     * @Name: test_non_empty_custom_domains_are_unique_case_insensitive
     *
     * @Description: 数据库唯一索引拒绝大小写不同但归一化后相同的客户域名。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-28 17:59:50
     *
     * @UpdateTime: 2026-10-28 17:59:50
     *
     * @Return void 第二个重复域名写入被数据库拒绝
     *
     * @Throws AssertionFailedError 唯一约束未生效时抛出
     */
    public function test_non_empty_custom_domains_are_unique_case_insensitive(): void
    {
        Site::query()->create([
            'name' => 'First custom domain site',
            'domain' => 'first.geo.xinzhidi.cn',
            'custom_domain' => 'unique.example.com',
            'status' => 'active',
        ]);

        $this->expectException(QueryException::class);

        Site::query()->create([
            'name' => 'Duplicate custom domain site',
            'domain' => 'second.geo.xinzhidi.cn',
            'custom_domain' => 'UNIQUE.EXAMPLE.COM',
            'status' => 'active',
        ]);
    }

    private function createSiteWithArticle(string $siteName, string $domain, string $title, string $slug): Site
    {
        $site = Site::query()->create([
            'name' => $siteName,
            'domain' => $domain,
            'status' => 'active',
        ]);

        $category = Category::query()->create([
            'site_id' => $site->id,
            'name' => $siteName.' Category',
            'slug' => $slug.'-category',
        ]);
        $author = Author::query()->create([
            'site_id' => $site->id,
            'name' => $siteName.' Author',
        ]);

        Article::query()->create([
            'site_id' => $site->id,
            'title' => $title,
            'slug' => $slug,
            'excerpt' => $title.' excerpt',
            'content' => $title.' content',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        return $site;
    }
}
