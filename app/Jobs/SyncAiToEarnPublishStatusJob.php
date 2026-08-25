<?php

namespace App\Jobs;

use App\Models\SelfMediaPublishEvent;
use App\Models\SelfMediaPublishJob;
use App\Models\SelfMediaPublishJobItem;
use App\Services\AiToEarn\AiToEarnClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncAiToEarnPublishStatusJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(private readonly int $jobId) {}

    public function handle(AiToEarnClient $client): void
    {
        $job = SelfMediaPublishJob::query()
            ->with('items')
            ->whereKey($this->jobId)
            ->first();

        if (! $job instanceof SelfMediaPublishJob || (string) $job->external_flow_id === '') {
            return;
        }

        if (in_array((string) $job->status, ['success', 'failed', 'partial_success', 'cancelled'], true)) {
            return;
        }

        try {
            $response = $client->publishFlow((string) $job->external_flow_id);
            $this->applyFlowStatus($job, $response);
        } catch (Throwable $exception) {
            report($exception);
            $this->recordSyncFailure($job, $exception->getMessage());
        }
    }

    public function failed(Throwable $exception): void
    {
        $job = SelfMediaPublishJob::query()->whereKey($this->jobId)->first();
        if ($job instanceof SelfMediaPublishJob) {
            $this->recordSyncFailure($job, $exception->getMessage());
        }
    }

    /**
     * @param  array<string,mixed>  $response
     */
    private function applyFlowStatus(SelfMediaPublishJob $job, array $response): void
    {
        $tasks = collect((array) ($response['tasks'] ?? []));
        $job->load('items');

        foreach ($job->items as $item) {
            $task = $tasks->first(function (mixed $task) use ($item): bool {
                if (! is_array($task)) {
                    return false;
                }

                $taskId = trim((string) ($task['id'] ?? ''));
                if ($taskId !== '' && $taskId === (string) $item->external_task_id) {
                    return true;
                }

                return trim((string) ($task['accountId'] ?? '')) === (string) $item->external_account_id
                    && trim((string) ($task['platform'] ?? '')) === (string) $item->platform;
            });

            if (! is_array($task)) {
                continue;
            }

            $status = $this->itemStatus($task);
            $message = $this->message($task);
            $publishedUrl = trim((string) ($task['workLink'] ?? ''));
            $publishedAt = $status === 'success' ? now() : $item->published_at;

            $item->forceFill([
                'status' => $status,
                'progress' => $status === 'success' ? 100 : (int) $item->progress,
                'message' => mb_substr($message, 0, 500, 'UTF-8'),
                'published_url' => $publishedUrl !== '' ? $publishedUrl : (string) $item->published_url,
                'published_at' => $publishedAt,
                'raw_response' => $task,
                'last_event_at' => now(),
            ])->save();

            SelfMediaPublishEvent::query()->create([
                'job_id' => (int) $job->id,
                'job_item_id' => (int) $item->id,
                'provider' => 'aitoearn',
                'external_task_id' => trim((string) ($task['id'] ?? $item->external_task_id)),
                'event_type' => $status,
                'progress' => $status === 'success' ? 100 : null,
                'message' => mb_substr($message, 0, 500, 'UTF-8'),
                'raw_event' => $task,
                'created_at' => now(),
            ]);
        }

        $job->forceFill([
            'sync_attempts' => (int) $job->sync_attempts + 1,
            'raw_response' => $response,
        ])->save();

        $this->refreshFinalJobStatus($job);
    }

    private function recordSyncFailure(SelfMediaPublishJob $job, string $message): void
    {
        $attempts = (int) $job->sync_attempts + 1;
        $maxAttempts = max(1, (int) config('aitoearn.status_max_attempts', 40));

        $job->forceFill([
            'sync_attempts' => $attempts,
            'failure_reason' => mb_substr('AiToEarn 发布状态同步失败：'.$message, 0, 2000, 'UTF-8'),
        ])->save();

        if ($attempts >= $maxAttempts) {
            $job->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
            ])->save();

            SelfMediaPublishJobItem::query()
                ->where('job_id', (int) $job->id)
                ->whereNotIn('status', ['success', 'failed'])
                ->update([
                    'status' => 'failed',
                    'message' => mb_substr((string) $job->failure_reason, 0, 500, 'UTF-8'),
                    'last_event_at' => now(),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * @param  array<string,mixed>  $task
     */
    private function itemStatus(array $task): string
    {
        if (trim((string) ($task['workLink'] ?? '')) !== '' || (string) ($task['linkStatus'] ?? '') === 'ready') {
            return 'success';
        }

        if (trim((string) ($task['errorMsg'] ?? $task['linkError'] ?? '')) !== '') {
            return 'failed';
        }

        $status = $task['status'] ?? null;
        if (is_numeric($status) && (int) $status < 0) {
            return 'failed';
        }

        return 'publishing';
    }

    /**
     * @param  array<string,mixed>  $task
     */
    private function message(array $task): string
    {
        $message = trim((string) ($task['errorMsg'] ?? $task['linkError'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        return $this->itemStatus($task) === 'success' ? '发布成功' : '发布中';
    }

    private function refreshFinalJobStatus(SelfMediaPublishJob $job): void
    {
        $job->load('items');
        $statuses = $job->items->pluck('status')->map(fn ($status): string => (string) $status)->all();
        $successCount = count(array_filter($statuses, fn (string $status): bool => $status === 'success'));
        $failedCount = count(array_filter($statuses, fn (string $status): bool => $status === 'failed'));
        $total = count($statuses);

        $status = match (true) {
            $total > 0 && $successCount === $total => 'success',
            $total > 0 && $failedCount === $total => 'failed',
            $successCount > 0 && $failedCount > 0 => 'partial_success',
            default => 'publishing',
        };

        $job->forceFill([
            'status' => $status,
            'finished_at' => in_array($status, ['success', 'failed', 'partial_success'], true) ? now() : null,
        ])->save();

        if ($status === 'publishing' && (int) $job->sync_attempts < max(1, (int) config('aitoearn.status_max_attempts', 40))) {
            self::dispatch((int) $job->id)
                ->onQueue('self-media')
                ->delay(now()->addSeconds(max(5, (int) config('aitoearn.status_poll_delay', 30))));
        }
    }
}
