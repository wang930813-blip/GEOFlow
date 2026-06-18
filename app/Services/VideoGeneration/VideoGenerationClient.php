<?php

namespace App\Services\VideoGeneration;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class VideoGenerationClient
{
    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function createVideo(array $payload, string $requestId): array
    {
        $response = $this->request()
            ->withHeader('x-task-id', $requestId)
            ->post('/api/v1/videos', $payload);

        if (! $response->successful()) {
            $message = trim((string) data_get((array) $response->json(), 'message'));

            throw new RuntimeException($message !== '' ? $message : '视频生成服务暂不可用，请稍后重试');
        }

        $body = (array) $response->json();
        $taskId = trim((string) data_get($body, 'data.task_id'));
        if ($taskId === '') {
            throw new RuntimeException('视频生成服务未返回任务 ID');
        }

        return $body;
    }

    /**
     * @return array<string,mixed>
     */
    public function getTask(string $taskId): array
    {
        $response = $this->request()->get('/api/v1/tasks/'.$taskId);

        if (! $response->successful()) {
            $message = trim((string) data_get((array) $response->json(), 'message'));

            throw new RuntimeException($message !== '' ? $message : '视频生成任务查询失败');
        }

        return (array) $response->json();
    }

    public function absoluteUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (preg_match('/^https?:\/\//i', $path) === 1) {
            return $path;
        }

        return rtrim($this->baseUrl(), '/').'/'.ltrim($path, '/');
    }

    private function request(): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->timeout(max(1, (int) config('video-generation.timeout', 30)))
            ->connectTimeout(max(1, (int) config('video-generation.connect_timeout', 10)));

        $apiKey = trim((string) config('video-generation.api_key', ''));
        if ($apiKey !== '') {
            $request = $request->withHeader('x-api-key', $apiKey);
        }

        return $request;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('video-generation.base_url', 'http://127.0.0.1:8080'), '/');
    }
}
