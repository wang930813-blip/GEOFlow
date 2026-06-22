<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AdminWeb;
use Illuminate\View\View;

class MonitoringCenterController extends Controller
{
    public function index(): View
    {
        return view('admin.monitoring-center.index', [
            'pageTitle' => '监测中心',
            'activeMenu' => 'monitoring_center',
            'adminSiteName' => AdminWeb::siteName(),
        ]);
    }
}
