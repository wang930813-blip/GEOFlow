<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name', 80);
            $table->json('permissions');
            $table->timestamps();
            $table->unique(['site_id', 'name']);
            $table->index(['site_id', 'id']);
        });

        Schema::create('website_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('website_role_id')->constrained('website_roles')->restrictOnDelete();
            $table->string('username', 50);
            $table->string('display_name', 100)->default('');
            $table->string('password', 255);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('last_login')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'username']);
            $table->index(['site_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_users');
        Schema::dropIfExists('website_roles');
    }
};
