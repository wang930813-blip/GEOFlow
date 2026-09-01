<?php

namespace Tests\Unit;

use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use Tests\TestCase;

class BrandDiagnosisPlatformTest extends TestCase
{
    public function test_international_brand_diagnosis_platforms_are_limited_to_three_keys(): void
    {
        $this->assertSame(
            ['chatgpt', 'grok', 'gemini'],
            BrandDiagnosisPlatform::publicKeys()
        );

        $this->assertSame('in:chatgpt,grok,gemini', BrandDiagnosisPlatform::publicValidationRule());
    }

    public function test_international_brand_diagnosis_platforms_expose_expected_labels(): void
    {
        $this->assertSame('ChatGPT', BrandDiagnosisPlatform::publicLabel('chatgpt'));
        $this->assertSame('Grok', BrandDiagnosisPlatform::publicLabel('grok'));
        $this->assertSame('Gemini', BrandDiagnosisPlatform::publicLabel('gemini'));

        $this->assertFalse(BrandDiagnosisPlatform::publicIsSupported('doubao'));
        $this->assertFalse(BrandDiagnosisPlatform::publicIsSupported('deepseek'));
        $this->assertFalse(BrandDiagnosisPlatform::publicIsSupported('qianwen'));
        $this->assertFalse(BrandDiagnosisPlatform::publicIsSupported('wenxin'));
    }
}
