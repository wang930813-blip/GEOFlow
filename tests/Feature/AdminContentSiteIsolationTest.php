<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminContentSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_list_only_shows_current_site_articles(): void
    {
        [$admin, $site] = $this->createAdminWithSite('content_admin', 'Content Site');
        [$otherAdmin, $otherSite] = $this->createAdminWithSite('other_content_admin', 'Other Content Site');
        $category = Category::query()->create([
            'name' => 'Default Category',
            'slug' => 'default-category',
        ]);
        $author = Author::query()->create([
            'name' => 'Default Author',
        ]);

        Article::query()->create([
            'site_id' => $site->id,
            'title' => 'Current Site Article',
            'slug' => 'current-site-article',
            'content' => 'Current site content',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
        Article::query()->create([
            'site_id' => $otherSite->id,
            'title' => 'Other Site Article',
            'slug' => 'other-site-article',
            'content' => 'Other site content',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => $site->id])
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee('Current Site Article')
            ->assertDontSee('Other Site Article');

        $this->actingAs($otherAdmin, 'admin')
            ->withSession(['current_site_id' => $otherSite->id])
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee('Other Site Article')
            ->assertDontSee('Current Site Article');
    }

    private function createAdminWithSite(string $username, string $siteName): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => $siteName,
            'status' => 'active',
        ]);
        $site->members()->attach($admin->id, ['role' => 'owner']);

        return [$admin, $site];
    }
}
