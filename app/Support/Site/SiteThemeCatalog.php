<?php

/**
 * Created by Codex.
 * @Date: 2026-11-11
 * @Time: 15:12:50
 * @Author: cdkay
 * @Email: network@iyuanma.net
 *
 * @File： SiteThemeCatalog.php
 * @Description: 读取已安装官网主题及其公开封面图元数据。
 */

namespace App\Support\Site;

class SiteThemeCatalog
{
    /**
     * @return array<int, array{id:string,name:string,version:string,description:string,cover_url:string}>
     */
    public function all(): array
    {
        $themesRoot = resource_path('views/theme');
        if (! is_dir($themesRoot)) {
            return [];
        }

        $themes = [];
        $entries = scandir($themesRoot);
        if (! is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! preg_match('/^[a-zA-Z0-9_-]+$/', $entry)) {
                continue;
            }

            $themeDir = $themesRoot.DIRECTORY_SEPARATOR.$entry;
            if (! is_dir($themeDir)) {
                continue;
            }

            $manifestPath = $themeDir.DIRECTORY_SEPARATOR.'manifest.json';
            if (is_file($manifestPath)) {
                $manifestRaw = file_get_contents($manifestPath);
                if (! is_string($manifestRaw) || $manifestRaw === '') {
                    continue;
                }

                $manifest = json_decode($manifestRaw, true);
                if (! is_array($manifest)) {
                    continue;
                }

                $themes[] = [
                    'id' => (string) $entry,
                    'name' => (string) ($manifest['name'] ?? $entry),
                    'version' => (string) ($manifest['version'] ?? ''),
                    'description' => (string) ($manifest['description'] ?? ''),
                    'cover_url' => $this->coverUrl((string) $entry),
                ];

                continue;
            }

            if (! is_file($themeDir.DIRECTORY_SEPARATOR.'home.blade.php')) {
                continue;
            }

            $themes[] = [
                'id' => (string) $entry,
                'name' => ucfirst(str_replace(['-', '_'], ' ', $entry)),
                'version' => '',
                'description' => '',
                'cover_url' => $this->coverUrl((string) $entry),
            ];
        }

        usort($themes, static fn (array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        return $themes;
    }

    /**
     * @return array<int,string>
     */
    public function ids(): array
    {
        return array_map(static fn (array $theme): string => (string) $theme['id'], $this->all());
    }

    /**
     * @Name: coverUrl
     * @Description: 仅为存在于公开资源目录的主题封面返回站内 URL，避免输出指向不存在资源的链接。
     * @Author: cdkay
     * @CreateTime: 2026-11-11 15:12:50
     * @UpdateTime: 2026-11-11 15:12:50
     * @Param string $themeId 已校验的主题目录 ID
     * @Return string 图片相对 URL；封面文件缺失时返回空字符串
     * @Throws 无
     */
    private function coverUrl(string $themeId): string
    {
        $filename = $themeId.'.png';
        $path = public_path('assets/website-templates/'.$filename);

        return is_file($path) ? '/assets/website-templates/'.$filename : '';
    }
}
