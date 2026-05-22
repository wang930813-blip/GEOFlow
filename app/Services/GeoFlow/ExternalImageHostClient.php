<?php

namespace App\Services\GeoFlow;

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

        $response = Http::withToken($token)
            ->timeout(60)
            ->connectTimeout(15)
            ->attach('file', $binary, $filename, ['Content-Type' => $mimeType])
            ->post($uploadUrl, ['useHashName' => 'true']);

        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ($json['success'] ?? false) !== true || empty($json['data']['url'])) {
            throw new RuntimeException('图床上传失败: '.trim($response->body()));
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
