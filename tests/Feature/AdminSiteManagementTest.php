<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Site;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSiteManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_site_management_entry_and_page(): void
    {
        $superAdmin = $this->createAdmin('platform_admin', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.sites.manage.index'), false)
            ->assertSee('站点管理');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.sites.manage.index'))
            ->assertOk()
            ->assertSee('创建站点')
            ->assertSee('全部站点');
    }

    public function test_standard_admin_cannot_manage_sites(): void
    {
        $admin = $this->createAdmin('standard_admin', 'admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.sites.manage.index'))
            ->assertForbidden();
    }

    public function test_super_admin_can_create_site_with_domain_and_members(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $superAdmin = $this->createAdmin('platform_creator', 'super_admin');
        $owner = $this->createAdmin('client_owner', 'admin');
        $member = $this->createAdmin('client_editor', 'admin');

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.sites.manage.store'), [
                'name' => 'Client A Site',
                'domain' => 'https://A.geo.xinzhidi.cn/path',
                'status' => 'active',
                'owner_admin_id' => $owner->id,
                'member_ids' => [$member->id],
            ])
            ->assertRedirect(route('admin.sites.manage.index'));

        $site = Site::query()->where('name', 'Client A Site')->firstOrFail();

        $this->assertSame('a.geo.xinzhidi.cn', $site->domain);
        $this->assertSame($owner->id, $site->owner_admin_id);
        $this->assertDatabaseHas('site_members', [
            'site_id' => $site->id,
            'admin_id' => $owner->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('site_members', [
            'site_id' => $site->id,
            'admin_id' => $member->id,
            'role' => 'admin',
        ]);
    }

    public function test_super_admin_can_update_site_and_toggle_status(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $superAdmin = $this->createAdmin('platform_editor', 'super_admin');
        $owner = $this->createAdmin('old_owner', 'admin');
        $newOwner = $this->createAdmin('new_owner', 'admin');

        $site = Site::query()->create([
            'owner_admin_id' => $owner->id,
            'name' => 'Old Site',
            'domain' => 'old.geo.xinzhidi.cn',
            'status' => 'active',
        ]);
        $site->members()->attach($owner->id, ['role' => 'owner']);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.sites.manage.update', ['site' => $site->id]), [
                'name' => 'New Site',
                'domain' => 'new.geo.xinzhidi.cn',
                'status' => 'inactive',
                'owner_admin_id' => $newOwner->id,
                'member_ids' => [],
            ])
            ->assertRedirect(route('admin.sites.manage.index'));

        $site->refresh();

        $this->assertSame('New Site', $site->name);
        $this->assertSame('new.geo.xinzhidi.cn', $site->domain);
        $this->assertSame('inactive', $site->status);
        $this->assertDatabaseMissing('site_members', [
            'site_id' => $site->id,
            'admin_id' => $owner->id,
        ]);
        $this->assertDatabaseHas('site_members', [
            'site_id' => $site->id,
            'admin_id' => $newOwner->id,
            'role' => 'owner',
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.sites.manage.toggle-status', ['site' => $site->id]))
            ->assertRedirect(route('admin.sites.manage.index'));

        $this->assertSame('active', $site->fresh()->status);
    }

    private function createAdmin(string $username, string $role): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => str_replace('_', ' ', $username),
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
