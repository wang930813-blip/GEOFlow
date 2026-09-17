<?php

namespace App\Support\Site;

use Illuminate\Http\Request;
use InvalidArgumentException;

final class SitePageUrl
{
    private const PREVIEW_THEME_ATTRIBUTE = 'site_theme_preview';

    /** @var array<string, string> */
    private const PUBLIC_ROUTES = [
        'home' => 'site.home',
        'about' => 'site.about',
        'products' => 'site.products',
        'news' => 'site.news',
        'contact' => 'site.contact',
        'article' => 'site.article',
    ];

    public static function markPreview(Request $request, string $theme): void
    {
        $request->attributes->set(self::PREVIEW_THEME_ATTRIBUTE, $theme);
    }

    public static function isPreview(): bool
    {
        return self::previewTheme() !== '';
    }

    /**
     * Generate a public site URL, or keep the link inside the current admin theme preview.
     *
     * @param  array<string, scalar>  $parameters
     */
    public static function to(string $page, array $parameters = []): string
    {
        if (! isset(self::PUBLIC_ROUTES[$page])) {
            throw new InvalidArgumentException('Unsupported site page: '.$page);
        }

        $theme = self::previewTheme();
        if ($theme !== '') {
            return route('admin.site-settings.themes.preview', [
                'theme' => $theme,
                'preview_page' => $page,
                ...$parameters,
            ]);
        }

        return route(self::PUBLIC_ROUTES[$page], $parameters);
    }

    private static function previewTheme(): string
    {
        $theme = request()->attributes->get(self::PREVIEW_THEME_ATTRIBUTE, '');

        return is_string($theme) && preg_match('/^[A-Za-z0-9_-]+$/', $theme) === 1
            ? $theme
            : '';
    }
}
