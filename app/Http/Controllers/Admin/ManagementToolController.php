<?php

/**
 * Created by Codex.
 * @Date: 2026-09-21
 * @Time: 16:06:48
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File：ManagementToolController.php
 * @Description: 后台管理工具入口及产品案例导入任务控制器
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessProductCaseImportJob;
use App\Models\Admin;
use App\Models\ProductCase;
use App\Models\ProductCaseImport;
use App\Services\ProductCases\ProductCaseImportTaskService;
use App\Support\AdminDataScope;
use App\Support\AdminWeb;
use App\Support\CurrentSite;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\File;
use Illuminate\View\View;
use Throwable;

class ManagementToolController extends Controller
{
    public function __construct(
        private readonly CurrentSite $currentSite,
        private readonly AdminDataScope $adminDataScope,
        private readonly ProductCaseImportTaskService $taskService
    ) {}

    /**
     * 管理工具集合页。
     * 展示当前管理员可用的工具及最近案例导入任务。
     * @Url GET /geo_admin/management-tools
     *      登录 是
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 18:41:12
     *
     * @Return View 管理工具集合页面
     * @Throws 403 当前未登录后台管理员
     */
    public function index(): View
    {
        $admin = $this->currentAdmin();
        $tasks = $this->visibleImportQuery($admin)
            ->with(['site:id,name', 'creator:id,username,display_name'])
            ->latest('id')
            ->limit(8)
            ->get();

        return view('admin.management-tools.index', [
            'pageTitle' => '管理工具',
            'activeMenu' => 'management_tools',
            'adminSiteName' => AdminWeb::siteName(),
            'tasks' => $tasks,
            'caseCount' => $this->visibleCaseQuery($admin)->count(),
        ]);
    }

    /**
     * 产品案例导入页。
     * 展示上传表单和当前管理员可见的导入任务。
     * @Url GET /geo_admin/management-tools/product-case-import
     *      登录 是
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     *
     * @Return View 产品案例导入页面
     * @Throws 403 当前未登录后台管理员
     */
    public function productCaseImport(): View
    {
        $admin = $this->currentAdmin();

        return view('admin.management-tools.product-case-import', [
            'pageTitle' => '案例导入',
            'activeMenu' => 'management_tools',
            'adminSiteName' => AdminWeb::siteName(),
            'currentSite' => $this->currentSite->get(),
            'tasks' => $this->visibleImportQuery($admin)
                ->with(['site:id,name', 'creator:id,username,display_name'])
                ->latest('id')
                ->paginate(12)
                ->withQueryString(),
        ]);
    }

    /**
     * 上传 Excel 并创建异步案例导入任务。
     * 服务端只保存原文件并创建任务，表格解析、逐行明细建立和业务导入均由队列异步完成。
     * @Url POST /geo_admin/management-tools/product-case-import
     *      登录 是
     *      source file 必选 xlsx 案例表格，最大 20MB
     *      refresh_images boolean 可选 是否重新上传已有案例封面
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 18:56:14
     *
     * @Return RedirectResponse 跳转到导入任务详情
     * @Throws Throwable 文件保存或任务创建失败
     */
    public function storeProductCaseImport(Request $request): RedirectResponse
    {
        $admin = $this->currentAdmin();
        $site = $this->currentSite->get();

        if (! $site) {
            return back()->withErrors(['source' => '当前账号没有可用的站点，无法导入案例']);
        }

        $payload = $request->validate([
            'source' => [
                'required',
                File::types(['xlsx'])->max(20 * 1024),
            ],
            'refresh_images' => ['nullable', 'boolean'],
        ], [
            'source.required' => '请选择案例 Excel 文件',
            'source.types' => '案例文件必须是 XLSX 格式',
            'source.max' => '案例文件不能超过 20MB',
        ]);

        $file = $payload['source'] ?? null;
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            return back()->withErrors(['source' => '案例文件上传失败，请重新选择文件']);
        }

        $storedPath = '';
        $fileHash = '';
        $task = null;
        try {
            $realPath = $file->getRealPath();
            if (! is_string($realPath) || ! is_file($realPath)) {
                throw new \RuntimeException('无法读取上传的案例文件');
            }

            $fileHash = hash_file('sha256', $realPath) ?: '';
            if ($fileHash === '') {
                throw new \RuntimeException('无法计算案例文件校验值');
            }

            $existingTask = $this->taskService->findByFingerprint($site, $fileHash);
            if ($existingTask instanceof ProductCaseImport) {
                return redirect()
                    ->route('admin.management-tools.product-case-import.show', ['importId' => (int) $existingTask->id])
                    ->with('message', '相同案例文件已经创建过导入任务，无需重复提交');
            }

            $storedName = (string) Str::uuid().'.xlsx';
            $storedPath = 'product-case-imports/'.$storedName;
            $stored = Storage::disk('local')->putFileAs(
                'product-case-imports',
                $file,
                $storedName
            );
            if (! is_string($stored) || $stored === '') {
                throw new \RuntimeException('案例文件保存失败');
            }

            $task = $this->taskService->create(
                actor: $admin,
                site: $site,
                storedPath: $storedPath,
                originalFilename: (string) $file->getClientOriginalName(),
                rows: [],
                options: [
                    'refresh_images' => (bool) ($payload['refresh_images'] ?? false),
                ],
                fileSha256: $fileHash
            );

            ProcessProductCaseImportJob::dispatch((int) $task->id)
                ->onQueue('geoflow')
                ->afterCommit();

            return redirect()
                ->route('admin.management-tools.product-case-import.show', ['importId' => (int) $task->id])
                ->with('message', '案例文件已进入异步导入队列');
        } catch (QueryException $exception) {
            $existingTask = $fileHash !== '' ? $this->taskService->findByFingerprint($site, $fileHash) : null;
            if ($existingTask instanceof ProductCaseImport) {
                if ($storedPath !== '') {
                    Storage::disk('local')->delete($storedPath);
                }

                return redirect()
                    ->route('admin.management-tools.product-case-import.show', ['importId' => (int) $existingTask->id])
                    ->with('message', '相同案例文件已经创建过导入任务，无需重复提交');
            }

            report($exception);
            if ($task instanceof ProductCaseImport) {
                $this->taskService->markFailed($task, new \RuntimeException('导入任务创建失败'));
            } elseif ($storedPath !== '') {
                Storage::disk('local')->delete($storedPath);
            }

            return back()
                ->withInput()
                ->withErrors(['source' => '案例导入任务创建失败，请稍后重试']);
        } catch (Throwable $exception) {
            report($exception);
            if ($task instanceof ProductCaseImport) {
                // 队列投递失败时将任务明确标记为失败，避免页面保留无法执行的“排队中”任务。
                $this->taskService->markFailed($task, $exception);
            } elseif ($storedPath !== '') {
                Storage::disk('local')->delete($storedPath);
            }

            return back()
                ->withInput()
                ->withErrors(['source' => '案例导入任务创建失败，请检查文件后重试']);
        }
    }

    /**
     * 查看案例导入任务及逐行结果。
     * @Url GET /geo_admin/management-tools/product-case-import/{importId}
     *      登录 是
     *      importId int 必选 导入任务 ID
     *
     *      分页参数：
     *      page int 可选 逐行结果页码
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     *
     * @Return View 导入任务详情页面
     * @Throws 404 任务不存在或不属于当前管理员可见范围
     */
    public function showProductCaseImport(int $importId): View
    {
        $admin = $this->currentAdmin();
        $task = $this->visibleImportQuery($admin)
            ->with(['site:id,name', 'creator:id,username,display_name'])
            ->whereKey($importId)
            ->firstOrFail();
        $items = $task->items()
            ->orderBy('row_number')
            ->paginate(50)
            ->withQueryString();

        return view('admin.management-tools.product-case-import-show', [
            'pageTitle' => '案例导入任务',
            'activeMenu' => 'management_tools',
            'adminSiteName' => AdminWeb::siteName(),
            'task' => $task,
            'items' => $items,
            'statusLabel' => $this->statusLabel((string) $task->status),
            'progressPercent' => $this->progressPercent($task),
            'statusUrl' => route('admin.management-tools.product-case-import.status', ['importId' => (int) $task->id]),
        ]);
    }

    /**
     * 返回案例导入任务状态，用于详情页轮询进度。
     * @Url GET /geo_admin/management-tools/product-case-import/{importId}/status
     *      登录 是
     *      importId int 必选 导入任务 ID
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     *
     * @Return JsonResponse 任务状态、进度和结果统计
     * @Throws 404 任务不存在或不属于当前管理员可见范围
     */
    public function productCaseImportStatus(int $importId): JsonResponse
    {
        $admin = $this->currentAdmin();
        $task = $this->visibleImportQuery($admin)->whereKey($importId)->firstOrFail();

        return response()->json([
            'id' => (int) $task->id,
            'status' => (string) $task->status,
            'status_label' => $this->statusLabel((string) $task->status),
            'progress_percent' => $this->progressPercent($task),
            'total_rows' => (int) $task->total_rows,
            'processed_rows' => (int) $task->processed_rows,
            'created_count' => (int) $task->created_count,
            'updated_count' => (int) $task->updated_count,
            'failed_count' => (int) $task->failed_count,
            'images_uploaded' => (int) $task->images_uploaded,
            'images_reused' => (int) $task->images_reused,
            'diagnosis_runs' => (int) $task->diagnosis_runs,
            'error_message' => (string) ($task->error_message ?? ''),
            'finished_at' => $task->finished_at?->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 按当前管理员数据范围查询案例导入任务。
     *
     * @param  Admin $admin
     * @return \Illuminate\Database\Eloquent\Builder<ProductCaseImport>
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    private function visibleImportQuery(Admin $admin)
    {
        $query = ProductCaseImport::query();
        $this->adminDataScope->applySiteScope($query, $admin, 'product_case_imports.site_id');

        return $query;
    }

    /**
     * 按当前管理员数据范围查询产品案例。
     *
     * @param  Admin $admin
     * @return \Illuminate\Database\Eloquent\Builder<ProductCase>
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    private function visibleCaseQuery(Admin $admin)
    {
        $query = ProductCase::query();
        $this->adminDataScope->applySiteScope($query, $admin, 'product_cases.site_id');

        return $query;
    }

    /**
     * 获取当前后台管理员。
     *
     * @return Admin
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    private function currentAdmin(): Admin
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof Admin && $admin->isSuperAdmin(), 403);

        return $admin;
    }

    /**
     * 计算任务进度百分比。
     *
     * @param  ProductCaseImport $task
     * @return int
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    private function progressPercent(ProductCaseImport $task): int
    {
        $total = (int) $task->total_rows;
        if ($total <= 0) {
            return in_array((string) $task->status, ['completed', 'failed'], true) ? 100 : 0;
        }

        return min(100, max(0, (int) floor(((int) $task->processed_rows / $total) * 100)));
    }

    /**
     * 获取任务状态中文名称。
     *
     * @param  string $status
     * @return string
     *
     * @Author: cdkay
     * @CreateTime: 2026-09-21 16:06:48
     * @UpdateTime: 2026-09-21 16:06:48
     */
    private function statusLabel(string $status): string
    {
        return match ($status) {
            'queued' => '排队中',
            'running' => '执行中',
            'completed' => '已完成',
            'failed' => '执行失败',
            default => $status !== '' ? $status : '未知',
        };
    }
}
