<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('geo_inclusion_check_runs')) {
            Schema::create('geo_inclusion_check_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('keyword_library_id')->constrained('keyword_libraries')->cascadeOnDelete();
                $table->json('platforms');
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedInteger('total_checks')->default(0);
                $table->unsignedInteger('completed_checks')->default(0);
                $table->unsignedInteger('failed_checks')->default(0);
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['keyword_library_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('geo_inclusion_check_results')) {
            Schema::create('geo_inclusion_check_results', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('run_id')->constrained('geo_inclusion_check_runs')->cascadeOnDelete();
                $table->foreignId('keyword_library_id')->constrained('keyword_libraries')->cascadeOnDelete();
                $table->foreignId('keyword_id')->constrained('keywords')->cascadeOnDelete();
                $table->foreignId('question_variant_id')->nullable()->constrained('keyword_question_variants')->nullOnDelete();
                $table->string('platform', 40);
                $table->text('question');
                $table->longText('answer')->nullable();
                $table->boolean('keyword_hit')->default(false);
                $table->boolean('brand_hit')->default(false);
                $table->string('status', 20)->default('pending')->index();
                $table->text('error_message')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('checked_at')->nullable()->index();
                $table->timestamps();

                $table->unique(['run_id', 'question_variant_id', 'platform'], 'geo_inclusion_unique_run_question_platform');
                $table->index(['keyword_library_id', 'platform', 'checked_at'], 'geo_inclusion_library_platform_checked_index');
                $table->index(['keyword_id', 'checked_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_inclusion_check_results');
        Schema::dropIfExists('geo_inclusion_check_runs');
    }
};
