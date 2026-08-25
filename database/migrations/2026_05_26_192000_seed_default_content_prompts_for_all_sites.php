<?php

use App\Services\SiteDefaultContentPromptService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasTable('prompts')) {
            return;
        }

        $service = app(SiteDefaultContentPromptService::class);

        DB::table('sites')
            ->select(['id'])
            ->orderBy('id')
            ->chunkById(100, function ($sites) use ($service): void {
                foreach ($sites as $site) {
                    $service->ensureForSiteId((int) $site->id);
                }
            });
    }

    public function down(): void
    {
        // Data seed only. Keep site prompt data intact on rollback.
    }
};
