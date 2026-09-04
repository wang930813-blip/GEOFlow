<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ManualPublishStatEntry;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminManualPublishStatTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_view_manual_publish_stat_page_and_non_super_admin_cannot(): void
    {
        [$superAdmin, $site] = $this->createAdminWithSite('manual_stat_super', 'super_admin');
        [$admin] = $this->createAdminWithSite('manual_stat_member', 'direct_admin');

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
            ->assertForbidden();
    }

    public function test_super_admin_creates_entries_for_current_site_only(): void
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
            ->withSession(['current_site_id' => (int) $firstSite->id])
            ->post(route('admin.manual-publish-stats.store'), [
                'metric_type' => 'media',
                'quantity' => 12,
                'stat_date' => '2026-08-01',
                'remark' => 'Manual media count.',
            ])
            ->assertRedirect(route('admin.manual-publish-stats.index'))
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
            ->withSession(['current_site_id' => (int) $firstSite->id])
            ->get(route('admin.manual-publish-stats.index'));

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
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.manual-publish-stats.index'));

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
            ->withSession(['current_site_id' => (int) $site->id])
            ->delete(route('admin.manual-publish-stats.destroy', ['manual_publish_stat' => $entryId]))
            ->assertRedirect(route('admin.manual-publish-stats.index'));

        $this->assertSoftDeleted('manual_publish_stat_entries', ['id' => $entryId]);

        $response = $this->actingAs($superAdmin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.manual-publish-stats.index'));

        $summary = $response->viewData('summary');

        $response->assertOk()
            ->assertDontSee('To be deleted.');
        $this->assertSame(0, (int) $summary['total']);
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
}
