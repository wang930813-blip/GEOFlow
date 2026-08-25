<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('prompts') || ! Schema::hasColumn('prompts', 'site_id')) {
            return;
        }

        DB::table('prompts')
            ->whereNotNull('site_id')
            ->where('type', 'content')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('tasks')
                    ->whereColumn('tasks.prompt_id', 'prompts.id');
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('title_libraries')
                    ->whereColumn('title_libraries.prompt_id', 'prompts.id');
            })
            ->delete();
    }

    public function down(): void
    {
        // Cleanup migration only. Deleted duplicate site-level prompt copies are not restored.
    }
};
