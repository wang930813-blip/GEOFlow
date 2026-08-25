<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\GeoFlow\GeoReportQueryService;
use App\Support\AdminWeb;
use Illuminate\View\View;

class GeoReportController extends Controller
{
    public function __construct(private readonly GeoReportQueryService $reports) {}

    public function index(): View
    {
        return view('admin.geo-reports.index', [
            'pageTitle' => 'GEO 数据报表',
            'activeMenu' => 'geo_reports',
            'adminSiteName' => AdminWeb::siteName(),
            'report' => $this->reports->build(),
        ]);
    }
}
