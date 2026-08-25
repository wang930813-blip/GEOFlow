<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_resource_sync_runs')) {
            Schema::create('media_resource_sync_runs', function (Blueprint $table): void {
                $table->id();
                $table->string('status', 30)->default('pending')->index();
                $table->string('current_source_type', 40)->default('');
                $table->unsignedInteger('current_page')->default(0);
                $table->unsignedInteger('website_synced')->default(0);
                $table->unsignedInteger('zi_media_synced')->default(0);
                $table->unsignedInteger('total_synced')->default(0);
                $table->text('last_error_message')->nullable();
                $table->foreignId('started_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media_resource_sync_runs');
    }
};
