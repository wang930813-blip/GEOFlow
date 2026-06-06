<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\BrandDiagnosisRun;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBrandDiagnosisPageTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertSee('name="platforms[]"', false)
            ->assertSee('value="doubao"', false)
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
            ->assertSee('placeholder="开始日期"', false)
            ->assertSee('placeholder="结束日期"', false)
            ->assertSee('data-report-count', false)
            ->assertSee('data-record-toggle', false)
            ->assertDontSee('重新搜索')
            ->assertDontSee('_scope', false)
            ->assertSee('is-active font-medium', false);
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

    public function test_materials_entry_moves_from_top_nav_to_user_menu_after_admin_management(): void
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

        $this->assertStringNotContainsString(route('admin.materials.index'), $desktopNav);
        $this->assertStringContainsString(route('admin.materials.index'), $html);
        $this->assertLessThan(
            strpos($html, route('admin.materials.index')),
            strpos($html, route('admin.admin-users.index'))
        );
        $this->assertGreaterThan(
            strpos($html, route('admin.admin-users.index')),
            strpos($html, route('admin.materials.index'))
        );
    }

    public function test_standard_admin_can_access_materials_entry_from_user_menu(): void
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
        $userMenuStart = strpos($html, '<div id="user-menu"');
        $userMenuEnd = strpos($html, '<form method="POST" action="'.route('admin.logout').'"', $userMenuStart);
        $userMenu = substr($html, $userMenuStart, $userMenuEnd - $userMenuStart);

        $this->assertStringNotContainsString(route('admin.materials.index'), $desktopNav);
        $this->assertStringContainsString(route('admin.materials.index'), $userMenu);
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

        $this->assertSame(6, substr_count($html, ' data-source-item>'));
        $this->assertStringContainsString('hidden flex gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3" data-source-item', $html);
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
}
