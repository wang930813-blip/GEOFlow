<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('platform_plans') || ! Schema::hasTable('sites') || ! Schema::hasTable('site_plan_subscriptions')) {
            return;
        }

        $planId = DB::table('platform_plans')->where('code', 'legacy_unlimited')->value('id');
        if ($planId === null) {
            $planId = DB::table('platform_plans')->insertGetId([
                'name' => '历史站点不限量规格',
                'code' => 'legacy_unlimited',
                'audience' => 'both',
                'duration_days' => 36500,
                'price' => null,
                'market_price' => null,
                'description' => '规格体系上线时为历史站点自动创建的兼容规格。',
                'status' => 'active',
                'sort_order' => 999999,
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $resources = [
            'article_generations' => 'times',
            'brand_diagnoses' => 'times',
            'ai_title_generations' => 'times',
            'url_imports' => 'times',
            'keyword_question_generations' => 'times',
            'inclusion_checks' => 'times',
            'ai_image_generations' => 'times',
            'team_members' => 'accounts',
            'api_tokens' => 'tokens',
        ];
        foreach ($resources as $resourceKey => $unit) {
            DB::table('platform_plan_entitlements')->updateOrInsert(
                ['plan_id' => $planId, 'resource_key' => $resourceKey],
                [
                    'enabled' => true,
                    'quota_value' => 0,
                    'quota_period' => 'unlimited',
                    'unit' => $unit,
                    'meta' => json_encode([], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        $snapshot = collect($resources)
            ->mapWithKeys(static fn (string $unit, string $resourceKey): array => [
                $resourceKey => [
                    'enabled' => true,
                    'quota_value' => 0,
                    'quota_period' => 'unlimited',
                    'unit' => $unit,
                    'meta' => [],
                ],
            ])
            ->all();

        DB::table('sites')
            ->orderBy('id')
            ->select(['id', 'owner_admin_id', 'customer_mode'])
            ->chunkById(100, function ($sites) use ($planId, $snapshot): void {
                foreach ($sites as $site) {
                    $hasSubscription = DB::table('site_plan_subscriptions')
                        ->where('site_id', $site->id)
                        ->exists();
                    if ($hasSubscription) {
                        continue;
                    }

                    $subscriptionId = DB::table('site_plan_subscriptions')->insertGetId([
                        'site_id' => $site->id,
                        'plan_id' => $planId,
                        'mode' => $site->customer_mode ?: 'internal',
                        'owner_admin_id' => $site->owner_admin_id,
                        'agent_admin_id' => null,
                        'assigned_by_admin_id' => null,
                        'status' => 'active',
                        'starts_at' => now(),
                        'ends_at' => '2099-12-31 23:59:59',
                        'entitlements_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                        'remark' => '规格体系上线自动兼容历史站点',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    DB::table('site_subscription_logs')->insert([
                        'site_id' => $site->id,
                        'subscription_id' => $subscriptionId,
                        'action' => 'open',
                        'before_payload' => null,
                        'after_payload' => json_encode(['legacy_unlimited' => true], JSON_UNESCAPED_UNICODE),
                        'operator_admin_id' => null,
                        'remark' => '规格体系上线自动兼容历史站点',
                        'created_at' => now(),
                    ]);

                    DB::table('sites')
                        ->where('id', $site->id)
                        ->update([
                            'plan_status' => 'active',
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // 保留兼容数据，避免回滚误删线上站点订阅记录。
    }
};
