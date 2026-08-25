<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_settings') || ! Schema::hasColumn('site_settings', 'site_id')) {
            return;
        }

        Schema::table('site_settings', function (Blueprint $table): void {
            try {
                $table->dropUnique('site_settings_setting_key_unique');
            } catch (Throwable) {
                // Older installs may already have the scoped index.
            }

            $table->unique(['site_id', 'setting_key'], 'site_settings_site_id_setting_key_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('site_settings')) {
            return;
        }

        Schema::table('site_settings', function (Blueprint $table): void {
            try {
                $table->dropUnique('site_settings_site_id_setting_key_unique');
            } catch (Throwable) {
                // Keep rollback tolerant across SQLite/Postgres dev databases.
            }

            try {
                $table->unique('setting_key', 'site_settings_setting_key_unique');
            } catch (Throwable) {
                // Duplicate keys across sites cannot be collapsed safely during rollback.
            }
        });
    }
};
