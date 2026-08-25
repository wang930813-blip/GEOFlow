<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 后台栏目管理页最小可用测试：鉴权、页面可达、入口链接正确。
 */
class AdminCategoriesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_admin_login_when_visiting_categories_page(): void
    {
        $this->get(route('admin.categories.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_view_categories_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'categories_admin',
            'password' => 'secret-123',
            'email' => 'categories-admin@example.com',
            'display_name' => 'Categories Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.categories.index'))
            ->assertOk()
            ->assertSee(__('admin.categories.page_title'));
    }

    public function test_articles_page_category_manage_button_points_to_categories_route(): void
    {
        $admin = Admin::query()->create([
            'username' => 'categories_link_admin',
            'password' => 'secret-123',
            'email' => 'categories-link-admin@example.com',
            'display_name' => 'Categories Link Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index'))
            ->assertOk()
            ->assertSee(route('admin.categories.index'));
    }

    public function test_site_user_can_create_category_with_same_name_and_slug_as_another_users_category(): void
    {
        $firstUser = $this->admin('category_owner_first');
        $firstSite = $this->site('Category Owner First Site', $firstUser);
        Category::withoutEvents(fn (): Category => Category::query()->withoutGlobalScopes()->create([
            'name' => 'Shared Category',
            'slug' => 'shared-category',
            'description' => 'Existing category from another account.',
            'sort_order' => 0,
            'site_id' => (int) $firstSite->id,
            'owner_admin_id' => (int) $firstUser->id,
        ]));

        $secondUser = $this->admin('category_owner_second');
        $secondSite = $this->site('Category Owner Second Site', $secondUser);

        $this->actingAs($secondUser, 'admin')
            ->withSession(['current_site_id' => (int) $secondSite->id])
            ->from(route('admin.categories.create'))
            ->post(route('admin.categories.store'), [
                'name' => 'Shared Category',
                'slug' => '',
                'description' => '文章资讯发布',
                'sort_order' => 0,
            ])
            ->assertRedirect(route('admin.categories.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Category::query()->withoutGlobalScopes()->where('name', 'Shared Category')->count());
        $this->assertDatabaseHas('categories', [
            'name' => 'Shared Category',
            'site_id' => (int) $secondSite->id,
            'owner_admin_id' => (int) $secondUser->id,
        ]);
    }

    private function admin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => 'site_user',
            'status' => 'active',
        ]);
    }

    private function site(string $name, Admin $owner): Site
    {
        $site = Site::query()->create([
            'owner_admin_id' => (int) $owner->id,
            'name' => $name,
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        $site->members()->attach((int) $owner->id, ['role' => 'owner']);

        return $site;
    }
}
