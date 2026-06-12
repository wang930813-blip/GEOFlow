<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Services\Billing\PlanSubscriptionService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentUserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_agent_admin_can_create_site_user_until_team_member_limit(): void
    {
        $agent = $this->createAdmin('agent_owner', 'agent_admin');
        $site = $this->createSite('代理站点', $agent, 'agent');
        $plan = $this->createPlanWithTeamMembers(2);
        app(PlanSubscriptionService::class)->open($site, $plan, 'agent', $agent, $agent, now(), now()->addMonth(), false);

        $this->actingAs($agent, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.agent-users.index'))
            ->assertOk()
            ->assertSee('代理用户管理')
            ->assertSee('新增普通用户');

        $this->actingAs($agent, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.agent-users.store'), [
                'username' => 'agent_member_one',
                'display_name' => 'Agent Member One',
                'email' => 'agent-member-one@example.com',
                'password' => 'secret-123',
                'confirm_password' => 'secret-123',
            ])
            ->assertRedirect(route('admin.agent-users.index'));

        $this->assertDatabaseHas('admins', [
            'username' => 'agent_member_one',
            'role' => 'site_user',
        ]);
        $this->assertDatabaseHas('site_members', [
            'site_id' => (int) $site->id,
            'role' => 'member',
        ]);

        $this->actingAs($agent, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.agent-users.store'), [
                'username' => 'agent_member_two',
                'display_name' => 'Agent Member Two',
                'email' => 'agent-member-two@example.com',
                'password' => 'secret-123',
                'confirm_password' => 'secret-123',
            ])
            ->assertRedirect(route('admin.agent-users.index'));

        $this->actingAs($agent, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.agent-users.store'), [
                'username' => 'agent_member_three',
                'display_name' => 'Agent Member Three',
                'email' => 'agent-member-three@example.com',
                'password' => 'secret-123',
                'confirm_password' => 'secret-123',
            ])
            ->assertSessionHasErrors('username');
    }

    public function test_direct_admin_cannot_access_agent_user_management_or_create_users(): void
    {
        $direct = $this->createAdmin('direct_owner', 'direct_admin');
        $site = $this->createSite('直客站点', $direct, 'direct');
        $plan = $this->createPlanWithTeamMembers(10);
        app(PlanSubscriptionService::class)->open($site, $plan, 'direct', $direct, $direct, now(), now()->addMonth(), false);

        $this->actingAs($direct, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.agent-users.index'))
            ->assertForbidden();

        $this->actingAs($direct, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.agent-users.store'), [
                'username' => 'direct_member',
                'display_name' => 'Direct Member',
                'email' => 'direct-member@example.com',
                'password' => 'secret-123',
                'confirm_password' => 'secret-123',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('admins', [
            'username' => 'direct_member',
        ]);
    }

    public function test_agent_member_quota_counts_active_members_and_blocks_reenable_over_limit(): void
    {
        $agent = $this->createAdmin('agent_reenable_owner', 'agent_admin');
        $site = $this->createSite('Agent Reenable Site', $agent, 'agent');
        $plan = $this->createPlanWithTeamMembers(1);
        app(PlanSubscriptionService::class)->open($site, $plan, 'agent', $agent, $agent, now(), now()->addMonth(), false);

        $first = $this->createAdmin('agent_reenable_first', 'site_user');
        $second = $this->createAdmin('agent_reenable_second', 'site_user');
        $site->members()->attach((int) $first->id, ['role' => 'member']);
        $site->members()->attach((int) $second->id, ['role' => 'member']);
        $second->update(['status' => 'inactive']);

        $this->actingAs($agent, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.agent-users.index'))
            ->assertOk()
            ->assertSee('1 / 1');

        $this->actingAs($agent, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.agent-users.toggle-status', ['adminId' => $second->id]), [
                'next_status' => 'active',
            ])
            ->assertSessionHasErrors('user');

        $this->assertSame('inactive', $second->refresh()->status);
    }

    private function createPlanWithTeamMembers(int $limit): PlatformPlan
    {
        $plan = PlatformPlan::query()->create([
            'name' => '代理团队版',
            'code' => 'agent-team-'.str()->random(6),
            'audience' => 'agent',
            'duration_days' => 30,
            'status' => 'active',
            'sort_order' => 0,
        ]);
        $plan->entitlements()->create([
            'resource_key' => 'team_members',
            'enabled' => true,
            'quota_value' => $limit,
            'quota_period' => 'cycle',
            'unit' => 'accounts',
            'meta' => [],
        ]);

        return $plan;
    }

    private function createAdmin(string $username, string $role): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function createSite(string $name, Admin $owner, string $mode): Site
    {
        $site = Site::query()->create([
            'owner_admin_id' => (int) $owner->id,
            'name' => $name,
            'status' => 'active',
            'customer_mode' => $mode,
            'agent_admin_id' => $mode === 'agent' ? (int) $owner->id : null,
        ]);
        $site->members()->attach((int) $owner->id, ['role' => 'owner']);

        return $site;
    }
}
