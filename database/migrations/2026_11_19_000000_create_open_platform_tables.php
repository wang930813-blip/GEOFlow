<?php

/**
 * Created by Codex.
 *
 * @Date: 2026-11-19
 *
 * @Time: 12:11:31
 *
 * @Author: cdkay
 *
 * @Email: network@iyuanma.net
 *
 * @File： 2026_11_19_000000_create_open_platform_tables.php
 *
 * @Description: 创建开放平台应用凭证和短期访问令牌数据表。
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('open_platform_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('owner_admin_id')->constrained('admins')->cascadeOnDelete();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('client_id', 64)->unique();
            $table->string('client_secret_hash', 255);
            $table->json('scopes');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['site_id', 'owner_admin_id', 'is_active'], 'open_platform_apps_tenant_active_idx');
        });

        Schema::create('open_platform_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('application_id')->constrained('open_platform_applications')->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['application_id', 'expires_at'], 'open_platform_tokens_app_expiry_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('open_platform_access_tokens');
        Schema::dropIfExists('open_platform_applications');
    }
};
