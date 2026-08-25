<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tasks') || ! Schema::hasColumn('tasks', 'ai_model_id')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tasks ALTER COLUMN ai_model_id DROP NOT NULL');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE tasks MODIFY ai_model_id BIGINT UNSIGNED NULL');
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tasks') || ! Schema::hasColumn('tasks', 'ai_model_id')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE tasks ALTER COLUMN ai_model_id SET NOT NULL');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE tasks MODIFY ai_model_id BIGINT UNSIGNED NOT NULL');
        }
    }
};
