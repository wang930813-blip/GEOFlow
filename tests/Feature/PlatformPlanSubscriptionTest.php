<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\SiteResourceLedger;
use App\Models\SiteResourceUsage;
use App\Services\Billing\PlanSubscriptionService;
use App\Services\Billing\ResourceQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformPlanSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_open_plan_subscription_and_grant_plan_credits(): void
    {
        $superAdmin = $this->createAdmin('root_plan_admin', 'super_admin');
        $site = $this->createSite('开通客户站点', $superAdmin);
        $plan = $this->createPlan('专业版季度版', [
            'credits' => ['quota_value' => 3000, 'quota_period' => 'cycle', 'unit' => 'points'],
            'brand_diagnoses' => ['quota_value' => 5, 'quota_period' => 'cycle', 'unit' => 'times'],
        ]);

        $subscription = app(PlanSubscriptionService::class)->open(
            site: $site,
            plan: $plan,
            mode: 'direct',
            ownerAdmin: $superAdmin,
            operator: $superAdmin,
            startsAt: now(),
            endsAt: now()->addDays(90),
            grantCredits: true,
            remark: '线下收款后开通'
        );

        $this->assertSame('active', $subscription->status);
        $this->assertSame('direct', $site->fresh()->customer_mode);
        $this->assertDatabaseHas('site_credit_accounts', [
            'site_id' => (int) $site->id,
            'balance' => '3000.00',
        ]);
        $this->assertDatabaseHas('site_subscription_logs', [
            'site_id' => (int) $site->id,
            'subscription_id' => (int) $subscription->id,
            'action' => 'open',
            'operator_admin_id' => (int) $superAdmin->id,
        ]);
    }

    public function test_resource_quota_consumes_once_for_same_idempotency_key(): void
    {
        $admin = $this->createAdmin('quota_admin', 'admin');
        $site = $this->createSite('额度客户站点', $admin);
        $plan = $this->createPlan('品牌诊断版', [
            'brand_diagnoses' => ['quota_value' => 2, 'quota_period' => 'cycle', 'unit' => 'times'],
        ]);
        app(PlanSubscriptionService::class)->open($site, $plan, 'direct', $admin, $admin, now(), now()->addMonth(), false);

        $quota = app(ResourceQuotaService::class);
        $quota->consume((int) $site->id, 'brand_diagnoses', 1, [
            'actor_admin_id' => (int) $admin->id,
            'idempotency_key' => 'brand-run:100',
        ]);
        $quota->consume((int) $site->id, 'brand_diagnoses', 1, [
            'actor_admin_id' => (int) $admin->id,
            'idempotency_key' => 'brand-run:100',
        ]);

        $this->assertSame(1, SiteResourceUsage::query()->where('site_id', (int) $site->id)->value('used_amount'));
        $this->assertSame(1, SiteResourceLedger::query()->where('site_id', (int) $site->id)->where('type', 'consume')->count());
    }

    public function test_expired_subscription_blocks_resource_consumption(): void
    {
        $admin = $this->createAdmin('expired_admin', 'admin');
        $site = $this->createSite('过期客户站点', $admin);
        $plan = $this->createPlan('过期测试版', [
            'brand_diagnoses' => ['quota_value' => 1, 'quota_period' => 'cycle', 'unit' => 'times'],
        ]);
        app(PlanSubscriptionService::class)->open($site, $plan, 'direct', $admin, $admin, now()->subMonth(), now()->subDay(), false);

        $this->expectExceptionMessage('当前规格已到期，请联系平台续费');

        app(ResourceQuotaService::class)->consume((int) $site->id, 'brand_diagnoses', 1, [
            'actor_admin_id' => (int) $admin->id,
        ]);
    }

    public function test_super_admin_plan_pages_are_available(): void
    {
        $superAdmin = $this->createAdmin('plan_page_admin', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.platform-plans.index'))
            ->assertOk()
            ->assertSee('平台规格');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.plan-subscriptions.index'))
            ->assertOk()
            ->assertSee('客户开通');
    }

    public function test_super_admin_can_create_agent_user_with_site_and_plan_subscription(): void
    {
        $superAdmin = $this->createAdmin('onboard_root_admin', 'super_admin');
        $plan = $this->createPlan('代理开户规格', [
            'credits' => ['quota_value' => 1200, 'quota_period' => 'cycle', 'unit' => 'points'],
            'team_members' => ['quota_value' => 3, 'quota_period' => 'cycle', 'unit' => 'accounts'],
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.admin-users.store'), [
                'username' => 'agent_onboard_user',
                'display_name' => '代理开户用户',
                'email' => 'agent-onboard@example.com',
                'role' => 'agent_admin',
                'password' => 'password-123',
                'confirm_password' => 'password-123',
                'open_customer_subscription' => '1',
                'site_name' => '代理开户站点',
                'site_domain' => 'agent-onboard.example.com',
                'plan_id' => (int) $plan->id,
                'starts_at' => '2026-06-12T10:00',
                'ends_at' => '2026-09-10T10:00',
                'grant_credits' => '1',
                'subscription_remark' => '创建用户时同步开户',
            ])
            ->assertRedirect(route('admin.admin-users.index'));

        $admin = Admin::query()->where('username', 'agent_onboard_user')->firstOrFail();
        $site = Site::query()->where('name', '代理开户站点')->firstOrFail();

        $this->assertSame('agent_admin', (string) $admin->role);
        $this->assertSame((int) $admin->id, (int) $site->owner_admin_id);
        $this->assertSame('agent', (string) $site->customer_mode);
        $this->assertSame((int) $admin->id, (int) $site->agent_admin_id);
        $this->assertDatabaseHas('site_members', [
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'role' => 'owner',
        ]);
        $this->assertDatabaseHas('site_plan_subscriptions', [
            'site_id' => (int) $site->id,
            'plan_id' => (int) $plan->id,
            'mode' => 'agent',
            'owner_admin_id' => (int) $admin->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('site_credit_accounts', [
            'site_id' => (int) $site->id,
            'balance' => '1200.00',
        ]);
    }

    /**
     * @param  array<string,array{quota_value:int,quota_period:string,unit:string}>  $resources
     */
    private function createPlan(string $name, array $resources): PlatformPlan
    {
        $plan = PlatformPlan::query()->create([
            'name' => $name,
            'code' => str()->slug($name).'-'.str()->random(6),
            'audience' => 'both',
            'duration_days' => 90,
            'price' => null,
            'market_price' => null,
            'description' => '',
            'status' => 'active',
            'sort_order' => 0,
        ]);

        foreach ($resources as $key => $resource) {
            $plan->entitlements()->create([
                'resource_key' => $key,
                'enabled' => true,
                'quota_value' => $resource['quota_value'],
                'quota_period' => $resource['quota_period'],
                'unit' => $resource['unit'],
                'meta' => [],
            ]);
        }

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

    private function createSite(string $name, Admin $owner): Site
    {
        $site = Site::query()->create([
            'owner_admin_id' => (int) $owner->id,
            'name' => $name,
            'status' => 'active',
        ]);
        $site->members()->attach((int) $owner->id, ['role' => 'owner']);

        return $site;
    }
}
