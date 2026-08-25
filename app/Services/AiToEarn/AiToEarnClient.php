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
    public function publishFlow(string $flowId): array
    {
        return $this->get('/api/v2/channels/publish/flows/'.rawurlencode($flowId));
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
