<?php

namespace App\Jobs;

use App\Models\VideoGenerationJob;
use App\Services\VideoGeneration\VideoGenerationClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class PollVideoGenerationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * @var array<int,int>
     */
    public array $backoff = [10, 30, 60];

    public function __construct(public readonly int $videoGenerationJobId)
    {
        $this->onQueue('default');
    }

    public function handle(VideoGenerationClient $client): void
    {
        $job = VideoGenerationJob::query()->whereKey($this->videoGenerationJobId)->first();
        if (! $job instanceof VideoGenerationJob) {
            return;
        }

        if (! in_array((string) $job->status, ['queued', 'processing'], true)) {
            return;
        }

        $taskId = trim((string) $job->api_task_id);
        if ($taskId === '') {
            $this->markFailed($job, '视频生成任务缺少外部任务 ID');

            return;
        }

        $response = $client->getTask($taskId);
        $data = (array) data_get($response, 'data', []);
        $state = (int) data_get($data, 'state', data_get($response, 'state', 0));
        $progress = max(0, min(100, (int) data_get($data, 'progress', $job->progress)));

        if ($state === 1) {
            $videos = $this->normalizeUrls((array) data_get($data, 'videos', []), $client);
            $combinedVideos = $this->normalizeUrls((array) data_get($data, 'combined_videos', []), $client);
            if ($videos === [] && $combinedVideos === []) {
                $this->markFailed($job, '视频生成完成但未返回视频文件');

                return;
            }

            $job->forceFill([
                'status' => 'success',
                'progress' => 100,
                'result_payload' => $data,
                'videos' => $videos,
                'combined_videos' => $combinedVideos,
                'script' => (string) data_get($data, 'script', $job->script ?? ''),
                'terms' => is_array(data_get($data, 'terms'))
                    ? implode(', ', (array) data_get($data, 'terms'))
                    : (string) data_get($data, 'terms', $job->terms ?? ''),
                'failure_reason' => null,
                'finished_at' => now(),
            ])->save();

            return;
        }

        if ($state === -1) {
            $this->markFailed($job, (string) data_get($data, 'error', data_get($data, 'message', '视频生成失败')));

            return;
        }

        if ($job->started_at && $job->started_at->copy()->addMinutes(max(1, (int) config('video-generation.max_poll_minutes', 60)))->isPast()) {
            $this->markFailed($job, '视频生成任务超时');

            return;
        }

        $job->forceFill([
            'status' => 'processing',
            'progress' => $progress,
            'result_payload' => $data,
        ])->save();

        self::dispatch((int) $job->id)
            ->delay(now()->addSeconds(max(1, (int) config('video-generation.poll_interval', 10))));
    }

    public function failed(?Throwable $exception): void
    {
        $job = VideoGenerationJob::query()->whereKey($this->videoGenerationJobId)->first();
        if ($job instanceof VideoGenerationJob) {
            $this->markFailed($job, $exception?->getMessage() ?: '视频生成轮询失败');
        }
    }

    /**
     * @param  array<int,mixed>  $paths
     * @return array<int,string>
     */
    private function normalizeUrls(array $paths, VideoGenerationClient $client): array
    {
        return collect($paths)
            ->map(static fn ($path): string => is_string($path) ? trim($path) : '')
            ->filter(static fn (string $path): bool => $path !== '')
            ->map(fn (string $path): string => $client->absoluteUrl($path))
            ->values()
            ->all();
    }

    private function markFailed(VideoGenerationJob $job, string $reason): void
    {
        $job->forceFill([
            'status' => 'failed',
            'failure_reason' => trim($reason) ?: '视频生成失败',
            'finished_at' => now(),
        ])->save();
    }
}
