<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminPlanSubscription;
use App\Models\ManualPublishStatEntry;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\SitePlanSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminManualPublishStatTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_and_site_user_can_view_manual_publish_stat_page_but_only_super_admin_can_write(): void
    {
        [$superAdmin, $site] = $this->createAdminWithSite('manual_stat_super', 'super_admin');
        [$admin, $adminSite] = $this->createAdminWithSite('manual_stat_member', 'direct_admin');

        DB::table('manual_publish_stat_entries')->insert([
            'site_id' => (int) $adminSite->id,
            'owner_admin_id' => (int) $admin->id,
            'created_by_admin_id' => (int) $superAdmin->id,
            'metric_type' => 'media',
            'quantity' => 20,
            'stat_date' => '2026-09-04',
            'remark' => 'User raw entry should stay hidden.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.manual-publish-stats.index'))
            ->assertOk()
            ->assertSee('发布数据台账')
            ->assertSee('新增 媒体发布')
            ->assertSee('新增 自媒体发布')
            ->assertSee('新增 B2B 发布')
            ->assertSee('新增 官网发布')
            ->assertSee('新增 视频发布');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.manual-publish-stats.index'))
            ->assertOk()
            ->assertSee('发布数据台账')
            ->assertDontSee('新增 媒体发布')
            ->assertDontSee('增加记录')
            ->assertDontSee('User raw entry should stay hidden.')
            ->assertDontSee('删除');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.manual-publish-stats.store'), [
                'site_id' => (int) $site->id,
                'metric_type' => 'media',
                'quantity' => 1,
                'stat_date' => '2026-08-01',
            ])
            ->assertForbidden();
    }

    public function test_super_admin_creates_entries_for_selected_site_only(): void
    {
        [$superAdmin, $firstSite] = $this->createAdminWithSite('manual_stat_creator', 'super_admin');
        [$owner, $secondSite] = $this->createAdminWithSite('manual_stat_other_owner', 'direct_admin');

        DB::table('manual_publish_stat_entries')->insert([
            'site_id' => (int) $secondSite->id,
            'owner_admin_id' => (int) $owner->id,
            'created_by_admin_id' => (int) $superAdmin->id,
            'metric_type' => 'video',
            'quantity' => 66,
            'stat_date' => '2026-08-02',
            'remark' => 'Other site should stay hidden.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->post(route('admin.manual-publish-stats.store'), [
                'site_id' => (int) $firstSite->id,
                'metric_type' => 'media',
                'quantity' => 12,
                'stat_date' => '2026-08-01',
                'remark' => 'Manual media count.',
            ])
            ->assertRedirect(route('admin.manual-publish-stats.index', ['site_id' => (int) $firstSite->id]))
            ->assertSessionHasNoErrors();

        $entry = ManualPublishStatEntry::query()
            ->where('site_id', (int) $firstSite->id)
            ->where('metric_type', 'media')
            ->firstOrFail();

        $this->assertSame((int) $firstSite->owner_admin_id, (int) $entry->owner_admin_id);
        $this->assertSame((int) $superAdmin->id, (int) $entry->created_by_admin_id);
        $this->assertSame(12, (int) $entry->quantity);
        $this->assertSame('2026-08-01', $entry->stat_date?->toDateString());
        $this->assertSame('Manual media count.', $entry->remark);

        $response = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.manual-publish-stats.index', ['site_id' => (int) $firstSite->id]));

        $response->assertOk()
            ->assertSee('Manual media count.')
            ->assertDontSee('Other site should stay hidden.');

        $entries = $response->viewData('entries');
        $this->assertSame(1, $entries->total());
    }

    public function test_summary_chart_and_pie_data_are_scoped_to_current_site(): void
    {
        [$superAdmin, $site] = $this->createAdminWithSite('manual_stat_summary_super', 'super_admin');
        [$otherOwner, $otherSite] = $this->createAdminWithSite('manual_stat_summary_other', 'direct_admin');

        DB::table('manual_publish_stat_entries')->insert([
            [
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $site->owner_admin_id,
                'created_by_admin_id' => (int) $superAdmin->id,
                'metric_type' => 'media',
                'quantity' => 10,
                'stat_date' => now()->subDay()->toDateString(),
                'remark' => null,
                'created_at' => now()->subDay(),
                'updated_at' => now()->subDay(),
            ],
            [
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $site->owner_admin_id,
                'created_by_admin_id' => (int) $superAdmin->id,
                'metric_type' => 'video',
                'quantity' => 5,
                'stat_date' => now()->toDateString(),
                'remark' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'site_id' => (int) $otherSite->id,
                'owner_admin_id' => (int) $otherOwner->id,
                'created_by_admin_id' => (int) $superAdmin->id,
                'metric_type' => 'media',
                'quantity' => 99,
                'stat_date' => now()->toDateString(),
                'remark' => 'Other site count.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.manual-publish-stats.index', ['site_id' => (int) $site->id]));

        $summary = $response->viewData('summary');
        $pieSegments = collect($response->viewData('pieSegments'));
        $dailyChartRows = collect($response->viewData('dailyChartRows'));

        $response->assertOk();
        $this->assertSame(15, (int) $summary['total']);
        $this->assertSame(10, (int) $summary['by_type']['media']['quantity']);
        $this->assertSame(5, (int) $summary['by_type']['video']['quantity']);
        $this->assertSame(0, (int) $summary['by_type']['b2b']['quantity']);
        $this->assertSame(66.67, (float) $pieSegments->firstWhere('type', 'media')['percent']);
        $this->assertSame(33.33, (float) $pieSegments->firstWhere('type', 'video')['percent']);
        $this->assertTrue($dailyChartRows->contains(fn (array $row): bool => $row['date'] === now()->toDateString() && $row['values']['video'] === 5));
    }

    public function test_destroy_soft_deletes_entry_and_removes_it_from_summary(): void
    {
        [$superAdmin, $site] = $this->createAdminWithSite('manual_stat_delete_super', 'super_admin');

        $entryId = DB::table('manual_publish_stat_entries')->insertGetId([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $site->owner_admin_id,
            'created_by_admin_id' => (int) $superAdmin->id,
            'metric_type' => 'official_site',
            'quantity' => 18,
            'stat_date' => '2026-08-03',
            'remark' => 'To be deleted.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($superAdmin, 'admin')
            ->delete(route('admin.manual-publish-stats.destroy', ['manual_publish_stat' => $entryId]))
            ->assertRedirect(route('admin.manual-publish-stats.index', ['site_id' => (int) $site->id]));

        $this->assertSoftDeleted('manual_publish_stat_entries', ['id' => $entryId]);

        $response = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.manual-publish-stats.index', ['site_id' => (int) $site->id]));

        $summary = $response->viewData('summary');

        $response->assertOk()
            ->assertDontSee('To be deleted.');
        $this->assertSame(0, (int) $summary['total']);
    }

    public function test_progress_rows_use_selected_site_plan_entitlements_and_manual_totals(): void
    {
        [$superAdmin] = $this->createAdminWithSite('manual_stat_progress_super', 'super_admin');
        [$owner, $site] = $this->createAdminWithSite('manual_stat_progress_owner', 'direct_admin');
        $this->openPlanSnapshot($site, [
            PlatformPlan::RESOURCE_MEDIA_PUBLISHES => 4000,
            PlatformPlan::RESOURCE_CREBEE_PUBLISHES => 500,
            PlatformPlan::RESOURCE_B2B_WEBSITE_PUBLISHES => 800,
            PlatformPlan::RESOURCE_OFFICIAL_SITE_PUBLISHES => 120,
            PlatformPlan::RESOURCE_VIDEO_PUBLISHES => 60,
        ]);

        DB::table('manual_publish_stat_entries')->insert([
            [
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $owner->id,
                'created_by_admin_id' => (int) $superAdmin->id,
                'metric_type' => 'media',
                'quantity' => 200,
                'stat_date' => '2026-08-01',
                'remark' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $owner->id,
                'created_by_admin_id' => (int) $superAdmin->id,
                'metric_type' => 'video',
                'quantity' => 15,
                'stat_date' => '2026-08-02',
                'remark' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.manual-publish-stats.index', ['site_id' => (int) $site->id]));

        $progressRows = collect($response->viewData('progressRows'))->keyBy('type');

        $response->assertOk()
            ->assertSee('已发 200 / 总 4000 条')
            ->assertSee('已发 15 / 总 60 条')
            ->assertSee('手动选择站点用户');

        $this->assertSame(200, (int) $progressRows->get('media')['used']);
        $this->assertSame(4000, (int) $progressRows->get('media')['quota']);
        $this->assertSame(5, (int) $progressRows->get('media')['percent']);
        $this->assertSame(15, (int) $progressRows->get('video')['used']);
        $this->assertSame(60, (int) $progressRows->get('video')['quota']);
        $this->assertSame(25, (int) $progressRows->get('video')['percent']);
    }

    public function test_super_admin_keyword_search_selects_matching_site_when_submitted_site_does_not_match(): void
    {
        [$superAdmin, $firstSite] = $this->createAdminWithSite('manual_stat_search_super', 'super_admin');
        [$owner, $secondSite] = $this->createAdminWithSite('wxy22', 'direct_admin');
        $owner->forceFill([
            'display_name' => '王小云',
            'mobile' => '18300001111',
        ])->save();
        $secondSite->forceFill(['name' => 'wxy22'])->save();

        DB::table('manual_publish_stat_entries')->insert([
            [
                'site_id' => (int) $firstSite->id,
                'owner_admin_id' => (int) $firstSite->owner_admin_id,
                'created_by_admin_id' => (int) $superAdmin->id,
                'metric_type' => 'media',
                'quantity' => 3,
                'stat_date' => '2026-08-01',
                'remark' => 'First site data',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'site_id' => (int) $secondSite->id,
                'owner_admin_id' => (int) $owner->id,
                'created_by_admin_id' => (int) $superAdmin->id,
                'metric_type' => 'media',
                'quantity' => 9,
                'stat_date' => '2026-08-02',
                'remark' => 'Matched site data',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.manual-publish-stats.index', [
                'site_id' => (int) $firstSite->id,
                'keyword' => 'wxy22',
            ]));

        $selectedSite = $response->viewData('site');

        $response->assertOk()
            ->assertSee('Matched site data')
            ->assertDontSee('First site data')
            ->assertSee('value="wxy22"', false);

        $this->assertSame((int) $secondSite->id, (int) $selectedSite->id);
    }

    public function test_super_admin_keyword_search_keeps_selected_site_when_it_also_matches_keyword(): void
    {
        [$superAdmin] = $this->createAdminWithSite('manual_stat_search_keep_super', 'super_admin');
        [, $selectedSite] = $this->createAdminWithSite('match_keep_first', 'direct_admin');
        [, $latestSite] = $this->createAdminWithSite('match_keep_latest', 'direct_admin');

        DB::table('manual_publish_stat_entries')->insert([
            [
                'site_id' => (int) $selectedSite->id,
                'owner_admin_id' => (int) $selectedSite->owner_admin_id,
                'created_by_admin_id' => (int) $superAdmin->id,
                'metric_type' => 'media',
                'quantity' => 6,
                'stat_date' => '2026-08-01',
                'remark' => 'Selected site data',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'site_id' => (int) $latestSite->id,
                'owner_admin_id' => (int) $latestSite->owner_admin_id,
                'created_by_admin_id' => (int) $superAdmin->id,
                'metric_type' => 'media',
                'quantity' => 11,
                'stat_date' => '2026-08-02',
                'remark' => 'Latest matching site data',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.manual-publish-stats.index', [
                'site_id' => (int) $selectedSite->id,
                'keyword' => 'match_keep',
            ]));

        $selected = $response->viewData('site');

        $response->assertOk()
            ->assertSee('Selected site data')
            ->assertDontSee('Latest matching site data');

        $this->assertSame((int) $selectedSite->id, (int) $selected->id);
    }

    public function test_super_admin_site_selector_clears_keyword_for_manual_site_selection(): void
    {
        [$superAdmin] = $this->createAdminWithSite('manual_stat_selector_super', 'super_admin');
        $this->createAdminWithSite('manual_stat_selector_member', 'direct_admin');

        $html = $this->actingAs($superAdmin, 'admin')
            ->get(route('admin.manual-publish-stats.index', ['keyword' => 'wxy']))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("event.target?.id !== 'manual-publish-site-select'", $html);
        $this->assertStringContainsString("siteSearch.value = ''", $html);
        $this->assertStringContainsString('option.hidden = false', $html);
    }

    public function test_manual_publish_resources_are_visible_in_profile_and_plan_usage_pages(): void
    {
        [$superAdmin, $site] = $this->createAdminWithSite('manual_stat_usage_owner', 'direct_admin');
        $owner = $site->owner;
        $this->openPlanSnapshot($site, [
            PlatformPlan::RESOURCE_BRAND_DIAGNOSES => 9,
            PlatformPlan::RESOURCE_MEDIA_PUBLISHES => 4000,
            PlatformPlan::RESOURCE_CREBEE_PUBLISHES => 500,
            PlatformPlan::RESOURCE_B2B_WEBSITE_PUBLISHES => 800,
            PlatformPlan::RESOURCE_OFFICIAL_SITE_PUBLISHES => 120,
            PlatformPlan::RESOURCE_VIDEO_PUBLISHES => 60,
        ]);
        DB::table('manual_publish_stat_entries')->insert([
            [
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $owner->id,
                'created_by_admin_id' => (int) $superAdmin->id,
                'metric_type' => 'media',
                'quantity' => 12,
                'stat_date' => '2026-08-01',
                'remark' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $owner->id,
                'created_by_admin_id' => (int) $superAdmin->id,
                'metric_type' => 'self_media',
                'quantity' => 7,
                'stat_date' => '2026-08-01',
                'remark' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $owner->id,
                'created_by_admin_id' => (int) $superAdmin->id,
                'metric_type' => 'b2b',
                'quantity' => 33,
                'stat_date' => '2026-08-02',
                'remark' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $owner->id,
                'created_by_admin_id' => (int) $superAdmin->id,
                'metric_type' => 'official_site',
                'quantity' => 5,
                'stat_date' => '2026-08-02',
                'remark' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $owner->id,
                'created_by_admin_id' => (int) $superAdmin->id,
                'metric_type' => 'video',
                'quantity' => 2,
                'stat_date' => '2026-08-03',
                'remark' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $usageResponse = $this->actingAs($owner, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.plan-usages.index'))
            ->assertOk()
            ->assertSee('品牌诊断次数')
            ->assertSee('媒体发布条数')
            ->assertSee('自媒体发布条数')
            ->assertSee('B2B网站发布条数')
            ->assertSee('官网发布条数')
            ->assertSee('视频发布条数')
            ->assertSee('已用 12 / 4000')
            ->assertSee('已用 7 / 500')
            ->assertSee('已用 33 / 800')
            ->assertSee('已用 5 / 120')
            ->assertSee('已用 2 / 60');

        $this->assertStringContainsString('data-resource-key="'.PlatformPlan::RESOURCE_MEDIA_PUBLISHES.'"', $usageResponse->getContent());
        $this->assertStringContainsString('data-resource-key="'.PlatformPlan::RESOURCE_CREBEE_PUBLISHES.'"', $usageResponse->getContent());
        $this->assertStringContainsString('data-resource-key="'.PlatformPlan::RESOURCE_B2B_WEBSITE_PUBLISHES.'"', $usageResponse->getContent());
        $this->assertStringContainsString('data-resource-key="'.PlatformPlan::RESOURCE_OFFICIAL_SITE_PUBLISHES.'"', $usageResponse->getContent());
        $this->assertStringContainsString('data-resource-key="'.PlatformPlan::RESOURCE_VIDEO_PUBLISHES.'"', $usageResponse->getContent());

        $this->actingAs($owner, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.profile.index'))
            ->assertOk()
            ->assertSee('品牌诊断次数')
            ->assertSee('媒体发布条数')
            ->assertSee('自媒体发布条数')
            ->assertSee('B2B网站发布条数')
            ->assertSee('官网发布条数')
            ->assertSee('视频发布条数')
            ->assertSee('已用 12 / 4000')
            ->assertSee('已用 7 / 500')
            ->assertSee('已用 33 / 800')
            ->assertSee('已用 5 / 120')
            ->assertSee('已用 2 / 60');
    }

    /**
     * @return array{0: Admin, 1: Site}
     */
    private function createAdminWithSite(string $username, string $role): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => str_replace('_', ' ', $username),
            'role' => $role,
            'status' => 'active',
        ]);

        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => $username.' Site',
            'domain' => $username.'.example.test',
            'status' => 'active',
            'customer_mode' => 'direct',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        return [$admin, $site];
    }

    /**
     * @param  array<string,int>  $quotas
     */
    private function openPlanSnapshot(Site $site, array $quotas): SitePlanSubscription
    {
        $plan = PlatformPlan::query()->create([
            'name' => 'Manual Stat Plan '.str()->random(8),
            'code' => 'manual-stat-plan-'.str()->random(8),
            'audience' => 'both',
            'duration_days' => 365,
            'price' => null,
            'market_price' => null,
            'description' => '',
            'status' => 'active',
            'sort_order' => 0,
        ]);

        $snapshot = [];
        foreach ($quotas as $resourceKey => $quota) {
            $unit = PlatformPlan::resourceCatalog()[$resourceKey]['unit'] ?? 'times';
            $plan->entitlements()->create([
                'resource_key' => $resourceKey,
                'enabled' => true,
                'quota_value' => $quota,
                'quota_period' => 'cycle',
                'unit' => $unit,
                'meta' => [],
            ]);
            $snapshot[$resourceKey] = [
                'enabled' => true,
                'quota_value' => $quota,
                'quota_period' => 'cycle',
                'unit' => $unit,
                'meta' => [],
            ];
        }

        $siteSubscription = SitePlanSubscription::query()->create([
            'site_id' => (int) $site->id,
            'plan_id' => (int) $plan->id,
            'mode' => (string) ($site->customer_mode ?: 'direct'),
            'owner_admin_id' => (int) $site->owner_admin_id,
            'agent_admin_id' => (int) ($site->agent_admin_id ?? 0) ?: null,
            'assigned_by_admin_id' => null,
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addYear(),
            'entitlements_snapshot' => $snapshot,
            'remark' => 'Manual stat testing subscription',
        ]);
        $this->createAccountSubscription($site, $plan, $snapshot);

        return $siteSubscription;
    }

    /**
     * @param  array<string,array{enabled:bool,quota_value:int,quota_period:string,unit:string,meta:array<string,mixed>}>  $snapshot
     */
    private function createAccountSubscription(Site $site, PlatformPlan $plan, array $snapshot): AdminPlanSubscription
    {
        return AdminPlanSubscription::query()->create([
            'admin_id' => (int) $site->owner_admin_id,
            'site_id' => (int) $site->id,
            'plan_id' => (int) $plan->id,
            'source_subscription_id' => null,
            'inherited_from_admin_id' => null,
            'mode' => (string) ($site->customer_mode === 'agent' ? 'agent_owner' : 'direct_owner'),
            'status' => 'active',
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addYear(),
            'entitlements_snapshot' => $snapshot,
            'remark' => 'Manual stat testing subscription',
        ]);
    }
}
