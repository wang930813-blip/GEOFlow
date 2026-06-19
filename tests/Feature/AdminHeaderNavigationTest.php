<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminHeaderNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_header_splits_common_top_navigation_from_grouped_module_menu_for_super_admin(): void
    {
        $admin = $this->createAdmin('header_super_admin', 'super_admin');

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $primaryNav = $this->section($html, 'data-admin-primary-nav');
        $moduleMenu = $this->section($html, 'data-admin-module-menu');

        $this->assertStringContainsString(route('admin.dashboard'), $primaryNav);
        $this->assertStringContainsString(route('admin.brand-diagnosis.index'), $primaryNav);
        $this->assertStringContainsString(route('admin.video-generations.index'), $primaryNav);
        $this->assertStringNotContainsString(route('admin.media-distribution.resources.index'), $primaryNav);
        $this->assertStringNotContainsString(route('admin.platform-plans.index'), $primaryNav);

        $this->assertStringContainsString('规格与客户', $moduleMenu);
        $this->assertStringContainsString(route('admin.platform-plans.index'), $moduleMenu);
        $this->assertStringContainsString(route('admin.plan-subscriptions.index'), $moduleMenu);
        $this->assertStringContainsString(route('admin.admin-users.index'), $moduleMenu);
        $this->assertStringContainsString(route('admin.admin-activity-logs'), $moduleMenu);
        $this->assertStringContainsString(route('admin.media-distribution.resources.index'), $moduleMenu);

        $this->assertStringNotContainsString('admin-locale-select', $html);
        $this->assertGreaterThan(
            strpos($html, 'onclick="toggleUserMenu()"'),
            strpos($html, 'onclick="toggleModuleMenu()"')
        );
    }

    public function test_header_module_menu_is_filtered_for_agent_admin(): void
    {
        $admin = $this->createAdmin('header_agent_admin', 'agent_admin');

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $moduleMenu = $this->section($html, 'data-admin-module-menu');

        $this->assertStringContainsString(route('admin.agent-users.index'), $moduleMenu);
        $this->assertStringContainsString(route('admin.plan-usages.index'), $moduleMenu);
        $this->assertStringContainsString(route('admin.media-distribution.resources.index'), $moduleMenu);
        $this->assertStringNotContainsString(route('admin.platform-plans.index'), $moduleMenu);
        $this->assertStringNotContainsString(route('admin.plan-subscriptions.index'), $moduleMenu);
        $this->assertStringNotContainsString(route('admin.admin-users.index'), $moduleMenu);
        $this->assertStringNotContainsString(route('admin.admin-activity-logs'), $moduleMenu);
    }

    public function test_header_module_menu_hides_user_management_for_direct_admin(): void
    {
        $admin = $this->createAdmin('header_direct_admin', 'direct_admin');

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $moduleMenu = $this->section($html, 'data-admin-module-menu');

        $this->assertStringContainsString(route('admin.plan-usages.index'), $moduleMenu);
        $this->assertStringContainsString(route('admin.materials.index'), $moduleMenu);
        $this->assertStringNotContainsString(route('admin.agent-users.index'), $moduleMenu);
        $this->assertStringNotContainsString(route('admin.admin-users.index'), $moduleMenu);
        $this->assertStringNotContainsString(route('admin.platform-plans.index'), $moduleMenu);
        $this->assertStringNotContainsString(route('admin.plan-subscriptions.index'), $moduleMenu);
    }

    public function test_header_module_menu_is_filtered_for_site_user(): void
    {
        $admin = $this->createAdmin('header_site_user', 'site_user');

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->getContent();

        $moduleMenu = $this->section($html, 'data-admin-module-menu');

        $this->assertStringContainsString(route('admin.plan-usages.index'), $moduleMenu);
        $this->assertStringContainsString(route('admin.api-tokens.index'), $moduleMenu);
        $this->assertStringContainsString(route('admin.crebee-accounts.index'), $moduleMenu);
        $this->assertStringContainsString(route('admin.crebee-publish-records.index'), $moduleMenu);
        $this->assertStringNotContainsString(route('admin.platform-plans.index'), $moduleMenu);
        $this->assertStringNotContainsString(route('admin.plan-subscriptions.index'), $moduleMenu);
        $this->assertStringNotContainsString(route('admin.agent-users.index'), $moduleMenu);
        $this->assertStringNotContainsString(route('admin.admin-users.index'), $moduleMenu);
        $this->assertStringNotContainsString(route('admin.admin-activity-logs'), $moduleMenu);
    }

    public function test_self_media_module_menu_highlights_only_current_item(): void
    {
        $admin = $this->createAdmin('header_crebee_active_admin', 'super_admin');

        $recordsHtml = $this->actingAs($admin, 'admin')
            ->get(route('admin.crebee-publish-records.index'))
            ->assertOk()
            ->getContent();

        $recordsModuleMenu = $this->section($recordsHtml, 'data-admin-module-menu');
        $this->assertSame(1, substr_count($recordsModuleMenu, 'admin-menu-item-active'));
        $this->assertMenuRouteActive($recordsModuleMenu, route('admin.crebee-publish-records.index'));
        $this->assertMenuRouteInactive($recordsModuleMenu, route('admin.crebee-accounts.index'));

        $accountsHtml = $this->actingAs($admin, 'admin')
            ->get(route('admin.crebee-accounts.index'))
            ->assertOk()
            ->getContent();

        $accountsModuleMenu = $this->section($accountsHtml, 'data-admin-module-menu');
        $this->assertSame(1, substr_count($accountsModuleMenu, 'admin-menu-item-active'));
        $this->assertMenuRouteActive($accountsModuleMenu, route('admin.crebee-accounts.index'));
        $this->assertMenuRouteInactive($accountsModuleMenu, route('admin.crebee-publish-records.index'));
    }

    public function test_account_module_menu_highlights_only_current_item(): void
    {
        $admin = $this->createAdmin('header_api_tokens_active_admin', 'super_admin');

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.api-tokens.index'))
            ->assertOk()
            ->getContent();

        $moduleMenu = $this->section($html, 'data-admin-module-menu');

        $this->assertSame(1, substr_count($moduleMenu, 'admin-menu-item-active'));
        $this->assertMenuRouteActive($moduleMenu, route('admin.api-tokens.index'));
        $this->assertMenuRouteInactive($moduleMenu, route('admin.admin-users.index'));
        $this->assertMenuRouteInactive($moduleMenu, route('admin.admin-activity-logs'));
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
