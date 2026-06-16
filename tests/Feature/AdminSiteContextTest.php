<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PlatformPlan;
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

    public function test_site_admin_can_create_only_current_site_api_tokens(): void
    {
        $admin = Admin::query()->create([
            'username' => 'site_token_admin',
            'password' => 'secret-123',
            'email' => 'site-token-admin@example.com',
            'display_name' => 'Site Token Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => 'Token Site',
            'status' => 'active',
        ]);
        $site->members()->attach($admin->id, ['role' => 'owner']);
        $this->openTestingPlanForSite($site, $admin);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.api-tokens.index'))
            ->assertOk()
            ->assertSee('Token Site')
            ->assertDontSee('全局 Token（不限制站点）');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.api-tokens.store'), [
                'name' => 'Site Editor Token',
                'scopes' => ['catalog:read', 'articles:write'],
                'expires_at' => now()->addDay()->format('Y-m-d\TH:i'),
                'site_id' => '',
            ])
            ->assertRedirect(route('admin.api-tokens.index'));

        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'Site Editor Token',
            'site_id' => $site->id,
        ]);
    }

    public function test_api_token_quota_counts_current_account_tokens_only(): void
    {
        $admin = Admin::query()->create([
            'username' => 'site_token_account_quota_admin',
            'password' => 'secret-123',
            'email' => 'site-token-account-quota-admin@example.com',
            'display_name' => 'Site Token Account Quota Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $otherAdmin = Admin::query()->create([
            'username' => 'site_token_account_quota_other',
            'password' => 'secret-123',
            'email' => 'site-token-account-quota-other@example.com',
            'display_name' => 'Site Token Account Quota Other',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => 'Token Account Quota Site',
            'status' => 'active',
        ]);
        $site->members()->attach($admin->id, ['role' => 'owner']);
        $site->members()->attach($otherAdmin->id, ['role' => 'member']);
        $this->openTestingPlanForSite($site, $admin, [
            PlatformPlan::RESOURCE_API_TOKENS => ['quota_value' => 1, 'quota_period' => 'cycle', 'unit' => 'tokens'],
        ]);
        $this->openTestingPlanForSite($site, $otherAdmin, [
            PlatformPlan::RESOURCE_API_TOKENS => ['quota_value' => 1, 'quota_period' => 'cycle', 'unit' => 'tokens'],
        ]);

        $otherToken = $otherAdmin->createToken('Other Account Token', ['catalog:read'])->accessToken;
        $otherToken->forceFill(['site_id' => $site->id])->save();

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.api-tokens.store'), [
                'name' => 'Own Account Token',
                'scopes' => ['catalog:read'],
                'expires_at' => now()->addDay()->format('Y-m-d\TH:i'),
                'site_id' => '',
            ])
            ->assertRedirect(route('admin.api-tokens.index'));

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => (int) $admin->id,
            'name' => 'Own Account Token',
            'site_id' => (int) $site->id,
        ]);
    }

    public function test_super_admin_can_create_global_api_tokens(): void
    {
        $superAdmin = Admin::query()->create([
            'username' => 'super_token_admin',
            'password' => 'secret-123',
            'email' => 'super-token-admin@example.com',
            'display_name' => 'Super Token Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $siteOwner = Admin::query()->create([
            'username' => 'super_token_owner',
            'password' => 'secret-123',
            'email' => 'super-token-owner@example.com',
            'display_name' => 'Super Token Owner',
            'role' => 'admin',
            'status' => 'active',
        ]);
        Site::query()->create([
            'owner_admin_id' => $siteOwner->id,
            'name' => 'Selectable Token Site',
            'status' => 'active',
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.api-tokens.index'))
            ->assertOk()
            ->assertSee('全局 Token（不限制站点）')
            ->assertSee('Selectable Token Site');

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.api-tokens.store'), [
                'name' => 'Global Editor Token',
                'scopes' => ['catalog:read'],
                'expires_at' => now()->addDay()->format('Y-m-d\TH:i'),
                'site_id' => '',
            ])
            ->assertRedirect(route('admin.api-tokens.index'));

        $this->assertDatabaseHas('personal_access_tokens', [
            'name' => 'Global Editor Token',
            'site_id' => null,
        ]);
    }

    public function test_site_admin_only_lists_and_revokes_current_site_tokens(): void
    {
        $admin = Admin::query()->create([
            'username' => 'site_token_list_admin',
            'password' => 'secret-123',
            'email' => 'site-token-list-admin@example.com',
            'display_name' => 'Site Token List Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => 'Current Token Site',
            'status' => 'active',
        ]);
        $site->members()->attach($admin->id, ['role' => 'owner']);
        $otherSite = Site::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => 'Other Token Site',
            'status' => 'active',
        ]);

        $ownToken = $admin->createToken('Own Site Token', ['catalog:read'])->accessToken;
        $ownToken->forceFill(['site_id' => $site->id])->save();
        $otherToken = $admin->createToken('Other Site Token', ['catalog:read'])->accessToken;
        $otherToken->forceFill(['site_id' => $otherSite->id])->save();
        $globalToken = $admin->createToken('Global Token', ['catalog:read'])->accessToken;

        $this->actingAs($admin, 'admin')
            ->get(route('admin.api-tokens.index'))
            ->assertOk()
            ->assertSee('Own Site Token')
            ->assertDontSee('Other Site Token')
            ->assertDontSee('Global Token');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.api-tokens.revoke', ['tokenId' => $otherToken->id]))
            ->assertSessionHasErrors();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $globalToken->id]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.api-tokens.revoke', ['tokenId' => $ownToken->id]))
            ->assertRedirect(route('admin.api-tokens.index'));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $ownToken->id]);
    }
}
