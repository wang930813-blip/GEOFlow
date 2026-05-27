<?php

namespace App\Jobs;

use App\Models\MediaResourceSyncRun;
use App\Services\MediaDistribution\MediaResourceSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessMediaResourceSyncJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $runId) {}

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'media-resource-sync',
            'media-resource-sync-run:'.$this->runId,
        ];
    }

    public function handle(MediaResourceSyncService $syncService): void
    {
        $run = MediaResourceSyncRun::query()->whereKey($this->runId)->first();
        if (! $run) {
            return;
        }

        $syncService->syncAll($run);
    }

    public function failed(Throwable $exception): void
    {
        MediaResourceSyncRun::query()
            ->whereKey($this->runId)
            ->update([
                'status' => 'failed',
                'last_error_message' => mb_substr($exception->getMessage(), 0, 2000),
                'completed_at' => now(),
            ]);
    }
}
