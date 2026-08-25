<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('admin_plan_subscriptions')) {
            Schema::create('admin_plan_subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('plan_id')->nullable()->constrained('platform_plans')->nullOnDelete();
                $table->foreignId('source_subscription_id')->nullable()->constrained('site_plan_subscriptions')->nullOnDelete();
                $table->foreignId('inherited_from_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->string('mode', 30)->default('direct_owner')->index();
                $table->string('status', 20)->default('active')->index();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->json('entitlements_snapshot')->nullable();
                $table->text('remark')->nullable();
                $table->timestamps();

                $table->index(['admin_id', 'site_id', 'status'], 'admin_plan_subscriptions_account_idx');
                $table->index(['site_id', 'status', 'starts_at', 'ends_at'], 'admin_plan_subscriptions_active_idx');
            });
        }

        if (! Schema::hasTable('admin_resource_usages')) {
            Schema::create('admin_resource_usages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('subscription_id')->constrained('admin_plan_subscriptions')->cascadeOnDelete();
                $table->string('resource_key', 80);
                $table->string('period_key', 80);
                $table->unsignedInteger('used_amount')->default(0);
                $table->unsignedInteger('reserved_amount')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->unique(['admin_id', 'subscription_id', 'resource_key', 'period_key'], 'admin_resource_usages_unique');
                $table->index(['site_id', 'resource_key']);
            });
        }

        if (! Schema::hasTable('admin_resource_ledger')) {
            Schema::create('admin_resource_ledger', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('subscription_id')->nullable()->constrained('admin_plan_subscriptions')->nullOnDelete();
                $table->string('resource_key', 80);
                $table->string('type', 20)->index();
                $table->integer('amount');
                $table->integer('balance_after')->nullable();
                $table->foreignId('actor_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->string('subject_type', 120)->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->string('idempotency_key', 160)->nullable();
                $table->text('remark')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->unique('idempotency_key');
                $table->index(['admin_id', 'resource_key', 'created_at'], 'admin_resource_ledger_account_idx');
                $table->index(['subject_type', 'subject_id']);
            });
        }

        if (! Schema::hasTable('admin_credit_accounts')) {
            Schema::create('admin_credit_accounts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->decimal('balance', 14, 2)->default(0);
                $table->decimal('frozen_balance', 14, 2)->default(0);
                $table->decimal('total_granted', 14, 2)->default(0);
                $table->decimal('total_consumed', 14, 2)->default(0);
                $table->timestamps();

                $table->unique(['admin_id', 'site_id'], 'admin_credit_accounts_unique');
                $table->index('site_id');
            });
        }

        if (! Schema::hasTable('admin_credit_ledger')) {
            Schema::create('admin_credit_ledger', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('admin_id')->constrained('admins')->cascadeOnDelete();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('submission_id')->nullable()->constrained('media_submissions')->nullOnDelete();
                $table->string('type', 30)->index();
                $table->decimal('amount', 14, 2);
                $table->decimal('balance_after', 14, 2);
                $table->decimal('frozen_after', 14, 2)->default(0);
                $table->foreignId('operator_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->text('remark')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['admin_id', 'created_at'], 'admin_credit_ledger_account_idx');
                $table->index(['site_id', 'created_at'], 'admin_credit_ledger_site_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_credit_ledger');
        Schema::dropIfExists('admin_credit_accounts');
        Schema::dropIfExists('admin_resource_ledger');
        Schema::dropIfExists('admin_resource_usages');
        Schema::dropIfExists('admin_plan_subscriptions');
    }
};
