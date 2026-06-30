<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BrandDiagnosisBrandMention;
use App\Models\BrandDiagnosisQuestion;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Models\BrandDiagnosisSource;
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
        $this->assertStringContainsString('staticPlatformUrl(row.platform)', $html);
        $this->assertStringContainsString('https://www.doubao.com/chat/', $html);
        $this->assertStringContainsString('["DeepSeek", "移动", 0]', $html);
        $this->assertStringContainsString('["豆包", "移动", 0]', $html);
        $this->assertStringContainsString('["腾讯元宝", "移动", 0]', $html);
        $this->assertStringContainsString('["文心一言", "移动", 0]', $html);
        $this->assertStringContainsString('["千问", "移动", 0]', $html);
        $this->assertStringContainsString('2026年国内科研选题辅导机构哪些好', $html);
        $this->assertStringContainsString('2026年国内SCI/SSCI论文辅导机构有哪些？', $html);
        $this->assertStringContainsString('SCI/SSCI全流程能力辅导平台推荐', $html);
        $this->assertStringContainsString('国内科研选题辅导平台哪些好', $html);
        $this->assertStringContainsString('https://chat.baidu.com/csaitab/history/share?share_id=S0nxu34fNI2AMMxb0B38Nipa1PRoMntYVrs2s39yiShONfyw5GfJRZEAtbQeMfxPkUZcvAd9ixmHzZzVR6hI6GaXdC&v=2', $html);
        $this->assertStringContainsString('结合2026年最新实测数据', $html);
        $this->assertStringContainsString('下面按「2026年上半年行业测评/口碑数据」', $html);
        $this->assertStringContainsString('远离毕业延期烦恼 2026 本科毕设论文辅导平台实测精选', $html);
        $this->assertStringContainsString('id: -(index + 1)', $html);
        $this->assertStringContainsString('if (voucherId !== 0)', $html);
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

    public function test_snapshot_voucher_renders_result_by_id_without_user_scope(): void
    {
        [$owner, $site] = $this->createAdminWithSite('snapshot_owner_admin');
        $viewer = $this->createAdmin('snapshot_other_viewer');
        $result = $this->createBrandDiagnosisSnapshot($owner, $site);

        $response = $this->actingAs($viewer, 'admin')
            ->get(route('admin.snapshot-voucher.show', ['id' => (int) $result->id]));

        $response
            ->assertOk()
            ->assertSee('DeepSeek', false)
            ->assertSee('GEO内容优化服务商怎么选？')
            ->assertSee('内容由 Ai 生成，不能完全保障真实')
            ->assertSee('策影GEO')
            ->assertSee('引用资料标题')
            ->assertSee('和DeepSeek继续聊')
            ->assertSee('<table', false)
            ->assertDontSee('收录词不存在');
    }

    public function test_snapshot_voucher_can_be_viewed_without_admin_login(): void
    {
        [$owner, $site] = $this->createAdminWithSite('snapshot_public_owner_admin');
        $result = $this->createBrandDiagnosisSnapshot($owner, $site);

        $response = $this->get(route('admin.snapshot-voucher.show', ['id' => (int) $result->id]));

        $response
            ->assertOk()
            ->assertSee('GEO内容优化服务商怎么选？')
            ->assertSee('和DeepSeek继续聊');
    }

    public function test_snapshot_voucher_shows_missing_message_when_id_does_not_exist(): void
    {
        $admin = $this->createAdmin('snapshot_missing_viewer');

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.snapshot-voucher.show', ['id' => 999999]));

        $response
            ->assertOk()
            ->assertSee('收录词不存在')
            ->assertDontSee('和DeepSeek继续聊');
    }

    public function test_snapshot_voucher_renders_virtual_wenxin_snapshot_by_negative_id(): void
    {
        $response = $this->get(route('admin.snapshot-voucher.show', ['id' => -1]));

        $response
            ->assertOk()
            ->assertSee('EditSprings', false)
            ->assertSee('S0nxu34fNI2AMMxb0B38Nipa1PRoMntYVrs2s39yiShONfyw5GfJRZEAtbQeMfxPkUZcvAd9ixmHzZzVR6hI6GaXdC', false)
            ->assertSee('https://chat.baidu.com/', false)
            ->assertSee('<article class="answer">', false)
            ->assertDontSee('<section class="card empty-card"', false);
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

    private function createBrandDiagnosisSnapshot(Admin $admin, Site $site): BrandDiagnosisResult
    {
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['deepseek'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
            'completed_at' => now(),
        ]);

        $question = BrandDiagnosisQuestion::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $run->id,
            'question' => 'GEO内容优化服务商怎么选？',
            'question_type' => 'brand',
            'sort_order' => 1,
            'status' => 'completed',
        ]);

        $result = BrandDiagnosisResult::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'platform' => 'deepseek',
            'answer' => "| 厂商 | 说明 |\n| --- | --- |\n| 策影GEO | 这里放AI对话详情，用于快照凭证页面展示。 |",
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'positive',
            'status' => 'success',
            'checked_at' => now(),
        ]);

        BrandDiagnosisBrandMention::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'result_id' => (int) $result->id,
            'platform' => 'deepseek',
            'brand_name' => '策影GEO',
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'positive',
            'source_count' => 1,
            'is_target_brand' => true,
        ]);

        BrandDiagnosisSource::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'result_id' => (int) $result->id,
            'platform' => 'deepseek',
            'title' => '引用资料标题',
            'url' => 'https://example.com/source',
            'domain' => 'example.com',
            'source_type' => 'url_citation',
        ]);

        return $result;
    }
}
