<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Site;
use App\Support\CurrentSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSiteContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_creates_and_uses_default_site_context(): void
    {
        $admin = Admin::query()->create([
            'username' => 'tenant_admin',
            'password' => 'secret-123',
            'email' => 'tenant-admin@example.com',
            'display_name' => 'Tenant Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'));

        $response->assertOk();
        $this->assertDatabaseHas('sites', [
            'owner_admin_id' => $admin->id,
            'name' => 'Tenant Admin 的默认站点',
        ]);
        $site = Site::query()->where('owner_admin_id', $admin->id)->firstOrFail();
        $this->assertDatabaseHas('site_members', [
            'site_id' => $site->id,
            'admin_id' => $admin->id,
            'role' => 'owner',
        ]);
        $this->assertSame($site->id, session('current_site_id'));
        $response->assertSee('Tenant Admin 的默认站点');
        $response->assertSee('data-site-switcher-menu', false);
        $response->assertSee(route('admin.sites.switch'), false);
        $this->assertSame(1, substr_count($response->getContent(), route('admin.sites.switch')));
        $response->assertDontSee('<div class="mt-1 text-xs text-gray-500">', false);
    }

    public function test_admin_can_switch_only_to_member_site(): void
    {
        $admin = Admin::query()->create([
            'username' => 'switch_admin',
            'password' => 'secret-123',
            'email' => 'switch-admin@example.com',
            'display_name' => 'Switch Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $ownSite = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => 'Own Site',
            'status' => 'active',
        ]);
        $ownSite->members()->attach($admin->id, ['role' => 'owner']);

        $otherAdmin = Admin::query()->create([
            'username' => 'other_admin',
            'password' => 'secret-123',
            'email' => 'other-admin@example.com',
            'display_name' => 'Other Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $otherSite = Site::query()->create([
            'owner_admin_id' => $otherAdmin->id,
            'name' => 'Other Site',
            'status' => 'active',
        ]);
        $otherSite->members()->attach($otherAdmin->id, ['role' => 'owner']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.sites.switch'), ['site_id' => $otherSite->id])
            ->assertForbidden();

        $this->assertNotSame($otherSite->id, session('current_site_id'));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.sites.switch'), ['site_id' => $ownSite->id])
            ->assertRedirect();

        $this->assertSame($ownSite->id, session('current_site_id'));
        $this->assertSame($ownSite->id, app(CurrentSite::class)->id());
    }

    public function test_super_admin_can_switch_to_any_site(): void
    {
        $superAdmin = Admin::query()->create([
            'username' => 'platform_admin',
            'password' => 'secret-123',
            'email' => 'platform-admin@example.com',
            'display_name' => 'Platform Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $owner = Admin::query()->create([
            'username' => 'site_owner',
            'password' => 'secret-123',
            'email' => 'site-owner@example.com',
            'display_name' => 'Site Owner',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => $owner->id,
            'name' => 'Customer Site',
            'status' => 'active',
        ]);
        $site->members()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.sites.switch'), ['site_id' => $site->id])
            ->assertRedirect();

        $this->assertSame($site->id, session('current_site_id'));
        $this->assertSame($site->id, app(CurrentSite::class)->id());
    }
}
