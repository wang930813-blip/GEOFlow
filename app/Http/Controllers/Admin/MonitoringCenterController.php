<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;

class MonitoringCenterController extends Controller
{
    public function index(Request $request): Response
    {
        $report = $request->query('report') === 'industry' ? 'industry' : 'enterprise';
        $sourceFile = $report === 'industry'
            ? resource_path('views/admin/monitoring-center/reports/industry.html')
            : resource_path('views/admin/monitoring-center/reports/enterprise.html');

        abort_unless(File::exists($sourceFile), 404);

        return response($this->renderReport((string) File::get($sourceFile)), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function renderReport(string $html): string
    {
        $assetBase = rtrim(asset('assets/monitoring-center'), '/');

        $html = str_replace(
            [
                'href="assets/',
                'src="assets/',
                'url("assets/',
                "url('assets/",
                'src="ceying-ai-logo.png"',
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
                'src="'.$assetBase.'/ceying-ai-logo.png"',
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
}
