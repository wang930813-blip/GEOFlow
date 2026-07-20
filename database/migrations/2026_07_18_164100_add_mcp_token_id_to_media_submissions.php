<?php

/**
 * Created by 开发工具.
 *
 * @Date: 2026-07-18
 *
 * @Time: 16:41
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： 2026_07_18_164100_add_mcp_token_id_to_media_submissions.php
 *
 * @Description: 记录媒体投稿来源 MCP Key，用于执行并发安全的 Key 级每日消费上限。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @Name: up
     *
     * @Description: 为媒体投稿增加可空 MCP Token 编号及消费统计索引。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:41:00
     *
     * @UpdateTime: 2026-07-18 16:41:00
     *
     * @Return: void
     */
    public function up(): void
    {
        if (! Schema::hasTable('media_submissions') || Schema::hasColumn('media_submissions', 'mcp_token_id')) {
            return;
        }

        Schema::table('media_submissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('mcp_token_id')->nullable()->after('owner_admin_id');
            $table->index(['mcp_token_id', 'created_at'], 'media_submissions_mcp_token_created_idx');
        });
    }

    /**
     * @Name: down
     *
     * @Description: 删除媒体投稿 MCP Token 归属字段和索引。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-07-18 16:41:00
     *
     * @UpdateTime: 2026-07-18 16:41:00
     *
     * @Return: void
     */
    public function down(): void
    {
        if (! Schema::hasTable('media_submissions') || ! Schema::hasColumn('media_submissions', 'mcp_token_id')) {
            return;
        }

        Schema::table('media_submissions', function (Blueprint $table): void {
            $table->dropIndex('media_submissions_mcp_token_created_idx');
            $table->dropColumn('mcp_token_id');
        });
    }
};
