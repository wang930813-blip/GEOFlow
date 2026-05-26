<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SiteViewLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_front_article_views_are_saved_for_analytics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 10:15:00'));

        $article = $this->publishedArticle();

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.23'])
            ->withHeader('User-Agent', 'GPTBot/1.0')
            ->withHeader('Referer', 'https://example.com/ref')
            ->get('/article/'.$article->slug)
            ->assertOk();

        $this->assertDatabaseHas('view_logs', [
            'article_id' => (int) $article->id,
            'method' => 'GET',
            'path' => '/article/'.$article->slug,
            'route_name' => 'site.article',
            'status_code' => 200,
            'ip_address' => '198.51.100.23',
            'user_agent' => 'GPTBot/1.0',
            'referer' => 'https://example.com/ref',
            'created_at' => '2026-05-21 10:15:00',
        ]);

        Carbon::setTestNow();
    }

    public function test_front_home_views_are_saved_for_path_analytics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 11:20:00'));

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeader('User-Agent', 'Mozilla/5.0')
            ->get('/')
            ->assertOk();

        $this->assertDatabaseHas('view_logs', [
            'article_id' => null,
            'method' => 'GET',
            'path' => '/',
            'route_name' => 'site.home',
            'status_code' => 200,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => '2026-05-21 11:20:00',
        ]);

        Carbon::setTestNow();
    }

    public function test_front_views_are_saved_with_resolved_site_id(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:10:00'));

        $site = Site::query()->create([
            'name' => 'Alpha Site',
            'domain' => 'alpha.test',
            'status' => 'active',
        ]);
        app(CurrentSite::class)->set($site);
        $article = $this->publishedArticle('alpha-domain-article');
        app(CurrentSite::class)->set(null);

        $this->withServerVariables([
            'REMOTE_ADDR' => '198.51.100.77',
        ])
            ->get('http://alpha.test/article/'.$article->slug)
            ->assertOk();

        $this->assertDatabaseHas('view_logs', [
            'site_id' => (int) $site->id,
            'article_id' => (int) $article->id,
            'path' => '/article/'.$article->slug,
            'route_name' => 'site.article',
            'created_at' => '2026-05-21 12:10:00',
        ]);

        Carbon::setTestNow();
    }

    private function publishedArticle(string $slug = 'log-test-article'): Article
    {
        $author = Author::query()->create([
            'name' => '日志作者',
            'slug' => 'log-author',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '日志分类',
            'slug' => 'log-category',
            'status' => 'active',
        ]);

        return Article::query()->create([
            'title' => '日志测试文章',
            'slug' => $slug,
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'view_count' => 0,
            'published_at' => Carbon::parse('2026-05-20 09:00:00'),
        ]);
    }
}
