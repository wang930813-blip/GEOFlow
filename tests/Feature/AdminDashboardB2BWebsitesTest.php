<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardB2BWebsitesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_shows_b2b_industry_website_cards(): void
    {
        [$admin, $site] = $this->createAdminWithSite('b2b_dashboard_admin');

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.dashboard'));

        $response
            ->assertOk()
            ->assertSee('B2B行业网站')
            ->assertSee('天助网')
            ->assertSee('未开通')
            ->assertSee('开通');
    }

    public function test_dashboard_uses_b2b_website_logo_images_instead_of_initials(): void
    {
        [$admin, $site] = $this->createAdminWithSite('b2b_dashboard_logo_admin');

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.dashboard'));

        $response
            ->assertOk()
            ->assertSee('/assets/b2b-sites/01.png', false)
            ->assertDontSee('>TZ<', false);
    }

    public function test_admin_can_open_b2b_website_for_current_site_and_account(): void
    {
        [$admin, $site] = $this->createAdminWithSite('b2b_open_admin');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.dashboard.b2b-websites.open', ['websiteKey' => 'tianzhu']))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertDatabaseHas('admin_b2b_website_openings', [
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'website_key' => 'tianzhu',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('已开通');
    }

    public function test_b2b_website_open_state_is_isolated_by_site_and_account(): void
    {
        [$adminOne, $site] = $this->createAdminWithSite('b2b_open_one', 'site_user');
        [$adminTwo] = $this->createAdminWithSite('b2b_open_two', 'site_user', $site);

        $this->actingAs($adminOne, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.dashboard.b2b-websites.open', ['websiteKey' => 'tianzhu']))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertDatabaseMissing('admin_b2b_website_openings', [
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $adminTwo->id,
            'website_key' => 'tianzhu',
        ]);

        $this->actingAs($adminTwo, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('天助网')
            ->assertSee('未开通');
    }

    public function test_invalid_b2b_website_key_returns_not_found(): void
    {
        [$admin, $site] = $this->createAdminWithSite('b2b_invalid_admin');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.dashboard.b2b-websites.open', ['websiteKey' => 'missing']))
            ->assertNotFound();
    }

    /**
     * @return array{0: Admin, 1: Site}
     */
    private function createAdminWithSite(string $username, string $role = 'admin', ?Site $site = null): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);

        if (! $site instanceof Site) {
            $site = Site::query()->create([
                'owner_admin_id' => (int) $admin->id,
                'name' => $username.' Site',
                'status' => 'active',
            ]);
        }

        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        return [$admin, $site];
    }
}
