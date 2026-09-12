<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Site;
use App\Support\AdminDashboard\B2BIndustryWebsiteCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardB2BWebsitesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_does_not_render_b2b_industry_website_cards(): void
    {
        [$admin, $site] = $this->createAdminWithSite('b2b_dashboard_admin');

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.dashboard'));

        $response
            ->assertOk()
            ->assertDontSee('LinkedIn');
    }

    public function test_b2b_industry_website_page_shows_overseas_cards(): void
    {
        [$admin, $site] = $this->createAdminWithSite('b2b_page_admin');

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.b2b-websites.index'));

        $response
            ->assertOk()
            ->assertSee('LinkedIn')
            ->assertSee('Medium')
            ->assertSee('Stack Exchange')
            ->assertDontSee('Alibaba.com')
            ->assertDontSee('Thomasnet');
    }

    public function test_b2b_website_page_uses_local_logo_images(): void
    {
        [$admin, $site] = $this->createAdminWithSite('b2b_dashboard_logo_admin');
        $catalog = app(B2BIndustryWebsiteCatalog::class)->all();

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.b2b-websites.index'))
            ->assertOk()
            ->assertSee(asset('assets/b2b-sites/linkedin.png'), false)
            ->assertSee(asset('assets/b2b-sites/stack-exchange.png'), false)
            ->getContent();

        $this->assertStringNotContainsString('https://www.google.com/s2/favicons', $html);
        $this->assertStringNotContainsString('>AL<', $html);

        $this->assertCount(20, $catalog);
        foreach ($catalog as $website) {
            $this->assertStringStartsWith('assets/b2b-sites/', $website['logo']);
            $this->assertFileExists(public_path($website['logo']));
        }
    }

    public function test_admin_can_open_b2b_website_for_current_site_and_account(): void
    {
        [$admin, $site] = $this->createAdminWithSite('b2b_open_admin');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.b2b-websites.open', ['websiteKey' => 'linkedin']))
            ->assertRedirect(route('admin.b2b-websites.index'));

        $this->assertDatabaseHas('admin_b2b_website_openings', [
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'website_key' => 'linkedin',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.b2b-websites.index'))
            ->assertOk()
            ->assertSee('LinkedIn');
    }

    public function test_b2b_website_open_state_is_isolated_by_site_and_account(): void
    {
        [$adminOne, $site] = $this->createAdminWithSite('b2b_open_one', 'site_user');
        [$adminTwo] = $this->createAdminWithSite('b2b_open_two', 'site_user', $site);

        $this->actingAs($adminOne, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.b2b-websites.open', ['websiteKey' => 'linkedin']))
            ->assertRedirect(route('admin.b2b-websites.index'));

        $this->assertDatabaseMissing('admin_b2b_website_openings', [
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $adminTwo->id,
            'website_key' => 'linkedin',
        ]);

        $this->actingAs($adminTwo, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.b2b-websites.index'))
            ->assertOk()
            ->assertSee('LinkedIn');
    }

    public function test_invalid_b2b_website_key_returns_not_found(): void
    {
        [$admin, $site] = $this->createAdminWithSite('b2b_invalid_admin');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.b2b-websites.open', ['websiteKey' => 'missing']))
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
