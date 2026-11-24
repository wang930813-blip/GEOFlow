<?php

/**
 * Created by Codex.
 * @Date: 2026-11-23
 * @Time: 15:59:54
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： 2026_11_23_155954_expand_brand_diagnosis_run_status_length.php
 * @Description: 扩大品牌诊断任务状态字段，容纳资料检索和确认等完整状态值。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @Name: up
     * @Description: 将诊断任务状态字段从 20 个字符扩至 40 个字符，兼容当前及后续状态标识。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-23 15:59:54
     * @UpdateTime: 2026-11-23 16:01:03
     *
     * @Param: 无
     * @Return: void 更新品牌诊断任务状态字段长度
     * @Throws: RuntimeException 数据库迁移执行失败
     */
    public function up(): void
    {
        if (! Schema::hasTable('brand_diagnosis_runs') || ! Schema::hasColumn('brand_diagnosis_runs', 'status')) {
            return;
        }

        Schema::table('brand_diagnosis_runs', function (Blueprint $table): void {
            $table->string('status', 40)->default('pending')->change();
        });
    }

    /**
     * @Name: down
     * @Description: 仅在现有状态值不超过旧字段长度时收缩字段，避免迁移回滚造成数据截断。
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-23 15:59:54
     * @UpdateTime: 2026-11-23 16:01:03
     *
     * @Param: 无
     * @Return: void 安全恢复品牌诊断任务状态字段长度
     * @Throws: RuntimeException 存在超过 20 个字符的状态值时拒绝回滚
     */
    public function down(): void
    {
        if (! Schema::hasTable('brand_diagnosis_runs') || ! Schema::hasColumn('brand_diagnosis_runs', 'status')) {
            return;
        }

        $hasLongStatus = DB::table('brand_diagnosis_runs')
            ->whereRaw('char_length(status) > ?', [20])
            ->exists();

        if ($hasLongStatus) {
            throw new \RuntimeException('存在超过 20 个字符的品牌诊断状态，拒绝收缩字段以避免数据截断。');
        }

        Schema::table('brand_diagnosis_runs', function (Blueprint $table): void {
            $table->string('status', 20)->default('pending')->change();
        });
    }
};
