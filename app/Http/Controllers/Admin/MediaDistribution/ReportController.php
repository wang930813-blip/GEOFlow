<?php

namespace App\Http\Controllers\Admin\MediaDistribution;

use App\Http\Controllers\Controller;
use App\Models\MediaSubmission;
use App\Support\AdminWeb;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function profit(): View
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);

        return view('admin.media-distribution.profit-report', [
            'pageTitle' => '媒体投稿利润报表',
            'activeMenu' => 'media_distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'rows' => $this->profitRows()->get(),
        ]);
    }

    public function profitExport(): StreamedResponse
    {
        abort_unless(auth('admin')->user()?->isSuperAdmin(), 403);

        $rows = $this->profitRows();

        return response()->stream(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['站点', '订单数', '销售额', '成本', '利润']);
            $rows->chunk(200, function ($items) use ($out): void {
                foreach ($items as $item) {
                    fputcsv($out, [
                        $item->site_name,
                        $item->orders_count,
                        number_format((float) $item->sale_total, 2, '.', ''),
                        number_format((float) $item->cost_total, 2, '.', ''),
                        number_format((float) $item->profit_total, 2, '.', ''),
                    ]);
                }
            });
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="media-profit-report.csv"',
        ]);
    }

    private function profitRows()
    {
        return MediaSubmission::withoutGlobalScope('current_site')
            ->join('sites', 'sites.id', '=', 'media_submissions.site_id')
            ->select([
                'sites.name as site_name',
                DB::raw('COUNT(media_submissions.id) as orders_count'),
                DB::raw('SUM(media_submissions.sale_price_snapshot) as sale_total'),
                DB::raw('SUM(media_submissions.cost_price_snapshot) as cost_total'),
                DB::raw('SUM(media_submissions.sale_price_snapshot - media_submissions.cost_price_snapshot) as profit_total'),
            ])
            ->whereIn('media_submissions.status', ['submitted', 'publishing', 'published'])
            ->groupBy('sites.id', 'sites.name')
            ->orderBy('sites.id');
    }
}
