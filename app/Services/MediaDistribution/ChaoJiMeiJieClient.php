<?php

namespace App\Services\MediaDistribution;

use App\Models\MediaApiSetting;
use App\Models\MediaResource;
use App\Models\MediaSubmission;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\MediaDistribution\MediaPlatform;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ChaoJiMeiJieClient implements MediaPlatformClient
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly ChaoJiMeiJieSigner $signer,
    ) {}

    public function platformId(): int
    {
        return MediaPlatform::CEYING_MEDIA_2;
    }

    /**
     * @return Generator<int, array<int, array<string,mixed>>>
     */
    public function resourcePages(string $sourceType, int $startPage = 1): Generator
    {
        $pageSize = max(1, min(200, (int) config('media_distribution.page_size', 100)));
        $maxPages = max(1, (int) config('media_distribution.max_pages', 200));
        $startPage = max(1, min($startPage, $maxPages));
        $seenPageSignatures = [];

        for ($page = $startPage; $page <= $maxPages; $page++) {
            try {
                $response = $this->get($this->path($sourceType, 'resource'), [
                    'page' => $page,
                    'size' => $pageSize,
                ]);
            } catch (ConnectionException $exception) {
                throw new MediaDistributionException('优质媒体资源同步第 '.$page.' 页请求失败：'.$exception->getMessage(), previous: $exception);
            }

            $items = $this->extractItems($response);
            if ($items === []) {
                break;
            }

            $signature = $this->pageSignature($items);
            if (isset($seenPageSignatures[$signature])) {
                break;
            }
            $seenPageSignatures[$signature] = true;

            yield $page => $items;

            if (count($items) < $pageSize) {
                break;
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function submit(MediaSubmission $submission, MediaResource $resource, string $remark = ''): array
    {
        $payload = [
            'sn' => (string) $submission->agent_order_sn,
            'resource_id' => (int) $resource->external_resource_id,
            'title' => (string) $submission->title_snapshot,
            'content' => route('media-submission-preview.show', [
                'submission' => (int) $submission->id,
                'token' => (string) $submission->preview_token,
            ]),
        ];

        if (trim($remark) !== '') {
            $payload['remark'] = mb_substr(trim($remark), 0, 500);
        }
        if ((string) $submission->source_type === MediaResource::SOURCE_ZI_MEDIA) {
            $payload += [
                'publish_form' => 1,
                'publish_type' => 1,
                'account_rule' => 3,
            ];
        }

        return $this->post($this->path((string) $submission->source_type, 'order'), $payload);
    }

    /**
     * @return array<string,mixed>
     */
    public function orderInfo(MediaSubmission|string $submission, ?string $orderNid = null): array
    {
        if (! $submission instanceof MediaSubmission) {
            throw new RuntimeException('优质媒体查单需要媒体投稿记录');
        }

        return $this->get($this->path((string) $submission->source_type, 'order/query'), [
            'sn' => [(string) ($submission->agent_order_sn ?: $submission->external_order_nid)],
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function cancelOrder(MediaSubmission|string $submission, ?string $orderNid = null, ?string $reason = null): array
    {
        if (! $submission instanceof MediaSubmission) {
            throw new RuntimeException('优质媒体取消订单需要媒体投稿记录');
        }

        return $this->post($this->path((string) $submission->source_type, 'order/cancel'), [
            'sn' => (string) ($submission->agent_order_sn ?: $submission->external_order_nid),
            'reason' => mb_substr(trim((string) $orderNid), 0, 500),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function appeal(MediaSubmission $submission, string $content): array
    {
        return $this->post($this->path((string) $submission->source_type, 'order/apply-refund'), [
            'sn' => (string) ($submission->agent_order_sn ?: $submission->external_order_nid),
            'reason' => mb_substr(trim($content), 0, 500),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function get(string $path, array $payload): array
    {
        return $this->request('GET', $path, $payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, array $payload): array
    {
        [$baseUrl, $appId, $secret] = $this->credentials();
        $signedPayload = $this->signer->sign([
            'appid' => $appId,
            'timestamp' => time(),
            'algorithm' => 'sha256',
        ] + $payload, $secret);

        $request = Http::timeout((int) config('media_distribution.timeout', 30))
            ->connectTimeout((int) config('media_distribution.connect_timeout', 10))
            ->retry(
                max(1, (int) config('media_distribution.retry_times', 3)),
                max(0, (int) config('media_distribution.retry_sleep', 1000))
            )
            ->acceptJson();

        $response = strtoupper($method) === 'GET'
            ? $request->get($baseUrl.$path, $signedPayload)
            : $request->asForm()->post($baseUrl.$path, $signedPayload);

        if (! $response->successful()) {
            throw new MediaDistributionException('优质媒体接口请求失败：HTTP '.$response->status());
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new MediaDistributionException('优质媒体接口返回格式不正确');
        }

        if ((string) ($json['code'] ?? '') !== '200') {
            throw new MediaDistributionException((string) ($json['message'] ?? $json['msg'] ?? '优质媒体接口返回失败'));
        }

        return $json;
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function credentials(): array
    {
        $setting = MediaApiSetting::query()
            ->where('platform_id', MediaPlatform::CEYING_MEDIA_2)
            ->orderByDesc('id')
            ->first();
        $baseUrl = rtrim((string) ($setting?->api_base_url ?: config('media_distribution.chaojimeijie_base_url')), '/');
        $appId = trim((string) ($setting?->app_id ?? ''));
        $secret = $setting instanceof MediaApiSetting
            ? $this->apiKeyCrypto->decrypt((string) $setting->api_secret_ciphertext)
            : '';

        if ($baseUrl === '' || $appId === '' || $secret === '') {
            throw new RuntimeException('优质媒体接口配置不完整');
        }

        return [$baseUrl, $appId, $secret];
    }

    private function path(string $sourceType, string $action): string
    {
        $prefix = $sourceType === MediaResource::SOURCE_ZI_MEDIA ? '/we-media' : '/media';

        return $prefix.'/'.$action;
    }

    /**
     * @param  array<string,mixed>  $response
     * @return array<int, array<string,mixed>>
     */
    private function extractItems(array $response): array
    {
        $items = data_get($response, 'data.items', []);

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /**
     * @param  array<int, array<string,mixed>>  $items
     */
    private function pageSignature(array $items): string
    {
        return hash('sha256', json_encode(array_map(
            static fn (array $row): string => (string) ($row['id'] ?? json_encode($row)),
            $items
        )) ?: '');
    }
}
