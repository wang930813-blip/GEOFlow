<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sites') || Schema::hasColumn('sites', 'custom_domain')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->string('custom_domain', 255)->default('')->after('domain');
            $table->index('custom_domain');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sites') || ! Schema::hasColumn('sites', 'custom_domain')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table): void {
            $table->dropIndex(['custom_domain']);
            $table->dropColumn('custom_domain');
        });
    }
};
