<?php

namespace App\Console\Commands;

use App\Services\ProductCases\ProductCaseImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportProductCasesCommand extends Command
{
    protected $signature = 'geoflow:import-product-cases
        {--source=docs/GEO案例库_10个行业示例(1).xlsx : GEO 案例库 Excel 文件路径}
        {--admin-id= : 指定超级管理员 ID}
        {--site-id= : 指定超级管理员默认站点 ID}
        {--refresh-images : 已存在案例封面也重新上传}
        {--dry-run : 只读取并校验文件，不写入数据库或上传图片}';

    protected $description = '从 GEO 案例库 Excel 批量导入产品案例、图片和品牌诊断演示数据';

    public function handle(ProductCaseImportService $service): int
    {
        try {
            $stats = $service->import(
                sourcePath: (string) $this->option('source'),
                adminId: $this->positiveIntOption('admin-id'),
                siteId: $this->positiveIntOption('site-id'),
                refreshImages: (bool) $this->option('refresh-images'),
                dryRun: (bool) $this->option('dry-run')
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($stats['dry_run']) {
            $this->info(sprintf(
                '案例库校验完成：读取 %d 条，目标超级管理员 ID=%d，默认站点 ID=%d。',
                $stats['total'],
                $stats['admin_id'],
                $stats['site_id']
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '案例库导入完成：总计 %d 条，新增 %d 条，更新 %d 条，图片上传 %d 张，复用 %d 张，诊断任务 %d 个，失败 %d 条。',
            $stats['total'],
            $stats['created'],
            $stats['updated'],
            $stats['images_uploaded'],
            $stats['images_reused'],
            $stats['diagnosis_runs'],
            $stats['failed']
        ));
        foreach ($stats['errors'] as $error) {
            $this->warn($error);
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function positiveIntOption(string $name): ?int
    {
        $value = trim((string) $this->option($name));

        return $value !== '' && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
    }
}
