<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\MonitoringReportShare;
use App\Models\Site;
use App\Services\MonitoringCenter\MonitoringReportLogoResolver;
use App\Services\MonitoringCenter\MonitoringReportDataService;
use App\Services\MonitoringCenter\MonitoringReportRenderer;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MonitoringReportShareController extends Controller
{
    public function show(
        string $token,
        MonitoringReportRenderer $renderer,
        MonitoringReportLogoResolver $logoResolver,
        MonitoringReportDataService $reports
    ): Response
    {
        $token = trim($token);
        if ($token === '') {
            throw new NotFoundHttpException();
        }

        $share = MonitoringReportShare::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $share instanceof MonitoringReportShare || $this->isExpired($share)) {
            throw new NotFoundHttpException();
        }

        $admin = $this->adminForShare($share);
        if (! $admin instanceof Admin) {
            throw new NotFoundHttpException();
        }

        $site = $this->siteForShare($share);
        $reportType = (string) $share->report_type === 'industry' ? 'industry' : 'enterprise';
        $reportData = $reportType === 'industry'
            ? $reports->industryReport($admin, $site)
            : $reports->enterpriseReport($admin, $site);
        $useVirtualSearchReportData = (bool) config('geoflow.monitoring_search_report_virtual_data_enabled', false);

        $share->forceFill(['last_viewed_at' => now()])->save();
        $share->increment('view_count');

        $url = route('monitoring-report-share.show', ['token' => $token]);

        return response($renderer->render(
            $reportType,
            $reportData,
            $useVirtualSearchReportData,
            [
                'enterprise_url' => $url,
                'industry_url' => $url,
                'is_shared_view' => true,
                'report_logo_url' => $logoResolver->logoUrlForSiteId((int) ($share->site_id ?? 0)),
            ]
        ), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function isExpired(MonitoringReportShare $share): bool
    {
        $expiresAt = $share->expires_at ?? $share->created_at?->copy()->addDays(7);

        return $expiresAt === null || $expiresAt->isPast();
    }

    private function adminForShare(MonitoringReportShare $share): ?Admin
    {
        $adminId = (int) ($share->owner_admin_id ?: $share->created_by_admin_id);
        if ($adminId <= 0) {
            return null;
        }

        return Admin::query()->whereKey($adminId)->first();
    }

    private function siteForShare(MonitoringReportShare $share): ?Site
    {
        $siteId = (int) ($share->site_id ?? 0);
        if ($siteId <= 0) {
            return null;
        }

        return Site::query()->whereKey($siteId)->first();
    }
}
