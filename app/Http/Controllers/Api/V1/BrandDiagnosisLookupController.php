<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\BrandDiagnosisLookupRequest;
use App\Services\BrandDiagnosis\BrandDiagnosisLookupPresenter;
use App\Services\BrandDiagnosis\BrandDiagnosisLookupService;
use Illuminate\Http\JsonResponse;

final class BrandDiagnosisLookupController extends BaseApiController
{
    public function search(BrandDiagnosisLookupRequest $request, BrandDiagnosisLookupService $service, BrandDiagnosisLookupPresenter $presenter): JsonResponse
    {
        $includes = $request->includedModules();
        $result = $service->lookup((string) $request->validated()['brand_word'], $includes);

        $payload = $presenter->present($result, $includes);
        $meta = (array) ($payload['_meta'] ?? []);
        unset($payload['_meta']);

        return $this->successWithMeta($request, $payload, $meta);
    }
}
