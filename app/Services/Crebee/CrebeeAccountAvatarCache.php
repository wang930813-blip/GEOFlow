<?php

namespace App\Services\Crebee;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CrebeeAccountAvatarCache
{
    public function cache(string $url, string $platform, string $accountId): ?string
    {
        $url = $this->normalizeImageUrl($url);
        if ($url === '' || ! str_starts_with(strtolower($url), 'https://')) {
            return $url !== '' ? $url : null;
        }

        try {
            $response = Http::timeout(8)
                ->connectTimeout(3)
                ->withHeaders(['User-Agent' => 'GEOFlow/CreBeeAvatarCache'])
                ->get($url);

            if (! $response->ok() || $response->body() === '') {
                return $url;
            }

            $extension = $this->extensionFromContentType((string) $response->header('Content-Type'))
                ?? $this->extensionFromUrl($url)
                ?? 'jpg';
            $filename = sprintf(
                'crebee-avatars/%s-%s.%s',
                Str::slug($platform) ?: 'platform',
                sha1($accountId.'|'.$url),
                $extension
            );

            Storage::disk('public')->put($filename, $response->body());

            return Storage::url($filename);
        } catch (Throwable) {
            return $url;
        }
    }

    public function normalizeImageUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        if (str_starts_with(strtolower($url), 'http://')) {
            return 'https://'.substr($url, 7);
        }

        return $url;
    }

    private function extensionFromContentType(string $contentType): ?string
    {
        $contentType = strtolower(trim(explode(';', $contentType)[0] ?? ''));

        return match ($contentType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
    }

    private function extensionFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path)) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
            ? ($extension === 'jpeg' ? 'jpg' : $extension)
            : null;
    }
}
