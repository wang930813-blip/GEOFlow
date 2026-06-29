<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MonitoringCenter\MonitoringReportDataService;
use App\Support\CurrentSite;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class MonitoringCenterController extends Controller
{
    public function index(Request $request, MonitoringReportDataService $reports, CurrentSite $currentSite): Response
    {
        $report = $request->query('report') === 'industry' ? 'industry' : 'enterprise';
        $view = 'admin.monitoring-center.reports.'.$report;
        $sourceFile = resource_path('views/admin/monitoring-center/reports/'.$report.'.blade.php');

        abort_unless(File::exists($sourceFile), 404);

        $admin = $request->user('admin');
        abort_unless($admin instanceof \App\Models\Admin, 403);

        $useVirtualSearchReportData = (bool) config('geoflow.monitoring_search_report_virtual_data_enabled', false);
        $reportData = $report === 'industry'
            ? $reports->industryReport($admin, $currentSite->get())
            : $reports->enterpriseReport($admin, $currentSite->get());

        return response($this->renderReport((string) view($view, [
            'reportData' => $reportData,
            'report' => $report,
            'useVirtualSearchReportData' => $useVirtualSearchReportData,
        ])->render()), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function renderReport(string $html): string
    {
        $assetBase = rtrim(asset('assets/monitoring-center'), '/');

        $html = $this->removeLegacyStaticCompanyMeta($html);

        $html = str_replace(
            [
                'href="assets/',
                'src="assets/',
                'url("assets/',
                "url('assets/",
                '"assets/',
                "'assets/",
                'src="ceying-ai-logo.png"',
                'src="ceying-ai-logo1.png"',
                'href="geo-dashboard-replica.html"',
                'href="ai-search-competition-report.html"',
                'window.location.replace("geo-dashboard-replica.html");',
                '企业輿情分析报表',
            ],
            [
                'href="'.$assetBase.'/assets/',
                'src="'.$assetBase.'/assets/',
                'url("'.$assetBase.'/assets/',
                "url('".$assetBase.'/assets/',
                '"'.$assetBase.'/assets/',
                "'".$assetBase.'/assets/',
                'src="'.$assetBase.'/ceying-ai-logo1.png"',
                'src="'.$assetBase.'/ceying-ai-logo1.png"',
                'href="'.route('admin.monitoring-center.index', ['report' => 'enterprise']).'"',
                'href="'.route('admin.monitoring-center.index', ['report' => 'industry']).'"',
                '// Monitoring center keeps the selected report on refresh.',
                '企业舆情分析报表',
            ],
            $html
        );

        $title = str_contains($html, 'competitiveness_analysis_report_web')
            ? '行业竞争力分析报表 - 监测中心'
            : '企业舆情分析报表 - 监测中心';

        $html = preg_replace('/<title>.*?<\/title>/su', '<title>'.$title.'</title>', $html, 1) ?? $html;

        return str_replace('<head>', '<head>'.PHP_EOL.'  <meta name="geoflow-page" content="monitoring-center" />', $html);
    }

    private function removeLegacyStaticCompanyMeta(string $html): string
    {
        return preg_replace('/\s*<div>[^<]*<\/div>\s*<div>[^<]*026-06-17<\/div>/u', '', $html) ?? $html;
    }
}
