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
        Schema::table('self_media_auth_sessions', function (Blueprint $table) {
            $table->text('authorization_url')->default('')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('self_media_auth_sessions', function (Blueprint $table) {
            $table->string('authorization_url', 1000)->default('')->change();
        });
    }
};
