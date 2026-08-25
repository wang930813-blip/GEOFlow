<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('media_api_settings')) {
            Schema::create('media_api_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('api_base_url', 500)->default('');
                $table->text('api_key_ciphertext')->nullable();
                $table->string('status', 30)->default('inactive')->index();
                $table->timestamp('last_checked_at')->nullable();
                $table->text('last_error_message')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('media_resources')) {
            Schema::create('media_resources', function (Blueprint $table): void {
                $table->id();
                $table->string('source_type', 40);
                $table->string('external_resource_id', 120);
                $table->string('title', 255);
                $table->string('category', 120)->default('');
                $table->text('remarks')->nullable();
                $table->string('case_link', 500)->default('');
                $table->string('status', 30)->default('active')->index();
                $table->decimal('cost_price', 12, 2)->default(0);
                $table->decimal('sale_price', 12, 2)->default(0);
                $table->json('raw_payload')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->timestamps();

                $table->unique(['source_type', 'external_resource_id'], 'media_resources_source_external_unique');
                $table->index(['source_type', 'status']);
                $table->index('title');
            });
        }

        if (! Schema::hasTable('site_credit_accounts')) {
            Schema::create('site_credit_accounts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->decimal('balance', 12, 2)->default(0);
                $table->decimal('frozen_balance', 12, 2)->default(0);
                $table->decimal('total_recharged', 12, 2)->default(0);
                $table->decimal('total_consumed', 12, 2)->default(0);
                $table->timestamps();

                $table->unique('site_id');
            });
        }

        if (! Schema::hasTable('media_submissions')) {
            Schema::create('media_submissions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('article_id')->constrained('articles')->cascadeOnDelete();
                $table->foreignId('media_resource_id')->constrained('media_resources')->cascadeOnDelete();
                $table->string('source_type', 40);
                $table->string('external_order_nid', 120)->default('')->index();
                $table->string('title_snapshot', 255);
                $table->longText('content_snapshot');
                $table->decimal('cost_price_snapshot', 12, 2)->default(0);
                $table->decimal('sale_price_snapshot', 12, 2)->default(0);
                $table->decimal('points_amount', 12, 2)->default(0);
                $table->string('status', 30)->default('queued')->index();
                $table->text('remark')->nullable();
                $table->string('published_url', 500)->default('');
                $table->text('last_error_message')->nullable();
                $table->foreignId('submitted_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->json('raw_submit_response')->nullable();
                $table->json('raw_status_response')->nullable();
                $table->timestamps();

                $table->index(['site_id', 'status']);
                $table->index('article_id');
                $table->index('media_resource_id');
            });
        }

        if (! Schema::hasTable('site_credit_ledger')) {
            Schema::create('site_credit_ledger', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('submission_id')->nullable()->constrained('media_submissions')->nullOnDelete();
                $table->string('type', 30)->index();
                $table->decimal('amount', 12, 2);
                $table->decimal('balance_after', 12, 2)->default(0);
                $table->decimal('frozen_after', 12, 2)->default(0);
                $table->text('remark')->nullable();
                $table->foreignId('operator_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->timestamp('created_at')->nullable();

                $table->index(['site_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_credit_ledger');
        Schema::dropIfExists('media_submissions');
        Schema::dropIfExists('site_credit_accounts');
        Schema::dropIfExists('media_resources');
        Schema::dropIfExists('media_api_settings');
    }
};
