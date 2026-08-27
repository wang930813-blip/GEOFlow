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
        Schema::create('self_media_account_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('provider', 40)->default('aitoearn')->index();
            $table->string('external_group_id', 160);
            $table->string('group_name', 160)->default('');
            $table->boolean('is_default')->default(false)->index();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'site_id', 'owner_admin_id'], 'self_media_account_group_owner_unique');
            $table->index(['provider', 'external_group_id'], 'self_media_account_group_external_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('self_media_account_groups');
    }
};
