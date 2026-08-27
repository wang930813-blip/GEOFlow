<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('self_media_accounts', function (Blueprint $table): void {
            $table->string('external_group_id', 160)->nullable()->index('self_media_accounts_external_group_idx');
        });

        Schema::table('self_media_auth_sessions', function (Blueprint $table): void {
            $table->string('external_group_id', 160)->nullable()->index('self_media_auth_sessions_external_group_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('self_media_auth_sessions', function (Blueprint $table): void {
            $table->dropIndex('self_media_auth_sessions_external_group_idx');
            $table->dropColumn('external_group_id');
        });

        Schema::table('self_media_accounts', function (Blueprint $table): void {
            $table->dropIndex('self_media_accounts_external_group_idx');
            $table->dropColumn('external_group_id');
        });
    }
};
