<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('self_media_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('provider', 40)->default('aitoearn')->index();
            $table->string('platform', 40)->index();
            $table->string('external_account_id', 160);
            $table->string('account_name', 160)->default('');
            $table->string('avatar', 500)->default('');
            $table->string('status', 30)->default('bound')->index();
            $table->string('auth_status', 30)->default('authorized')->index();
            $table->timestamp('bound_at')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->json('raw_account')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'platform', 'external_account_id', 'owner_admin_id'], 'self_media_account_owner_unique');
            $table->index(['site_id', 'owner_admin_id', 'status'], 'self_media_account_owner_status_idx');
        });

        Schema::create('self_media_auth_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('provider', 40)->default('aitoearn')->index();
            $table->string('platform', 40)->index();
            $table->string('session_id', 160)->index();
            $table->string('authorization_url', 1000)->default('');
            $table->string('status', 30)->default('pending')->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_account_id')->nullable()->constrained('self_media_accounts')->nullOnDelete();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'session_id'], 'self_media_auth_provider_session_unique');
            $table->index(['site_id', 'owner_admin_id', 'platform', 'status'], 'self_media_auth_owner_status_idx');
        });

        Schema::create('self_media_publish_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('provider', 40)->default('aitoearn')->index();
            $table->string('content_type', 20)->index();
            $table->string('title', 255)->default('');
            $table->string('content_source_type', 40)->default('manual');
            $table->string('status', 30)->default('queued')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('quota_ledger_id')->nullable()->constrained('admin_resource_ledger')->nullOnDelete();
            $table->string('external_flow_id', 160)->default('')->index();
            $table->unsignedSmallInteger('sync_attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->json('payload')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'owner_admin_id', 'status'], 'self_media_publish_owner_status_idx');
            $table->index(['provider', 'status', 'scheduled_at'], 'self_media_publish_provider_status_idx');
        });

        Schema::create('self_media_publish_job_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->constrained('self_media_publish_jobs')->cascadeOnDelete();
            $table->foreignId('self_media_account_id')->nullable()->constrained('self_media_accounts')->nullOnDelete();
            $table->string('provider', 40)->default('aitoearn')->index();
            $table->string('platform', 40)->index();
            $table->string('external_account_id', 160)->default('');
            $table->string('external_task_id', 160)->default('')->index();
            $table->string('status', 30)->default('queued')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('message', 500)->default('');
            $table->string('published_url', 1000)->default('');
            $table->timestamp('published_at')->nullable();
            $table->json('payload')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('last_event_at')->nullable()->index();
            $table->timestamps();

            $table->index(['job_id', 'status'], 'self_media_publish_item_job_status_idx');
        });

        Schema::create('self_media_publish_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->constrained('self_media_publish_jobs')->cascadeOnDelete();
            $table->foreignId('job_item_id')->nullable()->constrained('self_media_publish_job_items')->nullOnDelete();
            $table->string('provider', 40)->default('aitoearn')->index();
            $table->string('external_task_id', 160)->default('')->index();
            $table->string('event_type', 60)->index();
            $table->unsignedTinyInteger('progress')->nullable();
            $table->string('message', 500)->default('');
            $table->json('raw_event')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['job_id', 'created_at'], 'self_media_publish_event_job_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('self_media_publish_events');
        Schema::dropIfExists('self_media_publish_job_items');
        Schema::dropIfExists('self_media_publish_jobs');
        Schema::dropIfExists('self_media_auth_sessions');
        Schema::dropIfExists('self_media_accounts');
    }
};
