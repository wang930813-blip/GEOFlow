<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\MonitoringReportShare;
use App\Services\MonitoringCenter\MonitoringReportDataService;
use App\Services\MonitoringCenter\MonitoringReportLogoResolver;
use App\Services\MonitoringCenter\MonitoringReportRenderer;
use App\Support\CurrentSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class MonitoringCenterController extends Controller
{
    public function index(
        Request $request,
        MonitoringReportDataService $reports,
        CurrentSite $currentSite,
        MonitoringReportLogoResolver $logoResolver,
        MonitoringReportRenderer $renderer
    ): Response {
        $report = $request->query('report') === 'industry' ? 'industry' : 'enterprise';

        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $useVirtualSearchReportData = (bool) config('geoflow.monitoring_search_report_virtual_data_enabled', false);
        $reportData = $report === 'industry'
            ? $reports->industryReport($admin, $currentSite->get())
            : $reports->enterpriseReport($admin, $currentSite->get());

        return response($renderer->render($report, $reportData, $useVirtualSearchReportData, [
            'share_create_url' => route('admin.monitoring-center.share'),
            'share_csrf_token' => csrf_token(),
            'report_logo_url' => $logoResolver->logoUrlForSite($currentSite->get()),
        ]), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    public function share(Request $request, MonitoringReportDataService $reports, CurrentSite $currentSite): JsonResponse
    {
        $payload = $request->validate([
            'report' => ['nullable', 'string', 'in:enterprise,industry'],
        ]);

        $report = ($payload['report'] ?? '') === 'industry' ? 'industry' : 'enterprise';
        $admin = $request->user('admin');
        abort_unless($admin instanceof Admin, 403);

        $site = $currentSite->get();
        $useVirtualSearchReportData = (bool) config('geoflow.monitoring_search_report_virtual_data_enabled', false);
        $reportData = $report === 'industry'
            ? $reports->industryReport($admin, $site)
            : $reports->enterpriseReport($admin, $site);

        $token = Str::random(64);
        $companyName = trim((string) data_get($reportData, 'context.company_name', ''));
        $reportLabel = $report === 'industry' ? 'Industry report' : 'Enterprise report';

        MonitoringReportShare::query()->create([
            'token_hash' => hash('sha256', $token),
            'report_type' => $report,
            'site_id' => $site?->id,
            'owner_admin_id' => (int) ($site?->owner_admin_id ?: $admin->id),
            'created_by_admin_id' => (int) $admin->id,
            'title' => trim($reportLabel.($companyName !== '' ? ' - '.$companyName : '')),
            'payload' => $reportData,
            'use_virtual_search_report_data' => $useVirtualSearchReportData,
        ]);

        return response()->json([
            'report' => $report,
            'url' => route('monitoring-report-share.show', ['token' => $token]),
        ]);
    }
}
