<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminCreditAccount;
use App\Models\AdminPlanSubscription;
use App\Models\AdminResourceUsage;
use App\Models\Article;
use App\Models\Category;
use App\Models\Author;
use App\Models\MediaResource;
use App\Models\MediaSubmission;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\SitePlanSubscription;
use App\Services\Billing\AdminPlanSubscriptionService;
use App\Services\Billing\AdminResourceQuotaService;
use App\Support\MediaDistribution\MediaPlatform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformPlanManagementTest extends TestCase
{
    use RefreshDatabase;

    private const CREDIT_DESCRIPTION = '支持官媒和 B2B 网站投放';

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
            ->assertSee('自媒体发布条数')
            ->assertSee('媒体发布条数')
            ->assertSee('B2B网站发布条数')
            ->assertSee('官网发布条数')
            ->assertSee('视频发布条数')
            ->assertSee(self::CREDIT_DESCRIPTION)
            ->assertDontSee('8000条官媒投放')
            ->assertDontSee('600条b2b行业网站投放')
            ->assertDontSee('API Token 数量')
            ->assertDontSee('CreBee 发布次数');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.platform-plans.edit', $plan))
            ->assertOk()
            ->assertSee('编辑规格')
            ->assertSee('详情测试规格')
            ->assertSee('生成视频次数')
            ->assertSee('自媒体发布条数')
            ->assertSee('媒体发布条数')
            ->assertSee('B2B网站发布条数')
            ->assertSee('官网发布条数')
            ->assertSee('视频发布条数')
            ->assertSee(self::CREDIT_DESCRIPTION)
            ->assertDontSee('8000条官媒投放')
            ->assertDontSee('600条b2b行业网站投放')
            ->assertDontSee('API Token 数量')
            ->assertDontSee('CreBee 发布次数');
    }

    public function test_platform_plan_create_page_shows_video_and_crebee_resources(): void
    {
        $superAdmin = $this->admin('plan_resource_root', 'super_admin');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.platform-plans.index'))
            ->assertOk()
            ->assertSee('生成视频次数')
            ->assertSee('自媒体发布条数')
            ->assertSee('媒体发布条数')
            ->assertSee('B2B网站发布条数')
            ->assertSee('官网发布条数')
            ->assertSee('视频发布条数')
            ->assertSee(self::CREDIT_DESCRIPTION)
            ->assertDontSee('8000条官媒投放')
            ->assertDontSee('600条b2b行业网站投放')
            ->assertDontSee('API Token 数量')
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

    public function test_platform_plan_allows_selected_resource_quota_zero_as_unlimited(): void
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
            ->assertSessionHasNoErrors();

        $plan = PlatformPlan::query()->where('code', 'resource_quota_plan')->firstOrFail();

        $this->assertDatabaseHas('platform_plans', [
            'code' => 'resource_quota_plan',
        ]);
        $this->assertDatabaseHas('platform_plan_entitlements', [
            'plan_id' => (int) $plan->id,
            'resource_key' => PlatformPlan::RESOURCE_BRAND_DIAGNOSES,
            'enabled' => true,
            'quota_value' => 0,
            'quota_period' => 'cycle',
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.platform-plans.index'))
            ->assertOk()
            ->assertSee('品牌诊断次数：不限');

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.platform-plans.show', $plan))
            ->assertOk()
            ->assertSee('不限');
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
        $this->assertDatabaseHas('platform_plan_entitlements', [
            'plan_id' => (int) $plan->id,
            'resource_key' => PlatformPlan::RESOURCE_B2B_WEBSITE_PUBLISHES,
            'enabled' => false,
            'quota_value' => 0,
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

    public function test_account_quota_service_treats_enabled_zero_quota_as_unlimited_and_records_usage(): void
    {
        $superAdmin = $this->admin('quota_unlimited_root', 'super_admin');
        $owner = $this->admin('quota_unlimited_direct', 'direct_admin');
        $site = $this->site('Unlimited Quota Site', $owner, 'direct');
        $plan = $this->plan('Unlimited Quota Plan', [
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 0,
        ]);

        app(AdminPlanSubscriptionService::class)->openOwner(
            admin: $owner,
            site: $site,
            plan: $plan,
            mode: 'direct_owner',
            operator: $superAdmin,
            startsAt: now()->subMinute(),
            endsAt: now()->addDays(30),
            grantCredits: false
        );

        $quota = app(AdminResourceQuotaService::class);
        $quota->assertCanUse((int) $owner->id, (int) $site->id, PlatformPlan::RESOURCE_BRAND_DIAGNOSES, 100);
        $quota->consume((int) $owner->id, (int) $site->id, PlatformPlan::RESOURCE_BRAND_DIAGNOSES, 2, [
            'idempotency_key' => 'unlimited-brand-1',
        ]);
        $quota->consume((int) $owner->id, (int) $site->id, PlatformPlan::RESOURCE_BRAND_DIAGNOSES, 3, [
            'idempotency_key' => 'unlimited-brand-2',
        ]);

        $remaining = $quota->remaining((int) $owner->id, (int) $site->id, PlatformPlan::RESOURCE_BRAND_DIAGNOSES);

        $this->assertNull($remaining['quota']);
        $this->assertSame(5, $remaining['used']);
        $this->assertNull($remaining['remaining']);
        $this->assertSame('unlimited', $remaining['period']);
        $this->assertSame(5, (int) AdminResourceUsage::query()
            ->where('admin_id', (int) $owner->id)
            ->where('site_id', (int) $site->id)
            ->where('resource_key', PlatformPlan::RESOURCE_BRAND_DIAGNOSES)
            ->value('used_amount'));

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.plan-usages.index'))
            ->assertOk()
            ->assertSee('剩余 不限')
            ->assertSee('已用 5 / 不限');
    }

    public function test_super_admin_can_soft_delete_plan_even_when_referenced_by_subscriptions(): void
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

        $this->assertSoftDeleted('platform_plans', [
            'id' => (int) $unusedPlan->id,
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.platform-plans.destroy', $usedPlan))
            ->assertRedirect(route('admin.platform-plans.index'));

        $this->assertSoftDeleted('platform_plans', [
            'id' => (int) $usedPlan->id,
        ]);
        $this->assertDatabaseHas('site_plan_subscriptions', [
            'site_id' => (int) $site->id,
            'plan_id' => (int) $usedPlan->id,
            'status' => 'active',
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
            PlatformPlan::RESOURCE_API_TOKENS => 3,
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
            ->assertDontSee('API Token 数量')
            ->assertDontSee('usage_direct');

        $this->actingAs($agent, 'admin')
            ->get(route('admin.plan-usages.index'))
            ->assertOk()
            ->assertSee('usage_agent_user')
            ->assertSee('代理使用情况站点')
            ->assertDontSee('API Token 数量')
            ->assertDontSee('usage_direct');

        $agentResponse = $this->actingAs($agent, 'admin')
            ->get(route('admin.plan-usages.index'));

        $agentResponse->assertOk();
        $agentHtml = $agentResponse->getContent();
        $this->assertSame(1, substr_count($agentHtml, 'data-plan-usage-row'));
        $this->assertStringNotContainsString('<span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-600">usage_agent</span>', $agentHtml);

        $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.plan-usages.index'))
            ->assertOk()
            ->assertSee('usage_agent_user')
            ->assertSee('usage_direct')
            ->assertSee('已用 2 / 5')
            ->assertDontSee('API Token 数量');
    }

    public function test_agent_profile_shows_own_plan_version_but_usage_rows_exclude_agent_subscription(): void
    {
        $superAdmin = $this->admin('profile_plan_root', 'super_admin');
        $agent = $this->admin('profile_plan_agent', 'agent_admin');
        $agentUser = $this->admin('profile_plan_user', 'site_user', $agent);
        $site = $this->site('代理个人中心用户站点', $agentUser, 'agent');
        $site->forceFill(['agent_admin_id' => (int) $agent->id])->save();

        $plan = $this->plan('代理当前版本套餐', [
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 5,
        ]);

        $subscriptionService = app(AdminPlanSubscriptionService::class);
        $subscriptionService->openAgentOwner($agent, $plan, $superAdmin, now()->subMinute(), now()->addDays(30));
        $subscriptionService->inheritForAgentUserFromAccount($agent, $agentUser, $site, $superAdmin);

        $response = $this->actingAs($agent, 'admin')
            ->get(route('admin.profile.index'));

        $response
            ->assertOk()
            ->assertSee('当前规格')
            ->assertSee('代理当前版本套餐')
            ->assertSee('profile_plan_user');

        $html = $response->getContent();
        $this->assertStringNotContainsString('<span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs text-slate-600">profile_plan_agent</span>', $html);
    }

    public function test_agent_profile_usage_rows_hide_team_members_deleted_records_and_render_usage_progress(): void
    {
        $superAdmin = $this->admin('profile_usage_root', 'super_admin');
        $agent = $this->admin('profile_usage_agent', 'agent_admin');
        $activeUser = $this->admin('profile_usage_active_user', 'site_user', $agent);
        $deletedUser = $this->admin('profile_usage_deleted_user', 'site_user', $agent);
        $deletedSiteUser = $this->admin('profile_usage_deleted_site_user', 'site_user', $agent);

        $activeSite = $this->agentUserSite('Profile Active Usage Site', $activeUser, $agent);
        $deletedUserSite = $this->agentUserSite('Profile Deleted User Site', $deletedUser, $agent);
        $deletedSite = $this->agentUserSite('Profile Deleted Site', $deletedSiteUser, $agent);

        $plan = $this->plan('Profile Usage Plan', [
            PlatformPlan::RESOURCE_CREDITS => 1600,
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 10,
            PlatformPlan::RESOURCE_ARTICLE_GENERATIONS => 0,
            PlatformPlan::RESOURCE_TEAM_MEMBERS => 71,
        ]);
        $deletedUserPlan = $this->plan('Profile Deleted User Plan', [
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 5,
        ]);
        $deletedSitePlan = $this->plan('Profile Deleted Site Plan', [
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 5,
        ]);

        $subscriptionService = app(AdminPlanSubscriptionService::class);
        $subscriptionService->openAgentOwner($agent, $plan, $superAdmin, now()->subMinute(), now()->addDays(30));
        $subscriptionService->inheritForAgentUserFromAccount($agent, $activeUser, $activeSite, $superAdmin);
        $activeSubscription = AdminPlanSubscription::query()
            ->where('admin_id', (int) $activeUser->id)
            ->where('site_id', (int) $activeSite->id)
            ->firstOrFail();

        AdminPlanSubscription::query()->create([
            'admin_id' => (int) $deletedUser->id,
            'site_id' => (int) $deletedUserSite->id,
            'plan_id' => (int) $deletedUserPlan->id,
            'inherited_from_admin_id' => (int) $agent->id,
            'mode' => 'agent_user',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDays(30),
            'entitlements_snapshot' => (array) $subscriptionService->activeAgentOwnerSubscription($agent)?->entitlements_snapshot,
        ]);
        AdminPlanSubscription::query()->create([
            'admin_id' => (int) $deletedSiteUser->id,
            'site_id' => (int) $deletedSite->id,
            'plan_id' => (int) $deletedSitePlan->id,
            'inherited_from_admin_id' => (int) $agent->id,
            'mode' => 'agent_user',
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addDays(30),
            'entitlements_snapshot' => (array) $subscriptionService->activeAgentOwnerSubscription($agent)?->entitlements_snapshot,
        ]);

        app(AdminResourceQuotaService::class)->consume((int) $activeUser->id, (int) $activeSite->id, PlatformPlan::RESOURCE_BRAND_DIAGNOSES, 3, [
            'idempotency_key' => 'profile-usage-brand',
        ]);
        app(AdminResourceQuotaService::class)->consume((int) $activeUser->id, (int) $activeSite->id, PlatformPlan::RESOURCE_ARTICLE_GENERATIONS, 2, [
            'idempotency_key' => 'profile-usage-article-unlimited',
        ]);
        $officialResource = $this->officialPackageResource();
        $this->mediaSubmission($activeUser, $activeSite, $officialResource, 'published');
        $this->mediaSubmission($activeUser, $activeSite, $officialResource, 'submitted');
        $this->mediaSubmission($activeUser, $activeSite, $officialResource, 'failed');
        $normalOfficialResource = $this->normalOfficialResource();
        $this->mediaSubmission($activeUser, $activeSite, $normalOfficialResource, 'published');
        $this->mediaSubmission($activeUser, $activeSite, $normalOfficialResource, 'cancelled');
        $b2bResource = $this->b2bPackageResource();
        $this->mediaSubmission($activeUser, $activeSite, $b2bResource, 'published');
        $this->mediaSubmission($activeUser, $activeSite, $b2bResource, 'publishing');
        $this->mediaSubmission($activeUser, $activeSite, $b2bResource, 'cancelled');
        AdminCreditAccount::query()->updateOrCreate([
            'admin_id' => (int) $activeUser->id,
            'site_id' => (int) $activeSite->id,
        ], [
            'balance' => '1594.00',
            'frozen_balance' => '0.00',
            'total_granted' => '1600.00',
            'total_consumed' => '6.00',
        ]);

        $deletedUser->delete();
        $deletedSite->delete();

        $response = $this->actingAs($agent, 'admin')
            ->get(route('admin.profile.index'));

        $response
            ->assertOk()
            ->assertSee('profile_usage_active_user')
            ->assertSee('Profile Active Usage Site')
            ->assertSee('已用 6.00 / 1600')
            ->assertSee('已用 3 / 10')
            ->assertSee('已用 2 / 不限')
            ->assertSee(self::CREDIT_DESCRIPTION)
            ->assertDontSee(PlatformPlan::resourceCatalog()[PlatformPlan::RESOURCE_TEAM_MEMBERS]['label'])
            ->assertDontSee('官媒累计投放')
            ->assertDontSee('B2B行业网站累计投放')
            ->assertDontSee('B2B网站发布条数')
            ->assertDontSee('Profile Deleted User Plan')
            ->assertDontSee('Profile Deleted Site Plan');

        $html = $response->getContent();
        $this->assertStringContainsString('style="width: 0%; min-width: 6px"', $html);
        $this->assertStringContainsString('style="width: 30%"', $html);
        $this->assertStringContainsString('data-resource-key="'.PlatformPlan::RESOURCE_ARTICLE_GENERATIONS.'"', $html);
        $this->assertStringNotContainsString('data-resource-key="official_media_publishes"', $html);
        $this->assertStringNotContainsString('data-resource-key="'.PlatformPlan::RESOURCE_B2B_WEBSITE_PUBLISHES.'"', $html);
        $this->assertStringNotContainsString('已用 400 /', $html);

        $usageResponse = $this->actingAs($agent, 'admin')
            ->get(route('admin.plan-usages.index'));

        $usageResponse
            ->assertOk()
            ->assertSee('profile_usage_active_user')
            ->assertSee('Profile Active Usage Site')
            ->assertSee('已用 6.00 / 1600')
            ->assertSee('已用 3 / 10')
            ->assertSee('已用 2 / 不限')
            ->assertSee(self::CREDIT_DESCRIPTION)
            ->assertDontSee(PlatformPlan::resourceCatalog()[PlatformPlan::RESOURCE_TEAM_MEMBERS]['label'])
            ->assertDontSee('官媒累计投放')
            ->assertDontSee('B2B行业网站累计投放')
            ->assertDontSee('B2B网站发布条数')
            ->assertDontSee('Profile Deleted User Plan')
            ->assertDontSee('Profile Deleted Site Plan');

        $usageHtml = $usageResponse->getContent();
        $this->assertStringContainsString('style="width: 0%; min-width: 6px"', $usageHtml);
        $this->assertStringContainsString('style="width: 30%"', $usageHtml);
        $this->assertStringContainsString('data-resource-key="'.PlatformPlan::RESOURCE_ARTICLE_GENERATIONS.'"', $usageHtml);
        $this->assertStringNotContainsString('data-resource-key="official_media_publishes"', $usageHtml);
        $this->assertStringNotContainsString('data-resource-key="'.PlatformPlan::RESOURCE_B2B_WEBSITE_PUBLISHES.'"', $usageHtml);
        $this->assertStringNotContainsString('已用 400 /', $usageHtml);
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
        $html = $this->usageListSection($response->getContent());
        $agentRow = $this->rowSection($html, 'usage_team_owner', 'data-plan-usage-row');
        $agentUserRow = $this->rowSection($html, 'usage_team_member', 'data-plan-usage-row');
        $directRow = $this->rowSection($html, 'usage_team_direct');

        $this->assertStringContainsString(PlatformPlan::RESOURCE_TEAM_MEMBERS, $agentRow);
        $this->assertStringNotContainsString(PlatformPlan::RESOURCE_TEAM_MEMBERS, $agentUserRow);
        $this->assertStringNotContainsString(PlatformPlan::RESOURCE_TEAM_MEMBERS, $directRow);
    }

    public function test_plan_usage_page_is_paginated_for_large_user_lists(): void
    {
        $superAdmin = $this->admin('usage_pagination_root', 'super_admin');
        $plan = $this->plan('Usage Pagination Plan', [
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 5,
        ]);
        $subscriptionService = app(AdminPlanSubscriptionService::class);

        for ($index = 1; $index <= 21; $index++) {
            $owner = $this->admin(sprintf('usage_page_user_%02d', $index), 'direct_admin', $superAdmin);
            $site = $this->site(sprintf('Usage Page Site %02d', $index), $owner, 'direct');
            $subscriptionService->openOwner($owner, $site, $plan, 'direct_owner', $superAdmin, now()->subMinute(), now()->addDays(30), false);
        }

        $response = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.plan-usages.index'));

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('usage_page_user_21', $html);
        $this->assertSame(20, substr_count($html, 'data-plan-usage-row'));
        $this->assertStringContainsString('page=2', $html);
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

    private function agentUserSite(string $name, Admin $owner, Admin $agent): Site
    {
        $site = Site::query()->create([
            'owner_admin_id' => (int) $owner->id,
            'agent_admin_id' => (int) $agent->id,
            'name' => $name,
            'status' => 'active',
            'customer_mode' => 'agent',
        ]);
        $site->members()->attach((int) $owner->id, ['role' => 'owner']);

        return $site;
    }

    private function b2bPackageResource(): MediaResource
    {
        return MediaResource::query()->create([
            'platform_id' => (int) config('media_distribution.b2b_package.platform_id', MediaPlatform::CEYING_MEDIA_1),
            'source_type' => MediaResource::SOURCE_WEBSITE,
            'external_resource_id' => 'b2b-package-test',
            'title' => (string) config('media_distribution.b2b_package.title', '200家B2B网站套餐'),
            'category' => 'B2B网站套餐',
            'remarks' => 'B2B网站套餐',
            'case_link' => '',
            'status' => 'active',
            'cost_price' => '0.00',
            'sale_price' => '0.00',
            'raw_payload' => [
                'package_size' => (int) config('media_distribution.b2b_package.size', 200),
            ],
            'last_synced_at' => now(),
        ]);
    }

    private function officialPackageResource(): MediaResource
    {
        return MediaResource::query()->create([
            'platform_id' => (int) config('media_distribution.package.platform_id', MediaPlatform::CEYING_MEDIA_2),
            'source_type' => MediaResource::SOURCE_WEBSITE,
            'external_resource_id' => 'official-package-test',
            'title' => (string) config('media_distribution.package.title', '100家特价媒体套餐'),
            'category' => '官媒套餐',
            'remarks' => '官媒套餐',
            'case_link' => '',
            'status' => 'active',
            'cost_price' => '0.00',
            'sale_price' => '0.00',
            'raw_payload' => [
                'package_size' => (int) config('media_distribution.package.size', 100),
            ],
            'last_synced_at' => now(),
        ]);
    }

    private function normalOfficialResource(): MediaResource
    {
        return MediaResource::query()->create([
            'platform_id' => MediaPlatform::CEYING_MEDIA_1,
            'source_type' => MediaResource::SOURCE_WEBSITE,
            'external_resource_id' => 'normal-official-test',
            'title' => '普通官媒资源',
            'category' => '官媒',
            'remarks' => '普通官媒',
            'case_link' => '',
            'status' => 'active',
            'cost_price' => '0.00',
            'sale_price' => '0.00',
            'raw_payload' => [],
            'last_synced_at' => now(),
        ]);
    }

    private function mediaSubmission(Admin $admin, Site $site, MediaResource $resource, string $status): MediaSubmission
    {
        $article = $this->article($admin, $site, 'B2B Test Article '.str()->random(8));

        return MediaSubmission::withoutGlobalScope('current_site')->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'article_id' => (int) $article->id,
            'media_resource_id' => (int) $resource->id,
            'platform_id' => (int) $resource->platform_id,
            'source_type' => (string) $resource->source_type,
            'external_order_nid' => 'b2b-order-'.str()->random(8),
            'agent_order_sn' => 'b2b-agent-'.str()->random(8),
            'preview_token' => str()->random(48),
            'title_snapshot' => (string) $article->title,
            'content_snapshot' => (string) $article->content,
            'cost_price_snapshot' => '0.00',
            'sale_price_snapshot' => '0.00',
            'points_amount' => '0.00',
            'status' => $status,
            'submitted_by_admin_id' => (int) $admin->id,
            'submitted_at' => now(),
        ]);
    }

    private function article(Admin $admin, Site $site, string $title): Article
    {
        $category = Category::query()->firstOrCreate([
            'site_id' => (int) $site->id,
            'slug' => 'test-category',
        ], [
            'name' => '测试分类',
            'description' => '',
            'sort_order' => 0,
        ]);
        $author = Author::query()->firstOrCreate([
            'site_id' => (int) $site->id,
            'email' => 'author-'.$site->id.'@example.com',
        ], [
            'name' => '测试作者',
            'bio' => '',
            'avatar' => '',
            'status' => 'active',
        ]);

        return Article::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'title' => $title,
            'slug' => str()->slug($title).'-'.str()->random(8),
            'excerpt' => '',
            'cover_image' => '',
            'content' => 'B2B test content',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'original_keyword' => '',
            'keywords' => '',
            'meta_description' => '',
            'status' => 'published',
            'review_status' => 'auto_approved',
            'view_count' => 0,
            'is_ai_generated' => 0,
            'published_at' => now(),
        ]);
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

    private function usageListSection(string $html): string
    {
        $start = strpos($html, 'data-plan-usage-row');
        $this->assertNotFalse($start, 'Expected first plan usage row marker missing.');

        return substr($html, (int) $start);
    }
}
