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
        $this->openAgentPlan($agent, $site, $plan);

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
            'role' => 'owner',
        ]);
        $this->assertDatabaseMissing('site_members', [
            'site_id' => (int) $site->id,
            'admin_id' => (int) Admin::query()->where('username', 'agent_member_one')->value('id'),
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

    public function test_agent_user_create_form_does_not_auto_refill_old_input(): void
    {
        $agent = $this->createAdmin('agent_no_refill_owner', 'agent_admin');
        $site = $this->createSite('Agent No Refill Site', $agent, 'agent');
        $plan = $this->createPlanWithTeamMembers(2);
        $this->openAgentPlan($agent, $site, $plan);

        $this->actingAs($agent, 'admin')
            ->withSession([
                'current_site_id' => (int) $site->id,
                '_old_input' => [
                    'username' => 'stale_agent_member',
                    'display_name' => 'Stale Agent Member',
                    'email' => 'stale-agent-member@example.com',
                ],
            ])
            ->get(route('admin.agent-users.index'))
            ->assertOk()
            ->assertSee('autocomplete="off"', false)
            ->assertDontSee('value="stale_agent_member"', false)
            ->assertDontSee('value="Stale Agent Member"', false)
            ->assertDontSee('value="stale-agent-member@example.com"', false);
    }

    public function test_agent_team_member_limit_uses_account_subscription_not_site_subscription(): void
    {
        $agent = $this->createAdmin('agent_account_quota_owner', 'agent_admin');
        $site = $this->createSite('Agent Account Quota Site', $agent, 'agent');

        $legacySitePlan = $this->createPlanWithTeamMembers(1);
        app(PlanSubscriptionService::class)->open($site, $legacySitePlan, 'agent', $agent, $agent, now(), now()->addMonth(), false);

        $accountPlan = $this->createPlanWithTeamMembers(2);
        app(\App\Services\Billing\AdminPlanSubscriptionService::class)->openOwner(
            admin: $agent,
            site: $site,
            plan: $accountPlan,
            mode: 'agent_owner',
            operator: $agent,
            startsAt: now(),
            endsAt: now()->addMonth(),
            grantCredits: false,
            remark: 'Account quota plan'
        );

        foreach (['one', 'two'] as $suffix) {
            $this->actingAs($agent, 'admin')
                ->withSession(['current_site_id' => (int) $site->id])
                ->post(route('admin.agent-users.store'), [
                    'username' => 'agent_account_quota_member_'.$suffix,
                    'display_name' => 'Agent Account Quota Member '.$suffix,
                    'email' => 'agent-account-quota-member-'.$suffix.'@example.com',
                    'password' => 'secret-123',
                    'confirm_password' => 'secret-123',
                ])
                ->assertRedirect(route('admin.agent-users.index'));
        }

        $this->actingAs($agent, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.agent-users.store'), [
                'username' => 'agent_account_quota_member_three',
                'display_name' => 'Agent Account Quota Member Three',
                'email' => 'agent-account-quota-member-three@example.com',
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
        $this->openAgentPlan($agent, $site, $plan);

        $first = $this->createAdmin('agent_reenable_first', 'site_user', $agent);
        $second = $this->createAdmin('agent_reenable_second', 'site_user', $agent);
        $firstSite = $this->createSite('Agent Reenable First Site', $first, 'agent', $agent);
        $secondSite = $this->createSite('Agent Reenable Second Site', $second, 'agent', $agent);
        app(\App\Services\Billing\AdminPlanSubscriptionService::class)->inheritForAgentUserSite($agent, $first, $site, $firstSite, $agent);
        app(\App\Services\Billing\AdminPlanSubscriptionService::class)->inheritForAgentUserSite($agent, $second, $site, $secondSite, $agent);
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

    public function test_agent_created_user_inherits_independent_account_subscription_and_credits(): void
    {
        $agent = $this->createAdmin('agent_inherit_owner', 'agent_admin');
        $site = $this->createSite('Agent Inherit Site', $agent, 'agent');
        $plan = PlatformPlan::query()->create([
            'name' => 'Agent Inherit Plan',
            'code' => 'agent-inherit-'.str()->random(6),
            'audience' => 'agent',
            'duration_days' => 30,
            'status' => 'active',
            'sort_order' => 0,
        ]);
        $plan->entitlements()->create([
            'resource_key' => PlatformPlan::RESOURCE_CREDITS,
            'enabled' => true,
            'quota_value' => 1000,
            'quota_period' => 'cycle',
            'unit' => 'points',
            'meta' => [],
        ]);
        $plan->entitlements()->create([
            'resource_key' => PlatformPlan::RESOURCE_BRAND_DIAGNOSES,
            'enabled' => true,
            'quota_value' => 3,
            'quota_period' => 'cycle',
            'unit' => 'times',
            'meta' => [],
        ]);
        $plan->entitlements()->create([
            'resource_key' => PlatformPlan::RESOURCE_TEAM_MEMBERS,
            'enabled' => true,
            'quota_value' => 3,
            'quota_period' => 'cycle',
            'unit' => 'accounts',
            'meta' => [],
        ]);

        app(\App\Services\Billing\AdminPlanSubscriptionService::class)->openOwner(
            admin: $agent,
            site: $site,
            plan: $plan,
            mode: 'agent_owner',
            operator: $agent,
            startsAt: now(),
            endsAt: now()->addDays(30),
            grantCredits: true,
            remark: 'Agent owner plan'
        );
        app(PlanSubscriptionService::class)->open($site, $plan, 'agent', $agent, $agent, now(), now()->addMonth(), false);

        $this->actingAs($agent, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->post(route('admin.agent-users.store'), [
                'username' => 'agent_inherit_member',
                'display_name' => 'Agent Inherit Member',
                'email' => 'agent-inherit-member@example.com',
                'password' => 'secret-123',
                'confirm_password' => 'secret-123',
            ])
            ->assertRedirect(route('admin.agent-users.index'));

        $member = Admin::query()->where('username', 'agent_inherit_member')->firstOrFail();
        $memberSite = Site::query()->where('owner_admin_id', (int) $member->id)->firstOrFail();

        $this->assertDatabaseHas('admin_plan_subscriptions', [
            'admin_id' => (int) $member->id,
            'site_id' => (int) $memberSite->id,
            'plan_id' => (int) $plan->id,
            'inherited_from_admin_id' => (int) $agent->id,
            'mode' => 'agent_user',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('admin_credit_accounts', [
            'admin_id' => (int) $member->id,
            'site_id' => (int) $memberSite->id,
            'balance' => '1000.00',
        ]);
        $this->assertDatabaseHas('sites', [
            'id' => (int) $memberSite->id,
            'owner_admin_id' => (int) $member->id,
            'customer_mode' => 'agent',
            'agent_admin_id' => (int) $agent->id,
        ]);
        $this->assertDatabaseHas('site_members', [
            'site_id' => (int) $memberSite->id,
            'admin_id' => (int) $member->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseMissing('site_members', [
            'site_id' => (int) $site->id,
            'admin_id' => (int) $member->id,
        ]);
    }

    public function test_agent_user_list_shows_users_across_agent_owned_customer_sites(): void
    {
        $agent = $this->createAdmin('agent_list_owner', 'agent_admin');
        $agentContextSite = $this->createSite('Agent List Context', $agent, 'agent');
        $plan = $this->createPlanWithTeamMembers(3);
        $this->openAgentPlan($agent, $agentContextSite, $plan);

        $firstUser = $this->createAdmin('agent_list_first', 'site_user', $agent);
        $firstSite = $this->createSite('First Customer Site', $firstUser, 'agent', $agent);
        app(\App\Services\Billing\AdminPlanSubscriptionService::class)->inheritForAgentUserSite($agent, $firstUser, $agentContextSite, $firstSite, $agent);

        $secondUser = $this->createAdmin('agent_list_second', 'site_user', $agent);
        $secondSite = $this->createSite('Second Customer Site', $secondUser, 'agent', $agent);
        app(\App\Services\Billing\AdminPlanSubscriptionService::class)->inheritForAgentUserSite($agent, $secondUser, $agentContextSite, $secondSite, $agent);

        $this->actingAs($agent, 'admin')
            ->withSession(['current_site_id' => (int) $agentContextSite->id])
            ->get(route('admin.agent-users.index'))
            ->assertOk()
            ->assertSee('agent_list_first')
            ->assertSee('First Customer Site')
            ->assertSee('agent_list_second')
            ->assertSee('Second Customer Site')
            ->assertSee('2 / 3');
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

    private function createAdmin(string $username, string $role, ?Admin $creator = null): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
            'created_by' => $creator?->id,
        ]);
    }

    private function createSite(string $name, Admin $owner, string $mode, ?Admin $agent = null): Site
    {
        $site = Site::query()->create([
            'owner_admin_id' => (int) $owner->id,
            'name' => $name,
            'status' => 'active',
            'customer_mode' => $mode,
            'agent_admin_id' => $mode === 'agent' ? (int) ($agent?->id ?? $owner->id) : null,
        ]);
        $site->members()->attach((int) $owner->id, ['role' => 'owner']);

        return $site;
    }

    private function openAgentPlan(Admin $agent, Site $site, PlatformPlan $plan): void
    {
        app(PlanSubscriptionService::class)->open($site, $plan, 'agent', $agent, $agent, now(), now()->addMonth(), false);
        app(\App\Services\Billing\AdminPlanSubscriptionService::class)->openOwner(
            admin: $agent,
            site: $site,
            plan: $plan,
            mode: 'agent_owner',
            operator: $agent,
            startsAt: now(),
            endsAt: now()->addMonth(),
            grantCredits: false,
            remark: 'Agent owner plan'
        );
    }
}
