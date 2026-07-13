<?php

namespace App\Http\Controllers;

use App\Models\MonitoringReportShare;
use App\Services\MonitoringCenter\MonitoringReportRenderer;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MonitoringReportShareController extends Controller
{
    public function show(string $token, MonitoringReportRenderer $renderer): Response
    {
        $token = trim($token);
        if ($token === '') {
            throw new NotFoundHttpException();
        }

        $share = MonitoringReportShare::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $share instanceof MonitoringReportShare || ($share->expires_at !== null && $share->expires_at->isPast())) {
            throw new NotFoundHttpException();
        }

        $share->forceFill(['last_viewed_at' => now()])->save();
        $share->increment('view_count');

        $url = route('monitoring-report-share.show', ['token' => $token]);

        return response($renderer->render(
            (string) $share->report_type,
            (array) $share->payload,
            (bool) $share->use_virtual_search_report_data,
            [
                'enterprise_url' => $url,
                'industry_url' => $url,
            ]
        ), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
