<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Site;
use App\Models\SiteSetting;
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
