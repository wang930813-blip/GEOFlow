<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int,string>
     */
    private array $tables = [
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
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'owner_admin_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->foreignId('owner_admin_id')
                    ->nullable()
                    ->after(Schema::hasColumn($tableName, 'site_id') ? 'site_id' : 'id')
                    ->constrained('admins')
                    ->nullOnDelete();

                if (Schema::hasColumn($tableName, 'site_id')) {
                    $table->index(['site_id', 'owner_admin_id'], $tableName.'_site_owner_idx');
                } else {
                    $table->index('owner_admin_id', $tableName.'_owner_idx');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'owner_admin_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('owner_admin_id');
            });
        }
    }
};
