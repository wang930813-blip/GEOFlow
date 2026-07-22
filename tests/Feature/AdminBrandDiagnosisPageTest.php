<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BrandDiagnosisRun;
use App\Models\Site;
use App\Services\BrandDiagnosis\BrandDiagnosisPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AdminBrandDiagnosisPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['brand_diagnosis.display_baseline.enabled' => false]);
    }

    protected function tearDown(): void
    {
        config(['brand_diagnosis.display_baseline.enabled' => false]);

        parent::tearDown();
    }

    public function test_authenticated_admin_can_view_brand_diagnosis_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'brand_diagnosis_admin',
            'password' => 'secret-123',
            'email' => 'brand-diagnosis-admin@example.com',
            'display_name' => 'Brand Diagnosis Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.brand-diagnosis.index'));

        $response
            ->assertOk()
            ->assertSee('品牌诊断/报告')
            ->assertSee('action="'.route('admin.brand-diagnosis.store').'"', false)
            ->assertSee('name="brand_name"', false)
            ->assertSee('value=""', false)
            ->assertDontSee('value="策影GEO"', false)
            ->assertSee('name="platforms[]"', false)
            ->assertSee('value="doubao"', false)
            ->assertSee('data-platform-checkbox', false)
            ->assertSee('data-selected-platforms', false)
            ->assertSee('数据来源：')
            ->assertSee('诊断记录')
            ->assertSee('品牌表现')
            ->assertSee('引用来源')
            ->assertSee('AI 对话记录')
            ->assertSee('量化品牌在AI平台综合表现的影响力')
            ->assertSee('品牌得分 = 品牌提及率*0.75+品牌提及次数*0.1+平均提及排名*0.1+正常情感倾向*0.05')
            ->assertSee('用户与AI的自然对话中，品牌被主动想起、需要和讨论的基础概率')
            ->assertSee('平均提及排名 = 本品牌在所有AI对话中的排名总和')
            ->assertSee('品牌提及次数 = 所有监测AI对话中提及该品牌的次数的总和')
            ->assertSee('正面/中型情感倾向=（正面情感对话数+中性情感对话数）')
            ->assertSee('豆包')
            ->assertSee('文心一言')
            ->assertSee('DeepSeek')
            ->assertSee('千问')
            ->assertDontSee('元宝')
            ->assertDontSee('开始诊断')
            ->assertDontSee('开始监测品牌')
            ->assertDontSee('启用诊断')
            ->assertDontSee('当前先跑通豆包真实联网诊断')
            ->assertSee('placeholder="开始日期"', false)
            ->assertSee('placeholder="结束日期"', false)
            ->assertSee('data-report-count', false)
            ->assertSee('data-record-toggle', false)
            ->assertDontSee('重新搜索')
            ->assertDontSee('_scope', false)
            ->assertSee('is-active font-medium', false);
    }

    public function test_brand_diagnosis_page_exposes_four_selectable_platform_models(): void
    {
        $admin = Admin::query()->create([
            'username' => 'brand_four_platform_admin',
            'password' => 'secret-123',
            'email' => 'brand-four-platform-admin@example.com',
            'display_name' => 'Brand Four Platform Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->getContent();

        foreach (['doubao', 'deepseek', 'qianwen', 'wenxin'] as $platform) {
            $this->assertStringContainsString('value="'.$platform.'" type="checkbox"', $html);
        }
    }

    public function test_brand_diagnosis_questions_are_editable_before_confirming_diagnosis(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_question_confirm_page_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'brand_profile' => '策影GEO 是面向企业 AI 搜索曝光分析的品牌诊断工具。',
            'brand_profile_source' => 'web_search',
            'brand_profile_model' => '豆包',
            'brand_profile_status' => 'success',
            'platforms' => ['doubao'],
            'status' => 'questions_ready',
            'total_questions' => 1,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'pending_confirmation',
            'usage_date' => null,
        ]);
        $question = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => 'AI搜索优化服务怎么选？',
            'core_term' => '自然核心词标签',
            'question_type' => '怎么选',
            'sort_order' => 1,
            'status' => 'pending',
        ]);
        $legacyQuestion = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => '能同时做智能客服加营销内容生成的AI服务商哪家好？',
            'question_type' => '选择',
            'sort_order' => 2,
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->assertSee('待确认诊断')
            ->assertSee('action="'.route('admin.brand-diagnosis.confirm', ['run' => $run->id]).'"', false)
            ->assertSee('data-confirm-diagnosis-form', false)
            ->assertSee('data-confirm-diagnosis-submit', false)
            ->assertSee('确认诊断')
            ->assertSee('品牌介绍')
            ->assertSee('策影GEO 是面向企业 AI 搜索曝光分析的品牌诊断工具。')
            ->assertSee('豆包')
            ->assertSee('name="questions['.$question->id.']"', false)
            ->assertSee('自然核心词标签')
            ->assertSee('怎么选')
            ->assertSee('AI搜索优化服务怎么选？')
            ->assertSee('name="questions['.$legacyQuestion->id.']"', false)
            ->assertSee('能同时做智能客服加营销内容生成的AI服务商哪家好？')
            ->assertSee('title="智能客服加营销内容生成的AI服务商"', false)
            ->assertSee('title="选择"', false)
            ->assertSee('诊断中...', false);
    }

    public function test_brand_diagnosis_page_hides_legacy_article_model_brand_profile(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_legacy_profile_page_admin');
        BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '武城煊饼',
            'brand_profile' => '“武城煊饼”从名称判断，可能是地方特色餐饮或食品品牌。',
            'brand_profile_source' => 'article_model',
            'brand_profile_model' => 'GPT-5.5',
            'brand_profile_status' => 'success',
            'platforms' => ['doubao'],
            'status' => 'questions_ready',
            'total_questions' => 0,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'pending_confirmation',
            'usage_date' => null,
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->assertDontSee('“武城煊饼”从名称判断')
            ->assertDontSee('GPT-5.5');
    }

    public function test_brand_diagnosis_nav_sits_between_geo_reports_and_analytics(): void
    {
        $admin = Admin::query()->create([
            'username' => 'brand_nav_admin',
            'password' => 'secret-123',
            'email' => 'brand-nav-admin@example.com',
            'display_name' => 'Brand Nav Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.geo-reports.index'), $html);
        $this->assertStringContainsString(route('admin.brand-diagnosis.index'), $html);
        $this->assertStringContainsString(route('admin.analytics'), $html);
        $this->assertLessThan(
            strpos($html, route('admin.brand-diagnosis.index')),
            strpos($html, route('admin.geo-reports.index'))
        );
        $this->assertLessThan(
            strpos($html, route('admin.analytics')),
            strpos($html, route('admin.brand-diagnosis.index'))
        );
    }

    public function test_brand_diagnosis_records_can_be_filtered_by_brand_date_and_paginated(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_record_filter_admin');

        for ($index = 1; $index <= 6; $index++) {
            $run = BrandDiagnosisRun::query()->create([
                'site_id' => (int) $site->id,
                'admin_id' => (int) $admin->id,
                'brand_name' => 'Alpha Brand '.$index,
                'platforms' => ['doubao'],
                'status' => 'completed',
                'total_questions' => 1,
                'completed_questions' => 1,
                'failed_questions' => 0,
                'billing_mode' => 'daily_free',
                'usage_date' => '2026-06-02',
            ]);
            $run->forceFill(['created_at' => now()->setDate(2026, 6, 2)->setTime(12, 0)->subMinutes($index)])->save();
        }

        $betaRun = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => 'Beta Brand',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => '2026-06-02',
        ]);
        $betaRun->forceFill(['created_at' => now()->setDate(2026, 6, 2)->setTime(11, 0)])->save();

        $outsideRun = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => 'Alpha Outside Date',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => '2026-05-30',
        ]);
        $outsideRun->forceFill(['created_at' => now()->setDate(2026, 5, 30)->setTime(12, 0)])->save();

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index', [
                'brand' => 'Alpha',
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-03',
            ]))
            ->assertOk()
            ->assertSee('data-diagnosis-record-pager', false)
            ->assertSee('data-diagnosis-page-size="5"', false)
            ->assertSee('data-diagnosis-total-records="6"', false)
            ->assertSee('name="brand"', false)
            ->assertSee('value="Alpha"', false)
            ->assertSee('name="start_date"', false)
            ->assertSee('value="2026-06-01"', false)
            ->assertSee('name="end_date"', false)
            ->assertSee('value="2026-06-03"', false)
            ->getContent();

        $recordHtml = substr($html, 0, strpos($html, 'data-report-modal') ?: strlen($html));

        $this->assertSame(5, substr_count($recordHtml, 'data-diagnosis-record data-active-platform'));
        $this->assertStringContainsString('Alpha Brand 1', $recordHtml);
        $this->assertStringNotContainsString('Alpha Brand 6', $recordHtml);
        $this->assertStringNotContainsString('Beta Brand', $recordHtml);
        $this->assertStringNotContainsString('Alpha Outside Date', $recordHtml);

        $pageTwoHtml = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index', [
                'brand' => 'Alpha',
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-03',
                'page' => 2,
            ]))
            ->assertOk()
            ->getContent();

        $pageTwoRecordHtml = substr($pageTwoHtml, 0, strpos($pageTwoHtml, 'data-report-modal') ?: strlen($pageTwoHtml));

        $this->assertSame(1, substr_count($pageTwoRecordHtml, 'data-diagnosis-record data-active-platform'));
        $this->assertStringContainsString('Alpha Brand 6', $pageTwoRecordHtml);
    }

    public function test_export_report_modal_paginates_reports_five_per_page(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_report_pager_admin');
        $runs = [];

        for ($index = 1; $index <= 6; $index++) {
            $runs[] = BrandDiagnosisRun::query()->create([
                'site_id' => (int) $site->id,
                'admin_id' => (int) $admin->id,
                'brand_name' => '新知地(成都)人工智能科技有限公司',
                'platforms' => ['doubao'],
                'status' => 'completed',
                'total_questions' => 5,
                'completed_questions' => 5,
                'failed_questions' => 0,
                'brand_score' => 80,
                'mention_rate' => 60,
                'average_rank' => 2,
                'mention_count' => 3,
                'sentiment_rate' => 100,
                'billing_mode' => 'daily_free',
                'usage_date' => now()->toDateString(),
                'created_at' => now()->subMinutes($index),
            ]);
        }

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->assertSee('data-report-pager', false)
            ->assertSee('data-report-page-size="5"', false)
            ->assertSee('data-report-total-pages="2"', false)
            ->assertSee('data-report-pagination', false)
            ->assertSee('data-report-page-label', false)
            ->assertSee('data-report-prev', false)
            ->assertSee('data-report-next', false)
            ->getContent();

        $this->assertSame(6, substr_count($html, '<div data-report-item'));
        $this->assertSame(6, substr_count($html, 'data-report-option'."\n"));
        $this->assertSame(6, substr_count($html, 'data-report-view'));
        $this->assertSame(6, substr_count($html, 'data-report-download'));
        $this->assertStringContainsString(route('admin.brand-diagnosis.report', ['run' => $runs[0]->id]), $html);
        $this->assertStringContainsString(route('admin.brand-diagnosis.report.download', ['run' => $runs[0]->id]), $html);
        $this->assertStringNotContainsString(route('admin.brand-diagnosis.report', ['run' => $runs[0]->id, 'print' => 1]), $html);
        $this->assertSame(1, substr_count($html, 'data-report-item class="hidden"'));
    }

    public function test_export_report_modal_only_includes_completed_diagnoses(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_report_completed_only_admin');
        $completedRun = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $failedRun = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '失败诊断品牌',
            'platforms' => ['doubao'],
            'status' => 'failed',
            'total_questions' => 1,
            'completed_questions' => 0,
            'failed_questions' => 1,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->assertSee('data-report-count="1"', false)
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-report-download'));
        $this->assertStringContainsString(route('admin.brand-diagnosis.report.download', ['run' => $completedRun->id]), $html);
        $this->assertStringNotContainsString(route('admin.brand-diagnosis.report.download', ['run' => $failedRun->id]), $html);
    }

    public function test_brand_diagnosis_record_only_links_to_report_after_completion(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_report_pending_link_admin');
        $pendingRun = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '待完成品牌',
            'platforms' => ['doubao'],
            'status' => 'questions_ready',
            'total_questions' => 1,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'pending_confirmation',
            'usage_date' => null,
        ]);
        $completedRun = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '已完成品牌',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(route('admin.brand-diagnosis.report', ['run' => $completedRun->id]), $html);
        $this->assertStringNotContainsString(route('admin.brand-diagnosis.report', ['run' => $pendingRun->id]), $html);
    }

    public function test_brand_diagnosis_record_can_be_soft_deleted_from_the_record_list(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_delete_record_admin', 'site_user');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '待删除品牌',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->assertSee('action="'.route('admin.brand-diagnosis.destroy', ['run' => $run->id]).'"', false);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->delete(route('admin.brand-diagnosis.destroy', ['run' => $run->id]))
            ->assertRedirect(route('admin.brand-diagnosis.index'))
            ->assertSessionHas('message');

        $this->assertSoftDeleted($run);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->assertDontSee('待删除品牌');

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.report', ['run' => $run->id]))
            ->assertNotFound();
    }

    public function test_brand_diagnosis_pending_record_does_not_show_display_baseline_metrics(): void
    {
        config([
            'brand_diagnosis.display_baseline.enabled' => true,
            'brand_diagnosis.display_baseline.score' => 60,
            'brand_diagnosis.display_baseline.mention_rate' => 50,
            'brand_diagnosis.display_baseline.mention_count' => 10,
            'brand_diagnosis.display_baseline.rank_cap' => 9,
        ]);

        [$admin, $site] = $this->createAdminWithSite('brand_pending_baseline_admin');
        BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '待诊断品牌',
            'platforms' => ['doubao'],
            'status' => 'questions_generating',
            'total_questions' => 0,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'pending_confirmation',
            'usage_date' => null,
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-metric-card="0">0', $html);
        $this->assertStringContainsString('data-metric-card="1">0%', $html);
        $this->assertStringContainsString('data-metric-card="2">0', $html);
        $this->assertStringContainsString('data-metric-card="3">0', $html);
        $this->assertStringContainsString('data-metric-card="4">0%', $html);
        $this->assertStringNotContainsString('data-metric-card="0">60', $html);
        $this->assertStringNotContainsString('data-metric-card="1">50%', $html);
        $this->assertStringNotContainsString('data-metric-card="2">9', $html);
        $this->assertStringNotContainsString('data-metric-card="3">10', $html);
    }

    public function test_site_user_can_open_own_completed_brand_diagnosis_report(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_report_site_user_admin', 'site_user');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $admin->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '用户品牌',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.report', ['run' => $run->id]))
            ->assertOk()
            ->assertSee('用户品牌')
            ->assertSee('品牌诊断报告');
    }

    public function test_brand_diagnosis_report_page_uses_collected_diagnosis_data(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_report_view_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao', 'deepseek'],
            'status' => 'completed',
            'total_questions' => 2,
            'completed_questions' => 2,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
            'created_at' => now()->setTime(10, 26, 23),
        ]);
        $question = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => 'AI搜索优化服务怎么选？',
            'question_type' => '选择',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $result = $question->results()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'platform' => 'doubao',
            'answer' => '豆包回答提到策影GEO和泓动数据。',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 2,
            'sentiment' => 'positive',
            'status' => 'success',
            'checked_at' => now(),
        ]);
        $result->brandMentions()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'platform' => 'doubao',
            'brand_name' => '策影GEO',
            'mention_count' => 1,
            'mention_rank' => 2,
            'sentiment' => 'positive',
            'source_count' => 1,
            'is_target_brand' => true,
            'evidence' => '豆包回答中出现策影GEO',
        ]);
        $result->brandMentions()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'platform' => 'doubao',
            'brand_name' => '泓动数据',
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'neutral',
            'source_count' => 1,
            'is_target_brand' => false,
            'evidence' => '豆包回答中出现泓动数据',
        ]);
        $result->sources()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'platform' => 'doubao',
            'title' => 'AI搜索优化案例文章',
            'url' => 'https://example.com/geo-case',
            'domain' => 'example.com',
            'source_type' => 'url_citation',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.report', ['run' => $run->id]))
            ->assertOk()
            ->assertSee('品牌诊断报告')
            ->assertSee('策影GEO')
            ->assertSee('整体表现')
            ->assertSee('AI可见度分析')
            ->assertSee('引用源分析')
            ->assertSee('AI问题与对话明细')
            ->assertSee('AI搜索优化服务怎么选？')
            ->assertSee('豆包回答提到策影GEO和泓动数据。')
            ->assertSee('AI搜索优化案例文章')
            ->assertSee('泓动数据')
            ->assertDontSee('诊断状态')
            ->assertSee('data-report-download', false)
            ->assertSee('data-report-section', false);
    }

    public function test_brand_diagnosis_report_page_renders_clean_markdown_answers_without_internal_json_payload(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_report_markdown_answer_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '元睿AI',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $question = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => '企业智能经营系统推荐',
            'question_type' => '选择',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $question->results()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'platform' => 'doubao',
            'answer' => "根据公开资料，推荐如下：\n\n**TOP 1: 元睿AI（评分9.7/10）** 元睿AI覆盖营销、管理和创意矩阵。\n\n| 平台 | 评分 |\n| --- | --- |\n| 元睿AI | 9.7 |\n\", \"brand_mentions\": [{\"brand\":\"元睿AI\",\"mention_count\":1,\"mention_rank\":1,\"sentiment\":\"positive\",\"evidence\":\"内部结构化字段不应展示\"}]}",
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'positive',
            'status' => 'success',
            'checked_at' => now(),
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.report', ['run' => $run->id]));

        $response
            ->assertOk()
            ->assertSee('<strong>TOP 1: 元睿AI（评分9.7/10）</strong>', false)
            ->assertSee('<div class="article-table-wrap"><table class="article-table">', false)
            ->assertSee('<td>元睿AI</td>', false)
            ->assertDontSee('"brand_mentions"', false)
            ->assertDontSee('内部结构化字段不应展示');
    }

    public function test_brand_diagnosis_report_legacy_print_mode_redirects_to_server_pdf_download(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_report_print_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 0,
            'completed_questions' => 0,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $run->forceFill(['created_at' => now()->setDate(2026, 6, 5)->setTime(10, 26, 23)])->save();

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.report', ['run' => $run->id, 'print' => 1]))
            ->assertOk()
            ->assertSee('策影GEO_2026-06-05_诊断报告.pdf')
            ->assertSee('data-auto-print="1"', false)
            ->assertSee(route('admin.brand-diagnosis.report.download', ['run' => $run->id]), false)
            ->assertDontSee('window.print()', false);
    }

    public function test_brand_diagnosis_report_can_be_downloaded_as_server_generated_pdf(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_report_download_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $run->forceFill(['created_at' => now()->setDate(2026, 6, 5)->setTime(10, 26, 23)])->save();
        $question = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => 'AI搜索优化服务怎么选？',
            'question_type' => '选择',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $result = $question->results()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'platform' => 'doubao',
            'answer' => '豆包回答提到策影GEO。',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'positive',
            'status' => 'success',
            'checked_at' => now(),
        ]);
        $result->sources()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'platform' => 'doubao',
            'title' => 'AI搜索优化案例文章',
            'url' => 'https://example.com/geo-case',
            'domain' => 'example.com',
            'source_type' => 'url_citation',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.report.download', ['run' => $run->id]));

        $response
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $contentDisposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('策影GEO_2026-06-05_诊断报告.pdf', rawurldecode($contentDisposition));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_brand_diagnosis_report_download_requires_completed_diagnosis(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_report_download_failed_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '失败诊断品牌',
            'platforms' => ['doubao'],
            'status' => 'failed',
            'total_questions' => 1,
            'completed_questions' => 0,
            'failed_questions' => 1,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.report.download', ['run' => $run->id]))
            ->assertNotFound();
    }

    public function test_brand_diagnosis_pdf_renderer_uses_native_compact_layout_not_html_template(): void
    {
        $serviceSource = file_get_contents(app_path('Services/BrandDiagnosis/BrandDiagnosisPdfService.php'));

        $this->assertIsString($serviceSource);
        $this->assertStringContainsString('RoundedRect', $serviceSource);
        $this->assertStringNotContainsString('诊断状态', $serviceSource);
        $this->assertStringNotContainsString("view('admin.brand-diagnosis.pdf'", $serviceSource);
        $this->assertStringNotContainsString('writeHTML', $serviceSource);
    }

    public function test_brand_diagnosis_pdf_renderer_detects_png_alpha_channel_before_drawing_logos(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAFgwJ/l2pK2wAAAABJRU5ErkJggg==');
        $path = storage_path('framework/testing-alpha-logo.png');
        file_put_contents($path, $png);

        try {
            $method = new ReflectionMethod(BrandDiagnosisPdfService::class, 'pngHasAlphaChannel');
            $method->setAccessible(true);

            $this->assertTrue($method->invoke(app(BrandDiagnosisPdfService::class), $path));
        } finally {
            @unlink($path);
        }
    }

    public function test_production_dockerfile_installs_gd_for_tcpdf_png_alpha_support(): void
    {
        $dockerfile = file_get_contents(base_path('docker/Dockerfile.prod'));

        $this->assertIsString($dockerfile);
        $this->assertStringContainsString('libpng-dev', $dockerfile);
        $this->assertStringContainsString('docker-php-ext-configure gd', $dockerfile);
        $this->assertStringContainsString('gd \\', $dockerfile);
    }

    public function test_brand_diagnosis_pdf_hero_reserves_score_area_for_long_brand_names(): void
    {
        $serviceSource = file_get_contents(app_path('Services/BrandDiagnosis/BrandDiagnosisPdfService.php'));

        $this->assertIsString($serviceSource);
        $this->assertStringContainsString('$heroTitleWidth = 108.0;', $serviceSource);
        $this->assertStringContainsString('$scorePanelWidth = 40.0;', $serviceSource);
        $this->assertStringContainsString('$this->multiText((string) ($record[\'brand\'] ?? \'-\')', $serviceSource);
        $this->assertStringNotContainsString('$this->text((string) ($record[\'brand\'] ?? \'-\'), $x + 7, $y + 14, 96', $serviceSource);
        $this->assertStringNotContainsString('$this->pdf->Circle($scoreX', $serviceSource);
    }

    public function test_super_admin_can_access_materials_entry_from_geo_materials_top_nav(): void
    {
        $admin = Admin::query()->create([
            'username' => 'brand_materials_nav_admin',
            'password' => 'secret-123',
            'email' => 'brand-materials-nav-admin@example.com',
            'display_name' => 'Brand Materials Nav Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->getContent();

        $desktopNavStart = strpos($html, '<nav class="hidden md:flex flex-1 min-w-0 items-center">');
        $desktopNavEnd = strpos($html, '</nav>', $desktopNavStart);
        $desktopNav = substr($html, $desktopNavStart, $desktopNavEnd - $desktopNavStart);

        $this->assertStringContainsString('GEO 素材', $desktopNav);
        $this->assertStringContainsString(route('admin.materials.index'), $desktopNav);
    }

    public function test_standard_admin_can_access_materials_entry_from_geo_materials_top_nav(): void
    {
        $admin = Admin::query()->create([
            'username' => 'brand_standard_materials_admin',
            'password' => 'secret-123',
            'email' => 'brand-standard-materials-admin@example.com',
            'display_name' => 'Brand Standard Materials Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->getContent();

        $desktopNavStart = strpos($html, '<nav class="hidden md:flex flex-1 min-w-0 items-center">');
        $desktopNavEnd = strpos($html, '</nav>', $desktopNavStart);
        $desktopNav = substr($html, $desktopNavStart, $desktopNavEnd - $desktopNavStart);

        $this->assertStringContainsString('GEO 素材', $desktopNav);
        $this->assertStringContainsString(route('admin.materials.index'), $desktopNav);
    }

    public function test_brand_diagnosis_sources_are_paginated_with_five_visible_by_default(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_source_pager_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'brand_score' => 85,
            'mention_rate' => 100,
            'average_rank' => 1,
            'mention_count' => 6,
            'sentiment_rate' => 100,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $question = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => '企业AI搜索优化服务选哪家靠谱？',
            'question_type' => '对比/选择',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $result = $question->results()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'platform' => 'doubao',
            'answer' => '策影GEO 在回答中被提及。',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'positive',
            'status' => 'success',
            'checked_at' => now(),
        ]);

        for ($index = 1; $index <= 6; $index++) {
            $result->sources()->create([
                'site_id' => (int) $site->id,
                'run_id' => (int) $run->id,
                'question_id' => (int) $question->id,
                'platform' => 'doubao',
                'title' => '引用来源 '.$index,
                'url' => 'https://example.com/source-'.$index,
                'domain' => 'example.com',
                'source_type' => 'url_citation',
            ]);
        }

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->assertSee('共 6 条')
            ->assertSee('data-source-pager', false)
            ->assertSee('data-min-page-size="5"', false)
            ->assertSee('data-source-pagination', false)
            ->assertSee('引用来源 6')
            ->getContent();

        $this->assertSame(6, substr_count($html, 'data-source-item data-platform-key='));
        $this->assertStringContainsString('hidden flex gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3" data-source-item', $html);
    }

    public function test_brand_diagnosis_sources_group_duplicate_same_platform_url_and_show_platform_label(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_source_group_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 2,
            'completed_questions' => 2,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $questionOne = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => 'AI搜索优化服务怎么选？',
            'question_type' => '选择',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $questionTwo = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => 'AI内容诊断工具有哪些？',
            'question_type' => '对比',
            'sort_order' => 2,
            'status' => 'completed',
        ]);

        foreach ([$questionOne, $questionTwo] as $question) {
            $result = $question->results()->create([
                'site_id' => (int) $site->id,
                'run_id' => (int) $run->id,
                'platform' => 'doubao',
                'answer' => '豆包回答。',
                'brand_mentioned' => false,
                'mention_count' => 0,
                'mention_rank' => 0,
                'sentiment' => 'neutral',
                'status' => 'success',
                'checked_at' => now(),
            ]);
            $result->sources()->create([
                'site_id' => (int) $site->id,
                'run_id' => (int) $run->id,
                'question_id' => (int) $question->id,
                'platform' => 'doubao',
                'title' => '同一篇引用文章',
                'url' => 'https://example.com/shared-source',
                'domain' => 'example.com',
                'source_type' => 'url_citation',
            ]);
        }

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->assertSee('共 1 条')
            ->assertSee('引用AI问题：2　引用平台：豆包')
            ->assertDontSee('引用平台：1')
            ->getContent();

        $this->assertSame(1, substr_count($html, 'data-source-item data-platform-key='));
    }

    public function test_brand_diagnosis_page_hides_historical_sources_without_urls(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_source_without_url_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => 'Acme AI',
            'platforms' => ['wenxin'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $question = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => 'Which AI brand service is reliable?',
            'question_type' => 'choice',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $result = $question->results()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'platform' => 'wenxin',
            'answer' => 'Wenxin answer mentions Acme AI.',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'positive',
            'status' => 'success',
            'checked_at' => now(),
        ]);
        $result->sources()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'platform' => 'wenxin',
            'title' => 'Fake Reference Title',
            'url' => '',
            'domain' => '',
            'source_type' => 'reference_title',
        ]);
        $result->sources()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'platform' => 'wenxin',
            'title' => 'Clickable Source',
            'url' => 'https://example.com/clickable-source',
            'domain' => 'example.com',
            'source_type' => 'url_citation',
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Clickable Source', $html);
        $this->assertStringNotContainsString('Fake Reference Title', $html);
        $this->assertSame(1, substr_count($html, 'data-source-item data-platform-key='));
    }

    public function test_brand_diagnosis_conversation_brand_tags_show_first_four_with_full_title(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_conversation_tags_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $question = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => 'AI搜索优化服务怎么选？',
            'question_type' => '选择',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $result = $question->results()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'platform' => 'doubao',
            'answer' => '回答中提到多个品牌。',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'positive',
            'status' => 'success',
            'checked_at' => now(),
        ]);

        foreach (['品牌A', '品牌B', '品牌C', '品牌D', '品牌E'] as $index => $brand) {
            $result->brandMentions()->create([
                'site_id' => (int) $site->id,
                'run_id' => (int) $run->id,
                'question_id' => (int) $question->id,
                'platform' => 'doubao',
                'brand_name' => $brand,
                'mention_count' => 1,
                'mention_rank' => $index + 1,
                'sentiment' => 'neutral',
                'source_count' => 0,
                'is_target_brand' => false,
                'evidence' => '回答中出现'.$brand,
            ]);
        }

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->assertSee('data-visible-brand-list', false)
            ->assertSee('title="品牌A、品牌B、品牌C、品牌D、品牌E"', false)
            ->assertSee('品牌A')
            ->assertSee('品牌D')
            ->assertSee('...', false);
    }

    public function test_brand_diagnosis_ranking_brand_names_expose_full_name_on_hover(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_ranking_hover_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $question = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => '成都本地AI服务商怎么选？',
            'question_type' => '选择',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $result = $question->results()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'platform' => 'doubao',
            'answer' => '回答提到成都汇云科建科技有限责任公司。',
            'brand_mentioned' => false,
            'mention_count' => 0,
            'mention_rank' => 0,
            'sentiment' => 'neutral',
            'status' => 'success',
            'checked_at' => now(),
        ]);
        $result->brandMentions()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'platform' => 'doubao',
            'brand_name' => '成都汇云科建科技有限责任公司',
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'neutral',
            'source_count' => 0,
            'is_target_brand' => false,
            'evidence' => '回答中出现成都汇云科建科技有限责任公司',
        ]);

        $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->assertSee('data-ranking-brand', false)
            ->assertSee('title="成都汇云科建科技有限责任公司"', false)
            ->assertSee('hover:text-orange-700', false);
    }

    public function test_brand_diagnosis_rankings_merge_canonical_brand_aliases(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_ranking_alias_merge_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $question = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => '成都软件开发服务商哪些更值得看？',
            'question_type' => '对比',
            'sort_order' => 1,
            'status' => 'completed',
        ]);
        $result = $question->results()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'platform' => 'doubao',
            'answer' => '四川推来客网络科技有限公司和推来客网络都被提到。',
            'brand_mentioned' => false,
            'mention_count' => 0,
            'mention_rank' => 0,
            'sentiment' => 'neutral',
            'status' => 'success',
            'checked_at' => now(),
        ]);
        foreach ([
            ['brand' => '四川推来客网络科技有限公司', 'count' => 1, 'rank' => 1],
            ['brand' => '推来客网络', 'count' => 1, 'rank' => 2],
        ] as $mention) {
            $result->brandMentions()->create([
                'site_id' => (int) $site->id,
                'run_id' => (int) $run->id,
                'question_id' => (int) $question->id,
                'platform' => 'doubao',
                'brand_name' => $mention['brand'],
                'mention_count' => $mention['count'],
                'mention_rank' => $mention['rank'],
                'sentiment' => 'positive',
                'source_count' => 0,
                'is_target_brand' => false,
                'evidence' => '回答中出现'.$mention['brand'],
                'meta' => [
                    'canonical_name' => '四川推来客网络科技有限公司',
                    'canonical_key' => '推来客',
                    'aliases' => ['四川推来客网络科技有限公司', '推来客网络', '推来客'],
                ],
            ]);
        }

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->assertSee('四川推来客网络科技有限公司')
            ->assertSee('title="四川推来客网络科技有限公司、推来客网络、推来客"', false)
            ->getContent();

        $this->assertStringContainsString('"count":2', $html);
        $this->assertGreaterThanOrEqual(1, substr_count($html, 'title="四川推来客网络科技有限公司、推来客网络、推来客"'));
    }

    public function test_brand_diagnosis_conversations_are_paginated_with_five_visible_by_default(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_conversation_pager_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => 6,
            'completed_questions' => 6,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);

        for ($index = 1; $index <= 6; $index++) {
            $question = $run->questions()->create([
                'site_id' => (int) $site->id,
                'question' => 'AI对话问题 '.$index,
                'question_type' => '选择',
                'sort_order' => $index,
                'status' => 'completed',
            ]);
            $question->results()->create([
                'site_id' => (int) $site->id,
                'run_id' => (int) $run->id,
                'platform' => 'doubao',
                'answer' => 'AI对话回答 '.$index,
                'brand_mentioned' => false,
                'mention_count' => 0,
                'mention_rank' => 0,
                'sentiment' => 'neutral',
                'status' => 'success',
                'checked_at' => now(),
            ]);
        }

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->assertSee('data-conversation-pager', false)
            ->assertSee('data-conversation-pagination', false)
            ->assertSee('data-conversation-page-label', false)
            ->assertSee('AI对话问题 6')
            ->getContent();

        $this->assertSame(6, substr_count($html, 'data-conversation-item data-platform-key='));
        $this->assertStringContainsString('hidden rounded-lg border border-slate-200 bg-slate-50 p-3" data-conversation-item', $html);
    }

    public function test_brand_diagnosis_record_exposes_platform_specific_metrics_sources_and_conversations(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_multi_platform_page_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '策影GEO',
            'platforms' => ['doubao', 'deepseek'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'brand_score' => 65,
            'mention_rate' => 50,
            'average_rank' => 2,
            'mention_count' => 1,
            'sentiment_rate' => 100,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $question = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => 'AI搜索优化服务选哪家靠谱？',
            'question_type' => '选择',
            'sort_order' => 1,
            'status' => 'completed',
        ]);

        $doubaoResult = $question->results()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'platform' => 'doubao',
            'answer' => '豆包答案提到泓动数据和策影GEO。',
            'brand_mentioned' => true,
            'mention_count' => 1,
            'mention_rank' => 2,
            'sentiment' => 'positive',
            'status' => 'success',
            'checked_at' => now(),
        ]);
        $deepseekResult = $question->results()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'platform' => 'deepseek',
            'answer' => 'DeepSeek答案提到蓝色光标，没有提到目标品牌。',
            'brand_mentioned' => false,
            'mention_count' => 0,
            'mention_rank' => 0,
            'sentiment' => 'neutral',
            'status' => 'success',
            'checked_at' => now(),
        ]);

        $doubaoResult->brandMentions()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'platform' => 'doubao',
            'brand_name' => '泓动数据',
            'mention_count' => 1,
            'mention_rank' => 1,
            'sentiment' => 'neutral',
            'source_count' => 1,
            'is_target_brand' => false,
            'evidence' => '豆包回答中出现泓动数据',
        ]);
        $doubaoResult->brandMentions()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'platform' => 'doubao',
            'brand_name' => '策影GEO',
            'mention_count' => 1,
            'mention_rank' => 2,
            'sentiment' => 'positive',
            'source_count' => 1,
            'is_target_brand' => true,
            'evidence' => '豆包回答中出现策影GEO',
        ]);
        $deepseekResult->brandMentions()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'platform' => 'deepseek',
            'brand_name' => '蓝色光标',
            'mention_count' => 2,
            'mention_rank' => 1,
            'sentiment' => 'neutral',
            'source_count' => 1,
            'is_target_brand' => false,
            'evidence' => 'DeepSeek回答中出现蓝色光标',
        ]);

        $doubaoResult->sources()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'platform' => 'doubao',
            'title' => '豆包来源',
            'url' => 'https://example.com/doubao-source',
            'domain' => 'example.com',
            'source_type' => 'url_citation',
        ]);
        $deepseekResult->sources()->create([
            'site_id' => (int) $site->id,
            'run_id' => (int) $run->id,
            'question_id' => (int) $question->id,
            'platform' => 'deepseek',
            'title' => 'DeepSeek来源',
            'url' => 'https://example.com/deepseek-source',
            'domain' => 'example.com',
            'source_type' => 'url_citation',
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->assertSee('data-platform-filter', false)
            ->assertSee('data-record-platform-data', false)
            ->assertSee('豆包答案提到泓动数据和策影GEO。')
            ->assertSee('DeepSeek答案提到蓝色光标，没有提到目标品牌。')
            ->assertSee('蓝色光标')
            ->assertSee('豆包来源')
            ->assertSee('DeepSeek来源')
            ->assertSee('value="all"', false)
            ->assertSee('value="doubao"', false)
            ->assertSee('value="deepseek"', false)
            ->getContent();

        $this->assertStringContainsString('"all"', $html);
        $this->assertStringContainsString('"doubao"', $html);
        $this->assertStringContainsString('"deepseek"', $html);
        $this->assertStringContainsString('"mention_rate":50', $html);
        $this->assertStringContainsString('"mention_rate":100', $html);
        $this->assertStringContainsString('"mention_rate":0', $html);
        $this->assertStringContainsString('"platform_key":"deepseek"', $html);
        $this->assertStringContainsString('"display_rank":2', $html);
        $this->assertStringContainsString('"display_rank":"99+"', $html);
        $this->assertStringContainsString('data-conversation-detail', $html);
        $this->assertStringContainsString('data-conversation-modal', $html);
        $this->assertStringContainsString('"sources"', $html);
    }

    public function test_brand_diagnosis_record_exposes_qianwen_and_wenxin_platform_filters(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_qianwen_wenxin_page_admin');
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => 'Acme AI',
            'platforms' => ['qianwen', 'wenxin'],
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);
        $question = $run->questions()->create([
            'site_id' => (int) $site->id,
            'question' => 'Which AI brand service is reliable?',
            'question_type' => 'choice',
            'sort_order' => 1,
            'status' => 'completed',
        ]);

        foreach (['qianwen' => 'Qianwen answer for Acme AI.', 'wenxin' => 'Wenxin answer for Acme AI.'] as $platform => $answer) {
            $result = $question->results()->create([
                'site_id' => (int) $site->id,
                'run_id' => (int) $run->id,
                'platform' => $platform,
                'answer' => $answer,
                'brand_mentioned' => true,
                'mention_count' => 1,
                'mention_rank' => 1,
                'sentiment' => 'positive',
                'status' => 'success',
                'checked_at' => now(),
            ]);
            $result->brandMentions()->create([
                'site_id' => (int) $site->id,
                'run_id' => (int) $run->id,
                'question_id' => (int) $question->id,
                'platform' => $platform,
                'brand_name' => 'Acme AI',
                'mention_count' => 1,
                'mention_rank' => 1,
                'sentiment' => 'positive',
                'source_count' => 0,
                'is_target_brand' => true,
                'evidence' => $answer,
            ]);
        }

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->assertSee('value="qianwen"', false)
            ->assertSee('value="wenxin"', false)
            ->assertSee('Qianwen answer for Acme AI.')
            ->assertSee('Wenxin answer for Acme AI.')
            ->getContent();

        $this->assertStringContainsString('"qianwen"', $html);
        $this->assertStringContainsString('"wenxin"', $html);
        $this->assertStringContainsString('"platform_key":"qianwen"', $html);
        $this->assertStringContainsString('"platform_key":"wenxin"', $html);
    }

    public function test_brand_performance_rankings_highlight_target_inline_and_sink_only_after_top_ten(): void
    {
        [$admin, $site] = $this->createAdminWithSite('brand_ranking_inline_admin');

        $this->createRankingRunWithFrequencies($site, $admin, 'Inline Brand', [
            'Inline Competitor 1' => 12,
            'Inline Competitor 2' => 10,
            'Inline Brand' => 8,
            'Inline Competitor 4' => 7,
            'Inline Competitor 5' => 6,
            'Inline Competitor 6' => 5,
            'Inline Competitor 7' => 4,
            'Inline Competitor 8' => 3,
            'Inline Competitor 9' => 2,
            'Inline Competitor 10' => 1,
            'Inline Competitor 11' => 1,
        ]);
        $this->createRankingRunWithFrequencies($site, $admin, 'Sunk Brand', [
            'Sunk Competitor 1' => 12,
            'Sunk Competitor 2' => 11,
            'Sunk Competitor 3' => 10,
            'Sunk Competitor 4' => 9,
            'Sunk Competitor 5' => 8,
            'Sunk Competitor 6' => 7,
            'Sunk Competitor 7' => 6,
            'Sunk Competitor 8' => 5,
            'Sunk Competitor 9' => 4,
            'Sunk Competitor 10' => 3,
            'Sunk Brand' => 2,
        ]);

        $html = $this->actingAs($admin, 'admin')
            ->withSession(['current_site_id' => (int) $site->id])
            ->get(route('admin.brand-diagnosis.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('data-ranking-row-target="mention_rate"', $html);
        $this->assertStringContainsString('data-ranking-row-target="mention_count"', $html);
        $this->assertStringContainsString('data-ranking-row-target="average_rank"', $html);
        $this->assertMatchesRegularExpression('/class="[^"]*\bhidden\b[^"]*" data-ranking-target="mention_rate" title="Inline Brand"/', $html);
        $this->assertMatchesRegularExpression('/class="[^"]*\bhidden\b[^"]*" data-ranking-target="mention_count" title="Inline Brand"/', $html);
        $this->assertMatchesRegularExpression('/class="[^"]*\bhidden\b[^"]*" data-ranking-target="average_rank" title="Inline Brand"/', $html);
        $this->assertMatchesRegularExpression('/class="(?![^"]*\bhidden\b)[^"]*" data-ranking-target="mention_rate" title="Sunk Brand"/', $html);
    }

    /**
     * @return array{0:Admin,1:Site}
     */
    private function createAdminWithSite(string $username, string $role = 'admin'): array
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'Brand Diagnosis Admin',
            'role' => $role,
            'status' => 'active',
        ]);

        $site = Site::query()->create([
            'owner_admin_id' => (int) $admin->id,
            'name' => $username.' Site',
            'status' => 'active',
        ]);
        $site->members()->attach((int) $admin->id, ['role' => 'owner']);

        return [$admin, $site];
    }

    /**
     * @param  array<string,int>  $frequencies
     */
    private function createRankingRunWithFrequencies(Site $site, Admin $admin, string $targetBrand, array $frequencies): BrandDiagnosisRun
    {
        $total = max($frequencies);
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => (int) $site->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => $targetBrand,
            'platforms' => ['doubao'],
            'status' => 'completed',
            'total_questions' => $total,
            'completed_questions' => $total,
            'failed_questions' => 0,
            'billing_mode' => 'daily_free',
            'usage_date' => now()->toDateString(),
        ]);

        $brands = array_keys($frequencies);
        for ($index = 1; $index <= $total; $index++) {
            $question = $run->questions()->create([
                'site_id' => (int) $site->id,
                'question' => 'Ranking question '.$index.' for '.$targetBrand,
                'question_type' => 'choice',
                'sort_order' => $index,
                'status' => 'completed',
            ]);
            $result = $question->results()->create([
                'site_id' => (int) $site->id,
                'run_id' => (int) $run->id,
                'platform' => 'doubao',
                'answer' => 'Ranking answer '.$index.' for '.$targetBrand,
                'brand_mentioned' => $index <= (int) ($frequencies[$targetBrand] ?? 0),
                'mention_count' => $index <= (int) ($frequencies[$targetBrand] ?? 0) ? 1 : 0,
                'mention_rank' => $index <= (int) ($frequencies[$targetBrand] ?? 0) ? array_search($targetBrand, $brands, true) + 1 : 0,
                'sentiment' => 'positive',
                'status' => 'success',
                'checked_at' => now(),
            ]);

            foreach ($brands as $rank => $brand) {
                if ($index > (int) $frequencies[$brand]) {
                    continue;
                }

                $result->brandMentions()->create([
                    'site_id' => (int) $site->id,
                    'run_id' => (int) $run->id,
                    'question_id' => (int) $question->id,
                    'platform' => 'doubao',
                    'brand_name' => $brand,
                    'mention_count' => 1,
                    'mention_rank' => $rank + 1,
                    'sentiment' => 'positive',
                    'source_count' => 0,
                    'is_target_brand' => $brand === $targetBrand,
                    'evidence' => 'Ranking answer mentions '.$brand,
                ]);
            }
        }

        return $run;
    }
}
