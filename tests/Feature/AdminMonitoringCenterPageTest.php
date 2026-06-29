<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\KnowledgeBase;
use App\Models\Site;
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
            ->assertSee('/assets/monitoring-center/assets/ai-platforms/deepseek.png', false)
            ->assertDontSee('"assets/ai-platforms/deepseek.png"', false)
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
            ->assertSee('/assets/monitoring-center/ceying-ai-logo1.png', false)
            ->assertDontSee('data-monitoring-dynamic-summary', false)
            ->assertDontSee('admin-topbar', false);
    }

    public function test_monitoring_center_injects_current_site_report_data_into_enterprise_page(): void
    {
        config(['geoflow.monitoring_search_report_virtual_data_enabled' => false]);

        [$admin, $site] = $this->createAdminWithSite('monitoring_dynamic_enterprise_admin');

        KnowledgeBase::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '企业知识库',
            'content' => '公司名称：星河智能科技有限公司',
            'created_at' => now(),
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.monitoring-center.index'))
            ->assertOk()
            ->assertSee('星河智能科技有限公司')
            ->assertSee('window.__MONITORING_REPORT__', false)
            ->assertSee('window.__MONITORING_SEARCH_REPORT_USE_VIRTUAL__ = false;', false)
            ->assertSee('/assets/monitoring-center/ceying-ai-logo1.png', false)
            ->assertDontSee('data-monitoring-dynamic-summary', false)
            ->getContent();

        $headerMeta = $this->headerCompanyMeta($html);
        $this->assertStringNotContainsString('026-06-17', $headerMeta);
    }

    public function test_monitoring_center_virtual_switch_only_keeps_search_report_static(): void
    {
        config(['geoflow.monitoring_search_report_virtual_data_enabled' => true]);

        [$admin, $site] = $this->createAdminWithSite('monitoring_virtual_static_admin');

        KnowledgeBase::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => 'virtual knowledge',
            'content' => '公司名称：虚拟搜索报表科技有限公司',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.monitoring-center.index'));

        $response
            ->assertOk()
            ->assertSee('虚拟搜索报表科技有限公司')
            ->assertSee('window.__MONITORING_SEARCH_REPORT_USE_VIRTUAL__ = true;', false)
            ->assertSee('window.__MONITORING_REPORT__', false)
            ->assertDontSee('window.__MONITORING_REPORT__ = [];', false);

        $html = $response->getContent();
        $this->assertStringContainsString('if (Array.isArray(dynamicReport.model_collection))', $html);
        $this->assertStringContainsString('if (Array.isArray(dynamicReport.metrics))', $html);
        $this->assertStringContainsString('if (Array.isArray(dynamicReport.distillation_words))', $html);
        $this->assertStringContainsString('if (!useVirtualSearchReportData && Array.isArray(dynamicReport.platform_filters)', $html);
        $this->assertStringContainsString('if (!useVirtualSearchReportData && Array.isArray(dynamicReport.search_rows))', $html);
        $this->assertStringNotContainsString('Array.isArray(dynamicReport.model_collection) && dynamicReport.model_collection.length', $html);
        $this->assertStringNotContainsString('Array.isArray(dynamicReport.metrics) && dynamicReport.metrics.length', $html);
        $this->assertStringNotContainsString('Array.isArray(dynamicReport.distillation_words) && dynamicReport.distillation_words.length', $html);
    }

    public function test_enterprise_trend_axis_labels_are_rendered_from_current_trend_dates(): void
    {
        $admin = $this->createAdmin('monitoring_trend_axis_admin');

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.monitoring-center.index'))
            ->assertOk()
            ->getContent();

        $axisMarkup = $this->trendAxisMarkup($html);

        $this->assertStringContainsString('id="trendAxisLabels"', $axisMarkup);
        $this->assertStringNotContainsString('2026-05-21', $axisMarkup);
        $this->assertStringContainsString('const TREND_AXIS_GROUP_SIZE = 6;', $html);
        $this->assertStringContainsString('renderTrendAxisLabels(dates, days)', $html);
        $this->assertStringContainsString('trendAxisLabelsForPeriod(dates, days)', $html);
    }

    public function test_enterprise_report_replaces_static_search_rows_even_when_dynamic_rows_are_empty(): void
    {
        config(['geoflow.monitoring_search_report_virtual_data_enabled' => false]);

        $admin = $this->createAdmin('monitoring_empty_search_rows_admin');

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.monitoring-center.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('if (!useVirtualSearchReportData && Array.isArray(dynamicReport.search_rows))', $html);
        $this->assertStringNotContainsString('Array.isArray(dynamicReport.search_rows) && dynamicReport.search_rows.length', $html);
        $this->assertStringContainsString('officialLink(row)', $html);
        $this->assertStringContainsString('platformLink(row)', $html);
        $this->assertStringContainsString('copyQuestion(', $html);
        $this->assertStringContainsString('continueChat()', $html);
        $this->assertStringContainsString('document.getElementById("startDate").addEventListener("change", applyFilters)', $html);
        $this->assertStringContainsString('document.getElementById("endDate").addEventListener("change", applyFilters)', $html);
        $this->assertStringContainsString('const question = String(row.question || "")', $html);
        $this->assertStringContainsString('const okStart = !start || row.date >= start', $html);
        $this->assertStringNotContainsString("showToast('已模拟打开官方链接')", $html);
        $this->assertStringNotContainsString("showToast('已模拟转到平台')", $html);
        $this->assertStringNotContainsString("showToast('已模拟跳转到对应 AI 平台')", $html);
    }

    public function test_monitoring_center_injects_current_site_report_data_into_industry_page(): void
    {
        [$admin, $site] = $this->createAdminWithSite('monitoring_dynamic_industry_admin');

        KnowledgeBase::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '企业知识库',
            'content' => '企业名称：星河智能科技有限公司',
            'created_at' => now(),
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.monitoring-center.index', ['report' => 'industry']))
            ->assertOk()
            ->assertSee('星河智能科技有限公司')
            ->assertSee('window.__MONITORING_REPORT__', false)
            ->assertSee('/assets/monitoring-center/ceying-ai-logo1.png', false)
            ->assertDontSee('data-monitoring-dynamic-summary', false)
            ->getContent();

        $headerMeta = $this->headerCompanyMeta($html);
        $this->assertStringNotContainsString('026-06-17', $headerMeta);
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

    private function headerCompanyMeta(string $html): string
    {
        $start = strpos($html, '<div class="company-meta">');
        $this->assertNotFalse($start);

        $end = strpos($html, '<details class="report-menu">', (int) $start);
        $this->assertNotFalse($end);

        return substr($html, (int) $start, (int) $end - (int) $start);
    }

    private function trendAxisMarkup(string $html): string
    {
        $start = strpos($html, '<div class="x-labels"');
        $this->assertNotFalse($start);

        $end = strpos($html, '</div>', (int) $start);
        $this->assertNotFalse($end);

        return substr($html, (int) $start, (int) $end - (int) $start + 6);
    }

    /**
     * @return array{0:Admin,1:Site}
     */
    private function createAdminWithSite(string $username): array
    {
        $admin = $this->createAdmin($username);
        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => $username.' Site',
            'domain' => $username.'.example.test',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        return [$admin, $site];
    }
}
