<?php

namespace App\Jobs;

use App\Models\SelfMediaPublishJob;
use App\Models\SelfMediaPublishJobItem;
use App\Services\AiToEarn\AiToEarnClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SubmitAiToEarnPublishFlowJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(private readonly int $jobId) {}

    public function handle(AiToEarnClient $client): void
    {
        $job = SelfMediaPublishJob::query()
            ->with('items')
            ->whereKey($this->jobId)
            ->first();

        if (! $job instanceof SelfMediaPublishJob || ! in_array((string) $job->status, ['queued', 'dispatching'], true)) {
            return;
        }

        if ($job->items->isEmpty()) {
            $this->markFailed($job, '没有可提交的自媒体发布平台');

            return;
        }

        $job->forceFill(['status' => 'dispatching'])->save();
        SelfMediaPublishJobItem::query()
            ->where('job_id', (int) $job->id)
            ->where('status', 'queued')
            ->update(['status' => 'dispatching', 'updated_at' => now()]);

        try {
            $payload = $this->flowPayload($job);
            $response = $client->createPublishFlow($payload);
            $this->applySubmittedResponse($job, $response);
        } catch (Throwable $exception) {
            report($exception);
            $this->markFailed($job, 'AiToEarn 发布流程创建失败：'.$exception->getMessage());
        }
    }

    public function failed(Throwable $exception): void
    {
        $job = SelfMediaPublishJob::query()->whereKey($this->jobId)->first();
        if ($job instanceof SelfMediaPublishJob) {
            $this->markFailed($job, 'AiToEarn 发布任务异常：'.$exception->getMessage());
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function flowPayload(SelfMediaPublishJob $job): array
    {
        $payload = (array) $job->payload;
        $payload['items'] = $job->items
            ->map(fn (SelfMediaPublishJobItem $item): array => (array) $item->payload)
            ->values()
            ->all();

        unset($payload['source']);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $response
     */
    private function applySubmittedResponse(SelfMediaPublishJob $job, array $response): void
    {
        $flowId = trim((string) ($response['flowId'] ?? $response['id'] ?? data_get($job->payload, 'flowId', '')));
        $tasks = collect((array) ($response['tasks'] ?? []));

        $job->forceFill([
            'status' => 'submitted',
            'submitted_at' => now(),
            'external_flow_id' => $flowId,
            'raw_response' => $response,
        ])->save();

        $job->load('items');
        foreach ($job->items as $item) {
            $task = $tasks->first(function (mixed $task) use ($item): bool {
                return is_array($task)
                    && trim((string) ($task['accountId'] ?? '')) === (string) $item->external_account_id
                    && trim((string) ($task['platform'] ?? '')) === (string) $item->platform;
            });

            $item->forceFill([
                'status' => 'submitted',
                'external_task_id' => is_array($task) ? trim((string) ($task['id'] ?? '')) : (string) $item->external_task_id,
                'raw_response' => is_array($task) ? $task : [],
                'last_event_at' => now(),
            ])->save();
        }

        if ($flowId !== '') {
            SyncAiToEarnPublishStatusJob::dispatch((int) $job->id)
                ->onQueue('self-media')
                ->delay(now()->addSeconds(max(5, (int) config('aitoearn.status_poll_delay', 30))));
        }
    }

    private function markFailed(SelfMediaPublishJob $job, string $message): void
    {
        $message = mb_substr($message, 0, 2000, 'UTF-8');
        $job->forceFill([
            'status' => 'failed',
            'failure_reason' => $message,
            'finished_at' => now(),
        ])->save();

        SelfMediaPublishJobItem::query()
            ->where('job_id', (int) $job->id)
            ->whereNotIn('status', ['success', 'failed'])
            ->update([
                'status' => 'failed',
                'message' => mb_substr($message, 0, 500, 'UTF-8'),
                'last_event_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
