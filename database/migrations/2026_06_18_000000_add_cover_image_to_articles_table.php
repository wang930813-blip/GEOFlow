<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            if (! Schema::hasColumn('articles', 'cover_image')) {
                $table->string('cover_image', 1000)->default('')->after('excerpt');
            }
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table): void {
            if (Schema::hasColumn('articles', 'cover_image')) {
                $table->dropColumn('cover_image');
            }
        });
    }
};
