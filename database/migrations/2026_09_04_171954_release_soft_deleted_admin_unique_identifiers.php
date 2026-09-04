<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('admins') || ! Schema::hasColumn('admins', 'deleted_at')) {
            return;
        }

        $hasMobile = Schema::hasColumn('admins', 'mobile');

        DB::table('admins')
            ->whereNotNull('deleted_at')
            ->orderBy('id')
            ->select(['id', 'username'])
            ->chunkById(100, function ($admins) use ($hasMobile): void {
                foreach ($admins as $admin) {
                    $attributes = [
                        'username' => $this->releasedUsername((int) $admin->id, (string) $admin->username),
                    ];

                    if ($hasMobile) {
                        $attributes['mobile'] = null;
                    }

                    DB::table('admins')
                        ->where('id', (int) $admin->id)
                        ->update($attributes);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The original usernames cannot be reconstructed after release.
    }

    private function releasedUsername(int $id, string $username): string
    {
        return 'deleted_'.$id.'_'.substr(sha1($id.'|'.$username), 0, 16);
    }
};
