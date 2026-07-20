<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $base = trim((string) config('geoflow.customer_site_domain_base', 'geo.xinzhidi.cn'), " \t\n\r\0\x0B.");
        if ($base === '') {
            return;
        }

        DB::table('sites')
            ->join('admins', 'admins.id', '=', 'sites.owner_admin_id')
            ->where('sites.customer_mode', 'direct')
            ->where('admins.role', 'direct_admin')
            ->where(function ($query): void {
                $query->whereNull('sites.domain')->orWhere('sites.domain', '');
            })
            ->orderBy('sites.id')
            ->select(['sites.id', 'admins.username'])
            ->chunkById(100, function ($sites) use ($base): void {
                foreach ($sites as $site) {
                    $domain = $this->uniqueRandomDomain($base, (int) $site->id);
                    if ($domain === '') {
                        continue;
                    }

                    DB::table('sites')
                        ->where('id', (int) $site->id)
                        ->update([
                            'domain' => $domain,
                            'updated_at' => now(),
                        ]);
                }
            }, 'sites.id', 'id');
    }

    public function down(): void
    {
        // Data backfill only. Domains may already be in use, so do not clear them.
    }

    private function uniqueRandomDomain(string $base, int $siteId): string
    {
        do {
            $domain = Str::lower(Str::random(8)).'.'.$base;

            $exists = DB::table('sites')
                ->where('domain', $domain)
                ->where('id', '!=', $siteId)
                ->exists();
        } while ($exists);

        return $domain;
    }
};
