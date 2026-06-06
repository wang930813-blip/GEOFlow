<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_diagnosis_runs')) {
            Schema::create('brand_diagnosis_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
                $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->string('brand_name', 120);
                $table->json('platforms');
                $table->string('status', 20)->default('pending')->index();
                $table->unsignedInteger('total_questions')->default(0);
                $table->unsignedInteger('completed_questions')->default(0);
                $table->unsignedInteger('failed_questions')->default(0);
                $table->unsignedTinyInteger('brand_score')->default(0);
                $table->unsignedTinyInteger('mention_rate')->default(0);
                $table->decimal('average_rank', 8, 2)->default(0);
                $table->unsignedInteger('mention_count')->default(0);
                $table->unsignedTinyInteger('sentiment_rate')->default(0);
                $table->string('billing_mode', 30)->default('daily_free');
                $table->unsignedInteger('points_cost')->default(0);
                $table->unsignedBigInteger('points_transaction_id')->nullable();
                $table->boolean('limit_bypassed')->default(false);
                $table->string('limit_bypass_reason', 80)->default('');
                $table->date('usage_date')->nullable()->index();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['site_id', 'admin_id', 'created_at']);
                $table->index(['site_id', 'brand_name', 'created_at']);
            });
        }

        if (! Schema::hasTable('brand_diagnosis_questions')) {
            Schema::create('brand_diagnosis_questions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
                $table->foreignId('run_id')->constrained('brand_diagnosis_runs')->cascadeOnDelete();
                $table->text('question');
                $table->string('question_type', 80)->default('');
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('status', 20)->default('pending')->index();
                $table->timestamps();

                $table->index(['run_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('brand_diagnosis_results')) {
            Schema::create('brand_diagnosis_results', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
                $table->foreignId('run_id')->constrained('brand_diagnosis_runs')->cascadeOnDelete();
                $table->foreignId('question_id')->constrained('brand_diagnosis_questions')->cascadeOnDelete();
                $table->string('platform', 40);
                $table->longText('answer')->nullable();
                $table->boolean('brand_mentioned')->default(false);
                $table->unsignedInteger('mention_count')->default(0);
                $table->unsignedInteger('mention_rank')->default(0);
                $table->string('sentiment', 20)->default('neutral');
                $table->string('status', 20)->default('pending')->index();
                $table->text('error_message')->nullable();
                $table->json('raw_response')->nullable();
                $table->json('meta')->nullable();
                $table->timestamp('checked_at')->nullable()->index();
                $table->timestamps();

                $table->unique(['question_id', 'platform'], 'brand_diag_unique_question_platform');
                $table->index(['run_id', 'platform', 'checked_at'], 'brand_diag_result_run_platform_checked');
            });
        }

        if (! Schema::hasTable('brand_diagnosis_sources')) {
            Schema::create('brand_diagnosis_sources', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
                $table->foreignId('run_id')->constrained('brand_diagnosis_runs')->cascadeOnDelete();
                $table->foreignId('question_id')->constrained('brand_diagnosis_questions')->cascadeOnDelete();
                $table->foreignId('result_id')->constrained('brand_diagnosis_results')->cascadeOnDelete();
                $table->string('platform', 40);
                $table->string('title', 500)->default('');
                $table->text('url')->nullable();
                $table->string('domain', 255)->default('');
                $table->string('source_type', 80)->default('');
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['run_id', 'platform']);
            });
        }

        if (! Schema::hasTable('brand_diagnosis_usage_limits')) {
            Schema::create('brand_diagnosis_usage_limits', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->nullable()->constrained('sites')->cascadeOnDelete();
                $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
                $table->date('usage_date');
                $table->unsignedInteger('free_runs_used')->default(0);
                $table->timestamps();

                $table->unique(['site_id', 'admin_id', 'usage_date'], 'brand_diag_usage_unique_site_admin_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_diagnosis_usage_limits');
        Schema::dropIfExists('brand_diagnosis_sources');
        Schema::dropIfExists('brand_diagnosis_results');
        Schema::dropIfExists('brand_diagnosis_questions');
        Schema::dropIfExists('brand_diagnosis_runs');
    }
};
