<?php

namespace App\Support;

final class SiteDomain
{
    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $host = parse_url($value, PHP_URL_HOST);
        if (! is_string($host) || trim($host) === '') {
            $withoutPath = preg_split('~[/?#]~', $value, 2)[0] ?? '';
            $host = preg_replace('/:\d+$/', '', trim($withoutPath));
        }

        $host = trim((string) $host);
        $host = preg_replace('/:\d+$/', '', $host) ?? '';
        $host = trim($host, " \t\n\r\0\x0B.");

        if ($host === '' || preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return '';
        }

        return $host;
    }
}
