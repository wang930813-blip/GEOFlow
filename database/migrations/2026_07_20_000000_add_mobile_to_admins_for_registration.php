<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admins') || Schema::hasColumn('admins', 'mobile')) {
            return;
        }

        Schema::table('admins', function (Blueprint $table): void {
            $table->string('mobile', 32)->nullable()->after('email');
            $table->unique('mobile', 'admins_mobile_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('admins') || ! Schema::hasColumn('admins', 'mobile')) {
            return;
        }

        Schema::table('admins', function (Blueprint $table): void {
            try {
                $table->dropUnique('admins_mobile_unique');
            } catch (Throwable) {
                // Keep rollback tolerant across existing database variants.
            }

            $table->dropColumn('mobile');
        });
    }
};
