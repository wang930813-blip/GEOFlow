<?php

/**
 * Created by Codex.
 * @Date: 2026-09-21
 * @Time: 16:06:48
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File：ProductCaseImportTaskService.php
 * @Description: 产品案例异步导入任务编排服务
 */

namespace App\Services\ProductCases;

use App\Models\Admin;
use App\Models\ProductCase;
use App\Models\ProductCaseImport;
use App\Models\ProductCaseImportItem;
use App\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProductCaseImportTaskService
{
    public function __construct(
        private readonly ProductCaseSpreadsheetReader $reader,
        private readonly ProductCaseImportService $importService
    ) {}

    /**
     * 创建案例导入任务。
     *
     * 页面上传场景只创建任务和文件索引，由队列首次执行时解析文件并批量建立明细。
     *
     * @param  list<array<string,mixed>>  $rows
     * @param  array<string,mixed>  $options
     * @param  string $fileSha256
     * @return ProductCaseImport
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 18:56:14
     */
    public function create(
        Admin $actor,
        Site $site,
        string $storedPath,
        string $originalFilename,
        array $rows,
        array $options = [],
        string $fileSha256 = ''
    ): ProductCaseImport {
        $ownerAdminId = (int) ($site->owner_admin_id ?: $actor->id);
        if ($ownerAdminId <= 0) {
            throw new RuntimeException('当前站点没有可用的案例归属管理员');
        }

        return DB::transaction(function () use (
            $actor,
            $site,
            $storedPath,
            $originalFilename,
            $rows,
            $options,
            $fileSha256,
            $ownerAdminId
        ): ProductCaseImport {
            $task = ProductCaseImport::query()->create([
                'site_id' => (int) $site->id,
                'owner_admin_id' => $ownerAdminId,
                'created_by_admin_id' => (int) $actor->id,
                'original_filename' => trim($originalFilename),
                'stored_path' => trim($storedPath),
                'file_sha256' => trim($fileSha256) !== '' ? trim($fileSha256) : null,
                'status' => 'queued',
                'total_rows' => count($rows),
                'options_json' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error_message' => '',
            ]);

            if ($rows !== []) {
                $this->insertRows($task, $rows);
            }

            return $task;
        });
    }

    /**
     * 查找当前站点已经创建过的相同文件任务。
     *
     * @param  Site $site
     * @param  string $fileSha256
     * @return ProductCaseImport|null
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 18:56:14
     * @UpdateTime: 2026-09-21 18:56:14
     *
     * @Throws 无
     */
    public function findByFingerprint(Site $site, string $fileSha256): ?ProductCaseImport
    {
        $fileSha256 = trim($fileSha256);
        if ($fileSha256 === '') {
            return null;
        }

        return ProductCaseImport::query()
            ->where('site_id', (int) $site->id)
            ->where('file_sha256', $fileSha256)
            ->latest('id')
            ->first();
    }

    /**
     * 执行一条案例导入任务并持续回写逐行进度。
     *
     * @param  ProductCaseImport $task
     * @return void
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     * @Throws RuntimeException 文件不存在、管理员不存在或导入上下文无效时抛出
     */
    public function process(ProductCaseImport $task): void
    {
        if ((string) $task->status === 'completed') {
            return;
        }

        $task->update([
            'status' => 'running',
            'started_at' => $task->started_at ?: now(),
            'finished_at' => null,
            'error_message' => '',
        ]);

        try {
            $path = Storage::disk('local')->path((string) $task->stored_path);
            if (! is_file($path)) {
                throw new RuntimeException('导入文件不存在或已被清理');
            }

            $site = Site::query()->whereKey((int) $task->site_id)->first();
            $ownerAdmin = Admin::query()->whereKey((int) $task->owner_admin_id)->first();
            $actor = Admin::query()->whereKey((int) $task->created_by_admin_id)->first();
            if (! $site instanceof Site || ! $ownerAdmin instanceof Admin) {
                throw new RuntimeException('导入任务的站点或案例归属管理员不存在');
            }

            $rows = $this->reader->read($path);
            $this->initializeRows($task, $rows);
            $task->refresh();
            $pendingRows = $this->pendingRows($task, $rows);
            if ($pendingRows !== []) {
                $this->importService->importRows(
                    rows: $pendingRows,
                    sourcePath: $path,
                    ownerAdmin: $ownerAdmin,
                    site: $site,
                    actorAdmin: $actor instanceof Admin ? $actor : $ownerAdmin,
                    refreshImages: $this->refreshImages($task),
                    onRowProcessed: function (array $result) use ($task): void {
                        $this->recordItemResult($task, $result);
                    },
                    preventCrossSiteOverwrite: true
                );
            }

            $stats = $this->aggregateStats($task);
            $finalStatus = $stats['failed_count'] > 0 && $stats['processed_rows'] === $stats['failed_count']
                ? 'failed'
                : 'completed';

            $task->update([
                'status' => $finalStatus,
                'processed_rows' => $stats['processed_rows'],
                'created_count' => $stats['created_count'],
                'updated_count' => $stats['updated_count'],
                'images_uploaded' => $stats['images_uploaded'],
                'images_reused' => $stats['images_reused'],
                'diagnosis_runs' => $stats['diagnosis_runs'],
                'failed_count' => $stats['failed_count'],
                'error_message' => $this->summaryError($task),
                'finished_at' => now(),
            ]);
            $this->deleteSourceFile($task);
        } catch (Throwable $exception) {
            throw $exception;
        }
    }

    /**
     * 回写队列任务无法处理时的最终失败状态。
     *
     * @param  ProductCaseImport $task
     * @param  Throwable|null $exception
     * @return void
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 18:56:14
     * @Throws void 不向队列失败回调继续抛出异常
     */
    public function markFailed(ProductCaseImport $task, ?Throwable $exception = null): void
    {
        if ((string) $task->status === 'completed') {
            return;
        }

        $stats = $this->aggregateStats($task);
        $totalRows = (int) $task->total_rows;
        $allRowsProcessed = $totalRows > 0 && $stats['processed_rows'] >= $totalRows;
        if ($allRowsProcessed) {
            $task->update([
                'status' => $stats['failed_count'] === $totalRows ? 'failed' : 'completed',
                'processed_rows' => $stats['processed_rows'],
                'created_count' => $stats['created_count'],
                'updated_count' => $stats['updated_count'],
                'images_uploaded' => $stats['images_uploaded'],
                'images_reused' => $stats['images_reused'],
                'diagnosis_runs' => $stats['diagnosis_runs'],
                'failed_count' => $stats['failed_count'],
                'error_message' => $this->summaryError($task),
                'finished_at' => now(),
            ]);
            $this->deleteSourceFile($task);

            return;
        }

        $message = trim((string) ($exception?->getMessage() ?? ''));
        if ($message === '') {
            $message = '案例导入任务执行失败';
        }

        $task->update([
            'status' => 'failed',
            'processed_rows' => $stats['processed_rows'],
            'created_count' => $stats['created_count'],
            'updated_count' => $stats['updated_count'],
            'images_uploaded' => $stats['images_uploaded'],
            'images_reused' => $stats['images_reused'],
            'diagnosis_runs' => $stats['diagnosis_runs'],
            'failed_count' => $stats['failed_count'],
            'error_message' => mb_substr($message, 0, 2000, 'UTF-8'),
            'finished_at' => now(),
        ]);

        $this->deleteSourceFile($task);
    }

    /**
     * 在队列中首次解析文件并建立逐行任务明细。
     *
     * @param  ProductCaseImport $task
     * @param  list<array<string,mixed>>  $rows
     * @return void
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 18:56:14
     * @UpdateTime: 2026-09-21 18:56:14
     *
     * @Throws RuntimeException 案例表格没有可导入数据
     */
    private function initializeRows(ProductCaseImport $task, array $rows): void
    {
        if ($task->items()->exists()) {
            return;
        }

        if ($rows === []) {
            throw new RuntimeException('案例文件没有可导入的数据');
        }

        DB::transaction(function () use ($task, $rows): void {
            $lockedTask = ProductCaseImport::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTask->items()->exists()) {
                return;
            }

            $this->insertRows($lockedTask, $rows);
            $lockedTask->update(['total_rows' => count($rows)]);
        });
    }

    /**
     * 批量建立逐行任务明细。
     *
     * @param  ProductCaseImport $task
     * @param  list<array<string,mixed>>  $rows
     * @return void
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 18:56:14
     * @UpdateTime: 2026-09-21 18:56:14
     *
     * @Throws 无
     */
    private function insertRows(ProductCaseImport $task, array $rows): void
    {
        $now = now();
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'product_case_import_id' => (int) $task->id,
                'row_number' => (int) ($row['row_number'] ?? 0),
                'brand_name' => trim((string) ($row['brand_name'] ?? '')),
                'title' => trim((string) ($row['title'] ?? '')),
                'status' => 'pending',
                'action' => '',
                'result_json' => '',
                'error_message' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        ProductCaseImportItem::query()->insert($items);
    }

    /**
     * 回写单行导入结果并更新任务进度。
     *
     * @param  ProductCaseImport $task
     * @param  array<string,mixed> $result
     * @return void
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 17:45:53
     * @Throws void 单行错误由导入服务转换为结果，不阻断后续行
     */
    private function recordItemResult(ProductCaseImport $task, array $result): void
    {
        DB::transaction(function () use ($task, $result): void {
            $item = ProductCaseImportItem::query()
                ->where('product_case_import_id', (int) $task->id)
                ->where('row_number', (int) ($result['row_number'] ?? 0))
                ->lockForUpdate()
                ->first();

            if (! $item instanceof ProductCaseImportItem) {
                return;
            }

            $oldCounters = $this->itemCounters(
                (string) $item->status,
                (string) $item->action,
                json_decode((string) $item->result_json, true)
            );
            $succeeded = (string) ($result['status'] ?? '') === 'succeeded';
            $newStatus = $succeeded ? 'succeeded' : 'failed';
            $newAction = trim((string) ($result['action'] ?? ''));
            $newCounters = $this->itemCounters($newStatus, $newAction, $result);

            $item->update([
                'status' => $newStatus,
                'action' => $newAction,
                'product_case_id' => (int) ($result['product_case_id'] ?? 0) > 0
                    ? (int) $result['product_case_id']
                    : null,
                'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'error_message' => trim((string) ($result['error'] ?? '')),
                'started_at' => $item->started_at ?: now(),
                'finished_at' => now(),
            ]);

            $task->refresh();
            $task->update([
                'processed_rows' => max(0, (int) $task->processed_rows + $newCounters['processed_rows'] - $oldCounters['processed_rows']),
                'created_count' => max(0, (int) $task->created_count + $newCounters['created_count'] - $oldCounters['created_count']),
                'updated_count' => max(0, (int) $task->updated_count + $newCounters['updated_count'] - $oldCounters['updated_count']),
                'images_uploaded' => max(0, (int) $task->images_uploaded + $newCounters['images_uploaded'] - $oldCounters['images_uploaded']),
                'images_reused' => max(0, (int) $task->images_reused + $newCounters['images_reused'] - $oldCounters['images_reused']),
                'diagnosis_runs' => max(0, (int) $task->diagnosis_runs + $newCounters['diagnosis_runs'] - $oldCounters['diagnosis_runs']),
                'failed_count' => max(0, (int) $task->failed_count + $newCounters['failed_count'] - $oldCounters['failed_count']),
            ]);
        });
    }

    /**
     * 计算单行结果对任务统计的贡献。
     *
     * @param  string $status
     * @param  string $action
     * @param  array<string,mixed>|null $result
     * @return array{processed_rows:int,created_count:int,updated_count:int,images_uploaded:int,images_reused:int,diagnosis_runs:int,failed_count:int}
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 18:56:14
     * @UpdateTime: 2026-09-21 18:56:14
     *
     * @Throws 无
     */
    private function itemCounters(string $status, string $action, ?array $result): array
    {
        if (! in_array($status, ['succeeded', 'failed'], true)) {
            return [
                'processed_rows' => 0,
                'created_count' => 0,
                'updated_count' => 0,
                'images_uploaded' => 0,
                'images_reused' => 0,
                'diagnosis_runs' => 0,
                'failed_count' => 0,
            ];
        }

        if ($status === 'failed') {
            return [
                'processed_rows' => 1,
                'created_count' => 0,
                'updated_count' => 0,
                'images_uploaded' => 0,
                'images_reused' => 0,
                'diagnosis_runs' => 0,
                'failed_count' => 1,
            ];
        }

        return [
            'processed_rows' => 1,
            'created_count' => $action === 'created' ? 1 : 0,
            'updated_count' => $action === 'updated' ? 1 : 0,
            'images_uploaded' => (int) ($result['images_uploaded'] ?? 0),
            'images_reused' => (int) ($result['images_reused'] ?? 0),
            'diagnosis_runs' => (int) ($result['diagnosis_run_id'] ?? 0) > 0 ? 1 : 0,
            'failed_count' => 0,
        ];
    }

    /**
     * 根据逐行结果计算任务统计，避免重复执行时累计错误。
     *
     * @param  ProductCaseImport $task
     * @return array{processed_rows:int,created_count:int,updated_count:int,images_uploaded:int,images_reused:int,diagnosis_runs:int,failed_count:int}
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    private function aggregateStats(ProductCaseImport $task): array
    {
        $items = ProductCaseImportItem::query()
            ->where('product_case_import_id', (int) $task->id)
            ->whereIn('status', ['succeeded', 'failed'])
            ->get(['status', 'action', 'result_json']);

        $stats = [
            'processed_rows' => $items->count(),
            'created_count' => 0,
            'updated_count' => 0,
            'images_uploaded' => 0,
            'images_reused' => 0,
            'diagnosis_runs' => 0,
            'failed_count' => 0,
        ];

        foreach ($items as $item) {
            if ((string) $item->status === 'failed') {
                $stats['failed_count']++;

                continue;
            }

            if ((string) $item->action === 'created') {
                $stats['created_count']++;
            } elseif ((string) $item->action === 'updated') {
                $stats['updated_count']++;
            }

            $result = json_decode((string) $item->result_json, true);
            if (! is_array($result)) {
                continue;
            }

            $stats['images_uploaded'] += (int) ($result['images_uploaded'] ?? 0);
            $stats['images_reused'] += (int) ($result['images_reused'] ?? 0);
            $stats['diagnosis_runs'] += (int) ($result['diagnosis_run_id'] ?? 0) > 0 ? 1 : 0;
        }

        return $stats;
    }

    /**
     * 过滤已经成功处理且对应案例仍存在的行，避免任务重试重复上传图片或重建诊断数据。
     *
     * 队列进程可能在单行处理完成、任务最终状态回写前被终止。重试时保留失败行和未完成行，
     * 仅跳过已经有有效案例结果的行，从而同时满足幂等和断点续跑。
     *
     * @param  ProductCaseImport $task
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 17:29:52
     * @UpdateTime: 2026-09-21 17:45:53
     *
     * @Throws 无
     */
    private function pendingRows(ProductCaseImport $task, array $rows): array
    {
        $completedItems = ProductCaseImportItem::query()
            ->where('product_case_import_id', (int) $task->id)
            ->where('status', 'succeeded')
            ->whereNotNull('product_case_id')
            ->get(['row_number', 'product_case_id'])
            ->filter(static fn (ProductCaseImportItem $item): bool => (int) ($item->product_case_id ?? 0) > 0);
        $existingCaseIds = ProductCase::query()
            ->whereIn(
                'id',
                $completedItems
                    ->pluck('product_case_id')
                    ->map(static fn (mixed $caseId): int => (int) $caseId)
                    ->all()
            )
            ->pluck('id')
            ->mapWithKeys(static fn (mixed $caseId): array => [(int) $caseId => true])
            ->all();
        $completedRows = $completedItems
            ->filter(static fn (ProductCaseImportItem $item): bool => isset($existingCaseIds[(int) $item->product_case_id]))
            ->pluck('row_number')
            ->map(static fn (mixed $rowNumber): int => (int) $rowNumber)
            ->flip()
            ->all();

        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ! isset($completedRows[(int) ($row['row_number'] ?? 0)])
        ));
    }

    /**
     * 删除仅供异步处理使用的原始上传文件。
     *
     * @param  ProductCaseImport  $task
     * @return void
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 17:45:53
     * @UpdateTime: 2026-09-21 17:45:53
     *
     * @Throws 无
     */
    private function deleteSourceFile(ProductCaseImport $task): void
    {
        $storedPath = trim((string) $task->stored_path);
        if ($storedPath !== '') {
            Storage::disk('local')->delete($storedPath);
        }
    }

    /**
     * 汇总逐行错误，控制页面展示长度。
     *
     * @param  ProductCaseImport $task
     * @return string
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    private function summaryError(ProductCaseImport $task): string
    {
        $errors = ProductCaseImportItem::query()
            ->where('product_case_import_id', (int) $task->id)
            ->where('status', 'failed')
            ->whereNotNull('error_message')
            ->pluck('error_message')
            ->map(static fn (mixed $message): string => trim((string) $message))
            ->filter()
            ->values()
            ->all();

        return mb_substr(implode("\n", $errors), 0, 2000, 'UTF-8');
    }

    /**
     * 读取任务的刷新封面配置。
     *
     * @param  ProductCaseImport $task
     * @return bool
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    private function refreshImages(ProductCaseImport $task): bool
    {
        $options = json_decode((string) $task->options_json, true);

        return is_array($options) && (bool) ($options['refresh_images'] ?? false);
    }
}
