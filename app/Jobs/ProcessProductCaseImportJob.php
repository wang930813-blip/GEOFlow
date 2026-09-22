<?php

/**
 * Created by Codex.
 * @Date: 2026-09-21
 * @Time: 16:06:48
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File：ProcessProductCaseImportJob.php
 * @Description: 产品案例异步导入队列任务
 */

namespace App\Jobs;

use App\Models\ProductCaseImport;
use App\Services\ProductCases\ProductCaseImportTaskService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ProcessProductCaseImportJob implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 1200;

    public int $uniqueFor = 3600;

    /**
     * 创建产品案例异步导入任务。
     *
     * @Url QUEUE geoflow
     *      登录 否
     *      importId int 必选 产品案例导入任务 ID
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     *
     * @Return void 任务构造完成
     * @Throws 无
     */
    public function __construct(public readonly int $importId) {}

    /**
     * 返回队列唯一键。
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     *
     * @Return string 产品案例导入任务唯一键
     * @Throws 无
     */
    public function uniqueId(): string
    {
        return 'product-case-import:'.$this->importId;
    }

    /**
     * 防止同一导入任务被多个队列进程并发处理。
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 17:45:53
     *
     * @Return array<int,WithoutOverlapping> 队列中间件
     * @Throws 无
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->releaseAfter(30)
                ->expireAfter(1800),
        ];
    }

    /**
     * 返回队列重试退避时间。
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 17:45:53
     *
     * @Return array<int,int> 每次重试前等待的秒数
     * @Throws 无
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * 返回 Horizon 展示标签。
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     *
     * @Return array<int,string> 队列标签
     * @Throws 无
     */
    public function tags(): array
    {
        return [
            'product-case-import',
            'product-case-import:'.$this->importId,
        ];
    }

    /**
     * 执行产品案例导入任务。
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     *
     * @Return void 导入结果写入任务表
     * @Throws Throwable 导入文件、图片或数据库处理失败时抛出
     */
    public function handle(ProductCaseImportTaskService $taskService): void
    {
        $task = ProductCaseImport::query()->whereKey($this->importId)->first();
        if (! $task instanceof ProductCaseImport) {
            return;
        }

        $taskService->process($task);
    }

    /**
     * 处理队列层异常，避免导入任务停留在执行中。
     *
     * @param  Throwable|null $exception
     * @return void
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    public function failed(?Throwable $exception = null): void
    {
        $task = ProductCaseImport::query()->whereKey($this->importId)->first();
        if (! $task instanceof ProductCaseImport) {
            return;
        }

        app(ProductCaseImportTaskService::class)->markFailed($task, $exception);
    }
}
