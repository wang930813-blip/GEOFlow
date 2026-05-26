<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $defaultSiteId = $this->defaultSiteId();
        if ($defaultSiteId === null) {
            return;
        }

        foreach ($this->siteOwnedTables() as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'site_id')) {
                continue;
            }

            $this->deduplicateUniqueSiteRows($tableName, $defaultSiteId);

            DB::table($tableName)
                ->whereNull('site_id')
                ->update(['site_id' => $defaultSiteId]);
        }
    }

    public function down(): void
    {
        // Data repair only. Do not reintroduce null site_id values on rollback.
    }

    /**
     * @return list<string>
     */
    private function siteOwnedTables(): array
    {
        return [
            'media_resource_site_prices',
            'media_submissions',
            'site_credit_accounts',
            'site_credit_ledger',
            'site_members',
        ];
    }

    private function deduplicateUniqueSiteRows(string $tableName, int $defaultSiteId): void
    {
        if ($tableName === 'media_resource_site_prices' && Schema::hasColumn($tableName, 'media_resource_id')) {
            DB::table($tableName)
                ->whereNull('site_id')
                ->orderBy('id')
                ->select(['id', 'media_resource_id'])
                ->chunkById(200, function ($rows) use ($defaultSiteId, $tableName): void {
                    foreach ($rows as $row) {
                        $exists = DB::table($tableName)
                            ->where('site_id', $defaultSiteId)
                            ->where('media_resource_id', (int) $row->media_resource_id)
                            ->exists();

                        if ($exists) {
                            DB::table($tableName)->where('id', (int) $row->id)->delete();
                        }
                    }
                });

            return;
        }

        if ($tableName === 'site_credit_accounts') {
            $hasDefaultAccount = DB::table($tableName)
                ->where('site_id', $defaultSiteId)
                ->exists();

            if ($hasDefaultAccount) {
                DB::table($tableName)
                    ->whereNull('site_id')
                    ->delete();
            }

            return;
        }

        if ($tableName === 'site_members' && Schema::hasColumn($tableName, 'admin_id')) {
            DB::table($tableName)
                ->whereNull('site_id')
                ->orderBy('id')
                ->select(['id', 'admin_id'])
                ->chunkById(200, function ($rows) use ($defaultSiteId, $tableName): void {
                    foreach ($rows as $row) {
                        $exists = DB::table($tableName)
                            ->where('site_id', $defaultSiteId)
                            ->where('admin_id', (int) $row->admin_id)
                            ->exists();

                        if ($exists) {
                            DB::table($tableName)->where('id', (int) $row->id)->delete();
                        }
                    }
                });
        }
    }

    private function defaultSiteId(): ?int
    {
        if (! Schema::hasTable('sites')) {
            return null;
        }

        $siteId = DB::table('sites')->orderBy('id')->value('id');

        return $siteId !== null ? (int) $siteId : null;
    }
};
