<?php

namespace App\Support\Crebee;

class SelfMediaPlatformCatalog
{
    /**
     * @return array<string,array{label:string,desc:string,logo:string}>
     */
    public static function all(): array
    {
        return [
            'douyin' => ['label' => '抖音', 'desc' => '短视频 / 图文', 'logo' => '01.png'],
            'xiaohongshu' => ['label' => '小红书', 'desc' => '笔记 / 图文', 'logo' => '02.png'],
            'kuaishou' => ['label' => '快手', 'desc' => '短视频 / 图文', 'logo' => '03.png'],
            'shipinhao' => ['label' => '视频号', 'desc' => '视频 / 图文', 'logo' => '04.png'],
            'bilibili' => ['label' => 'B站', 'desc' => '视频 / 专栏', 'logo' => '05.png'],
            'zhihu' => ['label' => '知乎', 'desc' => '文章 / 回答', 'logo' => '06.png'],
            'weibo' => ['label' => '微博', 'desc' => '图文 / 视频', 'logo' => '07.png'],
            'baijiahao' => ['label' => '百家号', 'desc' => '文章 / 视频', 'logo' => '08.png'],
            'toutiaohao' => ['label' => '头条号', 'desc' => '文章 / 视频', 'logo' => '09.png'],
            'gongzhonghao' => ['label' => '公众号', 'desc' => '账号数据 / 文章发布', 'logo' => '10.png'],
            'qiehao' => ['label' => '企鹅号', 'desc' => '文章 / 视频', 'logo' => '11.png'],
            'wangyihao' => ['label' => '网易号', 'desc' => '文章 / 视频', 'logo' => '12.png'],
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
     * @return array<string,string>
     */
    public static function videoPlatformLabels(): array
    {
        return collect(self::all())
            ->only(self::videoPlatforms())
            ->mapWithKeys(fn (array $platform, string $key): array => [$key => (string) $platform['label']])
            ->all();
    }

    /**
     * @return array<int,string>
     */
    public static function videoPlatforms(): array
    {
        return [
            'douyin',
            'bilibili',
            'kuaishou',
            'shipinhao',
            'xiaohongshu',
            'zhihu',
            'weibo',
            'baijiahao',
            'toutiaohao',
            'qiehao',
            'wangyihao',
        ];
    }

    public static function label(string $platform): string
    {
        return (string) (self::all()[$platform]['label'] ?? $platform);
    }

    public static function logoPath(string $platform): string
    {
        $logo = (string) (self::all()[$platform]['logo'] ?? ($platform.'.png'));

        return 'assets/self-media-platforms/'.$logo;
    }
}
