<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-10-28
 *
 * @Time: 16:08:47
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File：2026_10_28_020000_add_customer_domain_deployment_status_to_sites_table.php
 *
 * @Description: 记录客户自定义域名反向代理配置的部署状态。
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
            if (! Schema::hasColumn('sites', 'custom_domain_deployment_status')) {
                $table->string('custom_domain_deployment_status', 24)->default('manual')->after('custom_https_private_key');
            }
            if (! Schema::hasColumn('sites', 'custom_domain_deployment_error')) {
                $table->text('custom_domain_deployment_error')->nullable()->after('custom_domain_deployment_status');
            }
            if (! Schema::hasColumn('sites', 'custom_domain_deployed_at')) {
                $table->timestamp('custom_domain_deployed_at')->nullable()->after('custom_domain_deployment_error');
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
                Schema::hasColumn('sites', 'custom_domain_deployed_at') ? 'custom_domain_deployed_at' : null,
                Schema::hasColumn('sites', 'custom_domain_deployment_error') ? 'custom_domain_deployment_error' : null,
                Schema::hasColumn('sites', 'custom_domain_deployment_status') ? 'custom_domain_deployment_status' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
