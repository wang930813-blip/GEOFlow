<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defaultSiteId = $this->defaultSiteId();

        if (Schema::hasTable('view_logs') && ! Schema::hasColumn('view_logs', 'site_id')) {
            Schema::table('view_logs', function (Blueprint $table): void {
                $table->foreignId('site_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('sites')
                    ->cascadeOnDelete();
                $table->index('site_id', 'view_logs_site_id_index');
            });
        }

        $this->backfillViewLogs($defaultSiteId);
        $this->backfillNullSiteRows($defaultSiteId);
    }

    public function down(): void
    {
        if (! Schema::hasTable('view_logs') || ! Schema::hasColumn('view_logs', 'site_id')) {
            return;
        }

        Schema::table('view_logs', function (Blueprint $table): void {
            $table->dropForeign(['site_id']);
            $table->dropIndex('view_logs_site_id_index');
            $table->dropColumn('site_id');
        });
    }

    private function backfillViewLogs(?int $defaultSiteId): void
    {
        if (! Schema::hasTable('view_logs') || ! Schema::hasColumn('view_logs', 'site_id')) {
            return;
        }

        DB::table('view_logs')
            ->whereNull('view_logs.site_id')
            ->whereNotNull('view_logs.article_id')
            ->orderBy('view_logs.id')
            ->select(['view_logs.id', 'view_logs.article_id'])
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $siteId = DB::table('articles')->whereKey((int) $row->article_id)->value('site_id');
                    if ($siteId === null) {
                        continue;
                    }

                    DB::table('view_logs')
                        ->where('id', (int) $row->id)
                        ->whereNull('site_id')
                        ->update(['site_id' => (int) $siteId]);
                }
            }, 'view_logs.id', 'id');

        if ($defaultSiteId !== null) {
            DB::table('view_logs')
                ->whereNull('site_id')
                ->update(['site_id' => $defaultSiteId]);
        }
    }

    private function backfillNullSiteRows(?int $defaultSiteId): void
    {
        if ($defaultSiteId === null) {
            return;
        }

        foreach ($this->siteScopedTables() as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'site_id')) {
                continue;
            }

            if ($tableName === 'site_settings' && Schema::hasColumn('site_settings', 'setting_key')) {
                $this->deduplicateSiteSettings($defaultSiteId);
            }

            DB::table($tableName)
                ->whereNull('site_id')
                ->update(['site_id' => $defaultSiteId]);
        }
    }

    private function deduplicateSiteSettings(int $defaultSiteId): void
    {
        DB::table('site_settings')
            ->whereNull('site_id')
            ->orderBy('id')
            ->select(['id', 'setting_key'])
            ->chunkById(200, function ($rows) use ($defaultSiteId): void {
                foreach ($rows as $row) {
                    $exists = DB::table('site_settings')
                        ->where('site_id', $defaultSiteId)
                        ->where('setting_key', (string) $row->setting_key)
                        ->exists();

                    if ($exists) {
                        DB::table('site_settings')->where('id', (int) $row->id)->delete();
                    }
                }
            });
    }

    /**
     * @return list<string>
     */
    private function siteScopedTables(): array
    {
        return [
            'site_settings',
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
            'knowledge_bases',
            'knowledge_chunks',
            'image_libraries',
            'images',
            'article_images',
            'ai_models',
            'prompts',
            'sensitive_words',
            'distribution_channels',
            'distribution_channel_secrets',
            'distribution_logs',
            'article_distributions',
            'geo_inclusion_check_runs',
            'geo_inclusion_check_results',
            'url_import_jobs',
            'url_import_job_logs',
            'admin_activity_logs',
            'system_logs',
            'api_idempotency_keys',
        ];
    }

    private function defaultSiteId(): ?int
    {
        if (! Schema::hasTable('sites')) {
            return null;
        }

        $siteId = DB::table('sites')->orderBy('id')->value('id');

        return $siteId !== null ? (int) $siteId : null;
    }
};
