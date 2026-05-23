<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('keyword_question_variants')) {
            return;
        }

        if (! Schema::hasColumn('keyword_question_variants', 'site_id')) {
            Schema::table('keyword_question_variants', function (Blueprint $table): void {
                $table->foreignId('site_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('sites')
                    ->cascadeOnDelete();
                $table->index('site_id', 'keyword_question_variants_site_id_index');
            });
        }

        $this->backfillSiteIds();
    }

    public function down(): void
    {
        if (! Schema::hasTable('keyword_question_variants') || ! Schema::hasColumn('keyword_question_variants', 'site_id')) {
            return;
        }

        Schema::table('keyword_question_variants', function (Blueprint $table): void {
            $table->dropForeign(['site_id']);
            $table->dropIndex('keyword_question_variants_site_id_index');
            $table->dropColumn('site_id');
        });
    }

    private function backfillSiteIds(): void
    {
        if (! Schema::hasColumn('keywords', 'site_id')) {
            return;
        }

        DB::table('keyword_question_variants')
            ->select(['id', 'keyword_id'])
            ->whereNull('site_id')
            ->orderBy('id')
            ->chunkById(500, function (Collection $variants): void {
                $keywordIds = $variants
                    ->pluck('keyword_id')
                    ->filter()
                    ->unique()
                    ->values();

                if ($keywordIds->isEmpty()) {
                    return;
                }

                $siteIdsByKeyword = DB::table('keywords')
                    ->whereIn('id', $keywordIds)
                    ->pluck('site_id', 'id');

                foreach ($variants as $variant) {
                    $siteId = $siteIdsByKeyword->get($variant->keyword_id);
                    if ($siteId === null) {
                        continue;
                    }

                    DB::table('keyword_question_variants')
                        ->where('id', $variant->id)
                        ->update(['site_id' => $siteId]);
                }
            });
    }
};
