<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'fixed_title_id')) {
                $table->foreignId('fixed_title_id')
                    ->nullable()
                    ->after('title_library_id')
                    ->constrained('titles')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('tasks', 'fixed_title_id')) {
                $table->dropConstrainedForeignId('fixed_title_id');
            }
        });
    }
};
