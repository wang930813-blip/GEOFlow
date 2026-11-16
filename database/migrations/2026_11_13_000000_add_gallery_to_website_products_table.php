<?php

/**
 * Created by Codex.
 * @Date: 2026-11-13
 * @Time: 09:25:56
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： 2026_11_13_000000_add_gallery_to_website_products_table.php
 * @Description: 为官网产品保存有序的相册图片地址列表。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @Name: up
     * @Description: 为产品表增加可空 JSON 相册列，兼容已有产品记录。
     * @Author: cdkay
     * @CreateTime: 2026-11-13 09:25:56
     * @UpdateTime: 2026-11-13 09:25:56
     * @Return void 完成产品相册字段迁移
     * @Throws Throwable 数据库无法修改产品表结构
     */
    public function up(): void
    {
        if (Schema::hasTable('website_products') && ! Schema::hasColumn('website_products', 'gallery')) {
            Schema::table('website_products', function (Blueprint $table): void {
                $table->json('gallery')->nullable()->after('cover_image');
            });
        }
    }

    /**
     * @Name: down
     * @Description: 回滚时移除产品相册字段，不触碰产品其他内容。
     * @Author: cdkay
     * @CreateTime: 2026-11-13 09:25:56
     * @UpdateTime: 2026-11-13 09:25:56
     * @Return void 完成产品相册字段回滚
     * @Throws Throwable 数据库无法移除产品表字段
     */
    public function down(): void
    {
        if (Schema::hasTable('website_products') && Schema::hasColumn('website_products', 'gallery')) {
            Schema::table('website_products', function (Blueprint $table): void {
                $table->dropColumn('gallery');
            });
        }
    }
};
