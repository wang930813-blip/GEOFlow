<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-10-15
 *
 * @Time: 18:36:21
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： AdminAnalyticsCodeTest.php
 *
 * @Description: 验证后台统计代码的编码保存、权限边界和失败回填行为。
 */

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SiteSetting;
use App\Support\AdminWeb;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAnalyticsCodeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 验证超级管理员可以保存 Base64 传输的统计代码，并在前台页面头部输出。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-15 18:11:00
     *
     * @UpdateTime: 2026-10-15 18:36:21
     *
     * @Param: string $code 统计代码片段
     *
     * @Return void 无返回值
     *
     * @Throws \Throwable 测试请求或断言失败时抛出
     */
    #[DataProvider('analyticsSnippets')]
    public function test_super_admin_can_save_encoded_analytics_and_render_it_in_the_public_head(string $code): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->login();

        $this->post(route('admin.site-settings.update'), $this->payload([
            'analytics_code_base64' => base64_encode($code),
        ]))->assertRedirect(route('admin.site-settings.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('site_settings', ['setting_key' => 'analytics_code', 'setting_value' => $code]);
        $this->get(route('admin.site-settings.index'))->assertSee($code)->assertSee('js/admin-analytics-code.js', false);
        $html = $this->get(route('site.home'))->assertOk()->getContent();
        $headEnd = strpos($html, '</head>');

        $this->assertNotFalse($headEnd);
        $this->assertStringContainsString($code, substr($html, 0, $headEnd));
    }

    /**
     * 提供需要覆盖的统计代码片段。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-15 18:11:00
     *
     * @UpdateTime: 2026-10-15 18:36:21
     *
     * @Return array<int, array<int, string>> 统计代码数据集
     *
     * @Throws void 无
     */
    public static function analyticsSnippets(): array
    {
        return [
            'external script' => ['<script defer src="https://analytics.example.test/script.js"></script>'],
            'multiple scripts and unicode' => ["<!-- 中文统计 -->\n<script>window.analyticsName = '访问统计';</script>"],
        ];
    }

    /**
     * 验证空的 Base64 统计代码可以清除已有内容。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-15 18:11:00
     *
     * @UpdateTime: 2026-10-15 18:36:21
     *
     * @Return void 无返回值
     *
     * @Throws \Throwable 测试请求或断言失败时抛出
     */
    public function test_empty_encoded_analytics_clears_the_existing_snippet(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->login();
        $this->existingCode();

        $this->post(route('admin.site-settings.update'), $this->payload([
            'analytics_code_base64' => '',
        ]))->assertRedirect(route('admin.site-settings.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('site_settings', ['setting_key' => 'analytics_code', 'setting_value' => '']);
        $this->get(route('site.home'))->assertDontSee('<script>existing()</script>', false);
    }

    /**
     * 验证 Base64 字段优先于兼容保留的明文统计代码字段。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-15 18:11:00
     *
     * @UpdateTime: 2026-10-15 18:36:21
     *
     * @Return void 无返回值
     *
     * @Throws \Throwable 测试请求或断言失败时抛出
     */
    public function test_encoded_analytics_takes_precedence_over_the_legacy_field(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->login();
        $code = '<script>encoded()</script>';

        $this->post(route('admin.site-settings.update'), $this->payload([
            'analytics_code_base64' => base64_encode($code),
            'analytics_code' => '<script>legacy()</script>',
        ]))->assertRedirect(route('admin.site-settings.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('site_settings', ['setting_key' => 'analytics_code', 'setting_value' => $code]);
    }

    /**
     * 验证明文统计代码提交仍保持兼容。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-15 18:11:00
     *
     * @UpdateTime: 2026-10-15 18:36:21
     *
     * @Return void 无返回值
     *
     * @Throws \Throwable 测试请求或断言失败时抛出
     */
    public function test_legacy_analytics_submissions_remain_supported(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->login();
        $code = '<script>legacy()</script>';

        $this->post(route('admin.site-settings.update'), $this->payload([
            'analytics_code' => $code,
        ]))->assertRedirect(route('admin.site-settings.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('site_settings', ['setting_key' => 'analytics_code', 'setting_value' => $code]);
    }

    /**
     * 验证普通管理员不能通过编码字段修改统计代码。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-15 18:11:00
     *
     * @UpdateTime: 2026-10-15 18:36:21
     *
     * @Return void 无返回值
     *
     * @Throws \Throwable 测试请求或断言失败时抛出
     */
    public function test_standard_admin_cannot_change_analytics_using_the_encoded_field(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->login('admin');
        $this->existingCode();

        $this->post(route('admin.site-settings.update'), $this->payload([
            'analytics_code_base64' => base64_encode('<script>changed()</script>'),
        ]))->assertRedirect(route('admin.site-settings.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('site_settings', [
            'setting_key' => 'analytics_code',
            'setting_value' => '<script>existing()</script>',
        ]);
    }

    /**
     * 验证未登录用户不能保存编码统计代码。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-15 18:11:00
     *
     * @UpdateTime: 2026-10-15 18:36:21
     *
     * @Return void 无返回值
     *
     * @Throws \Throwable 测试请求或断言失败时抛出
     */
    public function test_guest_cannot_save_encoded_analytics(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->existingCode();

        $this->post(route('admin.site-settings.update'), $this->payload([
            'analytics_code_base64' => base64_encode('<script>changed()</script>'),
        ]))->assertRedirect(route('admin.login'));

        $this->assertDatabaseHas('site_settings', [
            'setting_key' => 'analytics_code',
            'setting_value' => '<script>existing()</script>',
        ]);
    }

    /**
     * 验证非法编码内容会被拒绝且不会覆盖已有设置。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-15 18:11:00
     *
     * @UpdateTime: 2026-10-15 18:36:21
     *
     * @Param: mixed $value 非法编码字段值
     *
     * @Param: string $errorKey 预期错误字段
     *
     * @Return void 无返回值
     *
     * @Throws \Throwable 测试请求或断言失败时抛出
     */
    #[DataProvider('invalidEncodedSnippets')]
    public function test_invalid_encoded_analytics_is_rejected_without_changing_settings(mixed $value, string $errorKey): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->login();
        $this->existingCode();

        $this->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.update'), $this->payload([
                'site_name' => 'Must not save',
                'analytics_code_base64' => $value,
            ]))
            ->assertSessionHasErrors($errorKey);

        $this->assertDatabaseHas('site_settings', [
            'setting_key' => 'analytics_code',
            'setting_value' => '<script>existing()</script>',
        ]);
        $this->assertDatabaseMissing('site_settings', ['setting_key' => 'site_name', 'setting_value' => 'Must not save']);
    }

    /**
     * 提供非法编码数据集。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-15 18:11:00
     *
     * @UpdateTime: 2026-10-15 18:36:21
     *
     * @Return array<int, array<int, mixed>> 非法编码数据集
     *
     * @Throws void 无
     */
    public static function invalidEncodedSnippets(): array
    {
        return [
            'invalid base64' => ['%%%invalid%%%', 'analytics_code'],
            'invalid unicode' => [base64_encode("\xff\xfe"), 'analytics_code'],
            'array instead of string' => [['invalid'], 'analytics_code_base64'],
        ];
    }

    /**
     * 验证其他字段校验失败时会回填解码后的草稿且不会造成管理页面注入。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-15 18:11:00
     *
     * @UpdateTime: 2026-10-15 18:36:21
     *
     * @Return void 无返回值
     *
     * @Throws \Throwable 测试请求或断言失败时抛出
     */
    public function test_validation_error_retains_the_decoded_draft_and_escapes_it_in_the_admin(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->login();
        $this->existingCode();
        $draft = '<script>window.draftAnalytics = "中文";</script></textarea><script>window.adminInjection = true;</script>';

        $this->from(route('admin.site-settings.index'))
            ->post(route('admin.site-settings.update'), $this->payload([
                'site_name' => '',
                'analytics_code_base64' => base64_encode($draft),
            ]))
            ->assertSessionHasErrors('site_name')
            ->assertSessionHasInput('analytics_code', $draft);

        $this->get(route('admin.site-settings.index'))->assertSee($draft)->assertDontSee($draft, false);
        $this->assertDatabaseHas('site_settings', [
            'setting_key' => 'analytics_code',
            'setting_value' => '<script>existing()</script>',
        ]);
    }

    /**
     * 构造登录管理员。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-15 18:11:00
     *
     * @UpdateTime: 2026-10-15 18:36:21
     *
     * @Param: string $role 管理员角色
     *
     * @Return void 无返回值
     *
     * @Throws \Throwable 数据库写入失败时抛出
     */
    private function login(string $role = 'super_admin'): void
    {
        $this->actingAs(Admin::query()->create([
            'username' => 'analytics-editor-'.str()->random(8),
            'password' => 'test-password',
            'role' => $role,
            'status' => 'active',
        ]), 'admin');
    }

    /**
     * 写入已有统计代码。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-15 18:11:00
     *
     * @UpdateTime: 2026-10-15 18:36:21
     *
     * @Return void 无返回值
     *
     * @Throws \Throwable 数据库写入失败时抛出
     */
    private function existingCode(): void
    {
        SiteSetting::query()->create([
            'setting_key' => 'analytics_code',
            'setting_value' => '<script>existing()</script>',
        ]);
    }

    /**
     * 构造站点设置提交数据。
     *
     * @Author: cdkay
     *
     * @CreateTime: 2026-10-15 18:11:00
     *
     * @UpdateTime: 2026-10-15 18:36:21
     *
     * @Param: array<string, mixed> $overrides 覆盖字段
     *
     * @Return array<string, mixed> 请求数据
     *
     * @Throws void 无
     */
    private function payload(array $overrides): array
    {
        return array_replace([
            'site_name' => 'Analytics Test Site',
            'admin_base_path' => AdminWeb::basePath(),
        ], $overrides);
    }
}
