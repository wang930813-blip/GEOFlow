<?php

namespace App\Support\MediaDistribution;

use App\Support\AdminDisplaySettings;

final class MediaPlatform
{
    public const CEYING_MEDIA_1 = 1;
    public const CEYING_MEDIA_2 = 2;

    /**
     * @return array<int,string>
     */
    public static function labels(): array
    {
        return AdminDisplaySettings::mediaPlatformLabels();
    }

    public static function label(int|string|null $platformId): string
    {
        return self::labels()[(int) $platformId] ?? '未知媒体平台';
    }

    /**
     * @return list<int>
     */
    public static function ids(): array
    {
        return [self::CEYING_MEDIA_1, self::CEYING_MEDIA_2];
    }
}
