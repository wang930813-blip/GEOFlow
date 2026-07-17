<?php

namespace App\Services\BrandDiagnosis;

final class BrandDiagnosisPlatform
{
    public const DOUBAO = 'doubao';

    public const DEEPSEEK = 'deepseek';

    public const QIANWEN = 'qianwen';

    public const WENXIN = 'wenxin';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return [
            self::DOUBAO,
            self::DEEPSEEK,
            self::QIANWEN,
            self::WENXIN,
        ];
    }

    public static function validationRule(): string
    {
        return 'in:'.implode(',', self::keys());
    }

    public static function isSupported(string $platform): bool
    {
        return in_array(strtolower(trim($platform)), self::keys(), true);
    }

    public static function normalize(string $platform, string $default = self::DOUBAO): string
    {
        $platform = strtolower(trim($platform));

        return self::isSupported($platform) ? $platform : $default;
    }

    public static function label(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return match ($platform) {
            self::DEEPSEEK => 'DeepSeek',
            self::QIANWEN => '千问',
            self::WENXIN => '文心一言',
            self::DOUBAO => '豆包',
            default => $platform,
        };
    }

    public static function icon(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return match ($platform) {
            self::DEEPSEEK => 'DS',
            self::QIANWEN => 'QW',
            self::WENXIN => 'WX',
            self::DOUBAO => 'DB',
            default => mb_strtoupper(mb_substr($platform, 0, 2, 'UTF-8'), 'UTF-8'),
        };
    }

    public static function logoPath(string $platform): string
    {
        $platform = strtolower(trim($platform));

        $file = match ($platform) {
            self::DEEPSEEK => 'deepseek.png',
            self::QIANWEN => 'qianwen.png',
            self::WENXIN => 'wenxin.png',
            self::DOUBAO => 'doubao.png',
            default => '',
        };

        return $file !== '' ? 'assets/monitoring-center/assets/ai-platforms/'.$file : '';
    }

    public static function logoUrl(string $platform): string
    {
        $path = self::logoPath($platform);

        return $path !== '' ? asset($path) : '';
    }

    public static function logoAbsolutePath(string $platform): string
    {
        $path = self::logoPath($platform);

        return $path !== '' ? public_path($path) : '';
    }

    public static function chatUrl(string $platform): string
    {
        return match (strtolower(trim($platform))) {
            self::DOUBAO => 'https://www.doubao.com/chat/',
            self::DEEPSEEK => 'https://chat.deepseek.com/',
            self::QIANWEN => 'https://tongyi.aliyun.com/qianwen/',
            self::WENXIN => 'https://chat.baidu.com/',
            default => '',
        };
    }

    /**
     * @return list<string>
     */
    public static function officialShareDomains(string $platform): array
    {
        return match (self::normalize($platform)) {
            self::DOUBAO => ['doubao.com'],
            self::DEEPSEEK => ['deepseek.com'],
            self::QIANWEN => ['tongyi.com', 'qianwen.com', 'aliyun.com', 'qwen.ai'],
            self::WENXIN => ['baidu.com'],
        };
    }

    public static function isOfficialShareUrl(string $platform, string $url): bool
    {
        $url = trim($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = rtrim(strtolower((string) ($parts['host'] ?? '')), '.');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        return collect(self::officialShareDomains($platform))
            ->contains(static fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain));
    }
}
