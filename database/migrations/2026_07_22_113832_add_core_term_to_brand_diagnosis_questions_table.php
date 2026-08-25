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
        Schema::table('brand_diagnosis_questions', function (Blueprint $table) {
            if (! Schema::hasColumn('brand_diagnosis_questions', 'core_term')) {
                $table->string('core_term', 120)->default('')->after('question_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brand_diagnosis_questions', function (Blueprint $table) {
            if (Schema::hasColumn('brand_diagnosis_questions', 'core_term')) {
                $table->dropColumn('core_term');
            }
        });
    }
};
