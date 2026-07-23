<?php

namespace App\Jobs;

use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandDiagnosisRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AutoConfirmBrandDiagnosisRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $runId) {}

    public function handle(BrandDiagnosisRunService $service): void
    {
        $run = BrandDiagnosisRun::query()
            ->withoutGlobalScope('current_site')
            ->with(['questions' => fn ($query) => $query->orderBy('sort_order')])
            ->whereKey($this->runId)
            ->first();

        if (! $run || trim((string) $run->api_task_key) === '') {
            return;
        }

        if (! in_array((string) $run->status, ['questions_ready', 'awaiting_confirmation'], true)) {
            return;
        }

        try {
            $service->confirmForApi($run);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error_message' => mb_strimwidth($exception->getMessage(), 0, 1000, '...', 'UTF-8'),
                'completed_at' => now(),
            ]);
        }
    }
}
