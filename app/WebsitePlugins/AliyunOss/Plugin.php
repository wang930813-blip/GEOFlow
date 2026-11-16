<?php

/**
 * Created by Codex.
 * @Date: 2026-11-12
 * @Time: 16:04:59
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： Plugin.php
 * @Description: 使用阿里云 OSS REST API 签名上传官网图片。
 */

namespace App\WebsitePlugins\AliyunOss;

use App\WebsitePlugins\Contracts\CloudStoragePlugin;
use App\WebsitePlugins\Support\CloudUpload;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

final class Plugin implements CloudStoragePlugin
{
    public function upload(UploadedFile $file, string $key, array $settings): string
    {
        $host = $settings['bucket'].'.oss-'.$settings['region'].'.aliyuncs.com';
        $mime = (string) $file->getMimeType();
        $date = gmdate('D, d M Y H:i:s').' GMT';
        $resource = '/'.$settings['bucket'].'/'.$key;
        $signature = base64_encode(hash_hmac('sha1', "PUT\n\n".$mime."\n".$date."\n".$resource, $settings['access_key_secret'], true));
        $response = Http::timeout(30)->withOptions(['allow_redirects' => false])
            ->withHeaders([
                'Date' => $date,
                'Authorization' => 'OSS '.$settings['access_key_id'].':'.$signature,
            ])
            ->withBody(file_get_contents($file->getRealPath()), $mime)
            ->put('https://'.$host.'/'.$key);
        CloudUpload::assertSucceeded($response, '阿里云 OSS');

        return CloudUpload::publicUrl($settings, $key);
    }
}
