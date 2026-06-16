<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

/**
 * 测试基类：Feature 测试如需数据库可在用例中 use {@see RefreshDatabase}。
 */
abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $this->forceTestingDatabaseEnvironment();

        $app = parent::createApplication();

        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');
        $app['config']->set('database.connections.pgsql.url', null);

        return $app;
    }

    private function forceTestingDatabaseEnvironment(): void
    {
        $variables = [
            'APP_ENV' => 'testing',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
        ];

        foreach ($variables as $key => $value) {
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }

    /**
     * Open an active testing subscription for flows that are intentionally
     * exercising business actions behind the platform-plan gate.
     *
     * @param  array<string,array{quota_value?:int,quota_period?:string,unit?:string}>  $resourceOverrides
     */
    protected function openTestingPlanForSite(
        \App\Models\Site $site,
        ?\App\Models\Admin $owner = null,
        array $resourceOverrides = [],
        string $mode = 'direct'
    ): \App\Models\SitePlanSubscription {
        $plan = \App\Models\PlatformPlan::query()->create([
            'name' => 'Testing Unlimited Plan '.str()->random(8),
            'code' => 'testing-unlimited-'.str()->random(12),
            'audience' => 'both',
            'duration_days' => 365,
            'price' => null,
            'market_price' => null,
            'description' => 'Testing-only plan subscription.',
            'status' => 'active',
            'sort_order' => 0,
            'created_by' => $owner?->id,
        ]);

        foreach (\App\Models\PlatformPlan::resourceCatalog() as $resourceKey => $definition) {
            $override = $resourceOverrides[$resourceKey] ?? [];
            $plan->entitlements()->create([
                'resource_key' => $resourceKey,
                'enabled' => true,
                'quota_value' => (int) ($override['quota_value'] ?? 0),
                'quota_period' => (string) ($override['quota_period'] ?? 'unlimited'),
                'unit' => (string) ($override['unit'] ?? $definition['unit']),
                'meta' => [],
            ]);
        }

        $siteSubscription = app(\App\Services\Billing\PlanSubscriptionService::class)->open(
            site: $site,
            plan: $plan,
            mode: $mode,
            ownerAdmin: $owner,
            operator: $owner,
            startsAt: now()->subMinute(),
            endsAt: now()->addYear(),
            grantCredits: false,
            remark: 'Testing subscription'
        );

        if ($owner instanceof \App\Models\Admin) {
            app(\App\Services\Billing\AdminPlanSubscriptionService::class)->openOwner(
                admin: $owner,
                site: $site,
                plan: $plan,
                mode: $mode === 'agent' ? 'agent_owner' : 'direct_owner',
                operator: $owner,
                startsAt: now()->subMinute(),
                endsAt: now()->addYear(),
                grantCredits: false,
                remark: 'Testing account subscription'
            );
        }

        return $siteSubscription;
    }
}
