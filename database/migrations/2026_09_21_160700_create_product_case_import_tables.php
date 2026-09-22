<?php

/**
 * Created by Codex.
 * @Date: 2026-09-21
 * @Time: 16:06:48
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File：2026_09_21_160700_create_product_case_import_tables.php
 * @Description: 创建产品案例异步导入任务及逐行结果表
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建产品案例异步导入任务表和任务明细表。
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    public function up(): void
    {
        Schema::create('product_case_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('original_filename', 255);
            $table->string('stored_path', 500);
            $table->string('file_sha256', 64)->nullable();
            $table->string('status', 20)->default('queued')->index();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('images_uploaded')->default(0);
            $table->unsignedInteger('images_reused')->default(0);
            $table->unsignedInteger('diagnosis_runs')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('options_json')->default('');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'status', 'created_at']);
            $table->index(['owner_admin_id', 'created_at']);
            $table->unique(['site_id', 'file_sha256']);
        });

        Schema::create('product_case_import_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_case_import_id')
                ->constrained('product_case_imports')
                ->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('brand_name', 180)->default('');
            $table->string('title', 180)->default('');
            $table->string('status', 20)->default('pending')->index();
            $table->string('action', 20)->default('');
            $table->foreignId('product_case_id')->nullable()->constrained('product_cases')->nullOnDelete();
            $table->text('result_json')->default('');
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['product_case_import_id', 'row_number']);
            $table->index(['product_case_import_id', 'status']);
        });
    }

    /**
     * 删除产品案例异步导入任务表和任务明细表。
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    public function down(): void
    {
        Schema::dropIfExists('product_case_import_items');
        Schema::dropIfExists('product_case_imports');
    }
};
