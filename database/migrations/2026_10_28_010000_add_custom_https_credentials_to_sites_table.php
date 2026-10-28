<?php

/**
 * Created by Codex.
 * @Date: 2026-10-28
 * @Time: 13:37:25
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File：2026_10_28_010000_add_custom_https_credentials_to_sites_table.php
 * @Description: 为站点保存客户自定义域名的 HTTPS 开关、证书和私钥配置。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            if (! Schema::hasColumn('sites', 'custom_https_enabled')) {
                $table->boolean('custom_https_enabled')->default(false)->after('custom_domain');
            }
            if (! Schema::hasColumn('sites', 'custom_https_certificate')) {
                $table->text('custom_https_certificate')->nullable()->after('custom_https_enabled');
            }
            if (! Schema::hasColumn('sites', 'custom_https_private_key')) {
                $table->text('custom_https_private_key')->nullable()->after('custom_https_certificate');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $columns = array_values(array_filter([
                Schema::hasColumn('sites', 'custom_https_private_key') ? 'custom_https_private_key' : null,
                Schema::hasColumn('sites', 'custom_https_certificate') ? 'custom_https_certificate' : null,
                Schema::hasColumn('sites', 'custom_https_enabled') ? 'custom_https_enabled' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
