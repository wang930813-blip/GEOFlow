<?php

namespace App\Support\Site;

use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewContract;

/**
 * 前台主题视图链：优先 {@see resources/views/theme/{themeId}/} 下模板，不存在则回退 {@see resources/views/site/}。
 */
final class SiteThemeViewResolver
{
    private static ?string $temporaryThemeId = null;

    /**
     * 当前启用的主题目录名（与 site_settings.active_theme 一致，仅允许安全字符）。
     */
    public static function activeThemeId(): string
    {
        if (self::$temporaryThemeId !== null) {
            return self::$temporaryThemeId;
        }

        $id = trim(SiteSettingsBag::get('active_theme', ''));
        if ($id === '') {
            $id = trim((string) config('geoflow.default_theme', ''));
        }

        return preg_match('/^[a-zA-Z0-9_-]+$/', $id) === 1 ? $id : '';
    }

    /**
     * 渲染第一个存在的视图：theme.{themeId}.{template} → site.{template}。
     *
     * @param  array<string, mixed>  $data
     */
    public static function first(string $template, array $data = []): ViewContract
    {
        $themeId = self::activeThemeId();
        if ($themeId !== '' && ! array_key_exists('activeThemeId', $data)) {
            $data['activeThemeId'] = $themeId;
        }

        return View::first(self::candidateViews($template), $data);
    }

    /**
     * @return list<string>
     */
    public static function candidateViews(string $template): array
    {
        $views = [];
        $themeId = self::activeThemeId();
        if ($themeId !== '') {
            if ($template === 'home' && self::usesSharedOfficialHomeShell($themeId)) {
                $views[] = 'theme.geoflow-template-shared.official-home';
            }

            $views[] = 'theme.'.$themeId.'.'.$template;
        }
        $views[] = 'site.'.$template;

        return $views;
    }

    public static function usingTheme(string $themeId, callable $callback): mixed
    {
        $previous = self::$temporaryThemeId;
        self::$temporaryThemeId = preg_match('/^[a-zA-Z0-9_-]+$/', $themeId) === 1 ? $themeId : '';

        try {
            return $callback();
        } finally {
            self::$temporaryThemeId = $previous;
        }
    }

    private static function usesSharedOfficialHomeShell(string $themeId): bool
    {
        return preg_match('/^geoflow-template-(0[1-9]|1[0-9]|20)-/', $themeId) === 1;
    }
}
