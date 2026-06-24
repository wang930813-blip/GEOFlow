<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AdminHeaderNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_shows_grouped_top_navigation_and_single_user_menu_for_super_admin(): void
    {
        Config::set('geoflow.operation_guide_url', 'https://guide.example.test/start');

        $admin = $this->createAdmin('header_super_admin', 'super_admin');

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $primaryNav = $this->section($html, 'data-admin-primary-nav');
        $userMenu = $this->section($html, 'data-admin-user-menu');

        $this->assertStringContainsString(route('admin.dashboard'), $primaryNav);
        $this->assertStringContainsString('https://guide.example.test/start', $primaryNav);
        $this->assertStringContainsString('target="_blank"', $primaryNav);
        $this->assertStringContainsString('全域数析', $primaryNav);
        $this->assertStringContainsString(route('admin.brand-diagnosis.index'), $primaryNav);
        $this->assertStringContainsString(route('admin.monitoring-center.index'), $primaryNav);
        $this->assertStringContainsString('官媒发布', $primaryNav);
        $this->assertStringContainsString(route('admin.media-distribution.resources.index'), $primaryNav);
        $this->assertStringContainsString(route('admin.crebee-accounts.index'), $primaryNav);
        $this->assertStringContainsString(route('admin.video-generations.index'), $primaryNav);
        $this->assertStringNotContainsString(route('admin.platform-plans.index'), $primaryNav);

        $this->assertStringContainsString(route('admin.profile.index'), $userMenu);
        $this->assertStringContainsString(route('admin.security-settings.password.edit'), $userMenu);
        $this->assertStringContainsString('data-account-menu-group="operations"', $userMenu);
        $this->assertStringContainsString('data-account-menu-group="accounts"', $userMenu);
        $this->assertStringContainsString(route('admin.ai.configurator'), $userMenu);
        $this->assertStringContainsString(route('admin.site-settings.index'), $userMenu);
        $this->assertStringContainsString(route('admin.sites.manage.index'), $userMenu);
        $this->assertStringContainsString(route('admin.platform-plans.index'), $userMenu);
        $this->assertStringContainsString(route('admin.plan-subscriptions.index'), $userMenu);
        $this->assertStringContainsString(route('admin.admin-users.index'), $userMenu);
        $this->assertStringContainsString(route('admin.admin-activity-logs'), $userMenu);
        $this->assertStringNotContainsString(route('admin.api-tokens.index'), $userMenu);
        $this->assertStringNotContainsString('data-admin-module-menu', $html);
        $this->assertStringContainsString('管理', $html);

        $this->assertStringNotContainsString('admin-locale-select', $html);
        $this->assertStringNotContainsString('onclick="toggleModuleMenu()"', $html);
    }

    public function test_header_user_menu_is_filtered_for_agent_admin(): void
    {
        $admin = $this->createAdmin('header_agent_admin', 'agent_admin');

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $userMenu = $this->section($html, 'data-admin-user-menu');

        $this->assertStringContainsString(route('admin.profile.index'), $userMenu);
        $this->assertStringContainsString(route('admin.security-settings.password.edit'), $userMenu);
        $this->assertStringContainsString('data-account-menu-group="operations"', $userMenu);
        $this->assertStringContainsString(route('admin.ai.configurator'), $userMenu);
        $this->assertStringNotContainsString(route('admin.site-settings.index'), $userMenu);
        $this->assertStringContainsString('data-account-menu-group="accounts"', $userMenu);
        $this->assertStringContainsString(route('admin.sites.manage.index'), $userMenu);
        $this->assertStringContainsString(route('admin.agent-users.index'), $userMenu);
        $this->assertStringContainsString(route('admin.plan-usages.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.platform-plans.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.plan-subscriptions.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.admin-users.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.admin-activity-logs'), $userMenu);
        $this->assertStringNotContainsString(route('admin.api-tokens.index'), $userMenu);
        $this->assertStringContainsString('代理', $html);
    }

    public function test_header_user_menu_hides_management_items_for_direct_admin(): void
    {
        $admin = $this->createAdmin('header_direct_admin', 'direct_admin');

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $userMenu = $this->section($html, 'data-admin-user-menu');

        $this->assertStringContainsString(route('admin.profile.index'), $userMenu);
        $this->assertStringContainsString(route('admin.security-settings.password.edit'), $userMenu);
        $this->assertStringContainsString('data-account-menu-group="operations"', $userMenu);
        $this->assertStringNotContainsString(route('admin.ai.configurator'), $userMenu);
        $this->assertStringContainsString(route('admin.site-settings.index'), $userMenu);
        $this->assertStringContainsString('data-account-menu-group="accounts"', $userMenu);
        $this->assertStringContainsString(route('admin.plan-usages.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.agent-users.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.admin-users.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.platform-plans.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.plan-subscriptions.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.admin-activity-logs'), $userMenu);
        $this->assertStringNotContainsString(route('admin.api-tokens.index'), $userMenu);
        $this->assertStringContainsString('会员', $html);
    }

    public function test_header_user_menu_is_filtered_for_site_user(): void
    {
        $admin = $this->createAdmin('header_site_user', 'site_user');

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $userMenu = $this->section($html, 'data-admin-user-menu');

        $this->assertStringContainsString(route('admin.profile.index'), $userMenu);
        $this->assertStringContainsString(route('admin.security-settings.password.edit'), $userMenu);
        $this->assertStringContainsString('data-account-menu-group="operations"', $userMenu);
        $this->assertStringNotContainsString(route('admin.ai.configurator'), $userMenu);
        $this->assertStringContainsString(route('admin.site-settings.index'), $userMenu);
        $this->assertStringContainsString('data-account-menu-group="accounts"', $userMenu);
        $this->assertStringContainsString(route('admin.plan-usages.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.api-tokens.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.platform-plans.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.plan-subscriptions.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.agent-users.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.admin-users.index'), $userMenu);
        $this->assertStringNotContainsString(route('admin.admin-activity-logs'), $userMenu);
        $this->assertStringContainsString('会员', $html);
    }

    public function test_self_media_top_menu_links_account_binding_and_records_entry(): void
    {
        $admin = $this->createAdmin('header_crebee_active_admin', 'super_admin');

        $accountsHtml = $this->actingAs($admin, 'admin')
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->getContent();

        $primaryNav = $this->section($accountsHtml, 'data-admin-primary-nav');
        $this->assertStringContainsString(route('admin.crebee-accounts.index'), $primaryNav);
        $this->assertStringNotContainsString(route('admin.crebee-publish-records.index'), $primaryNav);
        $this->assertStringContainsString(route('admin.crebee-publish-records.index'), $accountsHtml);
        $this->assertMenuRouteActive($primaryNav, route('admin.crebee-accounts.index'));
    }

    public function test_top_navigation_group_dropdowns_are_hover_driven(): void
    {
        $admin = $this->createAdmin('header_hover_nav_admin', 'super_admin');

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $primaryNav = $this->section($html, 'data-admin-primary-nav');
        $adminCss = file_get_contents(public_path('assets/css/admin.css')) ?: '';

        $this->assertStringContainsString('data-admin-nav-group', $primaryNav);
        $this->assertStringContainsString('data-admin-nav-dropdown', $primaryNav);
        $this->assertStringContainsString('.admin-nav-group:hover .admin-nav-dropdown', $adminCss);
        $this->assertStringContainsString('.admin-nav-group:focus-within .admin-nav-dropdown', $adminCss);
        $this->assertStringNotContainsString('group-hover:', $primaryNav);
    }

    public function test_profile_and_monitoring_center_pages_are_accessible(): void
    {
        $admin = $this->createAdmin('header_profile_admin', 'super_admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.profile.index'))
            ->assertOk()
            ->assertSee('个人中心')
            ->assertSee('平台数据融合');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.monitoring-center.index'))
            ->assertOk()
            ->assertSee('企业舆情分析报表')
            ->assertSee('行业竞争力分析报表');
    }

    public function test_api_token_page_is_not_in_navigation_even_if_route_still_exists(): void
    {
        $admin = $this->createAdmin('header_api_tokens_active_admin', 'super_admin');

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.api-tokens.index'))
            ->assertOk()
            ->getContent();

        $userMenu = $this->section($html, 'data-admin-user-menu');
        $this->assertStringNotContainsString(route('admin.api-tokens.index'), $userMenu);
    }

    public function test_agent_and_site_user_can_open_password_change_page(): void
    {
        foreach (['agent_admin', 'site_user'] as $role) {
            $admin = $this->createAdmin('header_password_'.$role, $role);

            $this->actingAs($admin, 'admin')
                ->get(route('admin.security-settings.password.edit'))
                ->assertOk()
                ->assertSee('name="current_password"', false)
                ->assertSee('name="new_password"', false)
                ->assertSee('name="confirm_password"', false);
        }
    }

    private function createAdmin(string $username, string $role): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function section(string $html, string $attribute): string
    {
        $start = strpos($html, $attribute);
        $this->assertNotFalse($start, "Missing {$attribute} section.");

        $end = strpos($html, 'data-admin-section-end', (int) $start);
        $this->assertNotFalse($end, "Missing {$attribute} section end.");

        return substr($html, (int) $start, (int) $end - (int) $start);
    }

    private function assertMenuRouteActive(string $html, string $route): void
    {
        $this->assertMatchesRegularExpression(
            '/<a\s+href="'.preg_quote($route, '/').'"\s+class="[^"]*admin-menu-item-active/',
            $html
        );
    }

    private function assertMenuRouteInactive(string $html, string $route): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/<a\s+href="'.preg_quote($route, '/').'"\s+class="[^"]*admin-menu-item-active/',
            $html
        );
    }
}
