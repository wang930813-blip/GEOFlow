<?php

namespace App\Jobs;

use App\Models\UrlImportJob;
use App\Models\UrlImportJobLog;
use App\Services\GeoFlow\UrlImportProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessUrlImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $jobId) {}

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'url-import',
            'url-import-job:'.$this->jobId,
        ];
    }

    public function handle(UrlImportProcessingService $service): void
    {
        $job = UrlImportJob::query()->whereKey($this->jobId)->first();
        if (! $job || in_array((string) $job->status, ['completed', 'imported'], true)) {
            return;
        }

        $service->process($job);
    }

    public function failed(?Throwable $exception = null): void
    {
        $job = UrlImportJob::query()->whereKey($this->jobId)->first();
        if (! $job || in_array((string) $job->status, ['completed', 'imported'], true)) {
            return;
        }

        $message = trim((string) ($exception?->getMessage() ?? ''));
        if ($message === '') {
            $message = __('admin.url_import.error.run_failed');
        }

        $job->update([
            'status' => 'failed',
            'progress_percent' => max(1, (int) $job->progress_percent),
            'error_message' => mb_substr($message, 0, 2000),
            'finished_at' => now(),
        ]);

        UrlImportJobLog::query()->create([
            'job_id' => (int) $job->id,
            'step' => (string) ($job->current_step ?: 'queued'),
            'level' => 'error',
            'message' => __('admin.url_import.log.failed', ['message' => $message]),
        ]);
    }
}
