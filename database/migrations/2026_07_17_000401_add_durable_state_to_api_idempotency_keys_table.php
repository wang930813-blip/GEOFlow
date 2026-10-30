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
        if (! Schema::hasTable('api_idempotency_keys')) {
            return;
        }

        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            if (! Schema::hasColumn('api_idempotency_keys', 'state')) {
                $table->string('state', 20)->default('completed');
            }
            if (! Schema::hasColumn('api_idempotency_keys', 'owner_token')) {
                $table->char('owner_token', 64)->nullable();
            }
            if (! Schema::hasColumn('api_idempotency_keys', 'lease_expires_at')) {
                $table->timestamp('lease_expires_at')->nullable();
            }
            if (! Schema::hasIndex('api_idempotency_keys', 'api_idempotency_keys_state_index')) {
                $table->index('state', 'api_idempotency_keys_state_index');
            }
            if (! Schema::hasIndex('api_idempotency_keys', 'api_idempotency_keys_lease_expires_at_index')) {
                $table->index('lease_expires_at', 'api_idempotency_keys_lease_expires_at_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('api_idempotency_keys')) {
            return;
        }

        $stateIsManagedByDomesticMigration = Schema::hasColumn('api_idempotency_keys', 'processing_started_at');

        Schema::table('api_idempotency_keys', function (Blueprint $table) use ($stateIsManagedByDomesticMigration): void {
            if (Schema::hasIndex('api_idempotency_keys', 'api_idempotency_keys_lease_expires_at_index')) {
                $table->dropIndex('api_idempotency_keys_lease_expires_at_index');
            }
            if (Schema::hasColumn('api_idempotency_keys', 'lease_expires_at')) {
                $table->dropColumn('lease_expires_at');
            }
            if (Schema::hasColumn('api_idempotency_keys', 'owner_token')) {
                $table->dropColumn('owner_token');
            }
            if (! $stateIsManagedByDomesticMigration) {
                if (Schema::hasIndex('api_idempotency_keys', 'api_idempotency_keys_state_index')) {
                    $table->dropIndex('api_idempotency_keys_state_index');
                }
                if (Schema::hasColumn('api_idempotency_keys', 'state')) {
                    $table->dropColumn('state');
                }
            }
        });
    }
};
