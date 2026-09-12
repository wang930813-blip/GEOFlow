<?php

namespace Tests\Unit;

use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use Tests\TestCase;

class BrandDiagnosisPlatformTest extends TestCase
{
    public function test_international_brand_diagnosis_platforms_include_claude(): void
    {
        $this->assertSame(
            ['chatgpt', 'grok', 'gemini', 'claude'],
            BrandDiagnosisPlatform::publicKeys()
        );

        $this->assertSame('in:chatgpt,grok,gemini,claude', BrandDiagnosisPlatform::publicValidationRule());
    }

    public function test_international_brand_diagnosis_platforms_expose_expected_labels(): void
    {
        $this->assertSame('ChatGPT', BrandDiagnosisPlatform::publicLabel('chatgpt'));
        $this->assertSame('Grok', BrandDiagnosisPlatform::publicLabel('grok'));
        $this->assertSame('Gemini', BrandDiagnosisPlatform::publicLabel('gemini'));
        $this->assertSame('Claude', BrandDiagnosisPlatform::publicLabel('claude'));
        $this->assertSame('CL', BrandDiagnosisPlatform::publicIcon('claude'));
        $this->assertSame('https://claude.ai/', BrandDiagnosisPlatform::publicChatUrl('claude'));
        $this->assertSame(['claude.ai', 'anthropic.com'], BrandDiagnosisPlatform::publicOfficialShareDomains('claude'));

        $this->assertFalse(BrandDiagnosisPlatform::publicIsSupported('doubao'));
        $this->assertFalse(BrandDiagnosisPlatform::publicIsSupported('deepseek'));
        $this->assertFalse(BrandDiagnosisPlatform::publicIsSupported('qianwen'));
        $this->assertFalse(BrandDiagnosisPlatform::publicIsSupported('wenxin'));
    }

    public function test_international_brand_diagnosis_platform_logos_use_static_site_svg_assets(): void
    {
        $expected = [
            'chatgpt' => 'ceying-geo-static/ai-platforms/chatgpt.svg',
            'grok' => 'ceying-geo-static/ai-platforms/grok.svg',
            'gemini' => 'ceying-geo-static/ai-platforms/gemini.svg',
            'claude' => 'ceying-geo-static/ai-platforms/claude.svg',
        ];

        foreach ($expected as $platform => $path) {
            $this->assertSame($path, BrandDiagnosisPlatform::logoPath($platform));
            $this->assertSame($path, BrandDiagnosisPlatform::publicLogoPath($platform));
            $this->assertFileExists(BrandDiagnosisPlatform::logoAbsolutePath($platform));
            $this->assertFileExists(BrandDiagnosisPlatform::publicLogoAbsolutePath($platform));
        }
    }

    public function test_grok_logo_asset_is_not_the_legacy_x_mark(): void
    {
        $svg = file_get_contents(BrandDiagnosisPlatform::logoAbsolutePath('grok'));
        $staticSiteSourceSvg = file_get_contents(public_path('ceying-geo-static/ai-platforms/x.svg'));

        $this->assertIsString($svg);
        $this->assertStringContainsString('data-logo="grok-wordmark"', $svg);
        $this->assertStringNotContainsString('M14.234 10.162 22.977 0', $svg);
        $this->assertIsString($staticSiteSourceSvg);
        $this->assertStringContainsString('data-logo="grok-wordmark"', $staticSiteSourceSvg);
        $this->assertStringNotContainsString('M14.234 10.162 22.977 0', $staticSiteSourceSvg);
    }
}
