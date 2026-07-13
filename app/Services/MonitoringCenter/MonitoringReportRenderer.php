<?php

namespace App\Services\MonitoringCenter;

use Illuminate\Support\Facades\File;

class MonitoringReportRenderer
{
    /**
     * @param  array<string,mixed>  $reportData
     * @param  array{
     *     enterprise_url?: string,
     *     industry_url?: string,
     *     share_create_url?: string,
     *     share_csrf_token?: string,
     * }  $options
     */
    public function render(string $report, array $reportData, bool $useVirtualSearchReportData, array $options = []): string
    {
        $report = $report === 'industry' ? 'industry' : 'enterprise';
        $view = 'admin.monitoring-center.reports.'.$report;
        $sourceFile = resource_path('views/admin/monitoring-center/reports/'.$report.'.blade.php');

        abort_unless(File::exists($sourceFile), 404);

        return $this->rewriteHtml((string) view($view, [
            'reportData' => $reportData,
            'report' => $report,
            'useVirtualSearchReportData' => $useVirtualSearchReportData,
            'shareCreateUrl' => (string) ($options['share_create_url'] ?? ''),
            'shareCsrfToken' => (string) ($options['share_csrf_token'] ?? ''),
        ])->render(), $options);
    }

    /**
     * @param  array{enterprise_url?: string, industry_url?: string}  $options
     */
    private function rewriteHtml(string $html, array $options): string
    {
        $assetBase = rtrim(asset('assets/monitoring-center'), '/');
        $enterpriseUrl = (string) ($options['enterprise_url'] ?? route('admin.monitoring-center.index', ['report' => 'enterprise']));
        $industryUrl = (string) ($options['industry_url'] ?? route('admin.monitoring-center.index', ['report' => 'industry']));

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
                '浼佷笟杓挎儏鍒嗘瀽鎶ヨ〃',
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
                'href="'.$enterpriseUrl.'"',
                'href="'.$industryUrl.'"',
                '// Monitoring center keeps the selected report on refresh.',
                '浼佷笟鑸嗘儏鍒嗘瀽鎶ヨ〃',
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
