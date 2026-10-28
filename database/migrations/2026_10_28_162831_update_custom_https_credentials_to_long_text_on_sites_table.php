<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-10-28
 *
 * @Time: 17:53:21
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File：2026_10_28_162831_update_custom_https_credentials_to_long_text_on_sites_table.php
 *
 * @Description: 扩大加密 HTTPS 证书和私钥字段容量，避免较大 PEM 内容写入失败。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @Name: up
     *
     * @Description: 将加密 HTTPS 证书和私钥列提升到 LONGTEXT 容量。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-28 16:30:02
     *
     * @UpdateTime: 2026-10-28 17:53:21
     *
     * @Return void 字段容量更新完成
     *
     * @Throws Throwable 数据库拒绝变更时抛出
     */
    public function up(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            if (Schema::hasColumn('sites', 'custom_https_certificate')) {
                $table->longText('custom_https_certificate')->nullable()->change();
            }
            if (Schema::hasColumn('sites', 'custom_https_private_key')) {
                $table->longText('custom_https_private_key')->nullable()->change();
            }
        });
    }

    /**
     * @Name: down
     *
     * @Description: 仅在加密字段均未超过 TEXT 上限时安全恢复字段类型。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-28 16:30:02
     *
     * @UpdateTime: 2026-10-28 17:53:21
     *
     * @Return void 字段类型恢复完成
     *
     * @Throws RuntimeException 已存值过大时拒绝执行降级
     */
    public function down(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        foreach (['custom_https_certificate', 'custom_https_private_key'] as $column) {
            if (! Schema::hasColumn('sites', $column)) {
                continue;
            }

            $hasOversizedValue = DB::table('sites')->whereRaw('LENGTH('.$column.') > 65535')->exists();
            if ($hasOversizedValue) {
                throw new RuntimeException('Cannot reduce '.$column.' to TEXT because a stored value exceeds the TEXT limit.');
            }
        }

        Schema::table('sites', function (Blueprint $table): void {
            if (Schema::hasColumn('sites', 'custom_https_certificate')) {
                $table->text('custom_https_certificate')->nullable()->change();
            }
            if (Schema::hasColumn('sites', 'custom_https_private_key')) {
                $table->text('custom_https_private_key')->nullable()->change();
            }
        });
    }
};
