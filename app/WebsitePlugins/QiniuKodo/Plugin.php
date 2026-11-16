<?php

/**
 * Created by Codex.
 * @Date: 2026-11-12
 * @Time: 16:04:59
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： Plugin.php
 * @Description: 使用七牛云上传凭证将官网图片写入 Kodo。
 */

namespace App\WebsitePlugins\QiniuKodo;

use App\WebsitePlugins\Contracts\CloudStoragePlugin;
use App\WebsitePlugins\Support\CloudUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

final class Plugin implements CloudStoragePlugin
{
    private const UPLOAD_HOSTS = [
        'z0' => 'up.qiniup.com',
        'z1' => 'up-z1.qiniup.com',
        'z2' => 'up-z2.qiniup.com',
        'na0' => 'up-na0.qiniup.com',
        'as0' => 'up-as0.qiniup.com',
        'cn-east-2' => 'up-cn-east-2.qiniup.com',
    ];

    public function upload(UploadedFile $file, string $key, array $settings): string
    {
        $policy = json_encode([
            'scope' => $settings['bucket'].':'.$key,
            'deadline' => time() + 600,
        ], JSON_THROW_ON_ERROR);
        $encodedPolicy = $this->urlSafeBase64($policy);
        $signature = $this->urlSafeBase64(hash_hmac('sha1', $encodedPolicy, $settings['secret_key'], true));
        $token = $settings['access_key'].':'.$signature.':'.$encodedPolicy;
        $response = Http::timeout(30)->withOptions(['allow_redirects' => false])
            ->attach('file', file_get_contents($file->getRealPath()), basename($key), ['Content-Type' => (string) $file->getMimeType()])
            ->post('https://'.self::UPLOAD_HOSTS[$settings['region']], ['token' => $token, 'key' => $key]);
        CloudUpload::assertSucceeded($response, '七牛云 Kodo');

        return CloudUpload::publicUrl($settings, $key);
    }

    private function urlSafeBase64(string $value): string
    {
        return strtr(base64_encode($value), '+/', '-_');
    }
}
