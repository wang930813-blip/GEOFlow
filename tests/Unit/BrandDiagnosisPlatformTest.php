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
}
