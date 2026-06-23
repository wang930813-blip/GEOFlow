<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\Prompt;
use App\Models\Site;
use App\Services\Billing\AdminPlanSubscriptionService;
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

    public function test_agent_admin_can_view_site_management_for_own_site_users_only(): void
    {
        $agent = $this->createAdmin('site_agent_owner', 'agent_admin');
        $otherAgent = $this->createAdmin('site_other_agent_owner', 'agent_admin');
        $member = $this->createAdmin('site_agent_member', 'site_user', $agent);
        $otherMember = $this->createAdmin('site_other_agent_member', 'site_user', $otherAgent);
        $directOwner = $this->createAdmin('site_direct_owner', 'direct_admin');

        $ownSite = Site::query()->create([
            'owner_admin_id' => (int) $member->id,
            'name' => 'Agent Owned Site',
            'domain' => 'agent-owned.geo.xinzhidi.cn',
            'status' => 'active',
            'customer_mode' => 'agent',
            'agent_admin_id' => (int) $agent->id,
        ]);
        $ownSite->members()->attach((int) $member->id, ['role' => 'owner']);

        $otherSite = Site::query()->create([
            'owner_admin_id' => (int) $otherMember->id,
            'name' => 'Other Agent Site',
            'domain' => 'other-agent.geo.xinzhidi.cn',
            'status' => 'active',
            'customer_mode' => 'agent',
            'agent_admin_id' => (int) $otherAgent->id,
        ]);
        $otherSite->members()->attach((int) $otherMember->id, ['role' => 'owner']);

        Site::query()->create([
            'owner_admin_id' => (int) $directOwner->id,
            'name' => 'Direct Customer Site',
            'domain' => 'direct-customer.geo.xinzhidi.cn',
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);

        $this->actingAs($agent, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee(route('admin.sites.manage.index'), false)
            ->assertSee('站点管理');

        $this->actingAs($agent, 'admin')
            ->get(route('admin.sites.manage.index'))
            ->assertOk()
            ->assertSee('Agent Owned Site')
            ->assertSee('site_agent_member')
            ->assertDontSee('Other Agent Site')
            ->assertDontSee('site_other_agent_member')
            ->assertDontSee('Direct Customer Site')
            ->assertDontSee('site_direct_owner');
    }

    public function test_agent_admin_can_create_site_for_own_site_user_only(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $agent = $this->createAdmin('site_create_agent_owner', 'agent_admin');
        $otherAgent = $this->createAdmin('site_create_other_agent', 'agent_admin');
        $member = $this->createAdmin('site_create_member', 'site_user', $agent);
        $otherMember = $this->createAdmin('site_create_other_member', 'site_user', $otherAgent);

        $this->actingAs($agent, 'admin')
            ->post(route('admin.sites.manage.store'), [
                'name' => 'Agent Created Site',
                'domain' => 'https://agent-created.geo.xinzhidi.cn/path',
                'status' => 'active',
                'customer_mode' => 'direct',
                'owner_admin_id' => $member->id,
                'member_ids' => [$member->id],
            ])
            ->assertRedirect(route('admin.sites.manage.index'));

        $site = Site::query()->where('name', 'Agent Created Site')->firstOrFail();

        $this->assertSame('agent-created.geo.xinzhidi.cn', $site->domain);
        $this->assertSame((int) $member->id, (int) $site->owner_admin_id);
        $this->assertSame('agent', (string) $site->customer_mode);
        $this->assertSame((int) $agent->id, (int) $site->agent_admin_id);
        $this->assertDatabaseHas('site_members', [
            'site_id' => (int) $site->id,
            'admin_id' => (int) $member->id,
            'role' => 'owner',
        ]);

        $this->actingAs($agent, 'admin')
            ->post(route('admin.sites.manage.store'), [
                'name' => 'Invalid Agent Site',
                'domain' => 'invalid-agent-site.geo.xinzhidi.cn',
                'status' => 'active',
                'owner_admin_id' => $otherMember->id,
                'member_ids' => [$otherMember->id],
            ])
            ->assertSessionHasErrors('owner_admin_id');

        $this->assertDatabaseMissing('sites', [
            'name' => 'Invalid Agent Site',
        ]);

        $this->actingAs($agent, 'admin')
            ->post(route('admin.sites.manage.store'), [
                'name' => 'Ownerless Agent Site',
                'domain' => 'ownerless-agent-site.geo.xinzhidi.cn',
                'status' => 'active',
                'owner_admin_id' => '',
                'member_ids' => [],
            ])
            ->assertSessionHasErrors('owner_admin_id');

        $this->assertDatabaseMissing('sites', [
            'name' => 'Ownerless Agent Site',
        ]);
    }

    public function test_agent_site_management_shows_inherited_user_account_plan(): void
    {
        $agent = $this->createAdmin('site_plan_agent_owner', 'agent_admin');
        $member = $this->createAdmin('site_plan_member', 'site_user', $agent);
        $plan = PlatformPlan::query()->create([
            'name' => '代理继承规格',
            'code' => 'agent-inherited-site-plan-'.str()->random(6),
            'audience' => 'agent',
            'duration_days' => 30,
            'status' => 'active',
            'sort_order' => 0,
        ]);
        $plan->entitlements()->create([
            'resource_key' => PlatformPlan::RESOURCE_BRAND_DIAGNOSES,
            'enabled' => true,
            'quota_value' => 3,
            'quota_period' => 'cycle',
            'unit' => 'times',
            'meta' => [],
        ]);

        app(AdminPlanSubscriptionService::class)->openAgentOwner(
            agent: $agent,
            plan: $plan,
            operator: $agent,
            startsAt: now()->subMinute(),
            endsAt: now()->addDays(30)
        );

        $site = Site::query()->create([
            'owner_admin_id' => (int) $member->id,
            'name' => '代理用户独立官网',
            'domain' => 'agent-member-site.geo.xinzhidi.cn',
            'status' => 'active',
            'customer_mode' => 'agent',
            'agent_admin_id' => (int) $agent->id,
        ]);
        $site->members()->attach((int) $member->id, ['role' => 'owner']);
        app(AdminPlanSubscriptionService::class)->inheritForAgentUserFromAccount($agent, $member, $site, $agent);

        $response = $this->actingAs($agent, 'admin')
            ->get(route('admin.sites.manage.index'));

        $response
            ->assertOk()
            ->assertSee('代理用户独立官网')
            ->assertSee('代理继承规格')
            ->assertDontSee('未开通');
    }

    public function test_agent_admin_cannot_update_toggle_or_delete_other_sites(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $agent = $this->createAdmin('site_guard_agent_owner', 'agent_admin');
        $otherAgent = $this->createAdmin('site_guard_other_agent', 'agent_admin');
        $member = $this->createAdmin('site_guard_member', 'site_user', $agent);
        $otherMember = $this->createAdmin('site_guard_other_member', 'site_user', $otherAgent);

        $otherSite = Site::query()->create([
            'owner_admin_id' => (int) $otherMember->id,
            'name' => 'Guard Other Site',
            'domain' => 'guard-other.geo.xinzhidi.cn',
            'status' => 'active',
            'customer_mode' => 'agent',
            'agent_admin_id' => (int) $otherAgent->id,
        ]);
        $otherSite->members()->attach((int) $otherMember->id, ['role' => 'owner']);

        $this->actingAs($agent, 'admin')
            ->post(route('admin.sites.manage.update', ['site' => $otherSite->id]), [
                'name' => 'Changed Other Site',
                'domain' => 'changed-other.geo.xinzhidi.cn',
                'status' => 'inactive',
                'owner_admin_id' => $member->id,
                'member_ids' => [$member->id],
            ])
            ->assertForbidden();

        $this->actingAs($agent, 'admin')
            ->post(route('admin.sites.manage.toggle-status', ['site' => $otherSite->id]))
            ->assertForbidden();

        $this->actingAs($agent, 'admin')
            ->post(route('admin.sites.manage.destroy', ['site' => $otherSite->id]))
            ->assertForbidden();

        $otherSite->refresh();
        $this->assertSame('Guard Other Site', $otherSite->name);
        $this->assertSame('active', $otherSite->status);
        $this->assertNull($otherSite->deleted_at);
    }

    public function test_super_admin_can_create_site_with_domain_members_and_default_content_prompts(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $superAdmin = $this->createAdmin('platform_creator', 'super_admin');
        $owner = $this->createAdmin('client_owner', 'admin');
        $member = $this->createAdmin('client_editor', 'admin');
        $defaultPromptNames = Prompt::withoutGlobalScope('current_site')
            ->whereNull('site_id')
            ->where('type', 'content')
            ->pluck('name')
            ->all();

        $this->assertCount(4, $defaultPromptNames);

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

        $createdPrompts = Prompt::withoutGlobalScope('current_site')
            ->where('site_id', $site->id)
            ->where('type', 'content')
            ->whereIn('name', $defaultPromptNames)
            ->pluck('name')
            ->all();

        $this->assertEqualsCanonicalizing($defaultPromptNames, $createdPrompts);
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

    public function test_super_admin_can_soft_delete_site_from_management_page(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);

        $superAdmin = $this->createAdmin('platform_site_deleter', 'super_admin');
        $owner = $this->createAdmin('soft_delete_site_owner', 'direct_admin');
        $site = Site::query()->create([
            'owner_admin_id' => (int) $owner->id,
            'name' => 'Soft Delete Site',
            'domain' => 'soft-delete.geo.xinzhidi.cn',
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        $site->members()->attach((int) $owner->id, ['role' => 'owner']);
        $this->openTestingPlanForSite($site, $owner);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.sites.manage.destroy', ['site' => $site->id]))
            ->assertRedirect(route('admin.sites.manage.index'));

        $this->assertSoftDeleted('sites', ['id' => (int) $site->id]);
        $this->assertDatabaseMissing('site_members', [
            'site_id' => (int) $site->id,
            'admin_id' => (int) $owner->id,
        ]);
        $this->assertDatabaseHas('site_plan_subscriptions', [
            'site_id' => (int) $site->id,
            'status' => 'cancelled',
        ]);
        $this->assertDatabaseHas('admin_plan_subscriptions', [
            'site_id' => (int) $site->id,
            'status' => 'cancelled',
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.sites.manage.index'))
            ->assertOk()
            ->assertDontSee('Soft Delete Site');
    }

    private function createAdmin(string $username, string $role, ?Admin $creator = null): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => str_replace('_', ' ', $username),
            'role' => $role,
            'status' => 'active',
            'created_by' => $creator?->id,
        ]);
    }
}
