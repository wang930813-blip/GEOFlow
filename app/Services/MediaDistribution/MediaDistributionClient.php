<?php

namespace App\Services\MediaDistribution;

use App\Models\MediaApiSetting;
use App\Models\MediaResource;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Support\Facades\Http;

class MediaDistributionClient
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    /**
     * @return array<int, array<string,mixed>>
     */
    public function listResources(string $sourceType): array
    {
        $pageSize = max(1, (int) config('media_distribution.page_size', 100));
        $maxPages = max(1, (int) config('media_distribution.max_pages', 200));
        $resources = [];
        $seenPageSignatures = [];

        for ($page = 1; $page <= $maxPages; $page++) {
            $data = $this->post($sourceType, 'media_list', [
                'page' => (string) $page,
                'page_size' => (string) $pageSize,
            ]);
            $list = $this->extractList($data);
            if ($list === []) {
                break;
            }

            $signature = $this->pageSignature($list);
            if (isset($seenPageSignatures[$signature])) {
                break;
            }
            $seenPageSignatures[$signature] = true;

            array_push($resources, ...$list);

            if (count($list) < $pageSize) {
                break;
            }
        }

        return $resources;
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
    public function orderInfo(string $sourceType, string $orderNid): array
    {
        return $this->post($sourceType, 'order_info', [
            'order_nid' => $orderNid,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function cancelOrder(string $sourceType, string $orderNid, string $reason): array
    {
        return $this->post($sourceType, 'cancel_order', [
            'order_nid' => $orderNid,
            'reason' => $reason,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function rejection(string $sourceType, string $orderNid, string $content): array
    {
        return $this->post($sourceType, 'rejection', [
            'order_nid' => $orderNid,
            'content' => $content,
        ]);
    }

    /**
     * @param  array<string,string>  $payload
     * @return array<string,mixed>
     */
    private function post(string $sourceType, string $action, array $payload): array
    {
        $setting = MediaApiSetting::query()->orderByDesc('id')->first();
        $baseUrl = rtrim((string) ($setting?->api_base_url ?: config('media_distribution.base_url')), '/');
        $apiKey = $setting instanceof MediaApiSetting
            ? $this->apiKeyCrypto->decrypt((string) $setting->api_key_ciphertext)
            : '';

        $path = $this->path($sourceType, $action);
        $response = Http::asMultipart()
            ->timeout((int) config('media_distribution.timeout', 30))
            ->connectTimeout((int) config('media_distribution.connect_timeout', 10))
            ->post($baseUrl.$path, ['api_key' => $apiKey] + $payload);

        if (! $response->successful()) {
            throw new MediaDistributionException('媒体接口请求失败：HTTP '.$response->status());
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new MediaDistributionException('媒体接口返回格式不正确');
        }

        $code = (string) ($json['code'] ?? '1');
        if (! in_array($code, ['1', '200', 'success'], true)) {
            throw new MediaDistributionException((string) ($json['msg'] ?? '媒体接口返回失败'));
        }

        return $json;
    }

    private function path(string $sourceType, string $action): string
    {
        $prefix = $sourceType === MediaResource::SOURCE_ZI_MEDIA ? '/api/zi_media_api' : '/api/media';

        return $prefix.'/'.$action;
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
