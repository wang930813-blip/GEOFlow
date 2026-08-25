<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens') || Schema::hasColumn('personal_access_tokens', 'site_id')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('site_id')
                ->nullable()
                ->after('tokenable_id')
                ->constrained('sites')
                ->nullOnDelete();
            $table->index('site_id', 'personal_access_tokens_site_id_index');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('personal_access_tokens') || ! Schema::hasColumn('personal_access_tokens', 'site_id')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropForeign(['site_id']);
            $table->dropIndex('personal_access_tokens_site_id_index');
            $table->dropColumn('site_id');
        });
    }
};
