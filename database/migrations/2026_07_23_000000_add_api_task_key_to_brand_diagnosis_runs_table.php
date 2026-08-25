<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_diagnosis_runs')) {
            return;
        }

        Schema::table('brand_diagnosis_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('brand_diagnosis_runs', 'api_task_key')) {
                $table->string('api_task_key', 40)->nullable()->unique()->after('id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('brand_diagnosis_runs') || ! Schema::hasColumn('brand_diagnosis_runs', 'api_task_key')) {
            return;
        }

        Schema::table('brand_diagnosis_runs', function (Blueprint $table): void {
            $table->dropUnique(['api_task_key']);
            $table->dropColumn('api_task_key');
        });
    }
};
