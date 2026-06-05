<?php

namespace Tests\Feature;

use App\Models\Admin;
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
            ->assertSee('诊断记录')
            ->assertSee('品牌表现')
            ->assertSee('引用来源')
            ->assertSee('AI 对话记录')
            ->assertSee('豆包')
            ->assertSee('DeepSeek')
            ->assertSee('元宝')
            ->assertSee('千问')
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
}
