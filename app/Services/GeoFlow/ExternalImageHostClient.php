<?php

namespace App\Services\GeoFlow;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ExternalImageHostClient
{
    /**
     * @return array{key:string,url:string,size:int,mime_type:string}
     */
    public function upload(string $binary, string $mimeType, string $filename): array
    {
        $uploadUrl = trim((string) config('geoflow.image_host.upload_url'));
        $token = trim((string) config('geoflow.image_host.token'));
        if ($uploadUrl === '' || $token === '') {
            throw new RuntimeException('图床上传地址或 Token 未配置');
        }

        try {
            $response = Http::withToken($token)
                ->timeout(60)
                ->connectTimeout(15)
                ->attach('file', $binary, $filename, ['Content-Type' => $mimeType])
                ->post($uploadUrl, ['useHashName' => 'true']);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('图床请求失败: '.$exception->getMessage(), 0, $exception);
        }

        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ($json['success'] ?? false) !== true || empty($json['data']['url'])) {
            $responseBody = trim($response->body());

            throw new RuntimeException('图床上传失败: '.($responseBody !== '' ? $responseBody : '图床未返回有效响应'));
        }

        $data = is_array($json['data'] ?? null) ? $json['data'] : [];

        return [
            'key' => (string) ($data['key'] ?? ''),
            'url' => (string) ($data['url'] ?? ''),
            'size' => (int) ($data['size'] ?? strlen($binary)),
            'mime_type' => (string) ($data['mimeType'] ?? $mimeType),
        ];
    }
}
