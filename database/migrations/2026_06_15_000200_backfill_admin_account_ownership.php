<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfillAccountSubscriptions();
        $this->backfillOwnerFromColumn('media_submissions', 'submitted_by_admin_id');
        $this->backfillOwnerFromColumn('brand_diagnosis_runs', 'admin_id');
        $this->backfillOwnerFromColumn('articles', 'admin_id');
        $this->backfillOwnerFromColumn('articles', 'created_by_admin_id');
        $this->backfillOwnerFromSite([
            'knowledge_bases',
            'knowledge_chunks',
            'image_libraries',
            'images',
            'articles',
            'categories',
            'authors',
            'tasks',
            'task_runs',
            'task_schedules',
            'title_libraries',
            'titles',
            'keyword_libraries',
            'keywords',
            'keyword_question_variants',
            'url_import_jobs',
            'url_import_job_logs',
            'geo_inclusion_check_runs',
            'geo_inclusion_check_results',
            'brand_diagnosis_runs',
            'brand_diagnosis_questions',
            'brand_diagnosis_results',
            'brand_diagnosis_sources',
            'brand_diagnosis_brand_mentions',
            'media_submissions',
        ]);
    }

    public function down(): void
    {
        // Data backfill is intentionally not reversed.
    }

    private function backfillAccountSubscriptions(): void
    {
        if (! Schema::hasTable('site_plan_subscriptions') || ! Schema::hasTable('admin_plan_subscriptions')) {
            return;
        }

        DB::table('site_plan_subscriptions')
            ->whereNotNull('owner_admin_id')
            ->orderBy('id')
            ->chunkById(200, function ($subscriptions): void {
                foreach ($subscriptions as $subscription) {
                    $exists = DB::table('admin_plan_subscriptions')
                        ->where('admin_id', (int) $subscription->owner_admin_id)
                        ->where('site_id', (int) $subscription->site_id)
                        ->where('source_subscription_id', (int) $subscription->id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('admin_plan_subscriptions')->insert([
                        'admin_id' => (int) $subscription->owner_admin_id,
                        'site_id' => (int) $subscription->site_id,
                        'plan_id' => $subscription->plan_id,
                        'source_subscription_id' => (int) $subscription->id,
                        'inherited_from_admin_id' => null,
                        'mode' => match ((string) $subscription->mode) {
                            'agent' => 'agent_owner',
                            'direct' => 'direct_owner',
                            default => 'internal',
                        },
                        'status' => (string) $subscription->status,
                        'starts_at' => $subscription->starts_at,
                        'ends_at' => $subscription->ends_at,
                        'entitlements_snapshot' => $subscription->entitlements_snapshot,
                        'remark' => '由站点规格历史数据回填',
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now(),
                    ]);
                }
            });
    }

    private function backfillOwnerFromColumn(string $table, string $sourceColumn): void
    {
        if (
            ! Schema::hasTable($table)
            || ! Schema::hasColumn($table, 'owner_admin_id')
            || ! Schema::hasColumn($table, $sourceColumn)
        ) {
            return;
        }

        DB::table($table)
            ->whereNull('owner_admin_id')
            ->whereNotNull($sourceColumn)
            ->select(['id', $sourceColumn])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($table, $sourceColumn): void {
                foreach ($rows as $row) {
                    DB::table($table)
                        ->where('id', (int) $row->id)
                        ->update(['owner_admin_id' => (int) $row->{$sourceColumn}]);
                }
            });
    }

    /**
     * @param  array<int,string>  $tables
     */
    private function backfillOwnerFromSite(array $tables): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'owner_admin_id')) {
            return;
        }

        $siteOwners = DB::table('sites')
            ->whereNotNull('owner_admin_id')
            ->pluck('owner_admin_id', 'id');

        if ($siteOwners->isEmpty()) {
            return;
        }

        foreach ($tables as $table) {
            if (
                ! Schema::hasTable($table)
                || ! Schema::hasColumn($table, 'owner_admin_id')
                || ! Schema::hasColumn($table, 'site_id')
            ) {
                continue;
            }

            foreach ($siteOwners as $siteId => $ownerAdminId) {
                DB::table($table)
                    ->whereNull('owner_admin_id')
                    ->where('site_id', (int) $siteId)
                    ->update(['owner_admin_id' => (int) $ownerAdminId]);
            }
        }
    }
};
