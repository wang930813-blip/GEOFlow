<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 *
 * @Time: 16:30
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： 2026_07_18_163000_add_processing_state_to_api_idempotency_keys.php
 *
 * @Description: 为 API 幂等缓存增加站点归属和执行前占位状态，阻止并发重复执行写操作。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @Name: up
     *
     * @Description: 补齐测试库站点字段，并增加处理中状态与开始时间。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:30:00
     *
     * @UpdateTime: 2026-07-18 16:30:00
     *
     * @Return: void
     */
    public function up(): void
    {
        if (! Schema::hasTable('api_idempotency_keys')) {
            return;
        }

        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            if (! Schema::hasColumn('api_idempotency_keys', 'site_id')) {
                $table->unsignedBigInteger('site_id')->nullable()->after('id')->index();
            }
            if (! Schema::hasColumn('api_idempotency_keys', 'state')) {
                $table->string('state', 20)->default('completed')->after('response_status')->index();
            }
            if (! Schema::hasColumn('api_idempotency_keys', 'processing_started_at')) {
                $table->timestamp('processing_started_at')->nullable()->after('state');
            }
        });
    }

    /**
     * @Name: down
     *
     * @Description: 回滚幂等处理中状态字段，保留可能由早期站点迁移创建的站点字段。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:30:00
     *
     * @UpdateTime: 2026-07-18 16:30:00
     *
     * @Return: void
     */
    public function down(): void
    {
        if (! Schema::hasTable('api_idempotency_keys')) {
            return;
        }

        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            if (Schema::hasColumn('api_idempotency_keys', 'processing_started_at')) {
                $table->dropColumn('processing_started_at');
            }
            if (Schema::hasColumn('api_idempotency_keys', 'state')) {
                $table->dropColumn('state');
            }
        });
    }
};
