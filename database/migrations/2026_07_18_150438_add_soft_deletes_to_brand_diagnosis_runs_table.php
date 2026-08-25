<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('brand_diagnosis_runs') || Schema::hasColumn('brand_diagnosis_runs', 'deleted_at')) {
            return;
        }

        Schema::table('brand_diagnosis_runs', function (Blueprint $table): void {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('brand_diagnosis_runs') || ! Schema::hasColumn('brand_diagnosis_runs', 'deleted_at')) {
            return;
        }

        Schema::table('brand_diagnosis_runs', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });
    }
};
