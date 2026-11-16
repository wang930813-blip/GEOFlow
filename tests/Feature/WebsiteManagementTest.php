<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 12:14:13
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： WebsiteManagementTest.php
 * @Description: 验证监测平台官网配置、官网内容后台及客户站点之间的权限隔离。
 */

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Site;
use App\Models\SiteSetting;
use App\Services\MonitoringCenter\MonitorUserAuthService;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class WebsiteManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @Name: test_monitor_website_configuration_is_scoped_to_the_current_owner_site
     * @Description: 通过监测专用令牌读取并变更一个客户站点模板，确认另一个客户的设置保持独立。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 12:14:13
     * @UpdateTime: 2026-11-11 15:22:45
     * @Return void 验证监测配置读写仅作用于令牌所属站点
     * @Throws AssertionFailedError API 响应或租户隔离结果与预期不符
     */
    public function test_monitor_website_configuration_is_scoped_to_the_current_owner_site(): void
    {
        [$owner, $site] = $this->createOwnerSite('website_owner_alpha', 'alpha.example.test');
        [$otherOwner, $otherSite] = $this->createOwnerSite('website_owner_beta', 'beta.example.test');
        $otherTheme = app(SiteThemeCatalog::class)->ids()[0] ?? '';
        $this->assertNotSame('', $otherTheme);

        SiteSetting::query()->create([
            'site_id' => (int) $otherSite->id,
            'owner_admin_id' => (int) $otherOwner->id,
            'setting_key' => 'active_theme',
            'setting_value' => $otherTheme,
        ]);

        $token = $this->monitorToken($owner);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/monitor/website')
            ->assertOk()
            ->assertJsonPath('data.site.id', (int) $site->id)
            ->assertJsonPath('data.domain.system_domain', 'alpha.example.test')
            ->assertJsonPath('data.theme.default_cover_url', '/assets/website-templates/'.config('geoflow.default_theme').'.png');

        $selectedTheme = $this->differentTheme($otherTheme);
        $this->assertNotSame($otherTheme, $selectedTheme);
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/monitor/website', ['active_theme' => $selectedTheme])
            ->assertOk()
            ->assertJsonPath('data.site.id', (int) $site->id)
            ->assertJsonPath('data.theme.active', $selectedTheme);

        $this->assertDatabaseHas('site_settings', [
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'setting_key' => 'active_theme',
            'setting_value' => $selectedTheme,
        ]);
        $this->assertDatabaseHas('site_settings', [
            'site_id' => (int) $otherSite->id,
            'owner_admin_id' => (int) $otherOwner->id,
            'setting_key' => 'active_theme',
            'setting_value' => $otherTheme,
        ]);
    }

    /**
     * @Name: test_theme_covers_exist_and_theme_preview_is_read_only
     * @Description: 确认所有已安装主题均有可访问封面，预览只切换当前响应且不改写已保存主题。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 15:22:45
     * @UpdateTime: 2026-11-11 15:22:45
     * @Return void 验证模板封面完整性及只读预览行为
     * @Throws AssertionFailedError 封面缺失、渲染主题错误或预览改变设置时抛出
     */
    public function test_theme_covers_exist_and_theme_preview_is_read_only(): void
    {
        $catalog = app(SiteThemeCatalog::class);
        $themes = $catalog->all();
        $this->assertNotEmpty($themes);

        foreach ($themes as $theme) {
            $this->assertNotSame('', $theme['cover_url']);
            $this->assertFileExists(public_path(ltrim($theme['cover_url'], '/')));
        }

        [$owner, $site] = $this->createOwnerSite('website_preview_owner', 'preview.example.test');
        $renderableThemes = array_values(array_filter(
            $catalog->ids(),
            static fn (string $themeId): bool => view()->exists('theme.'.$themeId.'.home')
        ));
        $this->assertGreaterThanOrEqual(2, count($renderableThemes));

        $storedTheme = $renderableThemes[0];
        $previewTheme = $renderableThemes[1];
        SiteSetting::query()->create([
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'setting_key' => 'active_theme',
            'setting_value' => $storedTheme,
        ]);

        $this->get('http://'.$site->domain.'/?'.http_build_query(['theme_preview' => $previewTheme]))
            ->assertOk()
            ->assertViewIs('theme.'.$previewTheme.'.home');

        $this->assertDatabaseHas('site_settings', [
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'setting_key' => 'active_theme',
            'setting_value' => $storedTheme,
        ]);

        $this->get('http://'.$site->domain.'/?'.http_build_query(['theme_preview' => 'not-installed-theme']))
            ->assertOk()
            ->assertViewIs('theme.'.$storedTheme.'.home');

        $this->get('http://'.$site->domain.'/?'.http_build_query(['theme_preview' => 'default']))
            ->assertOk()
            ->assertViewIs('theme.'.config('geoflow.default_theme').'.home');
    }

    /**
     * @Name: test_monitor_website_configuration_rejects_non_owner_and_invalid_domain_changes
     * @Description: 确认监测站点成员不能替所有者修改官网配置，并拒绝占用域名和缺少证书的 HTTPS 配置。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 12:14:13
     * @UpdateTime: 2026-11-11 12:17:14
     * @Return void 验证站点配置写入权限及域名证书校验
     * @Throws AssertionFailedError 权限拒绝或校验响应与预期不符
     */
    public function test_monitor_website_configuration_rejects_non_owner_and_invalid_domain_changes(): void
    {
        [$owner, $site] = $this->createOwnerSite('website_owner_guard', 'guard.example.test');
        [, $reservedSite] = $this->createOwnerSite('website_owner_reserved', 'reserved.example.test');
        $member = $this->createAdmin('website_member_guard', 'site_user');
        $site->members()->attach((int) $member->id, ['role' => 'admin']);

        $memberToken = $this->monitorToken($member);
        $this->withHeader('Authorization', 'Bearer '.$memberToken)
            ->putJson('/api/v1/monitor/website', ['active_theme' => app(SiteThemeCatalog::class)->ids()[0]])
            ->assertForbidden();

        $ownerToken = $this->monitorToken($owner);
        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->putJson('/api/v1/monitor/website', ['custom_domain' => (string) $reservedSite->domain])
            ->assertUnprocessable();

        $this->withHeader('Authorization', 'Bearer '.$ownerToken)
            ->putJson('/api/v1/monitor/website', [
                'custom_domain' => 'new-guard.example.test',
                'custom_https_enabled' => true,
            ])
            ->assertUnprocessable();

        $this->assertSame('', (string) $site->fresh()->custom_domain);
    }

    /**
     * @Name: test_monitor_website_admin_sso_uses_the_local_app_origin_for_localhost_domains
     * @Description: 确认开发环境官网后台 SSO 链接使用 APP_URL 的 HTTP 协议及非标准端口。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 16:19:58
     * @UpdateTime: 2026-11-11 16:19:58
     * @Return void 验证 localhost 子域名生成可访问的本地 SSO 地址
     * @Throws AssertionFailedError SSO 地址协议、域名或端口与开发配置不符
     */
    public function test_monitor_website_admin_sso_uses_the_local_app_origin_for_localhost_domains(): void
    {
        [$owner] = $this->createOwnerSite('website_sso_local_owner', '18381955873.localhost');
        config(['app.url' => 'http://localhost:18080']);
        $token = $this->monitorToken($owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/monitor/website-admin/sso');

        $response->assertOk();
        $this->assertStringStartsWith(
            'http://18381955873.localhost:18080/admin/sso/',
            (string) $response->json('data.url')
        );
    }

    /**
     * @Name: test_website_admin_uses_the_requested_domain_and_keeps_content_settings_site_scoped
     * @Description: 验证各客户域名进入各自 /admin，并确认 SEO 设置只更新当前站点及所有者名下的数据。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 12:14:13
     * @UpdateTime: 2026-11-11 18:47:36
     * @Return void 验证官网后台域名解析、配置归属及职责路由边界
     * @Throws AssertionFailedError 页面、路由或配置归属与预期不符
     */
    public function test_website_admin_uses_the_requested_domain_and_keeps_content_settings_site_scoped(): void
    {
        [$owner, $site] = $this->createOwnerSite('website_login_alpha', 'login-alpha.example.test');
        [$otherOwner, $otherSite] = $this->createOwnerSite('website_login_beta', 'login-beta.example.test');
        DB::table('site_settings')->insert([
            'site_id' => (int) $otherSite->id,
            'owner_admin_id' => (int) $otherOwner->id,
            'setting_key' => 'site_description',
            'setting_value' => '第二家官网原有说明',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->get('http://login-alpha.example.test/admin/login')
            ->assertOk()
            ->assertSee('login-alpha.example.test');
        $this->get('http://login-beta.example.test/admin/login')
            ->assertOk()
            ->assertSee('login-beta.example.test');

        $this->post('http://login-alpha.example.test/admin/login', [
            'username' => (string) $owner->username,
            'password' => 'website-secret-123',
        ])->assertRedirect();

        $this->put('http://login-alpha.example.test/admin/settings', [
            'site_name' => 'Alpha 官网',
            'site_description' => 'Alpha 专属站点介绍',
            'settings_tab' => 'seo',
        ])->assertRedirect(route('website-admin.settings', ['tab' => 'seo'], absolute: false));

        $this->assertDatabaseHas('site_settings', [
            'site_id' => (int) $site->id,
            'owner_admin_id' => (int) $owner->id,
            'setting_key' => 'site_description',
            'setting_value' => 'Alpha 专属站点介绍',
        ]);
        $this->assertDatabaseHas('site_settings', [
            'site_id' => (int) $otherSite->id,
            'owner_admin_id' => (int) $otherOwner->id,
            'setting_key' => 'site_description',
            'setting_value' => '第二家官网原有说明',
        ]);

        $this->get('http://login-alpha.example.test/admin/domain')->assertNotFound();
        $this->get('http://login-alpha.example.test/admin/themes')->assertNotFound();
    }

    /**
     * @Name: createOwnerSite
     * @Description: 建立带独立 GEO 所有者和站点成员关系的测试站点，避免不同测试复用租户数据。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 12:14:13
     * @UpdateTime: 2026-11-11 12:17:14
     * @Param string $username 唯一 GEO 登录账号
     * @Param string $domain 当前客户官网默认域名
     * @Return array{0:Admin,1:Site} 已创建的 GEO 所有者及其站点
     * @Throws QueryException 测试数据违反数据库约束时抛出
     */
    private function createOwnerSite(string $username, string $domain): array
    {
        $owner = $this->createAdmin($username, 'direct_admin');
        $site = Site::query()->create([
            'owner_admin_id' => (int) $owner->id,
            'name' => $username.' 官网',
            'domain' => $domain,
            'status' => 'active',
        ]);
        $site->members()->attach((int) $owner->id, ['role' => 'owner']);

        return [$owner, $site];
    }

    /**
     * @Name: createAdmin
     * @Description: 创建通过新版监测中心及官网内容后台认证的独立账号。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 12:14:13
     * @UpdateTime: 2026-11-11 12:17:14
     * @Param string $username 唯一登录账号
     * @Param string $role GEO 账号角色
     * @Return Admin 已创建的 GEO 管理账号
     * @Throws QueryException 测试账号违反数据库约束时抛出
     */
    private function createAdmin(string $username, string $role): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'website-secret-123',
            'email' => $username.'@example.test',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
    }

    /**
     * @Name: monitorToken
     * @Description: 使用正式的监测认证服务为测试账号签发短期访问令牌。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 12:14:13
     * @UpdateTime: 2026-11-11 12:17:14
     * @Param Admin $admin 具有站点成员关系的 GEO 账号
     * @Return string 监测中心专用访问令牌
     * @Throws ApiException 账号没有可访问站点时抛出
     */
    private function monitorToken(Admin $admin): string
    {
        $auth = app(MonitorUserAuthService::class)->login(
            (string) $admin->username,
            'website-secret-123',
            '127.0.0.1'
        );

        return (string) $auth['token'];
    }

    /**
     * @Name: differentTheme
     * @Description: 从已安装模板目录中选出与另一站点当前模板不同的有效主题标识。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 12:14:13
     * @UpdateTime: 2026-11-11 12:21:00
     * @Param string $currentTheme 另一站点当前模板 ID
     * @Return string 当前站点需要应用的不同模板 ID
     * @Throws AssertionFailedError 当前系统没有可用于测试的模板
     */
    private function differentTheme(string $currentTheme): string
    {
        $themes = app(SiteThemeCatalog::class)->ids();
        if (count($themes) < 2) {
            throw new \LogicException('至少需要两个已安装模板才能验证站点隔离');
        }

        foreach ($themes as $theme) {
            if ($theme !== $currentTheme) {
                return $theme;
            }
        }

        throw new \LogicException('当前站点模板不在已安装模板目录中');
    }
}
