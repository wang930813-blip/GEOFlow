<?php

/**
 * Created by Codex.
 * @Date: 2026-11-12
 * @Time: 16:04:59
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： CloudUpload.php
 * @Description: 提供云存储上传插件共用的公开地址与响应校验方法。
 */

namespace App\WebsitePlugins\Support;

use Illuminate\Http\Client\Response;
use RuntimeException;

final class CloudUpload
{
    public static function publicUrl(array $settings, string $key): string
    {
        return rtrim((string) $settings['public_url'], '/').'/'.$key;
    }

    public static function assertSucceeded(Response $response, string $provider): void
    {
        if (! $response->successful()) {
            throw new RuntimeException($provider.'上传失败，服务端状态码：'.$response->status());
        }
    }
}
