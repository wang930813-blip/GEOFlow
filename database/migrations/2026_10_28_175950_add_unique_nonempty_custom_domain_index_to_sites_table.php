<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-10-28
 *
 * @Time: 17:59:50
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File：2026_10_28_175950_add_unique_nonempty_custom_domain_index_to_sites_table.php
 *
 * @Description: 为非空客户自定义域名添加数据库级唯一约束。
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
     * @Description: 预检已有重复域名后建立非空域名的大小写不敏感唯一约束。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-28 17:59:50
     *
     * @UpdateTime: 2026-10-28 17:59:50
     *
     * @Return void 数据库约束创建完成
     *
     * @Throws RuntimeException 发现已有重复域名或数据库类型不支持时抛出
     */
    public function up(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'custom_domain')) {
            return;
        }

        $hasDuplicateDomains = DB::table('sites')
            ->selectRaw('LOWER(custom_domain)')
            ->whereNotNull('custom_domain')
            ->where('custom_domain', '<>', '')
            ->groupByRaw('LOWER(custom_domain)')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateDomains) {
            throw new RuntimeException('Duplicate customer domains exist; the uniqueness migration was stopped without changing data.');
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS sites_custom_domain_unique_nonempty ON sites (LOWER(custom_domain)) WHERE custom_domain IS NOT NULL AND custom_domain <> ''");

            return;
        }

        if ($driver === 'mysql') {
            Schema::table('sites', function (Blueprint $table): void {
                if (! Schema::hasColumn('sites', 'custom_domain_unique')) {
                    $table->string('custom_domain_unique', 253)
                        ->nullable()
                        ->charset('ascii')
                        ->virtualAs("NULLIF(LOWER(custom_domain), '')");
                    $table->unique('custom_domain_unique', 'sites_custom_domain_unique_nonempty');
                }
            });

            return;
        }

        throw new RuntimeException('The active database driver does not support customer-domain uniqueness.');
    }

    /**
     * @Name: down
     *
     * @Description: 删除此前添加的数据库级客户域名唯一约束。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-28 17:59:50
     *
     * @UpdateTime: 2026-10-28 17:59:50
     *
     * @Return void 约束删除完成
     *
     * @Throws RuntimeException 数据库类型不支持时抛出
     */
    public function down(): void
    {
        if (! Schema::hasTable('sites')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS sites_custom_domain_unique_nonempty');

            return;
        }

        if ($driver === 'mysql' && Schema::hasColumn('sites', 'custom_domain_unique')) {
            Schema::table('sites', function (Blueprint $table): void {
                $table->dropUnique('sites_custom_domain_unique_nonempty');
                $table->dropColumn('custom_domain_unique');
            });

            return;
        }

        if ($driver !== 'mysql') {
            throw new RuntimeException('The active database driver does not support customer-domain uniqueness rollback.');
        }
    }
};
