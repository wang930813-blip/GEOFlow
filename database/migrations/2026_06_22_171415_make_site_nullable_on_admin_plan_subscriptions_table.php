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
        if (Schema::hasTable('admin_plan_subscriptions')) {
            Schema::table('admin_plan_subscriptions', function (Blueprint $table): void {
                $table->foreignId('site_id')->nullable()->change();
                $table->index(['admin_id', 'mode', 'status', 'starts_at', 'ends_at'], 'admin_plan_subscriptions_owner_idx');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('admin_plan_subscriptions')) {
            Schema::table('admin_plan_subscriptions', function (Blueprint $table): void {
                $table->dropIndex('admin_plan_subscriptions_owner_idx');
                $table->foreignId('site_id')->nullable(false)->change();
            });
        }
    }
};
