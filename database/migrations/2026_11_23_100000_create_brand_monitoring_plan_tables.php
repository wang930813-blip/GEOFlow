<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-23
 *
 * @Time: 10:03:04
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： 2026_11_23_100000_create_brand_monitoring_plan_tables.php
 *
 * @Description: 创建品牌监测计划和每轮诊断执行记录。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_monitoring_plans')) {
            Schema::create('brand_monitoring_plans', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->string('name', 120);
                $table->string('brand_name', 120);
                $table->string('website_url', 2048)->nullable();
                $table->longText('brand_profile')->nullable();
                $table->json('brand_terms');
                $table->json('products');
                $table->json('competitors');
                $table->json('keywords');
                $table->json('questions');
                $table->json('platforms');
                $table->string('diagnosis_mode', 20)->default('standard');
                $table->string('frequency', 20)->default('daily');
                $table->unsignedSmallInteger('frequency_interval')->default(1);
                $table->string('run_time', 5)->default('09:00');
                $table->json('weekdays')->nullable();
                $table->unsignedTinyInteger('month_day')->nullable();
                $table->string('period_mode', 20)->default('indefinite');
                $table->date('starts_on');
                $table->date('ends_on')->nullable();
                $table->string('status', 24)->default('active')->index();
                $table->timestamp('next_run_at')->nullable()->index();
                $table->timestamp('last_run_at')->nullable();
                $table->foreignId('last_run_id')->nullable()->constrained('brand_diagnosis_runs')->nullOnDelete();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['site_id', 'status', 'next_run_at'], 'brand_monitor_plan_due_idx');
                $table->index(['site_id', 'owner_admin_id', 'created_at'], 'brand_monitor_plan_owner_idx');
            });
        }

        if (! Schema::hasTable('brand_monitoring_plan_runs')) {
            Schema::create('brand_monitoring_plan_runs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('plan_id')->constrained('brand_monitoring_plans')->cascadeOnDelete();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->foreignId('diagnosis_run_id')->nullable()->constrained('brand_diagnosis_runs')->nullOnDelete();
                $table->string('execution_key', 100);
                $table->timestamp('scheduled_for');
                $table->string('status', 24)->default('queued')->index();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();

                $table->unique(['plan_id', 'execution_key'], 'brand_monitor_plan_run_key_unique');
                $table->unique('diagnosis_run_id', 'brand_monitor_diag_run_unique');
                $table->index(['plan_id', 'scheduled_for'], 'brand_monitor_plan_run_history_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_monitoring_plan_runs');
        Schema::dropIfExists('brand_monitoring_plans');
    }
};
