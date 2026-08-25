<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_diagnosis_brand_mentions')) {
            return;
        }

        Schema::create('brand_diagnosis_brand_mentions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
            $table->foreignId('run_id')->constrained('brand_diagnosis_runs')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('brand_diagnosis_questions')->cascadeOnDelete();
            $table->foreignId('result_id')->constrained('brand_diagnosis_results')->cascadeOnDelete();
            $table->string('platform', 40);
            $table->string('brand_name', 120);
            $table->unsignedInteger('mention_count')->default(0);
            $table->unsignedInteger('mention_rank')->default(0);
            $table->string('sentiment', 20)->default('neutral');
            $table->unsignedInteger('source_count')->default(0);
            $table->boolean('is_target_brand')->default(false);
            $table->text('evidence')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['result_id', 'brand_name'], 'brand_diag_mentions_unique_result_brand');
            $table->index(['run_id', 'platform', 'brand_name'], 'brand_diag_mentions_run_platform_brand');
            $table->index(['run_id', 'is_target_brand'], 'brand_diag_mentions_run_target');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_diagnosis_brand_mentions');
    }
};
