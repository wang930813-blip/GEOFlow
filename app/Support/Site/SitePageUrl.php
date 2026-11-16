<?php

/**
 * Created by Codex.
 * @Date: 2026-11-16
 * @Time: 11:01:58
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： SitePageUrl.php
 * @Description: 为 GEOFlow 官网主题生成站内页面链接并保持只读主题预览上下文。
 */

namespace App\Support\Site;

use InvalidArgumentException;

final class SitePageUrl
{
    /**
     * @Name: to
     * @Description: 将主题页面标识映射至当前官网命名路由；只传递受支持的主题预览参数，并为缺失文章标识回退至资讯列表。
     * @Author: cdkay
     * @CreateTime: 2026-11-16 11:01:58
     * @UpdateTime: 2026-11-16 11:01:58
     * @Param string $page 官网页面标识
     * @Param array<string, mixed> $parameters 页面路由参数
     * @Return string 已生成的站内绝对 URL
     * @Throws InvalidArgumentException 页面标识不受支持
     */
    public static function to(string $page, array $parameters = []): string
    {
        $routes = [
            'home' => 'site.home',
            'about' => 'site.about',
            'products' => 'site.products.index',
            'news' => 'site.news',
            'article' => 'site.article',
            'contact' => 'site.contact',
        ];

        if (! isset($routes[$page])) {
            throw new InvalidArgumentException('不支持的官网页面标识。');
        }

        if ($page === 'article' && trim((string) ($parameters['slug'] ?? '')) === '') {
            $page = 'news';
            unset($parameters['slug']);
        }

        if ($page === 'products' && request()->query->has('category') && ! array_key_exists('category', $parameters)) {
            $parameters['category'] = request()->query('category');
        }

        $previewTheme = trim((string) request()->query('theme_preview', ''));
        if ($previewTheme !== '') {
            $isKnownPreview = $previewTheme === 'default'
                || (preg_match('/^[a-zA-Z0-9_-]+$/', $previewTheme) === 1
                    && (is_file(resource_path('views/theme/'.$previewTheme.'/manifest.json'))
                        || is_file(resource_path('views/theme/'.$previewTheme.'/home.blade.php'))));
            if ($isKnownPreview) {
                $parameters['theme_preview'] = $previewTheme;
            }
        }

        return route($routes[$page], $parameters);
    }
}
