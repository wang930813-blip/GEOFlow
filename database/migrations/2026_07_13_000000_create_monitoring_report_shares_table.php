<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_report_shares', function (Blueprint $table): void {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->string('report_type', 20)->index();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('title', 255)->default('');
            $table->json('payload');
            $table->boolean('use_virtual_search_report_data')->default(false);
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamps();

            $table->index(['site_id', 'report_type']);
            $table->index(['owner_admin_id', 'report_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_report_shares');
    }
};
