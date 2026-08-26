<?php

namespace App\Jobs;

use App\Models\SelfMediaPublishJob;
use App\Models\SelfMediaPublishJobItem;
use App\Services\AiToEarn\AiToEarnClient;
use App\Support\SelfMedia\SelfMediaPlatformCatalog;
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
            $payload = $this->flowPayload($job, $client);
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
    private function flowPayload(SelfMediaPublishJob $job, AiToEarnClient $client): array
    {
        $payload = (array) $job->payload;
        $payload['items'] = $job->items
            ->map(fn (SelfMediaPublishJobItem $item): array => (array) $item->payload)
            ->values()
            ->all();
        $payload['items'] = $this->normalizeItemOptions($payload['items'], $client);

        if ((string) $job->content_type === 'article') {
            $payload = $this->normalizeArticleMedia($payload, $client);
        } elseif ((string) $job->content_type === 'video') {
            $payload = $this->normalizeSharedMedia($payload);
        }

        $payload['publishAt'] = $this->futurePublishAt($payload['publishAt'] ?? null);

        return $this->rootPayloadForPublish($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function rootPayloadForPublish(array $payload): array
    {
        $normalized = [
            'content' => $this->contentForPublish((array) ($payload['content'] ?? [])),
            'publishAt' => (string) ($payload['publishAt'] ?? ''),
            'items' => (array) ($payload['items'] ?? []),
        ];

        $flowId = trim((string) ($payload['flowId'] ?? ''));
        if ($flowId !== '') {
            $normalized = ['flowId' => $flowId] + $normalized;
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $content
     * @return array<string,mixed>
     */
    private function contentForPublish(array $content): array
    {
        $normalized = [];

        $title = trim((string) ($content['title'] ?? ''));
        if ($title !== '') {
            $normalized['title'] = $title;
        }

        $body = trim((string) ($content['body'] ?? ''));
        if ($body !== '') {
            $normalized['body'] = $body;
        }

        $media = [];
        foreach ((array) ($content['media'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $mediaItem = $this->mediaItemForPublish($item);
            if ($mediaItem['url'] !== '') {
                $media[] = $mediaItem;
            }
        }

        if ($media !== []) {
            $normalized['media'] = $media;
        }

        if (isset($content['cover']) && is_array($content['cover'])) {
            $cover = $this->mediaItemForPublish($content['cover']);
            if ($cover['url'] !== '') {
                $normalized['cover'] = $cover;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizeSharedMedia(array $payload): array
    {
        $media = (array) data_get($payload, 'content.media', []);
        foreach ($media as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $payload['content']['media'][$index] = $this->mediaItemForPublish($item);
        }

        $cover = data_get($payload, 'content.cover');
        if (is_array($cover)) {
            $payload['content']['cover'] = $this->mediaItemForPublish($cover);
        }

        foreach ((array) ($payload['items'] ?? []) as $itemIndex => $item) {
            if (! is_array($item)) {
                continue;
            }

            $overrideMedia = (array) data_get($item, 'overrides.media', []);
            foreach ($overrideMedia as $mediaIndex => $mediaItem) {
                if (! is_array($mediaItem)) {
                    continue;
                }

                $payload['items'][$itemIndex]['overrides']['media'][$mediaIndex] = $this->mediaItemForPublish($mediaItem);
            }

            $overrideCover = data_get($item, 'overrides.cover');
            if (is_array($overrideCover)) {
                $payload['items'][$itemIndex]['overrides']['cover'] = $this->mediaItemForPublish($overrideCover);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function normalizeArticleMedia(array $payload, AiToEarnClient $client): array
    {
        $platforms = collect((array) ($payload['items'] ?? []))
            ->map(static fn (mixed $item): string => is_array($item) ? (string) ($item['platform'] ?? '') : '')
            ->filter()
            ->values()
            ->all();

        if ($this->requiresArticleImages($platforms)) {
            $payload = $this->ensureSharedArticleImageMedia($payload);
        }

        return $this->importArticleImages($payload, $client);
    }

    /**
     * @param  array<int,string>  $platforms
     */
    private function requiresArticleImages(array $platforms): bool
    {
        return collect($platforms)
            ->contains(fn (string $platform): bool => $platform === 'douyin');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function ensureSharedArticleImageMedia(array $payload): array
    {
        $media = collect((array) data_get($payload, 'content.media', []))
            ->filter(fn (mixed $item): bool => is_array($item) && trim((string) ($item['url'] ?? '')) !== '')
            ->values()
            ->all();

        $coverUrl = trim((string) data_get($payload, 'content.cover.url', ''));
        if ($media === [] && $coverUrl !== '') {
            $media[] = [
                'url' => $coverUrl,
            ];
        }

        if ($media === []) {
            throw new \RuntimeException('抖音/小红书图文发布需要至少一张封面图或配图，请先给文章补充图片后再发布');
        }

        data_set($payload, 'content.media', $media);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function importArticleImages(array $payload, AiToEarnClient $client): array
    {
        $cache = [];

        $media = (array) data_get($payload, 'content.media', []);
        foreach ($media as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $payload['content']['media'][$index] = $this->importMediaItem($item, $client, $cache);
        }

        $cover = data_get($payload, 'content.cover');
        if (is_array($cover)) {
            $payload['content']['cover'] = $this->importMediaItem($cover, $client, $cache);
        }

        foreach ((array) ($payload['items'] ?? []) as $itemIndex => $item) {
            if (! is_array($item)) {
                continue;
            }

            $overrideMedia = (array) data_get($item, 'overrides.media', []);
            foreach ($overrideMedia as $mediaIndex => $mediaItem) {
                if (! is_array($mediaItem)) {
                    continue;
                }

                $payload['items'][$itemIndex]['overrides']['media'][$mediaIndex] = $this->importMediaItem($mediaItem, $client, $cache);
            }

            $overrideCover = data_get($item, 'overrides.cover');
            if (is_array($overrideCover)) {
                $payload['items'][$itemIndex]['overrides']['cover'] = $this->importMediaItem($overrideCover, $client, $cache);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<string,array{url:string,mime_type:string,size:int}>  $cache
     * @return array<string,mixed>
     */
    private function importMediaItem(array $item, AiToEarnClient $client, array &$cache): array
    {
        $url = trim((string) ($item['url'] ?? ''));
        if ($url === '' || $this->isAiToEarnAssetUrl($url)) {
            return $this->mediaItemForPublish($item);
        }

        $cache[$url] ??= $client->importRemoteAsset($url);
        $item['url'] = $cache[$url]['url'];

        return $this->mediaItemForPublish($item);
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    private function mediaItemForPublish(array $item): array
    {
        $payload = [
            'url' => trim((string) ($item['url'] ?? '')),
        ];

        $options = $this->mediaOptionsForPublish((array) ($item['options'] ?? []));
        if ($options !== []) {
            $payload['options'] = $options;
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function mediaOptionsForPublish(array $options): array
    {
        $imageFormat = trim((string) data_get($options, 'adaptation.imageFormat', ''));
        if (! in_array($imageFormat, ['off', 'auto', 'jpeg', 'png', 'webp'], true)) {
            return [];
        }

        return [
            'adaptation' => [
                'imageFormat' => $imageFormat,
            ],
        ];
    }

    private function isAiToEarnAssetUrl(string $url): bool
    {
        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

        return in_array($host, ['assets.aitoearn.cn', 'assets.aitoearn.ai'], true);
    }

    private function futurePublishAt(mixed $publishAt): string
    {
        $minimum = now()->addSeconds(30);

        try {
            $target = \Illuminate\Support\Carbon::parse((string) $publishAt);
            if ($target->greaterThan($minimum)) {
                return $target->toIso8601String();
            }
        } catch (Throwable) {
            //
        }

        return now()
            ->addSeconds(max(60, (int) config('aitoearn.publish_delay_seconds', 60)))
            ->toIso8601String();
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function normalizeItemOptions(array $items, AiToEarnClient $client): array
    {
        $normalizedItems = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new \RuntimeException('AiToEarn 发布项格式不正确');
            }

            $platform = trim((string) ($item['platform'] ?? ''));
            $accountId = trim((string) ($item['accountId'] ?? ''));
            if ($platform === '' || $accountId === '') {
                throw new \RuntimeException('AiToEarn 发布项缺少平台或账号 ID');
            }

            if (! SelfMediaPlatformCatalog::isDomestic($platform)) {
                throw new \RuntimeException('AiToEarn 当前仅开放国内平台发布，请移除国外平台后重试');
            }

            $normalized = [
                'platform' => $platform,
                'accountId' => $accountId,
            ];

            $option = $this->platformOptionForPublish($platform, (array) ($item['option'] ?? []), $client, $accountId);
            if ($option !== [] || $this->platformRequiresOption($platform)) {
                $normalized['option'] = $option;
            }

            $overrides = $this->overridesForPublish((array) ($item['overrides'] ?? []));
            if ($overrides !== []) {
                $normalized['overrides'] = $overrides;
            }

            $normalizedItems[] = $normalized;
        }

        return $normalizedItems;
    }

    /**
     * @param  array<string,mixed>  $option
     * @return array<string,mixed>
     */
    private function platformOptionForPublish(string $platform, array $option, AiToEarnClient $client, string $accountId): array
    {
        if ($platform === 'bilibili') {
            $tid = $this->resolveBilibiliTid($client, $accountId, $option['tid'] ?? null);
            $copyright = $this->integerIn($option['copyright'] ?? 1, [1, 2]) ?? 1;
            $normalized = [
                'tid' => $tid,
                'copyright' => $copyright,
            ];

            $noReprint = $this->integerIn($option['no_reprint'] ?? null, [0, 1]);
            if ($noReprint !== null) {
                $normalized['no_reprint'] = $noReprint;
            }

            foreach (['topic_id', 'mission_id'] as $field) {
                $value = $this->positiveInteger($option[$field] ?? null);
                if ($value !== null) {
                    $normalized[$field] = $value;
                }
            }

            $source = trim((string) ($option['source'] ?? ''));
            if ($copyright === 2) {
                if ($source === '') {
                    throw new \RuntimeException('B 站转载发布需要填写转载来源 source');
                }

                $normalized['source'] = $source;
            }

            return $normalized;
        }

        if ($platform === 'douyin') {
            $normalized = [];
            $shortTitle = mb_substr(trim((string) ($option['short_title'] ?? '')), 0, 12, 'UTF-8');
            if ($shortTitle !== '') {
                $normalized['short_title'] = $shortTitle;
            }

            $coverTsp = $this->positiveInteger($option['cover_tsp'] ?? null, allowZero: true);
            if ($coverTsp !== null) {
                $normalized['cover_tsp'] = $coverTsp;
            }

            $downloadType = $this->integerIn($option['download_type'] ?? null, [1, 2]);
            if ($downloadType !== null) {
                $normalized['download_type'] = $downloadType;
            }

            $privateStatus = $this->integerIn($option['private_status'] ?? null, [0, 1, 2]);
            if ($privateStatus !== null) {
                $normalized['private_status'] = $privateStatus;
            }

            return $normalized;
        }

        if ($platform === 'wxGzh') {
            $normalized = [];
            $author = mb_substr(trim((string) ($option['author'] ?? '')), 0, 8, 'UTF-8');
            if ($author !== '') {
                $normalized['author'] = $author;
            }

            $digest = mb_substr(trim((string) ($option['digest'] ?? '')), 0, 120, 'UTF-8');
            if ($digest !== '') {
                $normalized['digest'] = $digest;
            }

            $openComment = $this->integerIn($option['open_comment'] ?? null, [0, 1]);
            if ($openComment !== null) {
                $normalized['open_comment'] = $openComment;
            }

            $onlyFansCanComment = $this->integerIn($option['only_fans_can_comment'] ?? null, [0, 1]);
            if ($onlyFansCanComment !== null) {
                $normalized['only_fans_can_comment'] = $onlyFansCanComment;
            }

            if (array_key_exists('showCoverPic', $option)) {
                $normalized['showCoverPic'] = filter_var($option['showCoverPic'], FILTER_VALIDATE_BOOLEAN);
            }

            $sourceUrl = trim((string) ($option['sourceUrl'] ?? ''));
            if ($sourceUrl !== '') {
                $normalized['sourceUrl'] = $sourceUrl;
            }

            return $normalized;
        }

        if ($platform === 'KWAI') {
            return array_filter([
                'cover' => trim((string) ($option['cover'] ?? '')),
                'stereo_type' => trim((string) ($option['stereo_type'] ?? '')),
                'merchant_product_id' => trim((string) ($option['merchant_product_id'] ?? '')),
            ], static fn (mixed $value): bool => $value !== '');
        }

        if ($platform === 'wxSph') {
            $normalized = array_filter([
                'workId' => trim((string) ($option['workId'] ?? '')),
                'workLink' => trim((string) ($option['workLink'] ?? '')),
                'linkStatus' => in_array((string) ($option['linkStatus'] ?? ''), ['pending', 'ready', 'failed'], true)
                    ? (string) $option['linkStatus']
                    : '',
            ], static fn (mixed $value): bool => $value !== '');

            if (isset($option['linkMeta']) && is_array($option['linkMeta'])) {
                $normalized['linkMeta'] = array_filter([
                    'mediaMd5sum' => trim((string) ($option['linkMeta']['mediaMd5sum'] ?? '')),
                    'videoClipTaskId' => trim((string) ($option['linkMeta']['videoClipTaskId'] ?? '')),
                    'scheduledTime' => $this->positiveInteger($option['linkMeta']['scheduledTime'] ?? null, allowZero: true),
                ], static fn (mixed $value): bool => $value !== '' && $value !== null);
            }

            return $normalized;
        }

        if ($platform === 'xhs') {
            $workLink = trim((string) ($option['workLink'] ?? ''));
            if ($workLink === '') {
                throw new \RuntimeException('小红书发布需要已完成的小红书作品链接，当前暂不支持直接创建小红书新内容发布任务');
            }

            return ['workLink' => $workLink];
        }

        return [];
    }

    private function platformRequiresOption(string $platform): bool
    {
        return in_array($platform, ['bilibili', 'xhs'], true);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function overridesForPublish(array $overrides): array
    {
        $normalized = array_filter([
            'title' => trim((string) ($overrides['title'] ?? '')),
            'body' => trim((string) ($overrides['body'] ?? '')),
        ], static fn (string $value): bool => $value !== '');

        $media = (array) ($overrides['media'] ?? []);
        foreach ($media as $item) {
            if (! is_array($item)) {
                continue;
            }

            $mediaItem = $this->mediaItemForPublish($item);
            if ($mediaItem['url'] !== '') {
                $normalized['media'][] = $mediaItem;
            }
        }

        if (array_key_exists('cover', $overrides)) {
            if ($overrides['cover'] === null) {
                $normalized['cover'] = null;
            } elseif (is_array($overrides['cover'])) {
                $cover = $this->mediaItemForPublish($overrides['cover']);
                if ($cover['url'] !== '') {
                    $normalized['cover'] = $cover;
                }
            }
        }

        return $normalized;
    }

    private function positiveInteger(mixed $value, bool $allowZero = false): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $integer = (int) $value;
        if ($allowZero) {
            return $integer >= 0 ? $integer : null;
        }

        return $integer > 0 ? $integer : null;
    }

    /**
     * @param  array<int,int>  $allowed
     */
    private function integerIn(mixed $value, array $allowed): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $integer = (int) $value;

        return in_array($integer, $allowed, true) ? $integer : null;
    }

    private function resolveBilibiliTid(AiToEarnClient $client, string $accountId, mixed $preferredTid): int
    {
        $preferredTid = $this->positiveInteger($preferredTid);

        try {
            $values = $this->enabledOptionValues($client->publishOptionValues($accountId, 'tid'));
        } catch (Throwable) {
            return $this->isKnownDisabledBilibiliTid($preferredTid) || $preferredTid === null
                ? 21
                : $preferredTid;
        }

        if ($values === []) {
            return $this->isKnownDisabledBilibiliTid($preferredTid) || $preferredTid === null
                ? 21
                : $preferredTid;
        }

        if ($preferredTid !== null && in_array($preferredTid, $values, true)) {
            return $preferredTid;
        }

        foreach ($this->bilibiliTidCandidates() as $candidate) {
            if (in_array($candidate, $values, true)) {
                return $candidate;
            }
        }

        return $values[0];
    }

    /**
     * @return array<int,int>
     */
    private function enabledOptionValues(array $response): array
    {
        $items = (array) data_get($response, 'items', $response);
        $values = [];

        $walk = function (array $nodes) use (&$walk, &$values): void {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $value = $this->positiveInteger($node['value'] ?? null);
                $disabled = filter_var($node['disabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if ($value !== null && ! $disabled) {
                    $values[] = $value;
                }

                $children = $node['children'] ?? [];
                if (is_array($children) && $children !== []) {
                    $walk($children);
                }
            }
        };

        $walk($items);

        return array_values(array_unique($values));
    }

    /**
     * @return array<int,int>
     */
    private function bilibiliTidCandidates(): array
    {
        $configured = $this->positiveInteger(config('aitoearn.default_bilibili_tid', null));

        return array_values(array_unique(array_filter([
            $this->isKnownDisabledBilibiliTid($configured) ? null : $configured,
            21,
            95,
            201,
            138,
        ], fn (?int $value): bool => $value !== null)));
    }

    private function isKnownDisabledBilibiliTid(?int $tid): bool
    {
        return in_array($tid, [160], true);
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
