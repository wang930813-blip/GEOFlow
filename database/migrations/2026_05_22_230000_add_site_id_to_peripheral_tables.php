<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
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

    public function up(): void
    {
        $defaultSiteId = $this->defaultSiteId();

        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'site_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->foreignId('site_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('sites')
                    ->cascadeOnDelete();
                $table->index('site_id', $tableName.'_site_id_index');
            });

            if ($defaultSiteId !== null) {
                DB::table($tableName)->whereNull('site_id')->update(['site_id' => $defaultSiteId]);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'site_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropForeign([$tableName.'_site_id_foreign']);
                $table->dropIndex($tableName.'_site_id_index');
                $table->dropColumn('site_id');
            });
        }
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
