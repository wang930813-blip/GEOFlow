<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfillFromTask('articles');
        $this->backfillFromTask('task_runs');
        $this->backfillFromTask('task_schedules');
        $this->backfillArticleImages();
    }

    public function down(): void
    {
        // Data backfill only. Do not erase site_id values on rollback.
    }

    private function backfillFromTask(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'site_id') || ! Schema::hasColumn($table, 'task_id')) {
            return;
        }

        DB::table($table)
            ->whereNull($table.'.site_id')
            ->whereNotNull($table.'.task_id')
            ->orderBy($table.'.id')
            ->select([$table.'.id', $table.'.task_id'])
            ->chunkById(200, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    $siteId = DB::table('tasks')->whereKey((int) $row->task_id)->value('site_id');
                    if ($siteId === null) {
                        continue;
                    }

                    DB::table($table)
                        ->whereKey((int) $row->id)
                        ->whereNull('site_id')
                        ->update(['site_id' => (int) $siteId]);
                }
            }, $table.'.id', 'id');
    }

    private function backfillArticleImages(): void
    {
        if (! Schema::hasTable('article_images') || ! Schema::hasColumn('article_images', 'site_id')) {
            return;
        }

        DB::table('article_images')
            ->whereNull('article_images.site_id')
            ->orderBy('article_images.id')
            ->select(['article_images.id', 'article_images.article_id'])
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $siteId = DB::table('articles')->whereKey((int) $row->article_id)->value('site_id');
                    if ($siteId === null) {
                        continue;
                    }

                    DB::table('article_images')
                        ->whereKey((int) $row->id)
                        ->whereNull('site_id')
                        ->update(['site_id' => (int) $siteId]);
                }
            }, 'article_images.id', 'id');
    }
};
