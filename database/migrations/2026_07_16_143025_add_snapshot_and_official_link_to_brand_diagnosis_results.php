<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('brand_diagnosis_results', function (Blueprint $table): void {
            $table->string('snapshot_token', 48)->nullable()->unique();
            $table->text('official_share_url')->nullable();
            $table->foreignId('official_share_updated_by')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();
            $table->timestamp('official_share_updated_at')->nullable();
        });

        DB::table('brand_diagnosis_results')
            ->select('id')
            ->whereNull('snapshot_token')
            ->orderBy('id')
            ->chunkById(500, function ($results): void {
                foreach ($results as $result) {
                    DB::table('brand_diagnosis_results')
                        ->where('id', (int) $result->id)
                        ->update(['snapshot_token' => Str::random(48)]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brand_diagnosis_results', function (Blueprint $table): void {
            $table->dropForeign(['official_share_updated_by']);
            $table->dropUnique(['snapshot_token']);
            $table->dropColumn([
                'snapshot_token',
                'official_share_url',
                'official_share_updated_by',
                'official_share_updated_at',
            ]);
        });
    }
};
