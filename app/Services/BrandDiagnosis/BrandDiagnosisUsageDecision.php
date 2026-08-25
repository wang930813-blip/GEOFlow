<?php

namespace App\Services\BrandDiagnosis;

class BrandDiagnosisUsageDecision
{
    public function __construct(
        public readonly string $billingMode,
        public readonly bool $limitBypassed,
        public readonly string $limitBypassReason,
    ) {}
}
