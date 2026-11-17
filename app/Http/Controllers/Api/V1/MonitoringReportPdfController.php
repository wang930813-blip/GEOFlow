<?php

/**
 * Created by Codex.
 * @Date: 2026-11-16
 * @Time: 16:16:41
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： MonitoringReportPdfController.php
 * @Description: 为新版监测中心原生生成当前筛选范围内的品牌诊断 PDF。
 */

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Site;
use App\Services\MonitoringCenter\MonitoringDashboardDataService;
use App\Services\MonitoringCenter\MonitoringReportPdfService;
use App\Support\CurrentSite;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class MonitoringReportPdfController extends BaseApiController
{
    /**
     * 导出监测中心品牌诊断 PDF
     * 按当前监测账号、授权站点及页面筛选范围读取报告数据，并通过 TCPDF 返回可在新标签页查看的原生 PDF。
     * @Url [GET] /api/v1/monitor/report/pdf
     *      登录 是
     *
     *      筛选参数：
     *      run_id integer 可选 诊断记录编号
     *      platform string 可选 平台筛选键
     *      question_id integer 可选 诊断问题编号
     *      start_date string 可选 诊断完成起始日期，格式 YYYY-MM-DD
     *      end_date string 可选 诊断完成结束日期，格式 YYYY-MM-DD
     *
     * @Author: cdkay
     * @CreateTime: 2026-11-16 16:16:41
     * @UpdateTime: 2026-11-16 16:26:50
     *
     * @Return Response application/pdf 报告文件内容
     * @Throws ApiException|ValidationException 凭证失效、无报告数据或筛选条件无效
     */
    public function show(
        Request $request,
        MonitoringDashboardDataService $dashboardData,
        MonitoringReportPdfService $pdfService,
        CurrentSite $currentSite
    ): Response {
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
            throw \Illuminate\Validation\ValidationException::withMessages([
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
        $dashboard = $dashboardData->build($admin, $site, $filters);
        $diagnosis = $dashboard['latest_diagnosis'] ?? null;
        if (! is_array($diagnosis) || $diagnosis === []) {
            throw new ApiException('not_found', '当前筛选范围内没有可导出的品牌诊断报告', 404);
        }

        $safeBrand = trim((string) preg_replace('/[^\pL\pN._-]+/u', '_', (string) ($diagnosis['brand'] ?? '品牌诊断')) , '_');
        $safeBrand = Str::limit($safeBrand !== '' ? $safeBrand : '品牌诊断', 48, '');
        $title = $safeBrand.'_'.now()->format('Ymd_His').'_品牌诊断报告';
        $content = $pdfService->render([
            'username' => (string) $admin->username,
            'display_name' => (string) ($admin->display_name ?: $admin->name ?: $admin->username),
        ], $site instanceof Site ? ['name' => (string) $site->name] : null, $dashboard, $title);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="brand-diagnosis-report.pdf"; filename*=UTF-8\'\''.rawurlencode($title.'.pdf'),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
