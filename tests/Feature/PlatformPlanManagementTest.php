<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminCreditAccount;
use App\Models\AdminPlanSubscription;
use App\Models\AdminResourceUsage;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\SitePlanSubscription;
use App\Services\Billing\AdminPlanSubscriptionService;
use App\Services\Billing\AdminResourceQuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformPlanManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_platform_plan_detail_and_edit_page(): void
    {
        $superAdmin = $this->admin('plan_detail_root', 'super_admin');
        $plan = $this->plan('详情测试规格', [
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 8,
            PlatformPlan::RESOURCE_ARTICLE_GENERATIONS => 20,
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.platform-plans.show', $plan))
            ->assertOk()
            ->assertSee('规格详情')
            ->assertSee('详情测试规格')
            ->assertSee('品牌诊断次数')
            ->assertSee('8')
            ->assertSee('生成视频次数')
            ->assertSee('自媒体发布次数')
            ->assertDontSee('CreBee 发布次数');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.platform-plans.edit', $plan))
            ->assertOk()
            ->assertSee('编辑规格')
            ->assertSee('详情测试规格')
            ->assertSee('生成视频次数')
            ->assertSee('自媒体发布次数')
            ->assertDontSee('CreBee 发布次数');
    }

    public function test_platform_plan_create_page_shows_video_and_crebee_resources(): void
    {
        $superAdmin = $this->admin('plan_resource_root', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.platform-plans.index'))
            ->assertOk()
            ->assertSee('生成视频次数')
            ->assertSee('自媒体发布次数')
            ->assertDontSee('CreBee 发布次数');
    }

    public function test_platform_plan_requires_at_least_one_resource_item(): void
    {
        $superAdmin = $this->admin('plan_resource_required_root', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.platform-plans.index'))
            ->post(route('admin.platform-plans.store'), [
                'name' => 'resource required plan',
                'code' => 'resource_required_plan',
                'audience' => 'both',
                'duration_days' => 30,
                'sort_order' => 0,
                'status' => 'active',
                'resources' => [],
            ])
            ->assertRedirect(route('admin.platform-plans.index'))
            ->assertSessionHasErrors(['resources' => '请选择套餐项']);

        $this->assertDatabaseMissing('platform_plans', [
            'code' => 'resource_required_plan',
        ]);
    }

    public function test_platform_plan_requires_selected_resource_quota_greater_than_zero(): void
    {
        $superAdmin = $this->admin('plan_resource_quota_root', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.platform-plans.index'))
            ->post(route('admin.platform-plans.store'), [
                'name' => 'resource quota plan',
                'code' => 'resource_quota_plan',
                'audience' => 'both',
                'duration_days' => 30,
                'sort_order' => 0,
                'status' => 'active',
                'resources' => [
                    PlatformPlan::RESOURCE_BRAND_DIAGNOSES => [
                        'enabled' => '1',
                        'quota_value' => 0,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.platform-plans.index'))
            ->assertSessionHasErrors(['resources.'.PlatformPlan::RESOURCE_BRAND_DIAGNOSES.'.quota_value']);

        $this->assertDatabaseMissing('platform_plans', [
            'code' => 'resource_quota_plan',
        ]);
    }

    public function test_platform_plan_normalizes_resource_quota_with_leading_zeroes(): void
    {
        $superAdmin = $this->admin('plan_resource_leading_zero_root', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->from(route('admin.platform-plans.index'))
            ->post(route('admin.platform-plans.store'), [
                'name' => 'leading zero quota plan',
                'code' => 'leading_zero_quota_plan',
                'audience' => 'both',
                'duration_days' => 30,
                'sort_order' => 0,
                'status' => 'active',
                'resources' => [
                    PlatformPlan::RESOURCE_BRAND_DIAGNOSES => [
                        'enabled' => '1',
                        'quota_value' => '050',
                    ],
                    PlatformPlan::RESOURCE_CREBEE_PUBLISHES => [
                        'enabled' => '1',
                        'quota_value' => '05',
                    ],
                ],
            ])
            ->assertRedirect(route('admin.platform-plans.index'))
            ->assertSessionHasNoErrors();

        $plan = PlatformPlan::query()->where('code', 'leading_zero_quota_plan')->firstOrFail();

        $this->assertDatabaseHas('platform_plan_entitlements', [
            'plan_id' => (int) $plan->id,
            'resource_key' => PlatformPlan::RESOURCE_BRAND_DIAGNOSES,
            'quota_value' => 50,
        ]);
        $this->assertDatabaseHas('platform_plan_entitlements', [
            'plan_id' => (int) $plan->id,
            'resource_key' => PlatformPlan::RESOURCE_CREBEE_PUBLISHES,
            'quota_value' => 5,
        ]);
    }

    public function test_super_admin_can_update_platform_plan_without_updating_existing_subscription_snapshot(): void
    {
        $superAdmin = $this->admin('plan_update_root', 'super_admin');
        $owner = $this->admin('plan_update_direct', 'direct_admin');
        $site = $this->site('编辑规格客户站点', $owner, 'direct');
        $plan = $this->plan('编辑前规格', [
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 3,
        ]);

        app(AdminPlanSubscriptionService::class)->openOwner(
            admin: $owner,
            site: $site,
            plan: $plan,
            mode: 'direct_owner',
            operator: $superAdmin,
            startsAt: now()->subMinute(),
            endsAt: now()->addDays(30),
            grantCredits: false,
            remark: '测试开通'
        );

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.platform-plans.update', $plan), [
                'name' => '编辑后规格',
                'code' => 'updated_plan_code',
                'audience' => 'both',
                'duration_days' => 90,
                'sort_order' => 5,
                'status' => 'active',
                'resources' => [
                    PlatformPlan::RESOURCE_BRAND_DIAGNOSES => [
                        'enabled' => '1',
                        'quota_value' => 10,
                    ],
                ],
            ])
            ->assertRedirect(route('admin.platform-plans.index'));

        $this->assertDatabaseHas('platform_plans', [
            'id' => (int) $plan->id,
            'name' => '编辑后规格',
            'code' => 'updated_plan_code',
            'duration_days' => 90,
        ]);
        $this->assertDatabaseHas('platform_plan_entitlements', [
            'plan_id' => (int) $plan->id,
            'resource_key' => PlatformPlan::RESOURCE_BRAND_DIAGNOSES,
            'quota_value' => 10,
        ]);

        $snapshot = AdminPlanSubscription::query()
            ->where('admin_id', (int) $owner->id)
            ->firstOrFail()
            ->entitlements_snapshot;

        $this->assertSame(3, (int) $snapshot[PlatformPlan::RESOURCE_BRAND_DIAGNOSES]['quota_value']);
    }

    public function test_opening_new_account_plan_resets_usage_to_new_subscription_snapshot(): void
    {
        $superAdmin = $this->admin('plan_switch_root', 'super_admin');
        $owner = $this->admin('plan_switch_direct', 'direct_admin');
        $site = $this->site('Plan Switch Site', $owner, 'direct');
        $firstPlan = $this->plan('Plan Switch First', [
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 2,
        ]);
        $secondPlan = $this->plan('Plan Switch Second', [
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 5,
        ]);
        $subscriptionService = app(AdminPlanSubscriptionService::class);
        $quota = app(AdminResourceQuotaService::class);

        $firstSubscription = $subscriptionService->openOwner(
            admin: $owner,
            site: $site,
            plan: $firstPlan,
            mode: 'direct_owner',
            operator: $superAdmin,
            startsAt: now()->subMinute(),
            endsAt: now()->addDays(30),
            grantCredits: false
        );
        $quota->consume((int) $owner->id, (int) $site->id, PlatformPlan::RESOURCE_BRAND_DIAGNOSES, 2, [
            'idempotency_key' => 'plan-switch-old-usage',
        ]);

        $this->assertSame(0, $quota->remaining((int) $owner->id, (int) $site->id, PlatformPlan::RESOURCE_BRAND_DIAGNOSES)['remaining']);

        $secondSubscription = $subscriptionService->openOwner(
            admin: $owner,
            site: $site,
            plan: $secondPlan,
            mode: 'direct_owner',
            operator: $superAdmin,
            startsAt: now()->subMinute(),
            endsAt: now()->addDays(60),
            grantCredits: false
        );

        $remaining = $quota->remaining((int) $owner->id, (int) $site->id, PlatformPlan::RESOURCE_BRAND_DIAGNOSES);

        $this->assertSame('cancelled', (string) $firstSubscription->refresh()->status);
        $this->assertSame('active', (string) $secondSubscription->refresh()->status);
        $this->assertSame(5, $remaining['quota']);
        $this->assertSame(0, $remaining['used']);
        $this->assertSame(5, $remaining['remaining']);
    }

    public function test_super_admin_can_delete_unused_plan_but_not_referenced_plan(): void
    {
        $superAdmin = $this->admin('plan_delete_root', 'super_admin');
        $unusedPlan = $this->plan('未使用规格', [
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 1,
        ]);
        $usedPlan = $this->plan('已使用规格', [
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 1,
        ]);
        $owner = $this->admin('plan_delete_owner', 'direct_admin');
        $site = $this->site('删除保护站点', $owner, 'direct');

        SitePlanSubscription::query()->create([
            'site_id' => (int) $site->id,
            'plan_id' => (int) $usedPlan->id,
            'mode' => 'direct',
            'owner_admin_id' => (int) $owner->id,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDays(30),
            'entitlements_snapshot' => [],
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.platform-plans.destroy', $unusedPlan))
            ->assertRedirect(route('admin.platform-plans.index'));

        $this->assertDatabaseMissing('platform_plans', [
            'id' => (int) $unusedPlan->id,
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.platform-plans.destroy', $usedPlan))
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('platform_plans', [
            'id' => (int) $usedPlan->id,
        ]);
    }

    public function test_plan_usage_page_limits_rows_for_direct_agent_and_super_admin(): void
    {
        $superAdmin = $this->admin('usage_root', 'super_admin');
        $agent = $this->admin('usage_agent', 'agent_admin');
        $agentUser = $this->admin('usage_agent_user', 'site_user', $agent);
        $direct = $this->admin('usage_direct', 'direct_admin');
        $agentSite = $this->site('代理使用情况站点', $agent, 'agent');
        $directSite = $this->site('直客使用情况站点', $direct, 'direct');
        $plan = $this->plan('使用情况规格', [
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 5,
            PlatformPlan::RESOURCE_ARTICLE_GENERATIONS => 12,
        ]);

        $subscriptionService = app(AdminPlanSubscriptionService::class);
        $subscriptionService->openOwner($agent, $agentSite, $plan, 'agent_owner', $superAdmin, now()->subMinute(), now()->addDays(30), false);
        $agentSite->members()->attach((int) $agentUser->id, ['role' => 'member']);
        $subscriptionService->inheritForAgentUser($agent, $agentUser, $agentSite, $superAdmin);
        $subscriptionService->openOwner($direct, $directSite, $plan, 'direct_owner', $superAdmin, now()->subMinute(), now()->addDays(30), false);

        app(AdminResourceQuotaService::class)->consume((int) $agentUser->id, (int) $agentSite->id, PlatformPlan::RESOURCE_BRAND_DIAGNOSES, 2, [
            'idempotency_key' => 'usage-agent-user-brand',
        ]);
        AdminCreditAccount::query()->create([
            'admin_id' => (int) $agentUser->id,
            'site_id' => (int) $agentSite->id,
            'balance' => '80.00',
            'frozen_balance' => '0.00',
            'total_granted' => '100.00',
            'total_consumed' => '20.00',
        ]);

        $this->actingAs($agentUser, 'admin')
            ->get(route('admin.plan-usages.index'))
            ->assertOk()
            ->assertSee('usage_agent_user')
            ->assertSee('代理使用情况站点')
            ->assertSee('已用 2 / 5')
            ->assertDontSee('usage_direct');

        $this->actingAs($agent, 'admin')
            ->get(route('admin.plan-usages.index'))
            ->assertOk()
            ->assertSee('usage_agent_user')
            ->assertSee('usage_agent')
            ->assertSee('代理使用情况站点')
            ->assertDontSee('usage_direct');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.plan-usages.index'))
            ->assertOk()
            ->assertSee('usage_agent_user')
            ->assertSee('usage_direct')
            ->assertSee('已用 2 / 5');
    }

    public function test_plan_usage_hides_team_member_resource_for_direct_owner_and_agent_user_rows(): void
    {
        $superAdmin = $this->admin('usage_team_root', 'super_admin');
        $agent = $this->admin('usage_team_owner', 'agent_admin');
        $agentUser = $this->admin('usage_team_member', 'site_user', $agent);
        $direct = $this->admin('usage_team_direct', 'direct_admin');
        $agentSite = $this->site('Agent Team Usage Site', $agent, 'agent');
        $directSite = $this->site('Direct Team Usage Site', $direct, 'direct');
        $plan = $this->plan('Team Usage Plan', [
            PlatformPlan::RESOURCE_TEAM_MEMBERS => 3,
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 5,
        ]);

        $subscriptionService = app(AdminPlanSubscriptionService::class);
        $subscriptionService->openOwner($agent, $agentSite, $plan, 'agent_owner', $superAdmin, now()->subMinute(), now()->addDays(30), false);
        $agentSite->members()->attach((int) $agentUser->id, ['role' => 'member']);
        $subscriptionService->inheritForAgentUser($agent, $agentUser, $agentSite, $superAdmin);
        $directSubscription = $subscriptionService->openOwner($direct, $directSite, $plan, 'direct_owner', $superAdmin, now()->subMinute(), now()->addDays(30), false);

        $snapshot = (array) $directSubscription->entitlements_snapshot;
        $snapshot[PlatformPlan::RESOURCE_TEAM_MEMBERS] = [
            'enabled' => true,
            'quota_value' => 3,
            'quota_period' => 'cycle',
            'unit' => 'accounts',
            'meta' => [],
        ];
        $directSubscription->forceFill(['entitlements_snapshot' => $snapshot])->save();

        $response = $this->actingAs($superAdmin, 'admin')->get(route('admin.plan-usages.index'));

        $response->assertOk();
        $html = $response->getContent();
        $agentRow = $this->rowSection($html, 'usage_team_owner', 'usage_team_member');
        $agentUserRow = $this->rowSection($html, 'usage_team_member', 'usage_team_direct');
        $directRow = $this->rowSection($html, 'usage_team_direct');

        $this->assertStringContainsString(PlatformPlan::RESOURCE_TEAM_MEMBERS, $agentRow);
        $this->assertStringNotContainsString(PlatformPlan::RESOURCE_TEAM_MEMBERS, $agentUserRow);
        $this->assertStringNotContainsString(PlatformPlan::RESOURCE_TEAM_MEMBERS, $directRow);
    }

    private function admin(string $username, string $role, ?Admin $creator = null): Admin
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

    private function site(string $name, Admin $owner, string $mode): Site
    {
        $site = Site::query()->create([
            'owner_admin_id' => (int) $owner->id,
            'agent_admin_id' => $mode === 'agent' ? (int) $owner->id : null,
            'name' => $name,
            'status' => 'active',
            'customer_mode' => $mode,
        ]);
        $site->members()->attach((int) $owner->id, ['role' => 'owner']);

        return $site;
    }

    /**
     * @param  array<string,int>  $resources
     */
    private function plan(string $name, array $resources): PlatformPlan
    {
        $plan = PlatformPlan::query()->create([
            'name' => $name,
            'code' => str()->slug($name).'-'.str()->random(8),
            'audience' => 'both',
            'duration_days' => 30,
            'price' => null,
            'market_price' => null,
            'description' => '',
            'status' => 'active',
            'sort_order' => 0,
        ]);

        foreach ($resources as $resourceKey => $quota) {
            $plan->entitlements()->create([
                'resource_key' => $resourceKey,
                'enabled' => true,
                'quota_value' => $quota,
                'quota_period' => 'cycle',
                'unit' => PlatformPlan::resourceCatalog()[$resourceKey]['unit'] ?? 'times',
                'meta' => [],
            ]);
        }

        return $plan;
    }

    private function rowSection(string $html, string $startNeedle, ?string $endNeedle = null): string
    {
        $start = strpos($html, $startNeedle);
        $this->assertNotFalse($start, 'Expected row marker missing: '.$startNeedle);

        if ($endNeedle === null) {
            return substr($html, (int) $start);
        }

        $end = strpos($html, $endNeedle, (int) $start + strlen($startNeedle));
        $this->assertNotFalse($end, 'Expected next row marker missing: '.$endNeedle);

        return substr($html, (int) $start, (int) $end - (int) $start);
    }
}
