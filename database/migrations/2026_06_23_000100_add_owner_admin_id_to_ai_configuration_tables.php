<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['ai_models', 'prompts', 'site_settings'] as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'owner_admin_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->foreignId('owner_admin_id')
                    ->nullable()
                    ->after(Schema::hasColumn($tableName, 'site_id') ? 'site_id' : 'id')
                    ->constrained('admins')
                    ->nullOnDelete();
                $table->index('owner_admin_id', $tableName.'_owner_admin_id_index');
            });
        }
    }

    public function down(): void
    {
        foreach (['site_settings', 'prompts', 'ai_models'] as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'owner_admin_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                $table->dropForeign(['owner_admin_id']);
                $table->dropIndex($tableName.'_owner_admin_id_index');
                $table->dropColumn('owner_admin_id');
            });
        }
    }
};
