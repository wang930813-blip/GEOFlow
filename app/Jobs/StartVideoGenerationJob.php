<?php

namespace App\Jobs;

use App\Models\VideoGenerationJob;
use App\Services\VideoGeneration\VideoGenerationClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class StartVideoGenerationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $videoGenerationJobId)
    {
        $this->onQueue('default');
    }

    /**
     * @return array<int,int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /**
     * @return array<int,string>
     */
    public function tags(): array
    {
        return [
            'video-generation',
            'video-generation-job:'.$this->videoGenerationJobId,
        ];
    }

    public function handle(VideoGenerationClient $client): void
    {
        $job = VideoGenerationJob::query()->whereKey($this->videoGenerationJobId)->first();
        if (! $job instanceof VideoGenerationJob) {
            return;
        }

        if (! in_array((string) $job->status, ['queued', 'processing'], true) || trim((string) $job->api_task_id) !== '') {
            return;
        }

        $requestPayload = (array) ($job->request_payload ?? []);
        if ($requestPayload === []) {
            $this->markFailed($job, '视频生成任务缺少请求参数');

            return;
        }

        $job->forceFill([
            'status' => 'processing',
            'failure_reason' => null,
            'started_at' => $job->started_at ?: now(),
        ])->save();

        $requestId = 'video-generation:'.$job->owner_admin_id.':'.$job->id;
        $response = $client->createVideo($requestPayload, $requestId);
        $taskId = trim((string) data_get($response, 'data.task_id'));

        $job->forceFill([
            'api_task_id' => $taskId,
            'result_payload' => $response,
            'progress' => 0,
        ])->save();

        PollVideoGenerationJob::dispatch((int) $job->id)
            ->delay(now()->addSeconds(max(1, (int) config('video-generation.poll_interval', 10))));
    }

    public function failed(?Throwable $exception): void
    {
        $job = VideoGenerationJob::query()->whereKey($this->videoGenerationJobId)->first();
        if ($job instanceof VideoGenerationJob && in_array((string) $job->status, ['queued', 'processing'], true)) {
            $this->markFailed($job, $exception?->getMessage() ?: '视频生成任务启动失败');
        }
    }

    private function markFailed(VideoGenerationJob $job, string $reason): void
    {
        $job->forceFill([
            'status' => 'failed',
            'failure_reason' => mb_substr(trim($reason) ?: '视频生成任务启动失败', 0, 2000),
            'finished_at' => now(),
        ])->save();
    }
}
