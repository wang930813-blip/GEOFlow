<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminFrontendRoleIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @Name: test_public_welcome_entry_opens_monitoring_center
     *
     * @Description: 验证未配置专用官网主机时，根页面默认把前端用户引导到新版监测中心。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-04 17:57:44
     *
     * @UpdateTime: 2026-11-04 21:10:31
     *
     * @Return: void 断言根页面不暴露后台入口
     *
     * @Throws: PHPUnit\\Framework\\AssertionFailedError 页面入口与预期不符
     */
    public function test_public_welcome_entry_opens_monitoring_center(): void
    {
        $html = view('welcome')->render();

        $this->assertStringContainsString(url('/monitor/'), $html);
        $this->assertStringContainsString('进入监测中心', $html);
        $this->assertStringNotContainsString('geo_admin', $html);
    }

    /**
     * @Name: test_frontend_account_roles_cannot_sign_in_to_admin_web
     *
     * @Description: 验证直客和站点普通账号不能通过旧后台表单建立管理员会话，并被送往监测中心。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-04 17:57:44
     *
     * @UpdateTime: 2026-11-04 17:57:44
     *
     * @Return: void 断言两个前端角色均未认证为后台管理员
     *
     * @Throws: PHPUnit\\Framework\\AssertionFailedError 登录边界与预期不符
     */
    public function test_frontend_account_roles_cannot_sign_in_to_admin_web(): void
    {
        foreach (['direct_admin', 'site_user'] as $index => $role) {
            Admin::query()->create([
                'username' => 'frontend_'.$role,
                'password' => 'secret-123',
                'email' => 'frontend-'.$index.'@example.com',
                'display_name' => 'Frontend User',
                'role' => $role,
                'status' => 'active',
            ]);

            $this->post(route('admin.login.attempt'), [
                'username' => 'frontend_'.$role,
                'password' => 'secret-123',
            ])
                ->assertRedirect('/monitor/login/');

            $this->assertGuest('admin');
        }
    }

    /**
     * @Name: test_frontend_account_session_is_cleared_when_opening_admin_page
     *
     * @Description: 验证已有前端角色后台会话访问管理页面时会注销并重定向到监测中心。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-04 17:57:44
     *
     * @UpdateTime: 2026-11-04 17:57:44
     *
     * @Return: void 断言前端角色会话已清除
     *
     * @Throws: PHPUnit\\Framework\\AssertionFailedError 会话隔离与预期不符
     */
    public function test_frontend_account_session_is_cleared_when_opening_admin_page(): void
    {
        $admin = Admin::query()->create([
            'username' => 'frontend_existing_session',
            'password' => 'secret-123',
            'email' => 'frontend-existing@example.com',
            'display_name' => 'Frontend User',
            'role' => 'site_user',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertRedirect('/monitor/login/');

        $this->assertGuest('admin');
    }

    /**
     * @Name: test_backend_admin_can_still_sign_in_through_manual_admin_url
     *
     * @Description: 验证后台管理员仍可手动打开旧入口并建立后台会话。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-04 17:57:44
     *
     * @UpdateTime: 2026-11-04 17:57:44
     *
     * @Return: void 断言后台管理员会话认证成功
     *
     * @Throws: PHPUnit\\Framework\\AssertionFailedError 后台管理员入口与预期不符
     */
    public function test_backend_admin_can_still_sign_in_through_manual_admin_url(): void
    {
        $admin = Admin::query()->create([
            'username' => 'backend_manual_login',
            'password' => 'secret-123',
            'email' => 'backend-manual@example.com',
            'display_name' => 'Backend Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->post(route('admin.login.attempt'), [
            'username' => (string) $admin->username,
            'password' => 'secret-123',
        ])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    /**
     * @Name: test_official_homepage_and_runtime_chunks_do_not_expose_admin_entry
     *
     * @Description: 验证官网首页与客户端运行时代码均不再包含旧后台入口，主按钮改指向监测中心。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-11-04 17:57:44
     *
     * @UpdateTime: 2026-11-04 17:57:44
     *
     * @Return: void 断言公开静态资源仅指向监测中心入口
     *
     * @Throws: PHPUnit\\Framework\\AssertionFailedError 官网静态入口仍包含后台链接
     */
    public function test_official_homepage_and_runtime_chunks_do_not_expose_admin_entry(): void
    {
        $homepagePath = public_path('ceying-geo-static/index.html');
        $runtimeChunks = glob(public_path('ceying-geo-static/_next/static/chunks/*.js')) ?: [];
        $files = array_merge([$homepagePath], $runtimeChunks);

        foreach ($files as $file) {
            $content = file_get_contents($file);

            $this->assertIsString($content, 'Unable to read public frontend file: '.$file);
            $this->assertStringNotContainsString('geo_admin/', $content, 'Public frontend links to the admin entry: '.$file);
        }

        $this->assertStringContainsString('href="/monitor/"', file_get_contents($homepagePath));
    }
}
