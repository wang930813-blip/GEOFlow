<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AdminAlibabaCloudThemeTest extends TestCase
{
    public function test_admin_theme_documents_alibaba_cloud_inspired_direction(): void
    {
        $root = dirname(__DIR__, 2);
        $design = file_get_contents($root.'/DESIGN.md');

        $this->assertIsString($design);
        $this->assertStringContainsString('Alibaba Cloud-inspired', $design);
        $this->assertStringContainsString('console-first', $design);
        $this->assertStringContainsString('#ff6a00', $design);
        $this->assertStringContainsString('#0064c8', $design);
    }

    public function test_admin_css_exposes_alibaba_cloud_console_tokens(): void
    {
        $root = dirname(__DIR__, 2);
        $css = file_get_contents($root.'/public/assets/css/admin.css');

        $this->assertIsString($css);
        $this->assertStringContainsString('--aliyun-primary: #ff6a00', $css);
        $this->assertStringContainsString('--aliyun-link: #0064c8', $css);
        $this->assertStringContainsString('--aliyun-console-bg: #f5f7fa', $css);
        $this->assertStringContainsString('--aliyun-radius: 2px', $css);
        $this->assertStringContainsString('admin-topbar', $css);
    }

    public function test_admin_brand_text_has_high_contrast_on_dark_topbar(): void
    {
        $root = dirname(__DIR__, 2);
        $css = file_get_contents($root.'/public/assets/css/admin.css');

        $this->assertIsString($css);
        $this->assertStringContainsString('.admin-topbar .admin-brand-mark', $css);
        $this->assertStringContainsString('.admin-topbar .admin-brand-mark span', $css);
        $this->assertMatchesRegularExpression('/\.admin-topbar\s+\.admin-brand-mark[^{]*\{[^}]*color:\s*#ffffff\s*!important;/s', $css);
    }
}
