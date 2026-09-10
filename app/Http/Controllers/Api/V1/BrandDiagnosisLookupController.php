<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Requests\Api\V1\BrandDiagnosisLookupRequest;
use App\Models\BrandDiagnosisLookupJob;
use App\Services\BrandDiagnosis\BrandDiagnosisLookupPresenter;
use App\Services\BrandDiagnosis\BrandDiagnosisLookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BrandDiagnosisLookupController extends BaseApiController
{
    public function search(BrandDiagnosisLookupRequest $request, BrandDiagnosisLookupService $service, BrandDiagnosisLookupPresenter $presenter): JsonResponse
    {
        $includes = $request->includedModules();
        $model = $request->modelFilter();
        $brandWord = (string) $request->validated()['brand_word'];

        $stored = $service->findStoredLookup($brandWord, $includes);
        if ($stored !== null) {
            return $this->presentResult($request, $stored, $includes, $presenter, $model);
        }

        $lookup = $service->queueAsyncLookup($brandWord, $includes);
        if ((string) $lookup->status === 'completed') {
            return $this->presentResult($request, $this->generatedResult($lookup), (array) $lookup->includes, $presenter, $model);
        }

        return $this->successWithMeta($request, [
            'lookup_id' => (string) $lookup->lookup_id,
            'brand_word' => (string) $lookup->brand_word,
            'data_source' => 'generated_not_stock',
            'status' => (string) $lookup->status,
            'retry_after' => max(1, (int) config('brand_diagnosis.lookup_api.async_poll_after', 3)),
        ], [
            'included' => (array) $lookup->includes,
            'omitted' => array_values(array_diff(BrandDiagnosisLookupService::MODULES, (array) $lookup->includes)),
        ], 202);
    }

    public function status(Request $request, string $lookupId, BrandDiagnosisLookupPresenter $presenter): JsonResponse
    {
        $lookup = BrandDiagnosisLookupJob::query()
            ->where('lookup_id', trim($lookupId))
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();
        if (! $lookup) {
            throw new ApiException('brand_diagnosis_lookup_not_found', '查询任务不存在或已过期', 404);
        }

        $includes = array_values((array) $lookup->includes);
        if ((string) $lookup->status === 'completed') {
            return $this->presentResult($request, $this->generatedResult($lookup), $includes, $presenter);
        }
        if ((string) $lookup->status === 'failed') {
            throw new ApiException(
                (string) ($lookup->error_code ?: 'brand_profile_provider_failed'),
                (string) ($lookup->error_message ?: '品牌诊断查询任务执行失败'),
                (int) ($lookup->error_status ?: 502)
            );
        }

        return $this->successWithMeta($request, [
            'lookup_id' => (string) $lookup->lookup_id,
            'brand_word' => (string) $lookup->brand_word,
            'data_source' => 'generated_not_stock',
            'status' => (string) $lookup->status,
            'retry_after' => max(1, (int) config('brand_diagnosis.lookup_api.async_poll_after', 3)),
        ], [
            'included' => $includes,
            'omitted' => array_values(array_diff(BrandDiagnosisLookupService::MODULES, $includes)),
        ], 202);
    }

    private function presentResult(Request $request, array $result, array $includes, BrandDiagnosisLookupPresenter $presenter, ?string $model = null): JsonResponse
    {
        $payload = $presenter->present($result, $includes, $model);
        $meta = (array) ($payload['_meta'] ?? []);
        unset($payload['_meta']);

        return $this->successWithMeta($request, $payload, $meta);
    }

    /**
     * @return array{brand_word:string,data_source:string,match_type:string,run:null,generated:array<string,mixed>}
     */
    private function generatedResult(BrandDiagnosisLookupJob $lookup): array
    {
        return [
            'brand_word' => (string) $lookup->brand_word,
            'data_source' => 'generated_not_stock',
            'match_type' => 'none',
            'run' => null,
            'generated' => (array) $lookup->result,
        ];
    }
}
