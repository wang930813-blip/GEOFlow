<?php

namespace App\Services\BrandDiagnosis;

final class BrandDiagnosisPlatform
{
    public const DOUBAO = 'doubao';
    public const DEEPSEEK = 'deepseek';
    public const QIANWEN = 'qianwen';
    public const WENXIN = 'wenxin';

    public const CHATGPT = 'chatgpt';
    public const GROK = 'grok';
    public const GEMINI = 'gemini';

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
        return 'in:'.implode(',', array_values(array_unique(array_merge(self::keys(), self::publicKeys()))));
    }

    /**
     * @return list<string>
     */
    public static function publicKeys(): array
    {
        return [
            self::CHATGPT,
            self::GROK,
            self::GEMINI,
        ];
    }

    public static function publicValidationRule(): string
    {
        return 'in:'.implode(',', self::publicKeys());
    }

    public static function isSupported(string $platform): bool
    {
        return in_array(strtolower(trim($platform)), array_merge(self::keys(), self::publicKeys()), true);
    }

    public static function publicIsSupported(string $platform): bool
    {
        return in_array(strtolower(trim($platform)), self::publicKeys(), true);
    }

    public static function normalize(string $platform, string $default = self::DOUBAO): string
    {
        $platform = strtolower(trim($platform));

        return self::isSupported($platform) ? $platform : $default;
    }

    public static function publicNormalize(string $platform, string $default = self::CHATGPT): string
    {
        $platform = strtolower(trim($platform));

        return self::publicIsSupported($platform) ? $platform : $default;
    }

    public static function label(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return match ($platform) {
            self::CHATGPT => 'ChatGPT',
            self::GROK => 'Grok',
            self::GEMINI => 'Gemini',
            self::DEEPSEEK => 'DeepSeek',
            self::QIANWEN => '千问',
            self::WENXIN => '文心一言',
            self::DOUBAO => '豆包',
            default => $platform,
        };
    }

    public static function publicLabel(string $platform): string
    {
        $platform = self::publicNormalize($platform);
        $label = trim((string) config('brand_diagnosis.public_platforms.'.$platform.'.label', ''));

        return $label !== '' ? $label : match ($platform) {
            self::CHATGPT => 'ChatGPT',
            self::GROK => 'Grok',
            self::GEMINI => 'Gemini',
            default => $platform,
        };
    }

    public static function icon(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return match ($platform) {
            self::CHATGPT => 'CG',
            self::GROK => 'GK',
            self::GEMINI => 'GE',
            self::DEEPSEEK => 'DS',
            self::QIANWEN => 'QW',
            self::WENXIN => 'WX',
            self::DOUBAO => 'DB',
            default => mb_strtoupper(mb_substr($platform, 0, 2, 'UTF-8'), 'UTF-8'),
        };
    }

    public static function publicIcon(string $platform): string
    {
        $platform = self::publicNormalize($platform);
        $icon = trim((string) config('brand_diagnosis.public_platforms.'.$platform.'.icon', ''));

        return $icon !== '' ? $icon : mb_strtoupper(mb_substr($platform, 0, 2, 'UTF-8'), 'UTF-8');
    }

    public static function logoPath(string $platform): string
    {
        $platform = strtolower(trim($platform));

        $file = match ($platform) {
            self::CHATGPT => 'chatgpt.png',
            self::GROK => 'grok.png',
            self::GEMINI => 'gemini.png',
            self::DEEPSEEK => 'deepseek.png',
            self::QIANWEN => 'qianwen.png',
            self::WENXIN => 'wenxin.png',
            self::DOUBAO => 'doubao.png',
            default => '',
        };

        return $file !== '' ? 'assets/monitoring-center/assets/ai-platforms/'.$file : '';
    }

    public static function publicLogoPath(string $platform): string
    {
        $platform = self::publicNormalize($platform);

        return trim((string) config('brand_diagnosis.public_platforms.'.$platform.'.logo_path', ''));
    }

    public static function logoUrl(string $platform): string
    {
        $path = self::logoPath($platform);

        return $path !== '' ? asset($path) : '';
    }

    public static function publicLogoUrl(string $platform): string
    {
        $path = self::publicLogoPath($platform);

        return $path !== '' ? asset($path) : '';
    }

    public static function logoAbsolutePath(string $platform): string
    {
        $path = self::logoPath($platform);

        return $path !== '' ? public_path($path) : '';
    }

    public static function publicLogoAbsolutePath(string $platform): string
    {
        $path = self::publicLogoPath($platform);

        return $path !== '' ? public_path($path) : '';
    }

    public static function chatUrl(string $platform): string
    {
        return match (strtolower(trim($platform))) {
            self::CHATGPT => 'https://chatgpt.com/',
            self::GROK => 'https://grok.com/',
            self::GEMINI => 'https://gemini.google.com/',
            self::DOUBAO => 'https://www.doubao.com/chat/',
            self::DEEPSEEK => 'https://chat.deepseek.com/',
            self::QIANWEN => 'https://tongyi.aliyun.com/qianwen/',
            self::WENXIN => 'https://chat.baidu.com/',
            default => '',
        };
    }

    public static function publicChatUrl(string $platform): string
    {
        $platform = self::publicNormalize($platform);

        return trim((string) config('brand_diagnosis.public_platforms.'.$platform.'.chat_url', ''));
    }

    /**
     * @return list<string>
     */
    public static function officialShareDomains(string $platform): array
    {
        $platform = strtolower(trim($platform));
        if (self::publicIsSupported($platform)) {
            return self::publicOfficialShareDomains($platform);
        }

        return match (self::normalize($platform)) {
            self::DOUBAO => ['doubao.com'],
            self::DEEPSEEK => ['deepseek.com'],
            self::QIANWEN => ['tongyi.com', 'qianwen.com', 'aliyun.com', 'qwen.ai', 'qianwen.my.cn'],
            self::WENXIN => ['baidu.com'],
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function publicOfficialShareDomains(string $platform): array
    {
        $platform = self::publicNormalize($platform);
        $domains = (array) config('brand_diagnosis.public_platforms.'.$platform.'.official_share_domains', []);

        return collect($domains)
            ->map(static fn (mixed $domain): string => strtolower(trim((string) $domain)))
            ->filter(static fn (string $domain): bool => $domain !== '')
            ->unique()
            ->values()
            ->all();
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

    public static function publicIsOfficialShareUrl(string $platform, string $url): bool
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

        return collect(self::publicOfficialShareDomains($platform))
            ->contains(static fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain));
    }
}
