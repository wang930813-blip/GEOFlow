<?php

namespace App\Services\MediaDistribution;

use App\Models\MediaApiSetting;
use App\Models\MediaResource;
use App\Models\MediaSubmission;
use App\Support\MediaDistribution\MediaPlatform;
use App\Support\GeoFlow\ApiKeyCrypto;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class MediaDistributionClient implements MediaPlatformClient
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    public function platformId(): int
    {
        return MediaPlatform::CEYING_MEDIA_1;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    public function listResources(string $sourceType): array
    {
        $resources = [];

        foreach ($this->resourcePages($sourceType) as $list) {
            array_push($resources, ...$list);
        }

        return $resources;
    }

    /**
     * @return Generator<int, array<int, array<string,mixed>>>
     */
    public function resourcePages(string $sourceType): Generator
    {
        $pageSize = max(1, min(100, (int) config('media_distribution.page_size', 100)));
        $maxPages = max(1, (int) config('media_distribution.max_pages', 200));
        $seenPageSignatures = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            try {
                $data = $this->post($sourceType, 'media_list', $this->paginationPayload($page, $pageSize));
            } catch (ConnectionException $exception) {
                throw new MediaDistributionException('媒体资源同步第 '.$page.' 页请求失败：'.$exception->getMessage(), previous: $exception);
            }

            $list = $this->extractList($data);
            if ($list === []) {
                break;
            }

            $signature = $this->pageSignature($list);
            if (isset($seenPageSignatures[$signature])) {
                break;
            }
            $seenPageSignatures[$signature] = true;

            yield $page => $list;

            if (count($list) < $pageSize) {
                break;
            }
        }
    }

    /**
     * @return array<string,string>
     */
    private function paginationPayload(int $page, int $pageSize): array
    {
        return [
            'page' => (string) $page,
            'p' => (string) $page,
            'page_size' => (string) $pageSize,
            'pageSize' => (string) $pageSize,
            'pagesize' => (string) $pageSize,
            'per_page' => (string) $pageSize,
            'limit' => (string) $pageSize,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function send(string $sourceType, array $payload): array
    {
        return $this->post($sourceType, 'send', $payload);
    }

    /**
     * @return array<string,mixed>
     */
    public function rejection(string $sourceType, string $orderNid, string $content): array
    {
        return $this->post($sourceType, 'rejection', $this->orderPayload($orderNid) + [
            'content' => $content,
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function orderPayload(string $orderNid): array
    {
        return [
            'order_nid' => $orderNid,
            'nid' => $orderNid,
            'order_id' => $orderNid,
            'id' => $orderNid,
        ];
    }

    /**
     * @param  array<string,string>  $payload
     * @return array<string,mixed>
     */
    private function post(string $sourceType, string $action, array $payload): array
    {
        return $this->sendPost($sourceType, $action, $payload, false);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function postMultipart(string $sourceType, string $action, array $payload): array
    {
        return $this->sendPost($sourceType, $action, $payload, true);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sendPost(string $sourceType, string $action, array $payload, bool $multipart): array
    {
        $setting = MediaApiSetting::query()
            ->where('platform_id', MediaPlatform::CEYING_MEDIA_1)
            ->orderByDesc('id')
            ->first();
        if (! $setting instanceof MediaApiSetting) {
            $setting = MediaApiSetting::query()->orderByDesc('id')->first();
        }
        $baseUrl = rtrim((string) ($setting?->api_base_url ?: config('media_distribution.base_url')), '/');
        $apiKey = $setting instanceof MediaApiSetting
            ? $this->apiKeyCrypto->decrypt((string) $setting->api_key_ciphertext)
            : '';

        $path = $this->path($sourceType, $action);
        $request = Http::timeout((int) config('media_distribution.timeout', 30))
            ->connectTimeout((int) config('media_distribution.connect_timeout', 10))
            ->retry(
                max(1, (int) config('media_distribution.retry_times', 3)),
                max(0, (int) config('media_distribution.retry_sleep', 1000))
            );
        $request = $multipart ? $request->asMultipart() : $request->asForm();

        $response = $request->post($baseUrl.$path, ['api_key' => $apiKey] + $payload);

        if (! $response->successful()) {
            throw new MediaDistributionException('媒体接口请求失败：HTTP '.$response->status());
        }

        $json = $this->decodeResponseBody($response->body());

        $code = (string) ($json['code'] ?? '1');
        if (! in_array($code, ['1', '200', 'success'], true)) {
            throw new MediaDistributionException((string) ($json['msg'] ?? '媒体接口返回失败'));
        }

        return $json;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeResponseBody(string $body): array
    {
        $normalized = trim($this->stripUtf8Bom($body));
        $json = json_decode($normalized, true);

        if (is_string($json)) {
            $nested = trim($this->stripUtf8Bom($json));
            $json = json_decode($nested, true);
        }

        if (! is_array($json)) {
            throw new MediaDistributionException('媒体接口返回格式不正确：'.$this->responseExcerpt($normalized));
        }

        return $json;
    }

    private function stripUtf8Bom(string $body): string
    {
        return str_starts_with($body, "\xEF\xBB\xBF") ? substr($body, 3) : $body;
    }

    private function responseExcerpt(string $body): string
    {
        $excerpt = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $excerpt = trim(preg_replace('/\s+/', ' ', $excerpt) ?? '');

        return $excerpt === '' ? '空响应' : substr($excerpt, 0, 200);
    }

    private function path(string $sourceType, string $action): string
    {
        $prefix = $sourceType === MediaResource::SOURCE_ZI_MEDIA ? '/api/zi_media_api' : '/api/media';

        return $prefix.'/'.$action;
    }

    /**
     * @return array<string,mixed>
     */
    public function submit(MediaSubmission $submission, MediaResource $resource, string $remark = ''): array
    {
        return $this->send((string) $submission->source_type, [
            'resource_id' => (string) $resource->external_resource_id,
            'title' => (string) $submission->title_snapshot,
            'content' => (string) $submission->content_snapshot,
            'remark' => $remark,
            'third_id' => (string) ($submission->agent_order_sn ?: 'geoflow-'.$submission->site_id.'-'.$submission->id),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function orderInfo(MediaSubmission|string $submission, ?string $orderNid = null): array
    {
        if ($submission instanceof MediaSubmission) {
            return $this->legacyOrderInfo((string) $submission->source_type, (string) $submission->external_order_nid);
        }

        return $this->legacyOrderInfo($submission, (string) $orderNid);
    }

    /**
     * @return array<string,mixed>
     */
    private function legacyOrderInfo(string $sourceType, string $orderNid): array
    {
        return $this->postMultipart($sourceType, 'order_info', [
            'order_nids' => [$orderNid],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function cancelOrder(MediaSubmission|string $submission, ?string $orderNid = null, ?string $reason = null): array
    {
        if ($submission instanceof MediaSubmission) {
            return $this->legacyCancelOrder((string) $submission->source_type, (string) $submission->external_order_nid, (string) $orderNid);
        }

        return $this->legacyCancelOrder($submission, (string) $orderNid, (string) $reason);
    }

    /**
     * @return array<string,mixed>
     */
    private function legacyCancelOrder(string $sourceType, string $orderNid, string $reason): array
    {
        return $this->post($sourceType, 'cancel_order', $this->orderPayload($orderNid) + [
            'reason' => $reason,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function appeal(MediaSubmission $submission, string $content): array
    {
        return $this->rejection((string) $submission->source_type, (string) $submission->external_order_nid, $content);
    }

    /**
     * @param  array<string,mixed>  $response
     * @return array<int, array<string,mixed>>
     */
    private function extractList(array $response): array
    {
        $data = $response['data'] ?? [];
        if (is_array($data) && array_is_list($data)) {
            return $data;
        }
        if (is_array($data)) {
            foreach (['list', 'data', 'items', 'rows'] as $key) {
                if (isset($data[$key]) && is_array($data[$key])) {
                    return $data[$key];
                }
            }
        }

        return [];
    }

    /**
     * @param  array<int, array<string,mixed>>  $list
     */
    private function pageSignature(array $list): string
    {
        return hash('sha256', json_encode(array_map(
            static fn (array $row): string => (string) ($row['resource_id'] ?? $row['id'] ?? $row['nid'] ?? json_encode($row)),
            $list
        )) ?: '');
    }
}
