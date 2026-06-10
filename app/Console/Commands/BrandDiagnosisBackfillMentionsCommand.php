<?php

namespace App\Console\Commands;

use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandDiagnosisMentionBackfillService;
use Illuminate\Console\Command;

class BrandDiagnosisBackfillMentionsCommand extends Command
{
    protected $signature = 'brand-diagnosis:backfill-mentions
        {run_id : 品牌诊断记录 ID}
        {--platform= : 只回填指定平台，例如 doubao 或 deepseek}
        {--all : 覆盖已有品牌提及并重新回填}';

    protected $description = '回填品牌诊断结果中的品牌/竞品提及数据，并刷新诊断指标。';

    public function handle(BrandDiagnosisMentionBackfillService $service): int
    {
        $run = BrandDiagnosisRun::query()
            ->withoutGlobalScope('current_site')
            ->whereKey((int) $this->argument('run_id'))
            ->first();

        if (! $run) {
            $this->error('品牌诊断记录不存在。');

            return self::FAILURE;
        }

        $platform = $this->option('platform');
        $platform = is_string($platform) && trim($platform) !== '' ? strtolower(trim($platform)) : null;
        if ($platform !== null && ! in_array($platform, ['doubao', 'deepseek'], true)) {
            $this->error('platform 只支持 doubao 或 deepseek。');

            return self::FAILURE;
        }

        $stats = $service->backfillRun(
            $run,
            onlyMissing: ! (bool) $this->option('all'),
            platform: $platform
        );

        $this->info(sprintf(
            '品牌提及回填完成：处理 %d 条，更新 %d 条，跳过 %d 条，失败 %d 条。',
            $stats['processed'],
            $stats['updated'],
            $stats['skipped'],
            $stats['failed']
        ));
        foreach ($stats['errors'] as $error) {
            $this->warn($error);
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
