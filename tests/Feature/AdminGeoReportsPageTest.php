<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminGeoReportsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_view_geo_reports_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'geo_report_admin',
            'password' => 'secret-123',
            'email' => 'geo-report-admin@example.com',
            'display_name' => 'Geo Report Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo-reports.index'))
            ->assertOk()
            ->assertSee('GEO 数据报表')
            ->assertSee('平台分布')
            ->assertSee('关键词排行');
    }
}
