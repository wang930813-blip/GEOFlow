<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $base = trim((string) config('geoflow.customer_site_domain_base', 'geo.xinzhidi.cn'), " \t\n\r\0\x0B.");
        if ($base === '') {
            return;
        }

        DB::table('sites')
            ->where('customer_mode', 'agent')
            ->whereNotNull('agent_admin_id')
            ->where(function ($query): void {
                $query->whereNull('domain')->orWhere('domain', '');
            })
            ->whereRaw("TRIM(COALESCE(name, '')) != ''")
            ->orderBy('id')
            ->select(['id', 'name'])
            ->chunkById(100, function ($sites) use ($base): void {
                foreach ($sites as $site) {
                    $prefix = strtolower(trim((string) $site->name));
                    if (! preg_match('/^[a-z0-9]{8}$/', $prefix)) {
                        continue;
                    }

                    $domain = $prefix.'.'.$base;
                    $exists = DB::table('sites')
                        ->where('domain', $domain)
                        ->where('id', '!=', (int) $site->id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    DB::table('sites')
                        ->where('id', (int) $site->id)
                        ->update(['domain' => $domain]);
                }
            }, 'id');
    }

    public function down(): void
    {
        // Data backfill only. Do not clear domains that may already be in use.
    }
};
