<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-23
 *
 * @Time: 10:17:20
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： BrandMonitoringRunDuePlansCommand.php
 *
 * @Description: 启动到期的品牌监测计划并同步历史诊断状态。
 */

namespace App\Console\Commands;

use App\Services\BrandDiagnosis\BrandMonitoringPlanService;
use Illuminate\Console\Command;

final class BrandMonitoringRunDuePlansCommand extends Command
{
    protected $signature = 'brand-monitoring:run-due {--limit=50 : 本次最多处理的计划数}';

    protected $description = '启动到期的品牌监测计划。';

    /**
     * @Name: handle
     *
     * @Description: 扫描到期计划并报告本轮成功启动数量，单个计划异常由服务记录后继续处理。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-23 10:17:20
     *
     * @UpdateTime: 2026-11-23 10:17:20
     *
     * @Param: BrandMonitoringPlanService $plans 品牌监测计划服务
     *
     * @Return int Artisan 命令退出状态
     *
     * @Throws: 无 单个计划错误由计划服务捕获并记录
     */
    public function handle(BrandMonitoringPlanService $plans): int
    {
        $started = $plans->runDuePlans((int) $this->option('limit'));
        $this->info(sprintf('本轮已处理 %d 个到期品牌监测计划。', $started));

        return self::SUCCESS;
    }
}
