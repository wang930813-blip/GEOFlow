<?php

namespace App\Support\SelfMedia;

class SelfMediaPlatformCatalog
{
    /**
     * @return array<string,array{label:string,desc:string,logo:string}>
     */
    public static function all(): array
    {
        return [
            'douyin' => ['label' => '抖音', 'desc' => '短视频 / 图文 / 文章', 'logo' => '01.png'],
            'xhs' => ['label' => '小红书', 'desc' => '笔记 / 图文', 'logo' => '02.png'],
            'KWAI' => ['label' => '快手', 'desc' => '短视频 / 图文', 'logo' => '03.png'],
            'wxSph' => ['label' => '视频号', 'desc' => '视频 / 图文', 'logo' => '04.png'],
            'bilibili' => ['label' => 'B站', 'desc' => '视频', 'logo' => '05.png'],
            'wxGzh' => ['label' => '公众号', 'desc' => '账号数据 / 文章发布', 'logo' => '10.png'],
            'youtube' => ['label' => 'YouTube', 'desc' => '视频', 'logo' => 'youtube.png'],
            'twitter' => ['label' => 'X / Twitter', 'desc' => '图文 / 视频', 'logo' => 'twitter.png'],
            'tiktok' => ['label' => 'TikTok', 'desc' => '短视频', 'logo' => 'tiktok.png'],
            'facebook' => ['label' => 'Facebook', 'desc' => '图文 / 视频', 'logo' => 'facebook.png'],
            'instagram' => ['label' => 'Instagram', 'desc' => '图文 / 视频', 'logo' => 'instagram.png'],
            'threads' => ['label' => 'Threads', 'desc' => '图文', 'logo' => 'threads.png'],
            'pinterest' => ['label' => 'Pinterest', 'desc' => '图文', 'logo' => 'pinterest.png'],
            'linkedin' => ['label' => 'LinkedIn', 'desc' => '图文 / 文章', 'logo' => 'linkedin.png'],
        ];
    }

    /**
     * @return array<string,string>
     */
    public static function labels(): array
    {
        return collect(self::all())
            ->mapWithKeys(fn (array $platform, string $key): array => [$key => (string) $platform['label']])
            ->all();
    }

    /**
     * @return array<int,string>
     */
    public static function domesticPlatforms(): array
    {
        return ['douyin', 'xhs', 'KWAI', 'wxSph', 'bilibili', 'wxGzh'];
    }

    /**
     * @param  array<string,array<string,mixed>>  $platforms
     * @return array<string,array<string,mixed>>
     */
    public static function onlyDomestic(array $platforms): array
    {
        return array_intersect_key($platforms, array_flip(self::domesticPlatforms()));
    }

    public static function isDomestic(string $platform): bool
    {
        return in_array($platform, self::domesticPlatforms(), true);
    }

    /**
     * @return array<int,string>
     */
    public static function articlePlatforms(): array
    {
        return ['douyin', 'wxGzh'];
    }

    /**
     * @return array<int,string>
     */
    public static function videoPlatforms(): array
    {
        return ['douyin', 'bilibili', 'KWAI', 'wxSph'];
    }

    public static function label(string $platform): string
    {
        return (string) (self::all()[$platform]['label'] ?? $platform);
    }

    public static function description(string $platform): string
    {
        return (string) (self::all()[$platform]['desc'] ?? '内容发布');
    }

    public static function logoPath(string $platform): string
    {
        $logo = (string) (self::all()[$platform]['logo'] ?? ($platform.'.png'));

        return 'assets/self-media-platforms/'.$logo;
    }
}
