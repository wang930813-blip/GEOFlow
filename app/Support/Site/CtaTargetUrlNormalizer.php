<?php

/**
 * Created by Codex.
 * @Date: 2026-10-15
 * @Time: 18:04:27
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： CtaTargetUrlNormalizer.php
 * @Description: 统一校验和归一化站点 CTA 目标地址，阻止危险协议及非法控制字符。
 */

namespace App\Support\Site;

use Illuminate\Support\Str;

final class CtaTargetUrlNormalizer
{
    /**
     * @Name: normalize
     * @Description: 仅允许站内相对路径或 HTTP(S) 地址，拒绝控制字符、协议相对地址、反斜杠和其它协议。
     *
     * @Author: cdkay
     * @CreateTime: 2026-10-15 18:04:27
     * @UpdateTime: 2026-10-15 18:04:27
     *
     * @Param: string $url 待校验的 CTA 目标地址
     * @Return string 归一化后的安全地址，非法地址返回空字符串
     */
    public static function normalize(string $url): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return '';
        }

        $normalized = trim($url);

        if ($normalized === '' || str_contains($normalized, '\\')) {
            return '';
        }

        if (str_starts_with($normalized, '//')) {
            return '';
        }

        if (str_starts_with($normalized, '/')) {
            return $normalized;
        }

        if (Str::isUrl($normalized, ['http', 'https'])) {
            return $normalized;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $normalized) === 1) {
            return '';
        }

        return '/'.ltrim($normalized, '/');
    }
}
