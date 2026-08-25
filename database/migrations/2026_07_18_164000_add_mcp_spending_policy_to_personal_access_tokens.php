<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 *
 * @Time: 16:40
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： 2026_07_18_164000_add_mcp_spending_policy_to_personal_access_tokens.php
 *
 * @Description: 为具备媒体投稿权限的 MCP Key 保存服务器端单价、单次总额和每日消费上限。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @Name: up
     *
     * @Description: 增加 MCP Key 消费策略字段；历史 Key 保持空值并由业务层禁止付费调用。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:40:00
     *
     * @UpdateTime: 2026-07-18 16:40:00
     *
     * @Return: void
     */
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->decimal('mcp_max_unit_price', 12, 2)->nullable()->after('site_id');
            $table->decimal('mcp_max_total_price', 12, 2)->nullable()->after('mcp_max_unit_price');
            $table->decimal('mcp_daily_spend_limit', 12, 2)->nullable()->after('mcp_max_total_price');
        });
    }

    /**
     * @Name: down
     *
     * @Description: 删除 MCP Key 消费策略字段。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:40:00
     *
     * @UpdateTime: 2026-07-18 16:40:00
     *
     * @Return: void
     */
    public function down(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn(['mcp_max_unit_price', 'mcp_max_total_price', 'mcp_daily_spend_limit']);
        });
    }
};
