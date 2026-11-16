<?php

/**
 * Created by Codex.
 * @Date: 2026-11-12
 * @Time: 16:04:59
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： Plugin.php
 * @Description: 使用腾讯云 COS XML API 签名上传官网图片。
 */

namespace App\WebsitePlugins\TencentCos;

use App\WebsitePlugins\Contracts\CloudStoragePlugin;
use App\WebsitePlugins\Support\CloudUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

final class Plugin implements CloudStoragePlugin
{
    public function upload(UploadedFile $file, string $key, array $settings): string
    {
        $host = $settings['bucket'].'.cos.'.$settings['region'].'.myqcloud.com';
        $now = time();
        $keyTime = $now.';'.($now + 600);
        $signKey = hash_hmac('sha1', $keyTime, $settings['secret_key']);
        $httpString = "put\n/".$key."\n\nhost=".$host."\n";
        $stringToSign = "sha1\n".$keyTime."\n".sha1($httpString)."\n";
        $signature = hash_hmac('sha1', $stringToSign, $signKey);
        $authorization = 'q-sign-algorithm=sha1&q-ak='.$settings['secret_id']
            .'&q-sign-time='.$keyTime.'&q-key-time='.$keyTime
            .'&q-header-list=host&q-url-param-list=&q-signature='.$signature;
        $response = Http::timeout(30)->withOptions(['allow_redirects' => false])
            ->withHeaders(['Authorization' => $authorization])
            ->withBody(file_get_contents($file->getRealPath()), (string) $file->getMimeType())
            ->put('https://'.$host.'/'.$key);
        CloudUpload::assertSucceeded($response, '腾讯云 COS');

        return CloudUpload::publicUrl($settings, $key);
    }
}
