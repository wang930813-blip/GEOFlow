<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BrandDiagnosisBrandMention;
use App\Models\BrandDiagnosisQuestion;
use App\Models\BrandDiagnosisResult;
use App\Models\BrandDiagnosisRun;
use App\Models\BrandDiagnosisSource;
use App\Models\KnowledgeBase;
use App\Models\KeywordLibrary;
use App\Models\MonitoringReportShare;
use App\Models\Site;
use App\Models\SiteSetting;
use App\Services\MonitoringCenter\MonitoringReportRenderer;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminMonitoringCenterPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitoring_report_renderer_uses_readable_utf8_page_titles(): void
    {
        $renderer = app(MonitoringReportRenderer::class);

        $enterprise = $renderer->render('enterprise', [], false);
        $industry = $renderer->render('industry', [], false);

        $this->assertStringContainsString('<title>企业舆情分析报表 - 监测中心</title>', $enterprise);
        $this->assertStringContainsString('<title>行业竞争力分析报表 - 监测中心</title>', $industry);
    }

    public function test_monitoring_report_logo_url_uses_content_hash_for_cache_busting(): void
    {
        $logoPath = public_path('assets/monitoring-center/ceying-ai-logo1.png');
        $version = substr((string) hash_file('sha256', $logoPath), 0, 12);

        $html = app(MonitoringReportRenderer::class)->render('enterprise', [], false);

        $this->assertStringContainsString(
            '/assets/monitoring-center/ceying-ai-logo1.png?v='.$version,
            $html
        );
    }

    public function test_monitoring_center_uses_current_site_report_logo_when_configured(): void
    {
        [$admin, $site] = $this->createAdminWithSite('monitoring_custom_logo_admin');

        SiteSetting::withoutGlobalScope('current_site')->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'setting_key' => 'monitoring_report_logo',
            'setting_value' => 'https://cdn.example.com/client-report-logo.png',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.monitoring-center.index'))
            ->assertOk()
            ->assertSee('src="https://cdn.example.com/client-report-logo.png"', false)
            ->assertDontSee('/assets/monitoring-center/ceying-ai-logo1.png', false);
    }

    public function test_industry_report_header_keeps_space_between_title_and_company_meta(): void
    {
        $html = app(MonitoringReportRenderer::class)->render('industry', [], false);

        $this->assertMatchesRegularExpression(
            '/\.report-header \.report-title\s*\{[^}]*left: calc\(50% - 70px\);[^}]*font-size: clamp\(28px, 2\.7vw, 42px\);/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/\.monitoring-share-toast\s*\{[^}]*left: 50%;/s',
            $html
        );
    }

    public function test_enterprise_report_header_keeps_space_between_title_and_company_meta(): void
    {
        $html = app(MonitoringReportRenderer::class)->render('enterprise', [], false);

        $this->assertMatchesRegularExpression(
            '/\.report-title\s*\{[^}]*left: calc\(50% - 70px\);[^}]*font-size: clamp\(28px, 2\.7vw, 42px\);/s',
            $html
        );
        $this->assertMatchesRegularExpression('/\.toast\s*\{[^}]*left: 50%;/s', $html);
    }

    public function test_shared_monitoring_report_renders_fixed_report_label(): void
    {
        $renderer = app(MonitoringReportRenderer::class);

        foreach (['enterprise' => '企业舆情分析报表', 'industry' => '行业竞争力分析报表'] as $report => $label) {
            $html = $renderer->render($report, [], false, [
                'enterprise_url' => '/monitoring-report/share/example-token',
                'industry_url' => '/monitoring-report/share/example-token',
                'is_shared_view' => true,
            ]);

            $this->assertStringContainsString('data-monitoring-fixed-report', $html);
            $this->assertStringContainsString('<span>'.$label.'</span>', $html);
            $this->assertStringNotContainsString('<details class="report-menu">', $html);
            $this->assertStringNotContainsString('<div class="report-menu-list">', $html);
        }

        $adminHtml = $renderer->render('enterprise', [], false);
        $this->assertStringContainsString('<details class="report-menu">', $adminHtml);
    }

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
            ->assertSee('data-monitoring-share-button', false)
            ->assertSee(route('admin.monitoring-center.share'), false)
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
            ->assertSee('href="'.route('admin.monitoring-center.index', ['report' => 'enterprise']).'"', false)
            ->assertSee('data-monitoring-share-button', false)
            ->assertSee(route('admin.monitoring-center.share'), false)
            ->assertSee('/assets/monitoring-center/ceying-ai-logo1.png', false)
            ->assertDontSee('data-monitoring-dynamic-summary', false)
            ->assertDontSee('admin-topbar', false);
    }

    public function test_monitoring_center_injects_current_site_report_data_into_enterprise_page(): void
    {
        config(['geoflow.monitoring_search_report_virtual_data_enabled' => false]);

        [$admin, $site] = $this->createAdminWithSite('monitoring_dynamic_enterprise_admin');

        KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '企业关键词库',
            'company_name' => '星河智能科技有限公司',
            'domain_keyword' => 'AI 搜索优化',
            'status' => 'active',
            'keyword_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
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

    public function test_enterprise_search_report_uses_diagnosis_brand_and_hides_unavailable_links(): void
    {
        config(['geoflow.monitoring_search_report_virtual_data_enabled' => false]);

        [$admin, $site] = $this->createAdminWithSite('monitoring_search_report_links_admin');

        KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Acme keyword library',
            'company_name' => 'Report Context Brand',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => 'Acme Brand',
            'platforms' => ['doubao', 'wenxin'],
            'status' => 'completed',
            'total_questions' => 2,
            'completed_questions' => 2,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
            'completed_at' => now(),
        ]);

        $matchedQuestion = BrandDiagnosisQuestion::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $run->id,
            'question' => 'How is Acme Brand ranked?',
            'question_type' => 'brand',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $matchedResult = BrandDiagnosisResult::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $matchedQuestion->id,
            'platform' => 'doubao',
            'answer' => 'Acme Brand appears in this answer.',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'positive',
            'status' => 'success',
            'official_share_url' => 'https://www.doubao.com/thread/acme-share',
            'checked_at' => now(),
        ]);
        BrandDiagnosisBrandMention::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $matchedQuestion->id,
            'result_id' => (int) $matchedResult->id,
            'platform' => 'doubao',
            'brand_name' => 'Acme Brand',
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'positive',
            'source_count' => 0,
            'is_target_brand' => true,
        ]);

        $unmatchedQuestion = BrandDiagnosisQuestion::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $run->id,
            'question' => 'Which service is suitable?',
            'question_type' => 'brand',
            'sort_order' => 2,
            'status' => 'completed',
        ]);
        $unmatchedResult = BrandDiagnosisResult::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $unmatchedQuestion->id,
            'platform' => 'wenxin',
            'answer' => 'This answer does not include the target brand.',
            'brand_mentioned' => false,
            'mention_count' => 0,
            'mention_rank' => 0,
            'sentiment' => 'neutral',
            'status' => 'success',
            'checked_at' => now()->subMinute(),
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.monitoring-center.index'))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '#"id":'.$matchedResult->id.'.*?"question":"How is Acme Brand ranked\\?".*?"target":"Acme Brand".*?"official_url":"https://www.doubao.com/thread/acme-share".*?"snapshot_url":"'.preg_quote(route('admin.snapshot-voucher.show', ['id' => (int) $matchedResult->id]), '#').'"#s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#"id":'.$unmatchedResult->id.'.*?"question":"Which service is suitable\\?".*?"target":"Acme Brand".*?"official_url":"".*?"snapshot_url":""#s',
            $html
        );
        $this->assertStringNotContainsString('/brand-diagnosis/snapshot/'.$matchedResult->snapshot_token, $html);
    }

    public function test_enterprise_search_report_includes_all_rows_and_mirrors_pc_rows_to_mobile(): void
    {
        config(['geoflow.monitoring_search_report_virtual_data_enabled' => false]);

        [$admin, $site] = $this->createAdminWithSite('monitoring_search_report_full_rows_admin');

        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => 'Full Rows Brand',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 81,
            'completed_questions' => 81,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
            'completed_at' => now(),
        ]);

        for ($index = 1; $index <= 81; $index++) {
            $question = BrandDiagnosisQuestion::query()->create([
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $admin->id,
                'run_id' => (int) $run->id,
                'question' => 'Full rows question '.$index,
                'question_type' => 'brand',
                'sort_order' => $index,
                'status' => 'completed',
            ]);

            BrandDiagnosisResult::query()->create([
                'site_id' => (int) $site->id,
                'owner_admin_id' => (int) $admin->id,
                'run_id' => (int) $run->id,
                'question_id' => (int) $question->id,
                'platform' => 'doubao',
                'answer' => 'Full Rows Brand answer '.$index,
                'brand_mentioned' => true,
                'mention_count' => 1,
                'mention_rank' => 1,
                'sentiment' => 'positive',
                'status' => 'success',
                'checked_at' => now()->subSeconds($index),
            ]);
        }

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.monitoring-center.index'))
            ->assertOk()
            ->getContent();

        $payload = $this->monitoringReportPayload($html);
        $rows = collect(data_get($payload, 'search_rows', []));

        $this->assertCount(162, $rows);
        $this->assertSame(81, $rows->where('terminal', 'PC')->count());
        $this->assertSame(81, $rows->reject(fn (array $row): bool => (string) $row['terminal'] === 'PC')->count());
        $this->assertSame(
            $rows->where('terminal', 'PC')->pluck('id')->sort()->values()->all(),
            $rows->reject(fn (array $row): bool => (string) $row['terminal'] === 'PC')->pluck('id')->sort()->values()->all()
        );

        $filters = collect(data_get($payload, 'platform_filters', []));
        $this->assertSame(162, (int) data_get($filters->first(), 'total'));
        $doubaoFilters = $filters->where('platform_key', 'doubao')->where('total', 81)->values();
        $this->assertCount(2, $doubaoFilters);
        $this->assertSame(1, $doubaoFilters->where('terminal', 'PC')->count());
        $this->assertSame(1, $doubaoFilters->reject(fn (array $row): bool => (string) $row['terminal'] === 'PC')->count());
    }

    public function test_monitoring_center_virtual_switch_only_keeps_search_report_static(): void
    {
        config(['geoflow.monitoring_search_report_virtual_data_enabled' => true]);

        [$admin, $site] = $this->createAdminWithSite('monitoring_virtual_static_admin');

        KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => 'virtual keyword library',
            'company_name' => '虚拟搜索报表科技有限公司',
            'domain_keyword' => '虚拟搜索报表',
            'status' => 'active',
            'keyword_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
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
        $this->assertStringContainsString('["全部", "全部", 25]', $html);
        $this->assertStringContainsString('["DeepSeek", "PC", 3]', $html);
        $this->assertStringContainsString('["DeepSeek", "移动", 2]', $html);
        $this->assertStringContainsString('["豆包", "PC", 3]', $html);
        $this->assertStringContainsString('["豆包", "移动", 2]', $html);
        $this->assertStringContainsString('["腾讯元宝", "PC", 3]', $html);
        $this->assertStringContainsString('["腾讯元宝", "移动", 2]', $html);
        $this->assertStringContainsString('["文心一言", "PC", 5]', $html);
        $this->assertStringContainsString('["文心一言", "移动", 0]', $html);
        $this->assertStringContainsString('["千问", "PC", 3]', $html);
        $this->assertStringContainsString('["千问", "移动", 2]', $html);
        $this->assertStringContainsString('2026年国内科研选题辅导机构哪些好', $html);
        $this->assertStringContainsString('2026年国内SCI/SSCI论文辅导机构有哪些？', $html);
        $this->assertStringContainsString('SCI/SSCI全流程能力辅导平台推荐', $html);
        $this->assertStringContainsString('国内科研选题辅导平台哪些好', $html);
        $this->assertStringContainsString('从科研选题到投稿预审的论文辅导平台有哪些？', $html);
        $this->assertStringContainsString('const supplementalStaticRows = [', $html);
        $this->assertStringContainsString('论文润色和写作指导怎么选？有没有适合科研人员使用的智能学术服务平台推荐？', $html);
        $this->assertStringContainsString('北京学术易科技有限公司的学术易在科研论文服务、论文辅导和智能写作方面口碑怎么样？', $html);
        $this->assertStringContainsString('国内有哪些提供SCI/SSCI论文辅导？', $html);
        $this->assertStringContainsString('选择科研论文服务机构时，如何判断论文辅导、查重降重和写作指导是否靠谱？', $html);
        $this->assertStringContainsString('snapshotAvailable: false', $html);
        $this->assertStringContainsString('snapshotLink(row)', $html);
        $this->assertStringContainsString('https://chat.baidu.com/csaitab/history/share?share_id=1rfMLncTo0YEAHsMeO6uRyOpW9PaSvgPRnzjJTcI5cdFD2fh97q7qPRUriJI0osQ22KzU4arkBVxbrAMkv6nE3aOrJmM&v=2', $html);
        $this->assertStringContainsString('target: "学术易"', $html);
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

    public function test_xueshuyi_report_uses_merged_search_rows_when_virtual_switch_is_enabled(): void
    {
        config(['geoflow.monitoring_search_report_virtual_data_enabled' => true]);

        [$admin, $site] = $this->createAdminWithSite('monitoring_xueshuyi_mixed_admin');

        KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '学术易品牌关键词库',
            'company_name' => '北京学术易科技有限公司',
            'domain_keyword' => '科研论文服务',
            'status' => 'active',
            'keyword_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.monitoring-center.index'))
            ->assertOk()
            ->assertSee('window.__MONITORING_SEARCH_REPORT_USE_VIRTUAL__ = true;', false)
            ->assertSee('"has_xueshuyi_static_search_rows":true', false)
            ->assertSee(route('admin.snapshot-voucher.show', ['id' => -1]), false)
            ->getContent();

        $this->assertStringContainsString(
            '&& dynamicReport.has_xueshuyi_static_search_rows !== true',
            $html
        );
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

    public function test_enterprise_article_trend_uses_display_override_for_target_mobile(): void
    {
        $this->travelTo(Carbon::parse('2026-08-06 12:00:00'));

        [$admin, $site] = $this->createAdminWithSite('monitoring_qianyicheng_admin');
        $admin->forceFill([
            'mobile' => '15934307829',
            'display_name' => '北京乾亿承文化科技股份有限公司',
        ])->save();

        KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => 'qianyicheng keyword library',
            'company_name' => '北京乾亿承文化科技股份有限公司',
            'status' => 'active',
            'keyword_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.monitoring-center.index'))
            ->assertOk()
            ->getContent();

        $payload = $this->monitoringReportPayload($html);
        $last30 = collect(data_get($payload, 'trend.last_30', []))->keyBy('date');

        foreach ($this->qianyichengOverrideDates() as $date) {
            $this->assertArrayHasKey($date, $last30);
            $this->assertGreaterThanOrEqual(50, (int) $last30[$date]['created']);
            $this->assertLessThanOrEqual(80, (int) $last30[$date]['created']);
            $this->assertGreaterThanOrEqual(50, (int) $last30[$date]['published']);
            $this->assertLessThanOrEqual(80, (int) $last30[$date]['published']);
        }

        $last7 = collect(data_get($payload, 'trend.last_7', []))->keyBy('date');
        foreach (['2026-08-01', '2026-08-02', '2026-08-03'] as $date) {
            $this->assertArrayHasKey($date, $last7);
            $this->assertGreaterThanOrEqual(50, (int) $last7[$date]['created']);
            $this->assertLessThanOrEqual(80, (int) $last7[$date]['created']);
            $this->assertGreaterThanOrEqual(50, (int) $last7[$date]['published']);
            $this->assertLessThanOrEqual(80, (int) $last7[$date]['published']);
        }
    }

    public function test_enterprise_article_trend_uses_display_override_for_registered_target_mobile(): void
    {
        $this->travelTo(Carbon::parse('2026-08-15 12:00:00'));

        [$admin, $site] = $this->createAdminWithSite('monitoring_registered_target_admin');
        $admin->forceFill([
            'mobile' => '17780529472',
            'display_name' => 'monitoring registered target',
        ])->save();

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.monitoring-center.index'))
            ->assertOk()
            ->getContent();

        $payload = $this->monitoringReportPayload($html);
        $last30 = collect(data_get($payload, 'trend.last_30', []));

        $this->assertSame('2026-07-17', (string) data_get($last30->first(), 'date'));
        $this->assertSame('2026-08-15', (string) data_get($last30->last(), 'date'));

        foreach ($last30 as $row) {
            $this->assertGreaterThanOrEqual(50, (int) $row['created']);
            $this->assertLessThanOrEqual(80, (int) $row['created']);
            $this->assertGreaterThanOrEqual(50, (int) $row['published']);
            $this->assertLessThanOrEqual(80, (int) $row['published']);
        }

        $last7 = collect(data_get($payload, 'trend.last_7', []));
        foreach ($last7 as $row) {
            $this->assertGreaterThanOrEqual(50, (int) $row['created']);
            $this->assertLessThanOrEqual(80, (int) $row['created']);
            $this->assertGreaterThanOrEqual(50, (int) $row['published']);
            $this->assertLessThanOrEqual(80, (int) $row['published']);
        }
    }

    public function test_enterprise_article_trend_does_not_override_other_accounts(): void
    {
        $this->travelTo(Carbon::parse('2026-08-06 12:00:00'));

        [$admin, $site] = $this->createAdminWithSite('monitoring_regular_article_trend_admin');

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.monitoring-center.index'))
            ->assertOk()
            ->getContent();

        $payload = $this->monitoringReportPayload($html);
        $last30 = collect(data_get($payload, 'trend.last_30', []))->keyBy('date');

        foreach ($this->qianyichengOverrideDates() as $date) {
            $this->assertArrayHasKey($date, $last30);
            $this->assertSame(0, (int) $last30[$date]['created']);
            $this->assertSame(0, (int) $last30[$date]['published']);
        }
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
        $this->assertStringContainsString('snapshotUrl: row.snapshot_url || ""', $html);
        $this->assertStringContainsString('const url = safeUrl(row.snapshotUrl)', $html);
        $this->assertStringContainsString('officialUrl: row.official_url || ""', $html);
        $this->assertMatchesRegularExpression('/function officialLink\(row\)\s*\{.*?return url\s*\?\s*`<a class="link-btn".*?`\s*:\s*"";/s', $html);
        $this->assertMatchesRegularExpression('/function snapshotLink\(row\)\s*\{.*?if \(url\) \{.*?return "";/s', $html);
        $this->assertStringNotContainsString('row.related_articles?.[0]?.url', $html);
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
            ->assertSee('class="brand"', false)
            ->assertSee('class="shell"', false)
            ->assertSee('class="card"', false)
            ->assertSee('class="question-bubble"', false)
            ->assertSee('class="continue-btn"', false)
            ->assertDontSee('class="voucher"', false)
            ->assertSee('DeepSeek', false)
            ->assertSee('GEO内容优化服务商怎么选？')
            ->assertSee('内容由 Ai 生成，不能完全保障真实')
            ->assertSee('策影GEO')
            ->assertSee('引用资料标题')
            ->assertSee('和DeepSeek继续聊')
            ->assertSee('href="https://chat.deepseek.com/"', false)
            ->assertSee('<table', false)
            ->assertDontSee('收录词不存在');
    }

    public function test_snapshot_voucher_renders_result_by_id_for_other_direct_admin(): void
    {
        [$owner, $site] = $this->createAdminWithSite('snapshot_direct_owner_admin');
        $viewer = $this->createAdmin('snapshot_other_direct_admin');
        $viewer->forceFill(['role' => 'direct_admin'])->save();
        $result = $this->createBrandDiagnosisSnapshot($owner, $site);

        $response = $this->actingAs($viewer, 'admin')
            ->get(route('admin.snapshot-voucher.show', ['id' => (int) $result->id]));

        $response
            ->assertOk()
            ->assertSee('class="brand"', false)
            ->assertSee('DeepSeek', false)
            ->assertSee('class="question-bubble"', false)
            ->assertSee('<article class="answer">', false)
            ->assertSee('href="https://chat.deepseek.com/"', false)
            ->assertDontSee('class="empty-card"', false);
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
        $response = $this->get(route('admin.snapshot-voucher.show', ['id' => -5]));

        $response
            ->assertOk()
            ->assertSee('从科研选题到投稿预审的论文辅导平台有哪些？')
            ->assertSee('学术易')
            ->assertSee('1rfMLncTo0YEAHsMeO6uRyOpW9PaSvgPRnzjJTcI5cdFD2fh97q7qPRUriJI0osQ22KzU4arkBVxbrAMkv6nE3aOrJmM', false)
            ->assertSee('https://chat.baidu.com/', false)
            ->assertSee('class="brand"', false)
            ->assertSee('class="card"', false)
            ->assertSee('class="continue-btn"', false)
            ->assertSee('<article class="answer">', false)
            ->assertDontSee('<section class="card empty-card"', false);
    }

    public function test_monitoring_center_injects_current_site_report_data_into_industry_page(): void
    {
        [$admin, $site] = $this->createAdminWithSite('monitoring_dynamic_industry_admin');

        KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => '企业关键词库',
            'company_name' => '星河智能科技有限公司',
            'domain_keyword' => '行业竞争力分析',
            'status' => 'active',
            'keyword_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.monitoring-center.index', ['report' => 'industry']))
            ->assertOk()
            ->assertSee('星河智能科技有限公司')
            ->assertSee('window.__MONITORING_REPORT__', false)
            ->assertSee('/assets/monitoring-center/ceying-ai-logo1.png', false)
            ->assertSee('id="industrySummaryRow"', false)
            ->assertSee('id="industryPlatformTable"', false)
            ->assertSee('function applyDynamicIndustryData', false)
            ->assertSee('renderIndustryPlatforms', false)
            ->getContent();

        $headerMeta = $this->headerCompanyMeta($html);
        $this->assertStringNotContainsString('026-06-17', $headerMeta);
    }

    public function test_monitoring_report_json_payload_substitutes_invalid_utf8_instead_of_rendering_empty_assignment(): void
    {
        $payload = [
            'context' => [
                'company_name' => "Broken\xB1Company",
                'site_name' => 'Broken Site',
                'date' => now()->toDateString(),
                'updated_at' => now()->format('Y-m-d H:i'),
            ],
            'summary' => [
                ['label' => 'Broken Metric', 'display' => 1, 'actual' => 1],
            ],
            'platforms' => [
                [
                    'platform_key' => 'doubao',
                    'platform' => "Broken\xB1Platform",
                    'analysis_count' => 1,
                    'top_rank_rates' => ['top1' => 100],
                    'positive_sentiment_rate' => 100,
                    'source_count' => 1,
                ],
            ],
            'sentiment' => [
                'overall' => ['positive_rate' => 100, 'neutral_rate' => 0, 'negative_rate' => 0],
                'platforms' => [],
            ],
        ];

        foreach (['enterprise', 'industry'] as $report) {
            $html = view("admin.monitoring-center.reports.{$report}", [
                'reportData' => $payload,
                'useVirtualSearchReportData' => false,
            ])->render();

            $this->assertStringContainsString('window.__MONITORING_REPORT__ = {', $html);
            $this->assertStringNotContainsString('window.__MONITORING_REPORT__ = ;', $html);
        }
    }

    public function test_monitoring_center_share_endpoint_creates_expiring_link_for_current_site(): void
    {
        $this->travelTo(Carbon::parse('2026-08-19 10:00:00'));
        $this->withoutMiddleware(ValidateCsrfToken::class);

        [$admin, $site] = $this->createAdminWithSite('monitoring_share_creator');

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->postJson(route('admin.monitoring-center.share'), [
                'report' => 'industry',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('report', 'industry')
            ->assertJsonStructure(['url']);

        $share = MonitoringReportShare::query()->firstOrFail();
        $this->assertSame('industry', (string) $share->report_type);
        $this->assertSame((int) $site->id, (int) $share->site_id);
        $this->assertSame((int) $admin->id, (int) $share->created_by_admin_id);
        $this->assertSame('2026-08-26 10:00:00', $share->expires_at?->format('Y-m-d H:i:s'));
        $this->assertStringContainsString('/monitoring-report/share/', (string) $response->json('url'));
    }

    public function test_monitoring_report_share_link_is_public_and_renders_realtime_report_data(): void
    {
        [$admin, $site] = $this->createAdminWithSite('monitoring_public_share');
        $token = 'publicsharetoken123';

        SiteSetting::withoutGlobalScope('current_site')->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'setting_key' => 'monitoring_report_logo',
            'setting_value' => 'https://cdn.example.com/share-report-logo.png',
        ]);

        KeywordLibrary::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'name' => 'Realtime share keyword library',
            'company_name' => 'Realtime Share Brand',
            'domain_keyword' => 'realtime monitoring',
            'status' => 'active',
            'keyword_count' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        MonitoringReportShare::query()->create([
            'token_hash' => hash('sha256', $token),
            'report_type' => 'enterprise',
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'created_by_admin_id' => (int) $admin->id,
            'title' => 'Enterprise report snapshot',
            'payload' => [
                'context' => [
                    'company_name' => 'Stale Snapshot Brand',
                    'site_name' => (string) $site->name,
                    'date' => '2026-07-13',
                    'updated_at' => '2026-07-13 10:00',
                ],
                'summary' => [],
                'model_collection' => [],
                'metrics' => [],
                'distillation_words' => [],
                'platform_filters' => [],
                'trend' => [],
                'search_rows' => [],
            ],
            'use_virtual_search_report_data' => false,
        ]);

        $this->get(route('monitoring-report-share.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('src="https://cdn.example.com/share-report-logo.png"', false)
            ->assertSee('Realtime Share Brand')
            ->assertDontSee('Stale Snapshot Brand')
            ->assertSee('window.__MONITORING_REPORT__', false)
            ->assertDontSee(route('admin.login'), false);
    }

    public function test_monitoring_report_share_link_with_legacy_null_expiry_expires_after_seven_days(): void
    {
        [$admin, $site] = $this->createAdminWithSite('monitoring_legacy_expired_share');
        $token = 'legacyexpiredsharetoken123';

        $share = MonitoringReportShare::query()->create([
            'token_hash' => hash('sha256', $token),
            'report_type' => 'enterprise',
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'created_by_admin_id' => (int) $admin->id,
            'title' => 'Legacy report share',
            'payload' => [],
            'use_virtual_search_report_data' => false,
            'expires_at' => null,
        ]);
        $share->forceFill(['created_at' => now()->subDays(8)])->save();

        $this->get(route('monitoring-report-share.show', ['token' => $token]))
            ->assertNotFound();
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
     * @return array<string,mixed>
     */
    private function monitoringReportPayload(string $html): array
    {
        $matched = preg_match(
            '/window\.__MONITORING_REPORT__\s*=\s*(\{.*?\});\s*window\.__MONITORING_SEARCH_REPORT_USE_VIRTUAL__/s',
            $html,
            $matches
        );
        $this->assertSame(1, $matched);

        $payload = json_decode((string) $matches[1], true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function qianyichengOverrideDates(): array
    {
        return [
            '2026-07-25',
            '2026-07-26',
            '2026-07-27',
            '2026-07-28',
            '2026-07-29',
            '2026-07-30',
            '2026-07-31',
            '2026-08-01',
            '2026-08-02',
            '2026-08-03',
        ];
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
