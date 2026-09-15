<?php

namespace App\Support\Site;

class SiteThemeCatalog
{
    /**
     * 这批旧官网模板暂时保留代码和静态资源，但不再暴露给后台用户选择。
     */
    private const HIDDEN_SELECTION_THEME_IDS = [
        'api-hot-recommendation-20260914',
        'apihot-recommend-20260623',
        'apple-support-inspired-20260914',
        'apple_support_clone',
        'corporate-growth-20260914',
    ];

    /**
     * @return array<int, array{id:string,name:string,version:string,description:string,templates:list<string>,preview_routes:list<string>,mode:string,base_theme_id:string,asset_css_exists:bool,asset_js_exists:bool,source:string}>
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

            if ($this->hiddenFromSelection($entry)) {
                continue;
            }

            $themeDir = $themesRoot.DIRECTORY_SEPARATOR.$entry;
            if (! is_dir($themeDir)) {
                continue;
            }

            $manifestPath = $themeDir.DIRECTORY_SEPARATOR.'manifest.json';
            if (is_file($manifestPath)) {
                $manifestRaw = file_get_contents($manifestPath);
                $manifest = is_string($manifestRaw) && $manifestRaw !== ''
                    ? json_decode($manifestRaw, true)
                    : null;

                if (is_array($manifest)) {
                    $templates = $this->normalizeTemplates($this->stringList($manifest['templates'] ?? ($manifest['blade_templates'] ?? [])));
                    $templates = array_values(array_unique(array_merge($templates, $this->bladeTemplates($themeDir))));
                    $previewRoutes = $this->stringList($manifest['preview_routes'] ?? ($manifest['preview'] ?? []));

                    $themes[] = [
                        'id' => (string) $entry,
                        'name' => (string) ($manifest['name'] ?? $this->humanName($entry)),
                        'version' => (string) ($manifest['version'] ?? ''),
                        'description' => (string) ($manifest['description'] ?? ''),
                        'templates' => $templates,
                        'preview_routes' => $previewRoutes,
                        'mode' => (string) ($manifest['mode'] ?? 'theme'),
                        'base_theme_id' => (string) ($manifest['base_theme_id'] ?? ''),
                        'asset_css_exists' => is_file(public_path('themes/'.$entry.'/theme.css')),
                        'asset_js_exists' => is_file(public_path('themes/'.$entry.'/theme.js')),
                        'source' => 'local',
                    ];

                    continue;
                }
            }

            if (! is_file($themeDir.DIRECTORY_SEPARATOR.'home.blade.php')) {
                continue;
            }

            $themes[] = [
                'id' => (string) $entry,
                'name' => $this->humanName($entry),
                'version' => '',
                'description' => '',
                'templates' => $this->bladeTemplates($themeDir),
                'preview_routes' => ['/'],
                'mode' => 'theme',
                'base_theme_id' => '',
                'asset_css_exists' => is_file(public_path('themes/'.$entry.'/theme.css')),
                'asset_js_exists' => is_file(public_path('themes/'.$entry.'/theme.js')),
                'source' => 'local',
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
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '' && ! in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function bladeTemplates(string $themeDir): array
    {
        $templates = [];
        $files = glob($themeDir.DIRECTORY_SEPARATOR.'*.blade.php');
        if (! is_array($files)) {
            return [];
        }

        foreach ($files as $file) {
            $name = basename((string) $file, '.blade.php');
            if ($name !== '' && ! in_array($name, $templates, true)) {
                $templates[] = $name;
            }
        }

        sort($templates);

        return array_values($templates);
    }

    /**
     * @param  list<string>  $templates
     * @return list<string>
     */
    private function normalizeTemplates(array $templates): array
    {
        $out = [];
        foreach ($templates as $template) {
            $template = trim(str_replace('\\', '/', $template));
            if ($template === '') {
                continue;
            }

            $template = basename($template);
            if (str_ends_with($template, '.blade.php')) {
                $template = substr($template, 0, -10);
            }

            if ($template !== '' && ! in_array($template, $out, true)) {
                $out[] = $template;
            }
        }

        return $out;
    }

    private function humanName(string $themeId): string
    {
        return str($themeId)
            ->replace(['-', '_'], ' ')
            ->title()
            ->toString();
    }

    private function hiddenFromSelection(string $themeId): bool
    {
        if (in_array($themeId, self::HIDDEN_SELECTION_THEME_IDS, true)) {
            return true;
        }

        return preg_match('/^geoflow-template-(0[1-9]|1[0-9]|20)-/', $themeId) === 1;
    }
}
