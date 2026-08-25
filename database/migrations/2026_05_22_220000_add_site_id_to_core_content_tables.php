<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
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
