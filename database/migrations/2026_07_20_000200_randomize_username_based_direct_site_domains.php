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
            ->whereNotNull('sites.domain')
            ->where('sites.domain', 'like', '%.'.$base)
            ->orderBy('sites.id')
            ->select(['sites.id', 'sites.domain'])
            ->chunkById(100, function ($sites) use ($base): void {
                foreach ($sites as $site) {
                    $prefix = Str::beforeLast((string) $site->domain, '.'.$base);
                    if (preg_match('/^[a-z0-9]{8}$/', $prefix) === 1) {
                        continue;
                    }

                    DB::table('sites')
                        ->where('id', (int) $site->id)
                        ->update([
                            'domain' => $this->uniqueRandomDomain($base, (int) $site->id),
                            'updated_at' => now(),
                        ]);
                }
            }, 'sites.id', 'id');
    }

    public function down(): void
    {
        // Data correction only. Do not restore username-based domains.
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
