<?php

namespace Tests\Unit;

use App\Services\BrandDiagnosis\BrandDiagnosisLookupService;
use App\Services\BrandDiagnosis\BrandEntityResolver;
use App\Services\BrandDiagnosis\BrandProfileResolver;
use App\Services\BrandDiagnosis\DoubaoBrandDiagnosisClient;
use Tests\TestCase;

class BrandDiagnosisLookupServiceTest extends TestCase
{
    public function test_all_modules_are_selected_when_include_is_empty(): void
    {
        $service = new BrandDiagnosisLookupService(
            app(BrandProfileResolver::class),
            app(DoubaoBrandDiagnosisClient::class),
            app(BrandEntityResolver::class),
        );

        $this->assertSame(
            ['profile', 'questions', 'performance', 'model_results', 'sources', 'snapshots', 'competitors', 'platform_analysis', 'competitor_visibility'],
            $service->normalizeIncludes([])
        );
    }
}
