<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAccountPlanSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_level_billing_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('admin_plan_subscriptions'));
        $this->assertTrue(Schema::hasTable('admin_resource_usages'));
        $this->assertTrue(Schema::hasTable('admin_resource_ledger'));
        $this->assertTrue(Schema::hasTable('admin_credit_accounts'));
        $this->assertTrue(Schema::hasTable('admin_credit_ledger'));

        $this->assertTrue(Schema::hasColumns('admin_plan_subscriptions', [
            'admin_id',
            'site_id',
            'plan_id',
            'source_subscription_id',
            'inherited_from_admin_id',
            'mode',
            'status',
            'starts_at',
            'ends_at',
            'entitlements_snapshot',
        ]));

        $this->assertTrue(Schema::hasColumns('admin_credit_accounts', [
            'admin_id',
            'site_id',
            'balance',
            'frozen_balance',
            'total_granted',
            'total_consumed',
        ]));
    }

    public function test_admin_has_account_level_billing_relationships(): void
    {
        $admin = \App\Models\Admin::query()->create([
            'username' => 'account_billing_owner',
            'password' => 'secret-123',
            'email' => 'account-billing-owner@example.com',
            'display_name' => 'Account Billing Owner',
            'role' => 'direct_admin',
            'status' => 'active',
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $admin->accountPlanSubscriptions());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class, $admin->creditAccount());
    }

    public function test_open_owner_account_subscription_grants_independent_credit_account(): void
    {
        $admin = \App\Models\Admin::query()->create([
            'username' => 'direct_account_owner',
            'password' => 'secret-123',
            'email' => 'direct-account-owner@example.com',
            'display_name' => 'Direct Account Owner',
            'role' => 'direct_admin',
            'status' => 'active',
        ]);
        $site = \App\Models\Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Direct Account Site',
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        $plan = $this->accountPlanWithResources([
            \App\Models\PlatformPlan::RESOURCE_CREDITS => ['quota_value' => 1000, 'unit' => 'points'],
            \App\Models\PlatformPlan::RESOURCE_BRAND_DIAGNOSES => ['quota_value' => 10, 'unit' => 'times'],
        ]);

        $subscription = app(\App\Services\Billing\AdminPlanSubscriptionService::class)->openOwner(
            admin: $admin,
            site: $site,
            plan: $plan,
            mode: 'direct_owner',
            operator: $admin,
            startsAt: now(),
            endsAt: now()->addDays(30),
            grantCredits: true,
            remark: 'Direct owner opened'
        );

        $this->assertSame((int) $admin->id, (int) $subscription->admin_id);
        $this->assertDatabaseHas('admin_credit_accounts', [
            'admin_id' => (int) $admin->id,
            'site_id' => (int) $site->id,
            'balance' => '1000.00',
        ]);
    }

    public function test_two_users_under_same_agent_have_independent_quota_usage(): void
    {
        $agent = \App\Models\Admin::query()->create([
            'username' => 'agent_quota_owner',
            'password' => 'secret-123',
            'email' => 'agent-quota-owner@example.com',
            'display_name' => 'Agent Quota Owner',
            'role' => 'agent_admin',
            'status' => 'active',
        ]);
        $userOne = \App\Models\Admin::query()->create([
            'username' => 'agent_quota_user_one',
            'password' => 'secret-123',
            'email' => 'agent-quota-user-one@example.com',
            'display_name' => 'Agent Quota User One',
            'role' => 'site_user',
            'status' => 'active',
            'created_by' => (int) $agent->id,
        ]);
        $userTwo = \App\Models\Admin::query()->create([
            'username' => 'agent_quota_user_two',
            'password' => 'secret-123',
            'email' => 'agent-quota-user-two@example.com',
            'display_name' => 'Agent Quota User Two',
            'role' => 'site_user',
            'status' => 'active',
            'created_by' => (int) $agent->id,
        ]);
        $site = \App\Models\Site::query()->create([
            'owner_admin_id' => (int) $agent->id,
            'name' => 'Agent Quota Site',
            'status' => 'active',
            'customer_mode' => 'agent',
            'agent_admin_id' => (int) $agent->id,
        ]);
        $site->members()->attach((int) $agent->id, ['role' => 'owner']);
        $site->members()->attach((int) $userOne->id, ['role' => 'member']);
        $site->members()->attach((int) $userTwo->id, ['role' => 'member']);

        $plan = $this->accountPlanWithResources([
            \App\Models\PlatformPlan::RESOURCE_BRAND_DIAGNOSES => ['quota_value' => 1, 'unit' => 'times'],
        ]);
        $subscriptionService = app(\App\Services\Billing\AdminPlanSubscriptionService::class);
        $subscriptionService->openOwner($agent, $site, $plan, 'agent_owner', $agent, now(), now()->addDays(30), false);
        $subscriptionService->inheritForAgentUser($agent, $userOne, $site, $agent);
        $subscriptionService->inheritForAgentUser($agent, $userTwo, $site, $agent);

        $quota = app(\App\Services\Billing\AdminResourceQuotaService::class);
        $quota->consume((int) $userOne->id, (int) $site->id, \App\Models\PlatformPlan::RESOURCE_BRAND_DIAGNOSES, 1, [
            'actor_admin_id' => (int) $userOne->id,
            'idempotency_key' => 'user-one-brand-run',
        ]);
        $quota->consume((int) $userTwo->id, (int) $site->id, \App\Models\PlatformPlan::RESOURCE_BRAND_DIAGNOSES, 1, [
            'actor_admin_id' => (int) $userTwo->id,
            'idempotency_key' => 'user-two-brand-run',
        ]);

        $this->assertDatabaseHas('admin_resource_usages', [
            'admin_id' => (int) $userOne->id,
            'resource_key' => \App\Models\PlatformPlan::RESOURCE_BRAND_DIAGNOSES,
            'used_amount' => 1,
        ]);
        $this->assertDatabaseHas('admin_resource_usages', [
            'admin_id' => (int) $userTwo->id,
            'resource_key' => \App\Models\PlatformPlan::RESOURCE_BRAND_DIAGNOSES,
            'used_amount' => 1,
        ]);

        $this->expectExceptionMessage('当前账号规格额度不足');

        $quota->consume((int) $userOne->id, (int) $site->id, \App\Models\PlatformPlan::RESOURCE_BRAND_DIAGNOSES, 1, [
            'actor_admin_id' => (int) $userOne->id,
            'idempotency_key' => 'user-one-second-brand-run',
        ]);
    }

    private function accountPlanWithResources(array $resources): \App\Models\PlatformPlan
    {
        $plan = \App\Models\PlatformPlan::query()->create([
            'name' => 'Account Plan '.str()->random(6),
            'code' => 'account-plan-'.str()->random(12),
            'audience' => 'both',
            'duration_days' => 30,
            'status' => 'active',
            'sort_order' => 0,
        ]);

        foreach ($resources as $resourceKey => $resource) {
            $plan->entitlements()->create([
                'resource_key' => $resourceKey,
                'enabled' => true,
                'quota_value' => (int) $resource['quota_value'],
                'quota_period' => 'cycle',
                'unit' => (string) $resource['unit'],
                'meta' => [],
            ]);
        }

        return $plan;
    }
}
