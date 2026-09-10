<?php

namespace App\Jobs;

use App\Exceptions\ApiException;
use App\Models\BrandDiagnosisLookupJob as BrandDiagnosisLookupRecord;
use App\Services\BrandDiagnosis\BrandDiagnosisLookupService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateBrandDiagnosisLookupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $lookupJobId)
    {
        $this->timeout = max(180, min(900, (int) config('brand_diagnosis.lookup_api.async_job_timeout', 300)));
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'brand-diagnosis-lookup',
            'brand-diagnosis-lookup-job:'.$this->lookupJobId,
        ];
    }

    public function handle(BrandDiagnosisLookupService $service): void
    {
        $lookup = BrandDiagnosisLookupRecord::query()->whereKey($this->lookupJobId)->first();
        if (! $lookup || in_array((string) $lookup->status, ['completed', 'failed'], true)) {
            return;
        }

        $lookup->forceFill([
            'status' => 'processing',
            'attempts' => (int) $lookup->attempts + 1,
            'started_at' => $lookup->started_at ?? now(),
            'error_code' => null,
            'error_status' => null,
            'error_message' => null,
        ])->save();

        try {
            $result = $service->generatePreviewForLookup((string) $lookup->brand_word);
            $lookup->forceFill([
                'status' => 'completed',
                'result' => $result,
                'completed_at' => now(),
            ])->save();
        } catch (ApiException $exception) {
            $this->markFailed($lookup, $exception->getErrorCode(), $exception->getHttpStatus(), $exception->getMessage());
        } catch (Throwable $exception) {
            $this->markFailed($lookup, 'brand_profile_provider_failed', 502, '品牌介绍核实服务暂不可用');
        }
    }

    public function failed(?Throwable $exception): void
    {
        $lookup = BrandDiagnosisLookupRecord::query()->whereKey($this->lookupJobId)->first();
        if (! $lookup || (string) $lookup->status === 'completed') {
            return;
        }

        $this->markFailed($lookup, 'brand_profile_provider_failed', 502, '品牌诊断查询任务执行失败');
    }

    private function markFailed(BrandDiagnosisLookupRecord $lookup, string $code, int $status, string $message): void
    {
        $lookup->forceFill([
            'status' => 'failed',
            'error_code' => $code,
            'error_status' => $status,
            'error_message' => mb_strimwidth($message, 0, 1000, '...', 'UTF-8'),
            'completed_at' => now(),
        ])->save();
    }
}
