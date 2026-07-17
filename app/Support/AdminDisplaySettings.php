<?php

namespace App\Support;

use App\Models\SiteSetting;

final class AdminDisplaySettings
{
    public const QUICK_START_EYEBROW = 'admin_quick_start_eyebrow';
    public const QUICK_START_TITLE = 'admin_quick_start_title';
    public const QUICK_START_SUBTITLE = 'admin_quick_start_subtitle';
    public const FOOTER_BRAND = 'admin_footer_brand';
    public const FOOTER_VERSION = 'admin_footer_version';
    public const MEDIA_PLATFORM_1_LABEL = 'media_platform_1_label';
    public const MEDIA_PLATFORM_2_LABEL = 'media_platform_2_label';

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $defaults = self::defaults();

        try {
            $stored = SiteSetting::withoutGlobalScope('current_site')
                ->whereNull('site_id')
                ->whereIn('setting_key', array_keys($defaults))
                ->pluck('setting_value', 'setting_key')
                ->all();
        } catch (\Throwable) {
            $stored = [];
        }

        foreach ($defaults as $key => $defaultValue) {
            $value = trim((string) ($stored[$key] ?? ''));
            $stored[$key] = $value !== '' ? $value : $defaultValue;
        }

        return array_map(static fn ($value): string => (string) $value, $stored);
    }

    /**
     * @return array<int, string>
     */
    public static function mediaPlatformLabels(): array
    {
        $settings = self::all();

        return [
            1 => $settings[self::MEDIA_PLATFORM_1_LABEL],
            2 => $settings[self::MEDIA_PLATFORM_2_LABEL],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function update(array $payload): void
    {
        $keys = array_keys(self::defaults());

        SiteSetting::withoutEvents(function () use ($keys, $payload): void {
            foreach ($keys as $key) {
                if (! array_key_exists($key, $payload)) {
                    continue;
                }

                SiteSetting::withoutGlobalScope('current_site')->updateOrCreate(
                    [
                        'site_id' => null,
                        'setting_key' => $key,
                    ],
                    [
                        'site_id' => null,
                        'owner_admin_id' => null,
                        'setting_value' => trim((string) $payload[$key]),
                    ]
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    private static function defaults(): array
    {
        return [
            self::QUICK_START_EYEBROW => (string) __('admin.dashboard.quick_start.eyebrow'),
            self::QUICK_START_TITLE => (string) __('admin.dashboard.quick_start.title'),
            self::QUICK_START_SUBTITLE => (string) __('admin.dashboard.quick_start.subtitle'),
            self::FOOTER_BRAND => 'GEO',
            self::FOOTER_VERSION => (string) config('geoflow.app_version', '2.0'),
            self::MEDIA_PLATFORM_1_LABEL => '权威媒体',
            self::MEDIA_PLATFORM_2_LABEL => '优质媒体',
        ];
    }
}
