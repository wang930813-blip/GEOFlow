<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (! Schema::hasColumn('tasks', 'image_mode')) {
                $table->string('image_mode', 20)->default('library')->after('image_library_id');
            }

            if (! Schema::hasColumn('tasks', 'ai_image_model_id')) {
                $table->foreignId('ai_image_model_id')
                    ->nullable()
                    ->after('image_count')
                    ->constrained('ai_models')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            if (Schema::hasColumn('tasks', 'ai_image_model_id')) {
                $table->dropConstrainedForeignId('ai_image_model_id');
            }

            if (Schema::hasColumn('tasks', 'image_mode')) {
                $table->dropColumn('image_mode');
            }
        });
    }
};
