<?php

namespace App\Support\MediaDistribution;

final class MediaPlatform
{
    public const CEYING_MEDIA_1 = 1;
    public const CEYING_MEDIA_2 = 2;

    /**
     * @return array<int,string>
     */
    public static function labels(): array
    {
        return [
            self::CEYING_MEDIA_1 => '权威媒体',
            self::CEYING_MEDIA_2 => '优质媒体',
        ];
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
        return array_keys(self::labels());
    }
}
