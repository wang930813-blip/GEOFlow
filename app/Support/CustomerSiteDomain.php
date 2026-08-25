<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Support\Str;

final class CustomerSiteDomain
{
    public function uniqueRandomDomain(): string
    {
        $base = $this->baseDomain();
        if ($base === '') {
            return '';
        }

        do {
            $domain = Str::lower(Str::random(8)).'.'.$base;
        } while (Site::query()->where('domain', $domain)->exists());

        return $domain;
    }

    public function uniqueDomain(string $seed, ?int $ignoreSiteId = null): string
    {
        $base = $this->baseDomain();
        if ($base === '') {
            return '';
        }

        $basePrefix = $this->normalizePrefix($seed);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $prefix = $attempt === 0
                ? $basePrefix
                : $basePrefix.'-'.Str::lower(Str::random(4));
            $domain = $prefix.'.'.$base;

            $exists = Site::query()
                ->where('domain', $domain)
                ->when($ignoreSiteId !== null, fn ($query) => $query->whereKeyNot($ignoreSiteId))
                ->exists();

            if (! $exists) {
                return $domain;
            }
        }

        do {
            $domain = $basePrefix.'-'.Str::lower(Str::random(8)).'.'.$base;
        } while (
            Site::query()
                ->where('domain', $domain)
                ->when($ignoreSiteId !== null, fn ($query) => $query->whereKeyNot($ignoreSiteId))
                ->exists()
        );

        return $domain;
    }

    public function baseDomain(): string
    {
        return trim((string) config('geoflow.customer_site_domain_base', 'geo.xinzhidi.cn'), " \t\n\r\0\x0B.");
    }

    private function normalizePrefix(string $seed): string
    {
        $prefix = Str::lower(trim($seed));
        $prefix = preg_replace('/[^a-z0-9-]+/', '-', $prefix) ?? '';
        $prefix = trim($prefix, '-');
        $prefix = preg_replace('/-+/', '-', $prefix) ?? '';
        $prefix = trim($prefix, '-');

        if ($prefix === '') {
            $prefix = 'site';
        }

        return Str::limit($prefix, 48, '');
    }
}
