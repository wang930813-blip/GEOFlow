<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreBrandDiagnosisRequest;
use App\Services\BrandDiagnosis\BrandDiagnosisApiResultPresenter;
use App\Services\BrandDiagnosis\BrandDiagnosisApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandDiagnosisController extends BaseApiController
{
    public function store(StoreBrandDiagnosisRequest $request, BrandDiagnosisApiService $service, BrandDiagnosisApiResultPresenter $presenter): JsonResponse
    {
        $validated = $request->validated();
        $run = $service->create(
            (string) $validated['brand_name'],
            (array) $validated['models']
        );

        return $this->success($request, $presenter->summary($run), 201);
    }

    public function show(Request $request, string $taskKey, BrandDiagnosisApiService $service, BrandDiagnosisApiResultPresenter $presenter): JsonResponse
    {
        return $this->success($request, $presenter->detail($service->findByTaskKey($taskKey)));
    }
}
