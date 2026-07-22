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
            if (! Schema::hasColumn('brand_diagnosis_runs', 'brand_profile')) {
                $table->longText('brand_profile')->nullable()->after('brand_name');
            }
            if (! Schema::hasColumn('brand_diagnosis_runs', 'brand_profile_source')) {
                $table->string('brand_profile_source', 40)->default('')->after('brand_profile');
            }
            if (! Schema::hasColumn('brand_diagnosis_runs', 'brand_profile_model')) {
                $table->string('brand_profile_model', 120)->default('')->after('brand_profile_source');
            }
            if (! Schema::hasColumn('brand_diagnosis_runs', 'brand_profile_status')) {
                $table->string('brand_profile_status', 20)->default('')->after('brand_profile_model');
            }
            if (! Schema::hasColumn('brand_diagnosis_runs', 'brand_profile_meta')) {
                $table->json('brand_profile_meta')->nullable()->after('brand_profile_status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('brand_diagnosis_runs')) {
            return;
        }

        Schema::table('brand_diagnosis_runs', function (Blueprint $table): void {
            foreach (['brand_profile_meta', 'brand_profile_status', 'brand_profile_model', 'brand_profile_source', 'brand_profile'] as $column) {
                if (Schema::hasColumn('brand_diagnosis_runs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
