<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('media_api_settings') && ! Schema::hasColumn('media_api_settings', 'price_multiplier')) {
            Schema::table('media_api_settings', function (Blueprint $table): void {
                $table->decimal('price_multiplier', 8, 2)->default(1)->after('status');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('media_api_settings') && Schema::hasColumn('media_api_settings', 'price_multiplier')) {
            Schema::table('media_api_settings', function (Blueprint $table): void {
                $table->dropColumn('price_multiplier');
            });
        }
    }
};
