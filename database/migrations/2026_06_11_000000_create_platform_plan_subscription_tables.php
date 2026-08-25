<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table): void {
            if (! Schema::hasColumn('sites', 'customer_mode')) {
                $table->string('customer_mode', 20)->default('internal')->after('status')->index();
            }
            if (! Schema::hasColumn('sites', 'agent_admin_id')) {
                $table->unsignedBigInteger('agent_admin_id')->nullable()->after('customer_mode')->index();
            }
            if (! Schema::hasColumn('sites', 'plan_status')) {
                $table->string('plan_status', 20)->default('none')->after('agent_admin_id')->index();
            }
        });

        if (! Schema::hasTable('platform_plans')) {
            Schema::create('platform_plans', function (Blueprint $table): void {
                $table->id();
                $table->string('name', 120);
                $table->string('code', 80)->unique();
                $table->string('audience', 20)->default('both')->index();
                $table->unsignedInteger('duration_days')->default(30);
                $table->decimal('price', 12, 2)->nullable();
                $table->decimal('market_price', 12, 2)->nullable();
                $table->text('description')->nullable();
                $table->string('status', 20)->default('active')->index();
                $table->unsignedInteger('sort_order')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('platform_plan_entitlements')) {
            Schema::create('platform_plan_entitlements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('plan_id')->constrained('platform_plans')->cascadeOnDelete();
                $table->string('resource_key', 80);
                $table->boolean('enabled')->default(true);
                $table->unsignedInteger('quota_value')->default(0);
                $table->string('quota_period', 20)->default('cycle');
                $table->string('unit', 30)->default('times');
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->unique(['plan_id', 'resource_key']);
                $table->index(['resource_key', 'enabled']);
            });
        }

        if (! Schema::hasTable('site_plan_subscriptions')) {
            Schema::create('site_plan_subscriptions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('plan_id')->nullable()->constrained('platform_plans')->nullOnDelete();
                $table->string('mode', 20)->default('direct')->index();
                $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->foreignId('agent_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->foreignId('assigned_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->string('status', 20)->default('active')->index();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->json('entitlements_snapshot')->nullable();
                $table->text('remark')->nullable();
                $table->timestamps();

                $table->index(['site_id', 'status', 'starts_at', 'ends_at'], 'site_plan_subscriptions_active_idx');
            });
        }

        if (! Schema::hasTable('site_resource_usages')) {
            Schema::create('site_resource_usages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('subscription_id')->constrained('site_plan_subscriptions')->cascadeOnDelete();
                $table->string('resource_key', 80);
                $table->string('period_key', 80);
                $table->unsignedInteger('used_amount')->default(0);
                $table->unsignedInteger('reserved_amount')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();

                $table->unique(['site_id', 'subscription_id', 'resource_key', 'period_key'], 'site_resource_usages_unique');
                $table->index(['site_id', 'resource_key']);
            });
        }

        if (! Schema::hasTable('site_resource_ledger')) {
            Schema::create('site_resource_ledger', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('subscription_id')->nullable()->constrained('site_plan_subscriptions')->nullOnDelete();
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
                $table->index(['site_id', 'resource_key', 'created_at']);
                $table->index(['subject_type', 'subject_id']);
            });
        }

        if (! Schema::hasTable('site_subscription_logs')) {
            Schema::create('site_subscription_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
                $table->foreignId('subscription_id')->nullable()->constrained('site_plan_subscriptions')->nullOnDelete();
                $table->string('action', 30)->index();
                $table->json('before_payload')->nullable();
                $table->json('after_payload')->nullable();
                $table->foreignId('operator_admin_id')->nullable()->constrained('admins')->nullOnDelete();
                $table->text('remark')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index(['site_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_subscription_logs');
        Schema::dropIfExists('site_resource_ledger');
        Schema::dropIfExists('site_resource_usages');
        Schema::dropIfExists('site_plan_subscriptions');
        Schema::dropIfExists('platform_plan_entitlements');
        Schema::dropIfExists('platform_plans');

        Schema::table('sites', function (Blueprint $table): void {
            if (Schema::hasColumn('sites', 'plan_status')) {
                $table->dropColumn('plan_status');
            }
            if (Schema::hasColumn('sites', 'agent_admin_id')) {
                $table->dropColumn('agent_admin_id');
            }
            if (Schema::hasColumn('sites', 'customer_mode')) {
                $table->dropColumn('customer_mode');
            }
        });
    }
};
