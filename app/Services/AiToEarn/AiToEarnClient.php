<?php

namespace App\Services\AiToEarn;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class AiToEarnClient
{
    /**
     * @return list<array<string,mixed>>
     */
    public function platforms(): array
    {
        $data = $this->get('/api/v2/channels/platforms');

        return array_values(array_filter((array) $data, 'is_array'));
    }

    /**
     * @return array{total:int,list:list<array<string,mixed>>}
     */
    public function accounts(?string $platform = null): array
    {
        $query = [];
        if ($platform !== null && trim($platform) !== '') {
            $query['type'] = trim($platform);
        }

        $data = $this->get('/api/v2/channels/accounts', $query);
        $list = (array) ($data['accounts'] ?? $data['list'] ?? $data['items'] ?? []);

        return [
            'total' => (int) ($data['total'] ?? count($list)),
            'list' => array_values(array_filter($list, 'is_array')),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function startAuthorization(string $platform, ?string $callbackUrl = null, ?string $redirectUri = null): array
    {
        $query = [];
        if ($callbackUrl !== null && trim($callbackUrl) !== '') {
            $query['callbackUrl'] = trim($callbackUrl);
        }
        if ($redirectUri !== null && trim($redirectUri) !== '') {
            $query['redirectUri'] = trim($redirectUri);
        }

        return $this->get('/api/v2/channels/accounts/auth/'.rawurlencode($platform), $query);
    }

    /**
     * @return array<string,mixed>
     */
    public function authorizationStatus(string $platform, string $sessionId): array
    {
        return $this->get('/api/v2/channels/accounts/auth/'.rawurlencode($platform).'/status/'.rawurlencode($sessionId));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function createPublishFlow(array $payload): array
    {
        return $this->post('/api/v2/channels/publish/flows', $payload);
    }

    /**
     * @return array<string,mixed>
     */
    public function createUploadSign(string $filename, string $type = 'publishMedia', ?int $size = null): array
    {
        $payload = [
            'filename' => $filename,
            'type' => $type,
        ];

        if ($size !== null && $size > 0) {
            $payload['size'] = $size;
        }

        return $this->post('/api/assets/uploadSign', $payload);
    }

    /**
     * @return array<string,mixed>
     */
    public function confirmUpload(string $assetId): array
    {
        return $this->post('/api/assets/'.rawurlencode($assetId).'/confirm', []);
    }

    /**
     * @return array{url:string,mime_type:string,size:int}
     */
    public function importRemoteAsset(string $url, string $type = 'publishMedia'): array
    {
        $url = trim($url);
        if ($url === '') {
            throw new AiToEarnException('AiToEarn asset URL is empty');
        }

        $remote = $this->downloadRemoteAsset($url);
        $sign = $this->createUploadSign(
            $this->remoteAssetFilename($url, $remote['mime_type']),
            $type,
            $remote['size']
        );

        $assetId = trim((string) ($sign['id'] ?? ''));
        $uploadUrl = trim((string) ($sign['uploadUrl'] ?? ''));
        if ($assetId === '' || $uploadUrl === '') {
            throw new AiToEarnException('AiToEarn upload sign response is missing id or uploadUrl');
        }

        $this->uploadSignedAsset($uploadUrl, $remote['body'], $remote['mime_type']);
        $asset = $this->confirmUpload($assetId);

        $assetUrl = trim((string) ($asset['url'] ?? $sign['url'] ?? ''));
        if ($assetUrl === '') {
            throw new AiToEarnException('AiToEarn confirm response is missing asset URL');
        }

        return [
            'url' => $assetUrl,
            'mime_type' => trim((string) ($asset['mimeType'] ?? $remote['mime_type'])) ?: $remote['mime_type'],
            'size' => (int) ($asset['size'] ?? $remote['size']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function publishFlow(string $flowId): array
    {
        return $this->get('/api/v2/channels/publish/flows/'.rawurlencode($flowId));
    }

    /**
     * @return array<string,mixed>
     */
    public function publishRecordUserAction(string $recordId): array
    {
        return $this->get('/api/v2/channels/publish/records/'.rawurlencode($recordId).'/user-action');
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function platformPublishOptions(string $platform): array
    {
        $data = $this->get('/api/v2/channels/platforms/'.rawurlencode($platform).'/publish-options');

        return array_values(array_filter((array) $data, 'is_array'));
    }

    /**
     * @return array<string,mixed>
     */
    public function publishOptionValues(string $accountId, string $field): array
    {
        return $this->get('/api/v2/channels/accounts/'.rawurlencode($accountId).'/publish-options/'.rawurlencode($field).'/values');
    }

    /**
     * @param  array<string,mixed>  $query
     * @return array<string,mixed>
     */
    private function get(string $path, array $query = []): array
    {
        try {
            return $this->unwrap($this->request()->get($this->url($path), $query));
        } catch (AiToEarnException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AiToEarnException('AiToEarn request failed: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function post(string $path, array $payload): array
    {
        try {
            return $this->unwrap($this->request()->post($this->url($path), $payload));
        } catch (AiToEarnException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new AiToEarnException('AiToEarn request failed: '.$exception->getMessage(), previous: $exception);
        }
    }

    private function request(): PendingRequest
    {
        $apiKey = trim((string) config('aitoearn.api_key', ''));
        if ($apiKey === '') {
            throw new AiToEarnException('AiToEarn API Key is not configured');
        }

        return Http::asJson()
            ->acceptJson()
            ->withHeaders(['X-Api-Key' => $apiKey])
            ->connectTimeout(max(1, (int) config('aitoearn.connect_timeout', 10)))
            ->timeout(max(1, (int) config('aitoearn.timeout', 60)))
            ->retry(2, 300, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || $exception instanceof RequestException;
            }, throw: false);
    }

    /**
     * @return array{body:string,mime_type:string,size:int}
     */
    private function downloadRemoteAsset(string $url): array
    {
        try {
            $response = Http::accept('*/*')
                ->connectTimeout(max(1, (int) config('aitoearn.connect_timeout', 10)))
                ->timeout(max(1, (int) config('aitoearn.timeout', 60)))
                ->get($url);
        } catch (Throwable $exception) {
            throw new AiToEarnException('AiToEarn remote asset download failed: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw new AiToEarnException('AiToEarn remote asset download failed: HTTP '.$response->status().' '.$this->responsePreview($response->body()));
        }

        $body = $response->body();
        if ($body === '') {
            throw new AiToEarnException('AiToEarn remote asset download returned an empty file');
        }

        return [
            'body' => $body,
            'mime_type' => trim((string) ($response->header('Content-Type') ?: 'application/octet-stream')),
            'size' => strlen($body),
        ];
    }

    private function uploadSignedAsset(string $uploadUrl, string $body, string $mimeType): void
    {
        try {
            $response = Http::withHeaders(['Content-Type' => $mimeType])
                ->withBody($body, $mimeType)
                ->connectTimeout(max(1, (int) config('aitoearn.connect_timeout', 10)))
                ->timeout(max(1, (int) config('aitoearn.timeout', 60)))
                ->put($uploadUrl);
        } catch (Throwable $exception) {
            throw new AiToEarnException('AiToEarn signed asset upload failed: '.$exception->getMessage(), previous: $exception);
        }

        if ($response->failed()) {
            throw new AiToEarnException('AiToEarn signed asset upload failed: HTTP '.$response->status().' '.$this->responsePreview($response->body()));
        }
    }

    private function remoteAssetFilename(string $url, string $mimeType): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $filename = basename($path);
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: '';
        $filename = trim($filename, '.-');

        if ($filename === '' || ! str_contains($filename, '.')) {
            $filename = 'geoflow-asset-'.Str::lower(Str::random(10)).'.'.$this->extensionForMimeType($mimeType);
        }

        return $filename;
    }

    private function extensionForMimeType(string $mimeType): string
    {
        $mimeType = Str::lower(trim(explode(';', $mimeType)[0] ?? $mimeType));

        return match ($mimeType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            default => 'jpg',
        };
    }

    private function url(string $path): string
    {
        $baseUrl = rtrim((string) config('aitoearn.base_url', 'https://aitoearn.cn'), '/');
        $path = '/'.ltrim($path, '/');

        return $baseUrl.$path;
    }

    /**
     * @return array<string,mixed>
     */
    private function unwrap(\Illuminate\Http\Client\Response $response): array
    {
        if ($response->failed()) {
            throw new AiToEarnException('AiToEarn HTTP request failed: HTTP '.$response->status().' '.$this->responsePreview($response->body()));
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new AiToEarnException('AiToEarn returned a non-JSON response: '.$this->responsePreview($response->body()));
        }

        $code = $json['code'] ?? 0;
        if ((int) $code !== 0) {
            throw new AiToEarnException($this->businessErrorMessage($json));
        }

        $data = $json['data'] ?? [];

        return is_array($data) ? $data : ['value' => $data];
    }

    private function responsePreview(string $body): string
    {
        $body = trim(preg_replace('/\s+/u', ' ', $body) ?? $body);

        return Str::limit($body, 300, '');
    }

    /**
     * @param  array<string,mixed>  $json
     */
    private function businessErrorMessage(array $json): string
    {
        $message = trim((string) ($json['message'] ?? 'AiToEarn business request failed'));
        $parts = [];

        if (isset($json['code'])) {
            $parts[] = 'code: '.(string) $json['code'];
        }

        if (isset($json['requestId']) && trim((string) $json['requestId']) !== '') {
            $parts[] = 'requestId: '.trim((string) $json['requestId']);
        }

        foreach (['errors', 'details', 'error'] as $key) {
            if (array_key_exists($key, $json)) {
                $details = $this->flattenErrorDetails($json[$key]);
                if ($details !== '') {
                    $parts[] = $details;
                }
            }
        }

        return $parts === []
            ? $message
            : $message.' ('.implode('; ', $parts).')';
    }

    private function flattenErrorDetails(mixed $value, string $prefix = ''): string
    {
        if (is_string($value) || is_numeric($value) || is_bool($value)) {
            $text = trim((string) $value);

            return $text === '' ? '' : ($prefix !== '' ? $prefix.': '.$text : $text);
        }

        if (! is_array($value)) {
            return '';
        }

        $items = [];
        foreach ($value as $key => $item) {
            $itemPrefix = is_string($key) && ! is_numeric($key)
                ? ($prefix !== '' ? $prefix.'.'.$key : $key)
                : $prefix;
            $text = $this->flattenErrorDetails($item, $itemPrefix);
            if ($text !== '') {
                $items[] = $text;
            }
        }

        return implode(', ', $items);
    }
}
