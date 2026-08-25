<?php

namespace Tests\Unit;

use App\Services\BrandDiagnosis\BrandDiagnosisApiTaskKey;
use Tests\TestCase;

class BrandDiagnosisApiTaskKeyTest extends TestCase
{
    public function test_task_key_is_opaque_prefixed_and_validatable(): void
    {
        $service = new BrandDiagnosisApiTaskKey;

        $key = $service->generate(123);

        $this->assertMatchesRegularExpression('/^bdg_[a-f0-9]{32}$/', $key);
        $this->assertTrue($service->isValid($key));
        $this->assertFalse($service->isValid('123'));
        $this->assertFalse($service->isValid('bdg_invalid'));
        $this->assertStringNotContainsString('123', $key);
    }
}
