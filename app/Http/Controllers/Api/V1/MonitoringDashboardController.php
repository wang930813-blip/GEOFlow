<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-02
 *
 * @Time: 17:37:32
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： MonitoringDashboardController.php
 *
 * @Description: 提供新版监测中心读取现有监测和品牌诊断数据的接口。
 */

namespace App\Http\Controllers\Api\V1;

use App\Models\Admin;
use App\Models\AdminPlanSubscription;
use App\Models\Site;
use App\Services\MonitoringCenter\MonitoringDashboardDataService;
use App\Support\CurrentSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class MonitoringDashboardController extends BaseApiController
{
    /**
     * index
     * 获取当前账号和站点范围内按诊断记录、平台、问题和日期筛选的监测数据。
     *
     * @Url [GET] /api/v1/monitor/dashboard
     *      登录 是
     *
     *      分页参数：
     *      无
     *
     *      筛选参数：
     *      run_id integer 可选 诊断记录编号
     *      platform string 可选 平台筛选键
     *      question_id integer 可选 诊断问题编号
     *      start_date string 可选 诊断完成起始日期，格式 YYYY-MM-DD
     *      end_date string 可选 诊断完成结束日期，格式 YYYY-MM-DD
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-02 17:37:32
     *
     * @UpdateTime: 2026-11-25 09:18:53
     *
     * @Return: JsonResponse 当前账号及有效期、站点上下文、诊断记录、筛选选项和所选记录监测数据
     *
     * @Throws: ApiException|ValidationException 前端凭证失效、账号权限不足、站点不可用或筛选条件无效
     */
    public function index(
        Request $request,
        MonitoringDashboardDataService $dashboardData,
        CurrentSite $currentSite
    ): JsonResponse {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin, 403);
        $validated = $request->validate([
            'run_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:64'],
            'question_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);
        $startDate = $validated['start_date'] ?? null;
        $endDate = $validated['end_date'] ?? null;
        if (is_string($startDate) && is_string($endDate) && $endDate < $startDate) {
            throw ValidationException::withMessages([
                'end_date' => '结束日期不能早于开始日期',
            ]);
        }

        $filters = [
            'run_id' => isset($validated['run_id']) ? (int) $validated['run_id'] : null,
            'platform' => $validated['platform'] ?? null,
            'question_id' => isset($validated['question_id']) ? (int) $validated['question_id'] : null,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
        $site = $currentSite->get();
        $accountValidity = $this->accountValidity($admin, $site instanceof Site ? $site : null);

        return $this->success($request, [
            'account' => [
                'id' => (int) $admin->id,
                'username' => (string) $admin->username,
                'display_name' => (string) ($admin->display_name ?: $admin->name ?: $admin->username),
                'role' => (string) $admin->role,
                ...$accountValidity,
            ],
            'site' => $site instanceof Site ? [
                'id' => (int) $site->id,
                'name' => (string) $site->name,
            ] : null,
            'dashboard' => $dashboardData->build($admin, $site, $filters),
        ]);
    }

    /**
     * @Name: accountValidity
     *
     * @Description: 读取当前登录账号在当前站点上的最新套餐有效期，区分有效、待生效、过期、停用和无期限状态。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-25 09:17:32
     *
     * @UpdateTime: 2026-11-25 09:18:53
     *
     * @Param: Admin $admin 当前监测中心登录账号
     *
     * @Param: Site|null $site 当前监测站点
     *
     * @Return array<string,string|null> 订阅有效状态、开始日期和截止日期
     *
     * @Throws: QueryException 订阅有效期读取失败
     */
    private function accountValidity(Admin $admin, ?Site $site): array
    {
        $subscription = $site instanceof Site
            ? AdminPlanSubscription::query()
                ->where('admin_id', (int) $admin->id)
                ->where('site_id', (int) $site->id)
                ->orderByDesc('id')
                ->first()
            : null;

        if (! $subscription instanceof AdminPlanSubscription) {
            return [
                'validity_status' => 'unavailable',
                'valid_from' => null,
                'valid_until' => null,
            ];
        }

        $startsAt = $subscription->starts_at;
        $endsAt = $subscription->ends_at;
        $status = match (true) {
            $endsAt !== null && $endsAt->isPast() => 'expired',
            (string) $subscription->status !== 'active' => 'inactive',
            $startsAt !== null && $startsAt->isFuture() => 'scheduled',
            $endsAt === null => 'unlimited',
            default => 'active',
        };

        return [
            'validity_status' => $status,
            'valid_from' => $startsAt?->format('Y-m-d'),
            'valid_until' => $endsAt?->format('Y-m-d'),
        ];
    }
}
