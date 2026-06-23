<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminMonitoringCenterPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_center_renders_enterprise_report_as_standalone_page(): void
    {
        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.monitoring-center.index'));

        $response
            ->assertOk()
            ->assertSee('<!doctype html>', false)
            ->assertSee('企业舆情分析报表')
            ->assertSee('行业竞争力分析报表')
            ->assertSee('href="'.route('admin.monitoring-center.index', ['report' => 'industry']).'"', false)
            ->assertSee('/assets/monitoring-center/assets/backgrounds/enterprise-space-bg.png', false)
            ->assertDontSee('鐩戞祴涓', false)
            ->assertDontSee('admin-topbar', false);
    }

    public function test_monitoring_center_can_switch_to_industry_report(): void
    {
        $admin = $this->createAdmin('monitoring_industry_admin');

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.monitoring-center.index', ['report' => 'industry']));

        $response
            ->assertOk()
            ->assertSee('行业竞争力分析报表')
            ->assertSee('企业舆情分析报表')
            ->assertSee('href="'.route('admin.monitoring-center.index', ['report' => 'enterprise']).'"', false)
            ->assertSee('/assets/monitoring-center/ceying-ai-logo.png', false)
            ->assertDontSee('admin-topbar', false);
    }

    private function createAdmin(string $username = 'monitoring_center_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => 'admin',
            'status' => 'active',
        ]);
    }
}
