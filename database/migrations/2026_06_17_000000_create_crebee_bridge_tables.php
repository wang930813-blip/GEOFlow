<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crebee_agents', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('agent_uid', 120)->unique();
            $table->string('secret_hash');
            $table->string('status', 30)->default('active')->index();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('crebee_base_url', 255)->default('http://127.0.0.1:3456');
            $table->string('crebee_status', 30)->default('unknown');
            $table->string('version', 60)->default('');
            $table->json('site_scope')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('crebee_bind_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('crebee_agents')->nullOnDelete();
            $table->foreignId('operator_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('platform', 40)->index();
            $table->string('status', 30)->default('pending')->index();
            $table->text('failure_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('requested_at')->nullable()->index();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('expired_at')->nullable()->index();
            $table->timestamps();

            $table->index(['site_id', 'owner_admin_id', 'status']);
        });

        Schema::create('crebee_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('agent_id')->constrained('crebee_agents')->cascadeOnDelete();
            $table->string('platform', 40)->index();
            $table->string('crebee_account_id', 160);
            $table->string('account_name', 160)->default('');
            $table->string('avatar', 500)->default('');
            $table->string('status', 30)->default('available')->index();
            $table->timestamp('bound_at')->nullable();
            $table->timestamp('last_synced_at')->nullable()->index();
            $table->json('raw_account')->nullable();
            $table->timestamps();

            $table->unique(['agent_id', 'platform', 'crebee_account_id'], 'crebee_accounts_agent_platform_account_unique');
            $table->index(['site_id', 'owner_admin_id', 'status']);
        });

        Schema::create('crebee_publish_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('agent_id')->constrained('crebee_agents')->cascadeOnDelete();
            $table->string('content_type', 20)->index();
            $table->string('title', 255)->default('');
            $table->string('content_source_type', 40)->default('manual');
            $table->string('status', 30)->default('queued')->index();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('dispatch_started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('quota_ledger_id')->nullable()->constrained('admin_resource_ledger')->nullOnDelete();
            $table->text('failure_reason')->nullable();
            $table->json('payload')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status', 'scheduled_at']);
            $table->index(['site_id', 'owner_admin_id', 'status']);
        });

        Schema::create('crebee_publish_job_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->constrained('crebee_publish_jobs')->cascadeOnDelete();
            $table->foreignId('crebee_account_id')->nullable()->constrained('crebee_accounts')->nullOnDelete();
            $table->string('platform', 40)->index();
            $table->string('crebee_task_id', 120)->index();
            $table->string('status', 30)->default('queued')->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('message', 500)->default('');
            $table->string('published_url', 1000)->default('');
            $table->timestamp('published_at')->nullable();
            $table->json('payload')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('last_event_at')->nullable()->index();
            $table->timestamps();

            $table->unique(['job_id', 'crebee_task_id'], 'crebee_items_job_task_unique');
        });

        Schema::create('crebee_publish_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_id')->constrained('crebee_publish_jobs')->cascadeOnDelete();
            $table->foreignId('job_item_id')->nullable()->constrained('crebee_publish_job_items')->nullOnDelete();
            $table->string('crebee_task_id', 120)->index();
            $table->string('event_type', 60)->index();
            $table->unsignedTinyInteger('progress')->nullable();
            $table->string('message', 500)->default('');
            $table->json('raw_event')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['job_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crebee_publish_events');
        Schema::dropIfExists('crebee_publish_job_items');
        Schema::dropIfExists('crebee_publish_jobs');
        Schema::dropIfExists('crebee_accounts');
        Schema::dropIfExists('crebee_bind_requests');
        Schema::dropIfExists('crebee_agents');
    }
};
