# Account-Level Plan Quota Isolation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Convert platform plan quotas, credits, and key business data from shared site-level ownership to independent admin-account ownership while keeping site-level compatibility during migration.

**Architecture:** Keep existing `site_*` billing tables and `site_id` multi-site context as compatibility infrastructure. Add account-level subscriptions, account-level quota usage, account-level credit accounts, and `owner_admin_id` data ownership so each agent-created user receives an independent copy of the agent plan quota. Route quota checks through new account-aware services first, then migrate business modules in small verified slices.

**Tech Stack:** Laravel, Eloquent models, PostgreSQL/SQLite-compatible migrations, Blade admin pages, feature tests with `RefreshDatabase`, existing Docker Compose test runner.

---

## Current Baseline

The current production code is site-level:

- `App\Services\Billing\ResourceQuotaService` finds active subscriptions by `site_id`.
- `App\Services\Billing\PlanSubscriptionService` opens `site_plan_subscriptions`.
- `App\Services\MediaDistribution\SiteCreditService` stores balances in `site_credit_accounts`.
- `App\Models\Concerns\BelongsToSite` globally scopes by `CurrentSite::id()`.
- Agent-created users are attached to `site_members`, but they do not receive independent subscriptions or credits.

The target behavior is account-level:

- Super admin creates an agent and assigns a plan.
- Agent creates ordinary users.
- Each ordinary user inherits the agent's allowed plan as an independent account subscription.
- If the plan grants `credits = 1000`, every user receives their own 1000 credits. They do not share the agent site balance.
- Direct customers cannot create ordinary users.
- Agent admin can manage/view users under the agent site; ordinary users only see their own resources.
- Super admin can view all and still switch sites.
- Media publishing consumes credits only. There is still no separate media publish count.
- Plan resource catalog adds `video_generations` and `crebee_publishes`.

## File Structure

Create:

- `database/migrations/2026_06_15_000000_create_admin_plan_subscription_tables.php`  
  Creates account-level subscription, resource usage, resource ledger, credit account, and credit ledger tables.
- `database/migrations/2026_06_15_000100_add_owner_admin_id_to_business_resources.php`  
  Adds nullable `owner_admin_id` to key site-owned business tables and indexes.
- `database/migrations/2026_06_15_000200_backfill_admin_account_ownership.php`  
  Backfills owner fields and creates compatible account subscriptions for existing active site subscriptions.
- `app/Models/AdminPlanSubscription.php`
- `app/Models/AdminResourceUsage.php`
- `app/Models/AdminResourceLedger.php`
- `app/Models/AdminCreditAccount.php`
- `app/Models/AdminCreditLedger.php`
- `app/Models/Concerns/BelongsToAdminOwner.php`
- `app/Services/Billing/AdminPlanSubscriptionService.php`
- `app/Services/Billing/AdminResourceQuotaService.php`
- `app/Services/MediaDistribution/AdminCreditService.php`
- `tests/Feature/AdminAccountPlanSubscriptionTest.php`
- `tests/Feature/AdminAccountResourceIsolationTest.php`

Modify:

- `app/Models/Admin.php`  
  Add relationships to account subscriptions, resource usage, and credit account.
- `app/Models/PlatformPlan.php`  
  Add `RESOURCE_VIDEO_GENERATIONS` and `RESOURCE_CREBEE_PUBLISHES`.
- `app/Http/Controllers/Admin/AgentUserController.php`  
  When an agent creates a user, open an inherited account subscription for that user.
- `app/Http/Controllers/Admin/AdminUserController.php`  
  When super admin creates an agent/direct customer with subscription, also open an account subscription for the owner admin.
- `app/Services/Billing/PlanSubscriptionService.php`  
  Keep site subscription behavior, but expose snapshot helpers reusable by account-level service.
- `app/Services\Billing\ResourceQuotaService.php`  
  Keep as compatibility service only. Do not extend new logic here except delegation if needed.
- `app/Services/MediaDistribution/MediaSubmissionService.php`  
  Switch credit checks/deductions to account-level service using `submitted_by_admin_id`.
- `app/Services/BrandDiagnosis/BrandDiagnosisUsagePolicy.php`  
  Consume account-level `brand_diagnoses` by `admin_id`.
- `app/Services/GeoFlow/WorkerExecutionService.php`  
  Consume account-level `article_generations`.
- `app/Services/GeoFlow/AiGeneratedArticleImageService.php`  
  Consume account-level `ai_image_generations`.
- `app/Http/Controllers/Admin/TitleLibraryController.php`  
  Consume account-level `ai_title_generations`.
- `app/Http/Controllers/Admin/UrlImportController.php`  
  Consume account-level `url_imports`.
- `app/Http/Controllers/Admin/KeywordLibraryController.php`  
  Consume account-level `keyword_question_generations` and `inclusion_checks`.
- `app/Http/Controllers/Admin/ApiTokenController.php`  
  Count account-level API tokens where the schema supports `created_by_admin_id`; otherwise keep site fallback until a token owner migration is added.
- Models using `BelongsToSite` for user-owned content  
  Add `BelongsToAdminOwner` in staged groups, starting with knowledge bases, image libraries, articles, brand diagnosis, and media submissions.

Do not delete in this feature:

- `site_plan_subscriptions`
- `site_resource_usages`
- `site_resource_ledger`
- `site_credit_accounts`
- `site_credit_ledger`
- `SiteCreditService`
- `ResourceQuotaService`

They remain production compatibility paths until all business modules are switched and old data is audited.

---

## Task 1: Add Account-Level Billing Tables

**Files:**
- Create: `database/migrations/2026_06_15_000000_create_admin_plan_subscription_tables.php`
- Test: `tests/Feature/AdminAccountPlanSubscriptionTest.php`

- [ ] **Step 1: Write failing schema test**

Create `tests/Feature/AdminAccountPlanSubscriptionTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAccountPlanSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_level_billing_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('admin_plan_subscriptions'));
        $this->assertTrue(Schema::hasTable('admin_resource_usages'));
        $this->assertTrue(Schema::hasTable('admin_resource_ledger'));
        $this->assertTrue(Schema::hasTable('admin_credit_accounts'));
        $this->assertTrue(Schema::hasTable('admin_credit_ledger'));

        $this->assertTrue(Schema::hasColumns('admin_plan_subscriptions', [
            'admin_id',
            'site_id',
            'plan_id',
            'source_subscription_id',
            'inherited_from_admin_id',
            'mode',
            'status',
            'starts_at',
            'ends_at',
            'entitlements_snapshot',
        ]));

        $this->assertTrue(Schema::hasColumns('admin_credit_accounts', [
            'admin_id',
            'site_id',
            'balance',
            'frozen_balance',
            'total_granted',
            'total_consumed',
        ]));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountPlanSubscriptionTest.php --filter=account_level_billing_tables_exist
```

Expected: FAIL because the new account-level tables do not exist.

- [ ] **Step 3: Create migration**

Create `database/migrations/2026_06_15_000000_create_admin_plan_subscription_tables.php`:

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountPlanSubscriptionTest.php --filter=account_level_billing_tables_exist
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_15_000000_create_admin_plan_subscription_tables.php tests/Feature/AdminAccountPlanSubscriptionTest.php
git commit -m "feat: add account-level billing tables"
```

---

## Task 2: Add Account-Level Billing Models

**Files:**
- Create: `app/Models/AdminPlanSubscription.php`
- Create: `app/Models/AdminResourceUsage.php`
- Create: `app/Models/AdminResourceLedger.php`
- Create: `app/Models/AdminCreditAccount.php`
- Create: `app/Models/AdminCreditLedger.php`
- Modify: `app/Models/Admin.php`
- Test: `tests/Feature/AdminAccountPlanSubscriptionTest.php`

- [ ] **Step 1: Add failing relationship test**

Append to `tests/Feature/AdminAccountPlanSubscriptionTest.php`:

```php
public function test_admin_has_account_level_billing_relationships(): void
{
    $admin = \App\Models\Admin::query()->create([
        'username' => 'account_billing_owner',
        'password' => 'secret-123',
        'email' => 'account-billing-owner@example.com',
        'display_name' => 'Account Billing Owner',
        'role' => 'direct_admin',
        'status' => 'active',
    ]);

    $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $admin->accountPlanSubscriptions());
    $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class, $admin->creditAccount());
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountPlanSubscriptionTest.php --filter=admin_has_account_level_billing_relationships
```

Expected: FAIL because relationships and models are missing.

- [ ] **Step 3: Create model classes**

Create `app/Models/AdminPlanSubscription.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminPlanSubscription extends Model
{
    protected $fillable = [
        'admin_id',
        'site_id',
        'plan_id',
        'source_subscription_id',
        'inherited_from_admin_id',
        'mode',
        'status',
        'starts_at',
        'ends_at',
        'entitlements_snapshot',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'site_id' => 'integer',
            'plan_id' => 'integer',
            'source_subscription_id' => 'integer',
            'inherited_from_admin_id' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'entitlements_snapshot' => 'array',
        ];
    }

    public function scopeActiveNow(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function (Builder $query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', now());
            });
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlatformPlan::class);
    }

    public function sourceSubscription(): BelongsTo
    {
        return $this->belongsTo(SitePlanSubscription::class, 'source_subscription_id');
    }

    public function inheritedFromAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'inherited_from_admin_id');
    }
}
```

Create `app/Models/AdminResourceUsage.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminResourceUsage extends Model
{
    protected $fillable = [
        'admin_id',
        'site_id',
        'subscription_id',
        'resource_key',
        'period_key',
        'used_amount',
        'reserved_amount',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'site_id' => 'integer',
            'subscription_id' => 'integer',
            'used_amount' => 'integer',
            'reserved_amount' => 'integer',
            'last_used_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(AdminPlanSubscription::class, 'subscription_id');
    }
}
```

Create `app/Models/AdminResourceLedger.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminResourceLedger extends Model
{
    public $timestamps = false;

    protected $table = 'admin_resource_ledger';

    protected $fillable = [
        'admin_id',
        'site_id',
        'subscription_id',
        'resource_key',
        'type',
        'amount',
        'balance_after',
        'actor_admin_id',
        'subject_type',
        'subject_id',
        'idempotency_key',
        'remark',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'site_id' => 'integer',
            'subscription_id' => 'integer',
            'amount' => 'integer',
            'balance_after' => 'integer',
            'actor_admin_id' => 'integer',
            'subject_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(AdminPlanSubscription::class, 'subscription_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'actor_admin_id');
    }
}
```

Create `app/Models/AdminCreditAccount.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminCreditAccount extends Model
{
    protected $fillable = [
        'admin_id',
        'site_id',
        'balance',
        'frozen_balance',
        'total_granted',
        'total_consumed',
    ];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'site_id' => 'integer',
            'balance' => 'decimal:2',
            'frozen_balance' => 'decimal:2',
            'total_granted' => 'decimal:2',
            'total_consumed' => 'decimal:2',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
```

Create `app/Models/AdminCreditLedger.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminCreditLedger extends Model
{
    public $timestamps = false;

    protected $table = 'admin_credit_ledger';

    protected $fillable = [
        'admin_id',
        'site_id',
        'submission_id',
        'type',
        'amount',
        'balance_after',
        'frozen_after',
        'operator_admin_id',
        'remark',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'site_id' => 'integer',
            'submission_id' => 'integer',
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'frozen_after' => 'decimal:2',
            'operator_admin_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(MediaSubmission::class);
    }
}
```

- [ ] **Step 4: Add Admin relationships**

Modify `app/Models/Admin.php` imports and methods:

```php
use Illuminate\Database\Eloquent\Relations\HasOne;
```

Add methods:

```php
public function accountPlanSubscriptions(): HasMany
{
    return $this->hasMany(AdminPlanSubscription::class, 'admin_id');
}

public function creditAccount(): HasOne
{
    return $this->hasOne(AdminCreditAccount::class, 'admin_id');
}
```

- [ ] **Step 5: Run test to verify it passes**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountPlanSubscriptionTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Admin.php app/Models/AdminPlanSubscription.php app/Models/AdminResourceUsage.php app/Models/AdminResourceLedger.php app/Models/AdminCreditAccount.php app/Models/AdminCreditLedger.php tests/Feature/AdminAccountPlanSubscriptionTest.php
git commit -m "feat: add account billing models"
```

---

## Task 3: Add Account-Level Subscription Service

**Files:**
- Create: `app/Services/Billing/AdminPlanSubscriptionService.php`
- Modify: `app/Services/Billing/PlanSubscriptionService.php`
- Test: `tests/Feature/AdminAccountPlanSubscriptionTest.php`

- [ ] **Step 1: Add failing service test**

Append to `tests/Feature/AdminAccountPlanSubscriptionTest.php`:

```php
public function test_open_owner_account_subscription_grants_independent_credit_account(): void
{
    $admin = \App\Models\Admin::query()->create([
        'username' => 'direct_account_owner',
        'password' => 'secret-123',
        'email' => 'direct-account-owner@example.com',
        'display_name' => 'Direct Account Owner',
        'role' => 'direct_admin',
        'status' => 'active',
    ]);
    $site = \App\Models\Site::query()->create([
        'owner_admin_id' => (int) $admin->id,
        'name' => 'Direct Account Site',
        'status' => 'active',
        'customer_mode' => 'direct',
    ]);
    $site->members()->attach((int) $admin->id, ['role' => 'owner']);

    $plan = $this->accountPlanWithResources([
        \App\Models\PlatformPlan::RESOURCE_CREDITS => ['quota_value' => 1000, 'unit' => 'points'],
        \App\Models\PlatformPlan::RESOURCE_BRAND_DIAGNOSES => ['quota_value' => 10, 'unit' => 'times'],
    ]);

    $subscription = app(\App\Services\Billing\AdminPlanSubscriptionService::class)->openOwner(
        admin: $admin,
        site: $site,
        plan: $plan,
        mode: 'direct_owner',
        operator: $admin,
        startsAt: now(),
        endsAt: now()->addDays(30),
        grantCredits: true,
        remark: 'Direct owner opened'
    );

    $this->assertSame((int) $admin->id, (int) $subscription->admin_id);
    $this->assertDatabaseHas('admin_credit_accounts', [
        'admin_id' => (int) $admin->id,
        'site_id' => (int) $site->id,
        'balance' => '1000.00',
    ]);
}
```

Add helper in the same test class:

```php
private function accountPlanWithResources(array $resources): \App\Models\PlatformPlan
{
    $plan = \App\Models\PlatformPlan::query()->create([
        'name' => 'Account Plan '.str()->random(6),
        'code' => 'account-plan-'.str()->random(12),
        'audience' => 'both',
        'duration_days' => 30,
        'status' => 'active',
        'sort_order' => 0,
    ]);

    foreach ($resources as $resourceKey => $resource) {
        $plan->entitlements()->create([
            'resource_key' => $resourceKey,
            'enabled' => true,
            'quota_value' => (int) $resource['quota_value'],
            'quota_period' => 'cycle',
            'unit' => (string) $resource['unit'],
            'meta' => [],
        ]);
    }

    return $plan;
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountPlanSubscriptionTest.php --filter=open_owner_account_subscription_grants_independent_credit_account
```

Expected: FAIL because `AdminPlanSubscriptionService` does not exist.

- [ ] **Step 3: Expose entitlement snapshot helper**

Modify `app/Services/Billing/PlanSubscriptionService.php` only if the current `entitlementSnapshot()` is not reusable. Keep this method public:

```php
/**
 * @return array<string,array{enabled:bool,quota_value:int,quota_period:string,unit:string,meta:array<string,mixed>}>
 */
public function entitlementSnapshot(PlatformPlan $plan, string $mode): array
{
    $mode = $this->normalizeMode($mode);

    return $plan->entitlements()
        ->where('enabled', true)
        ->get()
        ->filter(function ($entitlement) use ($mode): bool {
            if ($mode === 'direct' && (string) $entitlement->resource_key === PlatformPlan::RESOURCE_TEAM_MEMBERS) {
                return false;
            }

            return array_key_exists((string) $entitlement->resource_key, PlatformPlan::resourceCatalog());
        })
        ->mapWithKeys(static fn ($entitlement): array => [
            (string) $entitlement->resource_key => [
                'enabled' => (bool) $entitlement->enabled,
                'quota_value' => (int) $entitlement->quota_value,
                'quota_period' => (string) $entitlement->quota_period,
                'unit' => (string) $entitlement->unit,
                'meta' => (array) ($entitlement->meta ?? []),
            ],
        ])
        ->all();
}
```

- [ ] **Step 4: Create AdminPlanSubscriptionService**

Create `app/Services/Billing/AdminPlanSubscriptionService.php`:

```php
<?php

namespace App\Services\Billing;

use App\Models\Admin;
use App\Models\AdminPlanSubscription;
use App\Models\PlatformPlan;
use App\Models\Site;
use App\Models\SitePlanSubscription;
use App\Services\MediaDistribution\AdminCreditService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminPlanSubscriptionService
{
    public function __construct(
        private readonly PlanSubscriptionService $siteSubscriptionService,
        private readonly AdminCreditService $creditService
    ) {}

    public function openOwner(
        Admin $admin,
        Site $site,
        PlatformPlan $plan,
        string $mode,
        ?Admin $operator,
        ?CarbonInterface $startsAt = null,
        ?CarbonInterface $endsAt = null,
        bool $grantCredits = true,
        string $remark = ''
    ): AdminPlanSubscription {
        $mode = $this->normalizeMode($mode);
        $startsAt ??= now();
        $endsAt ??= $startsAt->copy()->addDays(max(1, (int) $plan->duration_days));
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new RuntimeException('到期时间必须晚于开始时间');
        }

        return DB::transaction(function () use ($admin, $site, $plan, $mode, $operator, $startsAt, $endsAt, $grantCredits, $remark): AdminPlanSubscription {
            AdminPlanSubscription::query()
                ->where('admin_id', (int) $admin->id)
                ->where('site_id', (int) $site->id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled']);

            $snapshotMode = str_starts_with($mode, 'direct') ? 'direct' : 'agent';
            $snapshot = $this->siteSubscriptionService->entitlementSnapshot($plan, $snapshotMode);

            $subscription = AdminPlanSubscription::query()->create([
                'admin_id' => (int) $admin->id,
                'site_id' => (int) $site->id,
                'plan_id' => (int) $plan->id,
                'source_subscription_id' => null,
                'inherited_from_admin_id' => null,
                'mode' => $mode,
                'status' => 'active',
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'entitlements_snapshot' => $snapshot,
                'remark' => $remark,
            ]);

            if ($grantCredits) {
                $this->grantCreditsFromSnapshot($admin, $site, $snapshot, $operator, '规格开通赠送：'.$plan->name);
            }

            return $subscription;
        });
    }

    public function inheritForAgentUser(
        Admin $agent,
        Admin $user,
        Site $site,
        ?Admin $operator,
        string $remark = '代理创建用户继承规格'
    ): AdminPlanSubscription {
        $source = $this->activeSubscriptionForAdmin((int) $agent->id, (int) $site->id);

        return DB::transaction(function () use ($agent, $user, $site, $operator, $remark, $source): AdminPlanSubscription {
            AdminPlanSubscription::query()
                ->where('admin_id', (int) $user->id)
                ->where('site_id', (int) $site->id)
                ->where('status', 'active')
                ->update(['status' => 'cancelled']);

            $snapshot = (array) $source->entitlements_snapshot;
            $subscription = AdminPlanSubscription::query()->create([
                'admin_id' => (int) $user->id,
                'site_id' => (int) $site->id,
                'plan_id' => $source->plan_id,
                'source_subscription_id' => $source->source_subscription_id,
                'inherited_from_admin_id' => (int) $agent->id,
                'mode' => 'agent_user',
                'status' => 'active',
                'starts_at' => $source->starts_at,
                'ends_at' => $source->ends_at,
                'entitlements_snapshot' => $snapshot,
                'remark' => $remark,
            ]);

            $this->grantCreditsFromSnapshot($user, $site, $snapshot, $operator, '代理用户继承规格赠送');

            return $subscription;
        });
    }

    public function activeSubscriptionForAdmin(int $adminId, int $siteId): AdminPlanSubscription
    {
        $subscription = AdminPlanSubscription::query()
            ->where('admin_id', $adminId)
            ->where('site_id', $siteId)
            ->activeNow()
            ->orderByDesc('ends_at')
            ->orderByDesc('id')
            ->first();

        if (! $subscription instanceof AdminPlanSubscription) {
            throw new RuntimeException('当前账号规格已到期，请联系平台续费');
        }

        return $subscription;
    }

    public function backfillFromSiteSubscription(Admin $admin, Site $site, SitePlanSubscription $siteSubscription): AdminPlanSubscription
    {
        return AdminPlanSubscription::query()->firstOrCreate(
            [
                'admin_id' => (int) $admin->id,
                'site_id' => (int) $site->id,
                'source_subscription_id' => (int) $siteSubscription->id,
            ],
            [
                'plan_id' => $siteSubscription->plan_id,
                'inherited_from_admin_id' => null,
                'mode' => match ((string) $siteSubscription->mode) {
                    'agent' => 'agent_owner',
                    'direct' => 'direct_owner',
                    default => 'internal',
                },
                'status' => $siteSubscription->status,
                'starts_at' => $siteSubscription->starts_at,
                'ends_at' => $siteSubscription->ends_at,
                'entitlements_snapshot' => (array) $siteSubscription->entitlements_snapshot,
                'remark' => '由站点规格兼容迁移生成',
            ]
        );
    }

    private function grantCreditsFromSnapshot(Admin $admin, Site $site, array $snapshot, ?Admin $operator, string $remark): void
    {
        $credits = (int) data_get($snapshot, PlatformPlan::RESOURCE_CREDITS.'.quota_value', 0);
        if ($credits <= 0) {
            return;
        }

        $this->creditService->grant(
            adminId: (int) $admin->id,
            siteId: (int) $site->id,
            amount: number_format($credits, 2, '.', ''),
            operatorAdminId: $operator?->id,
            remark: $remark
        );
    }

    private function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (! in_array($mode, ['agent_owner', 'agent_user', 'direct_owner', 'internal'], true)) {
            throw new RuntimeException('账号规格模式不正确');
        }

        return $mode;
    }
}
```

- [ ] **Step 5: Run test and note missing credit service failure**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountPlanSubscriptionTest.php --filter=open_owner_account_subscription_grants_independent_credit_account
```

Expected: FAIL because `AdminCreditService` does not exist.

- [ ] **Step 6: Commit after Task 4 passes**

Do not commit this task until Task 4 creates the required credit service and the test passes.

---

## Task 4: Add Account-Level Credit Service

**Files:**
- Create: `app/Services/MediaDistribution/AdminCreditService.php`
- Test: `tests/Feature/AdminAccountPlanSubscriptionTest.php`

- [ ] **Step 1: Create AdminCreditService**

Create `app/Services/MediaDistribution/AdminCreditService.php`:

```php
<?php

namespace App\Services\MediaDistribution;

use App\Models\AdminCreditAccount;
use App\Models\AdminCreditLedger;
use App\Models\MediaSubmission;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminCreditService
{
    public function accountForAdmin(int $adminId, int $siteId): AdminCreditAccount
    {
        return AdminCreditAccount::query()->firstOrCreate(
            ['admin_id' => $adminId, 'site_id' => $siteId],
            [
                'balance' => '0.00',
                'frozen_balance' => '0.00',
                'total_granted' => '0.00',
                'total_consumed' => '0.00',
            ]
        );
    }

    public function ensureSufficient(int $adminId, int $siteId, mixed $amount): void
    {
        $account = $this->accountForAdmin($adminId, $siteId);
        if ($this->moneyToCents($account->balance) < $this->moneyToCents($amount)) {
            throw new RuntimeException('当前账号积分不足');
        }
    }

    public function grant(int $adminId, int $siteId, string $amount, ?int $operatorAdminId, string $remark = ''): AdminCreditAccount
    {
        return DB::transaction(function () use ($adminId, $siteId, $amount, $operatorAdminId, $remark): AdminCreditAccount {
            $account = $this->lockedAccount($adminId, $siteId);
            $amountCents = $this->moneyToCents($amount);
            if ($amountCents <= 0) {
                throw new RuntimeException('发放积分必须大于 0');
            }

            $balance = $this->moneyToCents($account->balance) + $amountCents;
            $totalGranted = $this->moneyToCents($account->total_granted) + $amountCents;
            $account->forceFill([
                'balance' => $this->centsToMoney($balance),
                'total_granted' => $this->centsToMoney($totalGranted),
            ])->save();

            $this->ledger($adminId, $siteId, null, 'grant', $amountCents, $account, $operatorAdminId, $remark);

            return $account;
        });
    }

    public function deductForSubmission(MediaSubmission $submission, int $adminId, ?int $operatorAdminId): AdminCreditAccount
    {
        return DB::transaction(function () use ($submission, $adminId, $operatorAdminId): AdminCreditAccount {
            $siteId = (int) $submission->site_id;
            $account = $this->lockedAccount($adminId, $siteId);
            $amountCents = $this->moneyToCents($submission->points_amount);
            $balanceCents = $this->moneyToCents($account->balance);
            if ($balanceCents < $amountCents) {
                throw new RuntimeException('当前账号积分不足');
            }

            $account->forceFill([
                'balance' => $this->centsToMoney($balanceCents - $amountCents),
                'total_consumed' => $this->centsToMoney($this->moneyToCents($account->total_consumed) + $amountCents),
            ])->save();

            $this->ledger($adminId, $siteId, (int) $submission->id, 'deduct', -$amountCents, $account, $operatorAdminId, '媒体投稿扣除');

            return $account;
        });
    }

    public function refundForSubmission(MediaSubmission $submission, int $adminId, ?int $operatorAdminId, string $remark = '媒体投稿失败退回'): AdminCreditAccount
    {
        return DB::transaction(function () use ($submission, $adminId, $operatorAdminId, $remark): AdminCreditAccount {
            if (AdminCreditLedger::query()
                ->where('admin_id', $adminId)
                ->where('submission_id', (int) $submission->id)
                ->where('type', 'refund')
                ->exists()) {
                return $this->lockedAccount($adminId, (int) $submission->site_id);
            }

            $siteId = (int) $submission->site_id;
            $account = $this->lockedAccount($adminId, $siteId);
            $amountCents = $this->moneyToCents($submission->points_amount);
            $account->forceFill([
                'balance' => $this->centsToMoney($this->moneyToCents($account->balance) + $amountCents),
                'total_consumed' => $this->centsToMoney(max(0, $this->moneyToCents($account->total_consumed) - $amountCents)),
            ])->save();

            $this->ledger($adminId, $siteId, (int) $submission->id, 'refund', $amountCents, $account, $operatorAdminId, $remark);

            return $account;
        });
    }

    private function lockedAccount(int $adminId, int $siteId): AdminCreditAccount
    {
        $this->accountForAdmin($adminId, $siteId);

        return AdminCreditAccount::query()
            ->where('admin_id', $adminId)
            ->where('site_id', $siteId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function ledger(int $adminId, int $siteId, ?int $submissionId, string $type, int $amountCents, AdminCreditAccount $account, ?int $operatorAdminId, string $remark): void
    {
        AdminCreditLedger::query()->create([
            'admin_id' => $adminId,
            'site_id' => $siteId,
            'submission_id' => $submissionId,
            'type' => $type,
            'amount' => $this->centsToMoney($amountCents),
            'balance_after' => $account->balance,
            'frozen_after' => $account->frozen_balance,
            'operator_admin_id' => $operatorAdminId,
            'remark' => $remark,
            'created_at' => now(),
        ]);
    }

    private function moneyToCents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private function centsToMoney(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
```

- [ ] **Step 2: Run Task 3 test to verify it passes**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountPlanSubscriptionTest.php --filter=open_owner_account_subscription_grants_independent_credit_account
```

Expected: PASS.

- [ ] **Step 3: Commit Task 3 and Task 4 together**

```bash
git add app/Services/Billing/AdminPlanSubscriptionService.php app/Services/MediaDistribution/AdminCreditService.php app/Services/Billing/PlanSubscriptionService.php tests/Feature/AdminAccountPlanSubscriptionTest.php
git commit -m "feat: open account-level plan subscriptions"
```

---

## Task 5: Add Account-Level Quota Service

**Files:**
- Create: `app/Services/Billing/AdminResourceQuotaService.php`
- Test: `tests/Feature/AdminAccountPlanSubscriptionTest.php`

- [ ] **Step 1: Add failing quota independence test**

Append to `tests/Feature/AdminAccountPlanSubscriptionTest.php`:

```php
public function test_two_users_under_same_agent_have_independent_quota_usage(): void
{
    $agent = \App\Models\Admin::query()->create([
        'username' => 'agent_quota_owner',
        'password' => 'secret-123',
        'email' => 'agent-quota-owner@example.com',
        'display_name' => 'Agent Quota Owner',
        'role' => 'agent_admin',
        'status' => 'active',
    ]);
    $userOne = \App\Models\Admin::query()->create([
        'username' => 'agent_quota_user_one',
        'password' => 'secret-123',
        'email' => 'agent-quota-user-one@example.com',
        'display_name' => 'Agent Quota User One',
        'role' => 'site_user',
        'status' => 'active',
        'created_by' => (int) $agent->id,
    ]);
    $userTwo = \App\Models\Admin::query()->create([
        'username' => 'agent_quota_user_two',
        'password' => 'secret-123',
        'email' => 'agent-quota-user-two@example.com',
        'display_name' => 'Agent Quota User Two',
        'role' => 'site_user',
        'status' => 'active',
        'created_by' => (int) $agent->id,
    ]);
    $site = \App\Models\Site::query()->create([
        'owner_admin_id' => (int) $agent->id,
        'name' => 'Agent Quota Site',
        'status' => 'active',
        'customer_mode' => 'agent',
        'agent_admin_id' => (int) $agent->id,
    ]);
    $site->members()->attach((int) $agent->id, ['role' => 'owner']);
    $site->members()->attach((int) $userOne->id, ['role' => 'member']);
    $site->members()->attach((int) $userTwo->id, ['role' => 'member']);

    $plan = $this->accountPlanWithResources([
        \App\Models\PlatformPlan::RESOURCE_BRAND_DIAGNOSES => ['quota_value' => 1, 'unit' => 'times'],
    ]);
    $subscriptionService = app(\App\Services\Billing\AdminPlanSubscriptionService::class);
    $subscriptionService->openOwner($agent, $site, $plan, 'agent_owner', $agent, now(), now()->addDays(30), false);
    $subscriptionService->inheritForAgentUser($agent, $userOne, $site, $agent);
    $subscriptionService->inheritForAgentUser($agent, $userTwo, $site, $agent);

    $quota = app(\App\Services\Billing\AdminResourceQuotaService::class);
    $quota->consume((int) $userOne->id, (int) $site->id, \App\Models\PlatformPlan::RESOURCE_BRAND_DIAGNOSES, 1, [
        'actor_admin_id' => (int) $userOne->id,
        'idempotency_key' => 'user-one-brand-run',
    ]);
    $quota->consume((int) $userTwo->id, (int) $site->id, \App\Models\PlatformPlan::RESOURCE_BRAND_DIAGNOSES, 1, [
        'actor_admin_id' => (int) $userTwo->id,
        'idempotency_key' => 'user-two-brand-run',
    ]);

    $this->assertDatabaseHas('admin_resource_usages', [
        'admin_id' => (int) $userOne->id,
        'resource_key' => \App\Models\PlatformPlan::RESOURCE_BRAND_DIAGNOSES,
        'used_amount' => 1,
    ]);
    $this->assertDatabaseHas('admin_resource_usages', [
        'admin_id' => (int) $userTwo->id,
        'resource_key' => \App\Models\PlatformPlan::RESOURCE_BRAND_DIAGNOSES,
        'used_amount' => 1,
    ]);

    $this->expectExceptionMessage('当前账号规格额度不足');

    $quota->consume((int) $userOne->id, (int) $site->id, \App\Models\PlatformPlan::RESOURCE_BRAND_DIAGNOSES, 1, [
        'actor_admin_id' => (int) $userOne->id,
        'idempotency_key' => 'user-one-second-brand-run',
    ]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountPlanSubscriptionTest.php --filter=two_users_under_same_agent_have_independent_quota_usage
```

Expected: FAIL because `AdminResourceQuotaService` does not exist.

- [ ] **Step 3: Create AdminResourceQuotaService**

Create `app/Services/Billing/AdminResourceQuotaService.php`:

```php
<?php

namespace App\Services\Billing;

use App\Models\Admin;
use App\Models\AdminPlanSubscription;
use App\Models\AdminResourceLedger;
use App\Models\AdminResourceUsage;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AdminResourceQuotaService
{
    public function __construct(
        private readonly AdminPlanSubscriptionService $subscriptionService
    ) {}

    public function activeSubscriptionForAdmin(int $adminId, int $siteId): AdminPlanSubscription
    {
        return $this->subscriptionService->activeSubscriptionForAdmin($adminId, $siteId);
    }

    public function assertSubscriptionActive(int $adminId, int $siteId, ?Admin $admin = null): void
    {
        if ($admin instanceof Admin && $admin->isSuperAdmin()) {
            return;
        }

        $this->activeSubscriptionForAdmin($adminId, $siteId);
    }

    public function assertCanUse(int $adminId, int $siteId, string $resourceKey, int $amount = 1, ?Admin $admin = null): void
    {
        if ($admin instanceof Admin && $admin->isSuperAdmin()) {
            return;
        }

        $subscription = $this->activeSubscriptionForAdmin($adminId, $siteId);
        $entitlement = $this->entitlement($subscription, $resourceKey);
        if ((string) $entitlement['quota_period'] === 'unlimited') {
            return;
        }

        $used = $this->usedAmount($subscription, $resourceKey);
        $quota = (int) $entitlement['quota_value'];
        if ($quota < $amount || $used + $amount > $quota) {
            throw new RuntimeException('当前账号规格额度不足，请联系平台升级或续费');
        }
    }

    public function consume(int $adminId, int $siteId, string $resourceKey, int $amount = 1, array $context = []): AdminResourceLedger
    {
        $amount = max(1, $amount);

        return DB::transaction(function () use ($adminId, $siteId, $resourceKey, $amount, $context): AdminResourceLedger {
            $idempotencyKey = trim((string) ($context['idempotency_key'] ?? ''));
            if ($idempotencyKey !== '') {
                $existing = AdminResourceLedger::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof AdminResourceLedger) {
                    return $existing;
                }
            }

            $subscription = $this->activeSubscriptionForAdmin($adminId, $siteId);
            $entitlement = $this->entitlement($subscription, $resourceKey);
            if ((string) $entitlement['quota_period'] === 'unlimited') {
                return $this->ledger($subscription, $resourceKey, 'consume', $amount, null, $context);
            }

            $periodKey = $this->periodKey($subscription, (string) $entitlement['quota_period']);
            $usage = AdminResourceUsage::query()->firstOrCreate(
                [
                    'admin_id' => $adminId,
                    'site_id' => $siteId,
                    'subscription_id' => (int) $subscription->id,
                    'resource_key' => $resourceKey,
                    'period_key' => $periodKey,
                ],
                [
                    'used_amount' => 0,
                    'reserved_amount' => 0,
                    'last_used_at' => null,
                ]
            );
            $usage = AdminResourceUsage::query()->whereKey((int) $usage->id)->lockForUpdate()->firstOrFail();
            $quota = (int) $entitlement['quota_value'];
            $used = (int) $usage->used_amount;
            if ($used + $amount > $quota) {
                throw new RuntimeException('当前账号规格额度不足，请联系平台升级或续费');
            }

            $usage->forceFill([
                'used_amount' => $used + $amount,
                'last_used_at' => now(),
            ])->save();

            return $this->ledger($subscription, $resourceKey, 'consume', $amount, $quota - $used - $amount, $context);
        });
    }

    public function remaining(int $adminId, int $siteId, string $resourceKey): array
    {
        $subscription = $this->activeSubscriptionForAdmin($adminId, $siteId);
        $entitlement = $this->entitlement($subscription, $resourceKey);
        if ((string) $entitlement['quota_period'] === 'unlimited') {
            return ['quota' => null, 'used' => 0, 'remaining' => null, 'period' => 'unlimited'];
        }

        $used = $this->usedAmount($subscription, $resourceKey);
        $quota = (int) $entitlement['quota_value'];

        return [
            'quota' => $quota,
            'used' => $used,
            'remaining' => max(0, $quota - $used),
            'period' => (string) $entitlement['quota_period'],
        ];
    }

    private function entitlement(AdminPlanSubscription $subscription, string $resourceKey): array
    {
        $snapshot = (array) $subscription->entitlements_snapshot;
        $entitlement = $snapshot[$resourceKey] ?? null;
        if (! is_array($entitlement) || ! (bool) ($entitlement['enabled'] ?? false)) {
            throw new RuntimeException('当前账号规格不包含该功能');
        }

        return [
            'enabled' => true,
            'quota_value' => (int) ($entitlement['quota_value'] ?? 0),
            'quota_period' => (string) ($entitlement['quota_period'] ?? 'cycle'),
            'unit' => (string) ($entitlement['unit'] ?? 'times'),
            'meta' => (array) ($entitlement['meta'] ?? []),
        ];
    }

    private function usedAmount(AdminPlanSubscription $subscription, string $resourceKey): int
    {
        $entitlement = $this->entitlement($subscription, $resourceKey);

        return (int) AdminResourceUsage::query()
            ->where('admin_id', (int) $subscription->admin_id)
            ->where('site_id', (int) $subscription->site_id)
            ->where('subscription_id', (int) $subscription->id)
            ->where('resource_key', $resourceKey)
            ->where('period_key', $this->periodKey($subscription, (string) $entitlement['quota_period']))
            ->value('used_amount');
    }

    private function periodKey(AdminPlanSubscription $subscription, string $quotaPeriod): string
    {
        return match ($quotaPeriod) {
            'day' => now()->format('Y-m-d'),
            'month' => now()->format('Y-m'),
            'year' => now()->format('Y'),
            default => 'subscription:'.(int) $subscription->id,
        };
    }

    private function ledger(AdminPlanSubscription $subscription, string $resourceKey, string $type, int $amount, ?int $balanceAfter, array $context): AdminResourceLedger
    {
        return AdminResourceLedger::query()->create([
            'admin_id' => (int) $subscription->admin_id,
            'site_id' => (int) $subscription->site_id,
            'subscription_id' => (int) $subscription->id,
            'resource_key' => $resourceKey,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'actor_admin_id' => isset($context['actor_admin_id']) ? (int) $context['actor_admin_id'] : null,
            'subject_type' => isset($context['subject_type']) ? (string) $context['subject_type'] : null,
            'subject_id' => isset($context['subject_id']) ? (int) $context['subject_id'] : null,
            'idempotency_key' => trim((string) ($context['idempotency_key'] ?? '')) ?: null,
            'remark' => isset($context['remark']) ? (string) $context['remark'] : null,
            'created_at' => now(),
        ]);
    }
}
```

- [ ] **Step 4: Run account tests**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountPlanSubscriptionTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Billing/AdminResourceQuotaService.php tests/Feature/AdminAccountPlanSubscriptionTest.php
git commit -m "feat: track resource quotas per admin account"
```

---

## Task 6: Add New Plan Resource Keys

**Files:**
- Modify: `app/Models/PlatformPlan.php`
- Modify: `resources/views/admin/platform-plans/index.blade.php`
- Test: `tests/Feature/PlatformPlanSubscriptionTest.php`

- [ ] **Step 1: Add failing resource catalog test**

Append to `tests/Feature/PlatformPlanSubscriptionTest.php`:

```php
public function test_platform_plan_catalog_contains_video_and_crebee_resources(): void
{
    $catalog = PlatformPlan::resourceCatalog();

    $this->assertArrayHasKey('video_generations', $catalog);
    $this->assertSame('生成视频次数', $catalog['video_generations']['label']);
    $this->assertArrayHasKey('crebee_publishes', $catalog);
    $this->assertSame('CreBee 发布次数', $catalog['crebee_publishes']['label']);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/PlatformPlanSubscriptionTest.php --filter=platform_plan_catalog_contains_video_and_crebee_resources
```

Expected: FAIL because the catalog does not contain the new resources.

- [ ] **Step 3: Add resource constants and catalog entries**

Modify `app/Models/PlatformPlan.php`:

```php
public const RESOURCE_VIDEO_GENERATIONS = 'video_generations';
public const RESOURCE_CREBEE_PUBLISHES = 'crebee_publishes';
```

Add to `resourceCatalog()`:

```php
self::RESOURCE_VIDEO_GENERATIONS => ['label' => '生成视频次数', 'unit' => 'times'],
self::RESOURCE_CREBEE_PUBLISHES => ['label' => 'CreBee 发布次数', 'unit' => 'times'],
```

- [ ] **Step 4: Run plan tests**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/PlatformPlanSubscriptionTest.php --filter=platform_plan_catalog_contains_video_and_crebee_resources
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/PlatformPlan.php tests/Feature/PlatformPlanSubscriptionTest.php
git commit -m "feat: add video and crebee plan resources"
```

---

## Task 7: Open Account Subscription When Super Admin Creates Agent or Direct Customer

**Files:**
- Modify: `app/Http/Controllers/Admin/AdminUserController.php`
- Test: `tests/Feature/PlatformPlanSubscriptionTest.php`

- [ ] **Step 1: Add failing onboarding test**

Extend `test_super_admin_can_create_agent_user_with_site_and_plan_subscription()` in `tests/Feature/PlatformPlanSubscriptionTest.php` with these assertions:

```php
$this->assertDatabaseHas('admin_plan_subscriptions', [
    'admin_id' => (int) $admin->id,
    'site_id' => (int) $site->id,
    'plan_id' => (int) $plan->id,
    'mode' => 'agent_owner',
    'status' => 'active',
]);
$this->assertDatabaseHas('admin_credit_accounts', [
    'admin_id' => (int) $admin->id,
    'site_id' => (int) $site->id,
    'balance' => '1200.00',
]);
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/PlatformPlanSubscriptionTest.php --filter=create_agent_user_with_site_and_plan_subscription
```

Expected: FAIL because the super-admin create flow opens only site-level subscription.

- [ ] **Step 3: Inject account subscription service**

Modify `app/Http/Controllers/Admin/AdminUserController.php` constructor:

```php
public function __construct(
    private readonly PlanSubscriptionService $subscriptionService,
    private readonly \App\Services\Billing\AdminPlanSubscriptionService $adminSubscriptionService
) {}
```

- [ ] **Step 4: Open owner account subscription after site subscription**

Inside the transaction after `$this->subscriptionService->open(...)`, store the returned site subscription and open the account subscription:

```php
$siteSubscription = $this->subscriptionService->open(
    site: $site,
    plan: PlatformPlan::query()->with('entitlements')->findOrFail((int) $payload['plan_id']),
    mode: $mode,
    ownerAdmin: $admin,
    operator: $operator,
    startsAt: $startsAt,
    endsAt: $endsAt,
    grantCredits: (bool) ($payload['grant_credits'] ?? false),
    remark: (string) ($payload['subscription_remark'] ?? '')
);

$this->adminSubscriptionService->openOwner(
    admin: $admin,
    site: $site,
    plan: $siteSubscription->plan()->with('entitlements')->firstOrFail(),
    mode: $mode === 'agent' ? 'agent_owner' : 'direct_owner',
    operator: $operator,
    startsAt: $startsAt,
    endsAt: $endsAt,
    grantCredits: (bool) ($payload['grant_credits'] ?? false),
    remark: (string) ($payload['subscription_remark'] ?? '')
);
```

If the existing variable already contains the loaded `PlatformPlan`, reuse it to avoid another query.

- [ ] **Step 5: Run onboarding test**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/PlatformPlanSubscriptionTest.php --filter=create_agent_user_with_site_and_plan_subscription
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/AdminUserController.php tests/Feature/PlatformPlanSubscriptionTest.php
git commit -m "feat: open account plan for created customers"
```

---

## Task 8: Inherit Independent Account Subscription When Agent Creates Site User

**Files:**
- Modify: `app/Http/Controllers/Admin/AgentUserController.php`
- Test: `tests/Feature/AgentUserManagementTest.php`

- [ ] **Step 1: Add failing inheritance test**

Append to `tests/Feature/AgentUserManagementTest.php`:

```php
public function test_agent_created_user_inherits_independent_account_subscription_and_credits(): void
{
    $agent = $this->createAdmin('agent_inherit_owner', 'agent_admin');
    $site = $this->createSite('Agent Inherit Site', $agent, 'agent');
    $plan = PlatformPlan::query()->create([
        'name' => 'Agent Inherit Plan',
        'code' => 'agent-inherit-'.str()->random(6),
        'audience' => 'agent',
        'duration_days' => 30,
        'status' => 'active',
        'sort_order' => 0,
    ]);
    $plan->entitlements()->create([
        'resource_key' => PlatformPlan::RESOURCE_CREDITS,
        'enabled' => true,
        'quota_value' => 1000,
        'quota_period' => 'cycle',
        'unit' => 'points',
        'meta' => [],
    ]);
    $plan->entitlements()->create([
        'resource_key' => PlatformPlan::RESOURCE_BRAND_DIAGNOSES,
        'enabled' => true,
        'quota_value' => 3,
        'quota_period' => 'cycle',
        'unit' => 'times',
        'meta' => [],
    ]);

    app(\App\Services\Billing\AdminPlanSubscriptionService::class)->openOwner(
        admin: $agent,
        site: $site,
        plan: $plan,
        mode: 'agent_owner',
        operator: $agent,
        startsAt: now(),
        endsAt: now()->addDays(30),
        grantCredits: true,
        remark: 'Agent owner plan'
    );
    app(PlanSubscriptionService::class)->open($site, $plan, 'agent', $agent, $agent, now(), now()->addMonth(), false);

    $this->actingAs($agent, 'admin')
        ->withSession(['current_site_id' => (int) $site->id])
        ->post(route('admin.agent-users.store'), [
            'username' => 'agent_inherit_member',
            'display_name' => 'Agent Inherit Member',
            'email' => 'agent-inherit-member@example.com',
            'password' => 'secret-123',
            'confirm_password' => 'secret-123',
        ])
        ->assertRedirect(route('admin.agent-users.index'));

    $member = Admin::query()->where('username', 'agent_inherit_member')->firstOrFail();

    $this->assertDatabaseHas('admin_plan_subscriptions', [
        'admin_id' => (int) $member->id,
        'site_id' => (int) $site->id,
        'plan_id' => (int) $plan->id,
        'inherited_from_admin_id' => (int) $agent->id,
        'mode' => 'agent_user',
        'status' => 'active',
    ]);
    $this->assertDatabaseHas('admin_credit_accounts', [
        'admin_id' => (int) $member->id,
        'site_id' => (int) $site->id,
        'balance' => '1000.00',
    ]);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AgentUserManagementTest.php --filter=agent_created_user_inherits_independent_account_subscription_and_credits
```

Expected: FAIL because `AgentUserController` only creates the admin and site member.

- [ ] **Step 3: Inject account subscription service**

Modify constructor in `app/Http/Controllers/Admin/AgentUserController.php`:

```php
public function __construct(
    private readonly ResourceQuotaService $quotaService,
    private readonly \App\Services\Billing\AdminPlanSubscriptionService $adminSubscriptionService
) {}
```

- [ ] **Step 4: Open inherited subscription inside transaction**

Inside `store()`, capture the created admin and call inheritance:

```php
DB::transaction(function () use ($payload, $site): void {
    $admin = Admin::query()->create([
        'username' => trim((string) $payload['username']),
        'display_name' => trim((string) ($payload['display_name'] ?? '')),
        'email' => trim((string) ($payload['email'] ?? '')),
        'password' => (string) $payload['password'],
        'role' => 'site_user',
        'status' => 'active',
        'created_by' => (int) (auth('admin')->id() ?? 0),
    ]);

    $site->members()->attach((int) $admin->id, ['role' => 'member']);

    $agent = auth('admin')->user();
    if ($agent instanceof Admin) {
        $this->adminSubscriptionService->inheritForAgentUser(
            agent: $agent,
            user: $admin,
            site: $site,
            operator: $agent
        );
    }
});
```

- [ ] **Step 5: Run agent tests**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AgentUserManagementTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/AgentUserController.php tests/Feature/AgentUserManagementTest.php
git commit -m "feat: inherit account plan for agent users"
```

---

## Task 9: Add Owner Admin Columns to Business Resources

**Files:**
- Create: `database/migrations/2026_06_15_000100_add_owner_admin_id_to_business_resources.php`
- Create: `tests/Feature/AdminAccountResourceIsolationTest.php`

- [ ] **Step 1: Write failing owner column test**

Create `tests/Feature/AdminAccountResourceIsolationTest.php`:

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAccountResourceIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_business_tables_have_owner_admin_id(): void
    {
        foreach ([
            'knowledge_bases',
            'knowledge_chunks',
            'image_libraries',
            'images',
            'articles',
            'categories',
            'authors',
            'tasks',
            'task_runs',
            'task_schedules',
            'title_libraries',
            'titles',
            'keyword_libraries',
            'keywords',
            'keyword_question_variants',
            'url_import_jobs',
            'url_import_job_logs',
            'geo_inclusion_check_runs',
            'geo_inclusion_check_results',
            'brand_diagnosis_runs',
            'brand_diagnosis_questions',
            'brand_diagnosis_results',
            'brand_diagnosis_sources',
            'brand_diagnosis_brand_mentions',
            'media_submissions',
        ] as $table) {
            if (Schema::hasTable($table)) {
                $this->assertTrue(Schema::hasColumn($table, 'owner_admin_id'), $table.' missing owner_admin_id');
            }
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountResourceIsolationTest.php --filter=core_business_tables_have_owner_admin_id
```

Expected: FAIL because most tables lack `owner_admin_id`.

- [ ] **Step 3: Create focused migration**

Create `database/migrations/2026_06_15_000100_add_owner_admin_id_to_business_resources.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'knowledge_bases',
        'knowledge_chunks',
        'image_libraries',
        'images',
        'articles',
        'categories',
        'authors',
        'tasks',
        'task_runs',
        'task_schedules',
        'title_libraries',
        'titles',
        'keyword_libraries',
        'keywords',
        'keyword_question_variants',
        'url_import_jobs',
        'url_import_job_logs',
        'geo_inclusion_check_runs',
        'geo_inclusion_check_results',
        'brand_diagnosis_runs',
        'brand_diagnosis_questions',
        'brand_diagnosis_results',
        'brand_diagnosis_sources',
        'brand_diagnosis_brand_mentions',
        'media_submissions',
    ];

    public function up(): void
    {
        foreach ($this->tables as $tableName) {
            if (! Schema::hasTable($tableName) || Schema::hasColumn($tableName, 'owner_admin_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('owner_admin_id')
                    ->nullable()
                    ->after('site_id')
                    ->constrained('admins')
                    ->nullOnDelete();
                $table->index(['site_id', 'owner_admin_id']);
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'owner_admin_id')) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('owner_admin_id');
            });
        }
    }
};
```

- [ ] **Step 4: Run owner column test**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountResourceIsolationTest.php --filter=core_business_tables_have_owner_admin_id
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_15_000100_add_owner_admin_id_to_business_resources.php tests/Feature/AdminAccountResourceIsolationTest.php
git commit -m "feat: add account owner fields to resources"
```

---

## Task 10: Add Owner Scope Trait

**Files:**
- Create: `app/Models/Concerns/BelongsToAdminOwner.php`
- Modify first wave models:
  - `app/Models/KnowledgeBase.php`
  - `app/Models/ImageLibrary.php`
  - `app/Models/Article.php`
  - `app/Models/BrandDiagnosisRun.php`
  - `app/Models/MediaSubmission.php`
- Test: `tests/Feature/AdminAccountResourceIsolationTest.php`

- [ ] **Step 1: Add failing query isolation test**

Append to `tests/Feature/AdminAccountResourceIsolationTest.php`:

```php
public function test_site_user_only_sees_own_knowledge_bases_inside_same_site(): void
{
    $site = \App\Models\Site::query()->create(['name' => 'Shared Agent Site', 'status' => 'active']);
    $userOne = \App\Models\Admin::query()->create([
        'username' => 'kb_owner_one',
        'password' => 'secret-123',
        'email' => 'kb-owner-one@example.com',
        'display_name' => 'KB Owner One',
        'role' => 'site_user',
        'status' => 'active',
    ]);
    $userTwo = \App\Models\Admin::query()->create([
        'username' => 'kb_owner_two',
        'password' => 'secret-123',
        'email' => 'kb-owner-two@example.com',
        'display_name' => 'KB Owner Two',
        'role' => 'site_user',
        'status' => 'active',
    ]);
    $site->members()->attach((int) $userOne->id, ['role' => 'member']);
    $site->members()->attach((int) $userTwo->id, ['role' => 'member']);

    app(\App\Support\CurrentSite::class)->set($site);

    \App\Models\KnowledgeBase::query()->create([
        'site_id' => (int) $site->id,
        'owner_admin_id' => (int) $userOne->id,
        'name' => 'User One Knowledge',
        'description' => '',
    ]);
    \App\Models\KnowledgeBase::query()->create([
        'site_id' => (int) $site->id,
        'owner_admin_id' => (int) $userTwo->id,
        'name' => 'User Two Knowledge',
        'description' => '',
    ]);

    $this->actingAs($userOne, 'admin');

    $names = \App\Models\KnowledgeBase::query()->pluck('name')->all();

    $this->assertSame(['User One Knowledge'], $names);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountResourceIsolationTest.php --filter=site_user_only_sees_own_knowledge_bases_inside_same_site
```

Expected: FAIL because `BelongsToSite` scopes by site only.

- [ ] **Step 3: Create trait**

Create `app/Models/Concerns/BelongsToAdminOwner.php`:

```php
<?php

namespace App\Models\Concerns;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Builder;

trait BelongsToAdminOwner
{
    public static function bootBelongsToAdminOwner(): void
    {
        static::addGlobalScope('admin_owner', function (Builder $builder): void {
            $admin = auth('admin')->user();
            if (! $admin instanceof Admin) {
                return;
            }

            if ($admin->isSuperAdmin() || $admin->isAgentAdmin()) {
                return;
            }

            $builder->where($builder->getModel()->getTable().'.owner_admin_id', (int) $admin->id);
        });

        static::creating(function ($model): void {
            if (! empty($model->owner_admin_id)) {
                return;
            }

            $admin = auth('admin')->user();
            if ($admin instanceof Admin && ! $admin->isSuperAdmin()) {
                $model->owner_admin_id = (int) $admin->id;
            }
        });
    }

    public function scopeForOwnerAdmin(Builder $query, int $adminId): Builder
    {
        return $query->withoutGlobalScope('admin_owner')->where($this->getTable().'.owner_admin_id', $adminId);
    }
}
```

- [ ] **Step 4: Add trait to first-wave models**

In each first-wave model, add:

```php
use App\Models\Concerns\BelongsToAdminOwner;
```

Then update the trait line:

```php
use BelongsToSite;
use BelongsToAdminOwner;
```

For models already using multiple traits, keep both traits in the class body:

```php
use BelongsToSite, BelongsToAdminOwner;
```

- [ ] **Step 5: Run isolation test**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountResourceIsolationTest.php --filter=site_user_only_sees_own_knowledge_bases_inside_same_site
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Concerns/BelongsToAdminOwner.php app/Models/KnowledgeBase.php app/Models/ImageLibrary.php app/Models/Article.php app/Models/BrandDiagnosisRun.php app/Models/MediaSubmission.php tests/Feature/AdminAccountResourceIsolationTest.php
git commit -m "feat: scope user-owned resources by admin account"
```

---

## Task 11: Backfill Existing Owner and Account Subscription Data

**Files:**
- Create: `database/migrations/2026_06_15_000200_backfill_admin_account_ownership.php`
- Test: `tests/Feature/AdminAccountPlanSubscriptionTest.php`

- [ ] **Step 1: Add migration behavior test**

Append to `tests/Feature/AdminAccountPlanSubscriptionTest.php`:

```php
public function test_legacy_site_subscription_has_owner_account_subscription_after_migrations(): void
{
    $admin = \App\Models\Admin::query()->create([
        'username' => 'legacy_account_owner',
        'password' => 'secret-123',
        'email' => 'legacy-account-owner@example.com',
        'display_name' => 'Legacy Account Owner',
        'role' => 'direct_admin',
        'status' => 'active',
    ]);
    $site = \App\Models\Site::query()->create([
        'owner_admin_id' => (int) $admin->id,
        'name' => 'Legacy Account Site',
        'status' => 'active',
        'customer_mode' => 'direct',
    ]);
    $site->members()->attach((int) $admin->id, ['role' => 'owner']);
    $plan = $this->accountPlanWithResources([
        \App\Models\PlatformPlan::RESOURCE_CREDITS => ['quota_value' => 500, 'unit' => 'points'],
    ]);

    $siteSubscription = app(\App\Services\Billing\PlanSubscriptionService::class)->open(
        site: $site,
        plan: $plan,
        mode: 'direct',
        ownerAdmin: $admin,
        operator: $admin,
        startsAt: now(),
        endsAt: now()->addDays(30),
        grantCredits: false
    );

    app(\App\Services\Billing\AdminPlanSubscriptionService::class)->backfillFromSiteSubscription($admin, $site, $siteSubscription);

    $this->assertDatabaseHas('admin_plan_subscriptions', [
        'admin_id' => (int) $admin->id,
        'site_id' => (int) $site->id,
        'source_subscription_id' => (int) $siteSubscription->id,
        'mode' => 'direct_owner',
    ]);
}
```

- [ ] **Step 2: Run test**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountPlanSubscriptionTest.php --filter=legacy_site_subscription_has_owner_account_subscription_after_migrations
```

Expected: PASS if Task 3 created `backfillFromSiteSubscription()`.

- [ ] **Step 3: Create backfill migration**

Create `database/migrations/2026_06_15_000200_backfill_admin_account_ownership.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('site_plan_subscriptions') && Schema::hasTable('admin_plan_subscriptions')) {
            DB::table('site_plan_subscriptions')
                ->whereNotNull('owner_admin_id')
                ->orderBy('id')
                ->get()
                ->each(function (object $subscription): void {
                    DB::table('admin_plan_subscriptions')->updateOrInsert(
                        [
                            'admin_id' => (int) $subscription->owner_admin_id,
                            'site_id' => (int) $subscription->site_id,
                            'source_subscription_id' => (int) $subscription->id,
                        ],
                        [
                            'plan_id' => $subscription->plan_id,
                            'inherited_from_admin_id' => null,
                            'mode' => match ((string) $subscription->mode) {
                                'agent' => 'agent_owner',
                                'direct' => 'direct_owner',
                                default => 'internal',
                            },
                            'status' => $subscription->status,
                            'starts_at' => $subscription->starts_at,
                            'ends_at' => $subscription->ends_at,
                            'entitlements_snapshot' => $subscription->entitlements_snapshot,
                            'remark' => '由站点规格兼容迁移生成',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                });
        }

        $this->backfillOwnerAdminId('articles', 'owner_admin_id');
        $this->backfillOwnerAdminId('media_submissions', 'submitted_by_admin_id');
        $this->backfillOwnerAdminId('brand_diagnosis_runs', 'admin_id');
    }

    public function down(): void
    {
        DB::table('admin_plan_subscriptions')
            ->where('remark', '由站点规格兼容迁移生成')
            ->delete();
    }

    private function backfillOwnerAdminId(string $table, string $sourceColumn): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'owner_admin_id') || ! Schema::hasColumn($table, $sourceColumn)) {
            return;
        }

        DB::table($table)
            ->whereNull('owner_admin_id')
            ->whereNotNull($sourceColumn)
            ->update(['owner_admin_id' => DB::raw($sourceColumn)]);
    }
};
```

- [ ] **Step 4: Run billing and isolation tests**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountPlanSubscriptionTest.php tests/Feature/AdminAccountResourceIsolationTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_06_15_000200_backfill_admin_account_ownership.php tests/Feature/AdminAccountPlanSubscriptionTest.php
git commit -m "feat: backfill account ownership data"
```

---

## Task 12: Switch Brand Diagnosis Quota to Account Level

**Files:**
- Modify: `app/Services/BrandDiagnosis/BrandDiagnosisUsagePolicy.php`
- Test: `tests/Feature/BrandDiagnosisDoubaoFlowTest.php`

- [ ] **Step 1: Add or update quota test**

In `tests/Feature/BrandDiagnosisDoubaoFlowTest.php`, update the test that checks plan quota consumption so it asserts `admin_resource_usages` instead of only `site_resource_usages`:

```php
$this->assertDatabaseHas('admin_resource_usages', [
    'admin_id' => (int) $admin->id,
    'site_id' => (int) $site->id,
    'resource_key' => PlatformPlan::RESOURCE_BRAND_DIAGNOSES,
    'used_amount' => 1,
]);
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/BrandDiagnosisDoubaoFlowTest.php --filter=quota
```

Expected: FAIL because brand diagnosis still consumes site-level quota.

- [ ] **Step 3: Inject account quota service**

Modify constructor:

```php
public function __construct(
    private readonly \App\Services\Billing\AdminResourceQuotaService $quotaService
) {}
```

- [ ] **Step 4: Consume account quota**

In `reserve()`:

```php
$this->quotaService->consume((int) $admin->id, $siteId, PlatformPlan::RESOURCE_BRAND_DIAGNOSES, 1, [
    'actor_admin_id' => (int) $admin->id,
    'idempotency_key' => 'brand-diagnosis:'.(int) $admin->id.':'.$siteId.':'.now()->timestamp.':'.str()->random(8),
    'remark' => '品牌诊断确认消耗',
]);
```

- [ ] **Step 5: Run brand diagnosis tests**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/BrandDiagnosisDoubaoFlowTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/BrandDiagnosis/BrandDiagnosisUsagePolicy.php tests/Feature/BrandDiagnosisDoubaoFlowTest.php
git commit -m "feat: consume brand diagnosis quota per account"
```

---

## Task 13: Switch Media Publishing Credits to Account Level

**Files:**
- Modify: `app/Services/MediaDistribution/MediaSubmissionService.php`
- Modify: `app/Http/Controllers/Admin/MediaDistribution/SubmissionController.php`
- Test: `tests/Feature/AdminMediaDistributionTest.php`

- [ ] **Step 1: Add failing account credit consumption test**

In `tests/Feature/AdminMediaDistributionTest.php`, add a media submission test with two users under one site:

```php
public function test_media_submission_deducts_current_admin_credit_not_shared_site_credit(): void
{
    $this->markTestSkipped('Enable after media submission test fixtures are available in this test file.');
}
```

Replace the skipped body in the implementation pass with existing media resource/article fixtures from this test file. The assertion must be:

```php
$this->assertDatabaseHas('admin_credit_ledger', [
    'admin_id' => (int) $admin->id,
    'type' => 'deduct',
    'amount' => '-100.00',
]);
```

- [ ] **Step 2: Run current media tests**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminMediaDistributionTest.php
```

Expected: PASS before implementation. Remove `markTestSkipped()` only when the full fixture is added.

- [ ] **Step 3: Inject AdminCreditService**

Modify constructor in `MediaSubmissionService`:

```php
public function __construct(
    private readonly MediaPlatformClientManager $clients,
    private readonly AdminCreditService $credits,
    private readonly \App\Services\Billing\AdminResourceQuotaService $quotaService,
) {}
```

- [ ] **Step 4: Switch subscription and credit checks**

In `submit()`:

```php
$this->quotaService->assertSubscriptionActive((int) $admin->id, (int) $article->site_id, $admin);

$salePrice = $this->salePriceForSite($resource, (int) $article->site_id);
$this->credits->ensureSufficient((int) $admin->id, (int) $article->site_id, $salePrice);
```

After creating submission:

```php
$this->credits->deductForSubmission($submission, (int) $admin->id, (int) $admin->id);
```

On failure:

```php
$this->credits->refundForSubmission($submission, (int) $admin->id, (int) $admin->id);
```

In `syncStatus()` and `cancel()`, use the submission owner:

```php
$ownerAdminId = (int) ($submission->submitted_by_admin_id ?? 0);
if ($ownerAdminId > 0) {
    $this->credits->refundForSubmission($submission, $ownerAdminId, null, '媒体订单'.$submission->status.'自动退回');
}
```

- [ ] **Step 5: Run media tests**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminMediaDistributionTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/MediaDistribution/MediaSubmissionService.php app/Http/Controllers/Admin/MediaDistribution/SubmissionController.php tests/Feature/AdminMediaDistributionTest.php
git commit -m "feat: use account credits for media submissions"
```

---

## Task 14: Switch Remaining Quota Entry Points to Account Level

**Files:**
- Modify: `app/Services/GeoFlow/WorkerExecutionService.php`
- Modify: `app/Services/GeoFlow/AiGeneratedArticleImageService.php`
- Modify: `app/Http/Controllers/Admin/TitleLibraryController.php`
- Modify: `app/Http/Controllers/Admin/UrlImportController.php`
- Modify: `app/Http/Controllers/Admin/KeywordLibraryController.php`
- Modify: `app/Http/Controllers/Admin/ApiTokenController.php`
- Tests:
  - `tests/Feature/WorkerExecutionSiteIsolationTest.php`
  - `tests/Feature/AdminArticlesPageTest.php`
  - `tests/Feature/AdminGeoInclusionCheckPhaseTwoTest.php`
  - `tests/Feature/PlatformPlanSubscriptionTest.php`

- [ ] **Step 1: Update tests one module at a time**

For each module, assert `admin_resource_usages.admin_id` is the acting admin:

```php
$this->assertDatabaseHas('admin_resource_usages', [
    'admin_id' => (int) $admin->id,
    'site_id' => (int) $site->id,
    'resource_key' => PlatformPlan::RESOURCE_ARTICLE_GENERATIONS,
]);
```

Use the correct `RESOURCE_*` constant for each module.

- [ ] **Step 2: Run the first module test and confirm failure**

Run the smallest relevant test:

```bash
docker compose exec -T app php artisan test tests/Feature/WorkerExecutionSiteIsolationTest.php --filter=quota
```

Expected: FAIL until the module uses `AdminResourceQuotaService`.

- [ ] **Step 3: Replace constructor dependency in each module**

Change:

```php
private readonly ResourceQuotaService $quotaService
```

To:

```php
private readonly \App\Services\Billing\AdminResourceQuotaService $quotaService
```

- [ ] **Step 4: Pass account id into checks and consumes**

Replace calls shaped like:

```php
$this->quotaService->assertCanUse($siteId, $resourceKey, $amount);
$this->quotaService->consume($siteId, $resourceKey, $amount, $context);
```

With:

```php
$adminId = (int) (auth('admin')->id() ?? ($context['actor_admin_id'] ?? 0));
$this->quotaService->assertCanUse($adminId, $siteId, $resourceKey, $amount);
$this->quotaService->consume($adminId, $siteId, $resourceKey, $amount, $context);
```

For queued jobs where `auth('admin')` is not available, use the task/article/run owner field already persisted on the record. If no owner exists, use `owner_admin_id` added in Task 9.

- [ ] **Step 5: Run focused tests after each module**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/WorkerExecutionSiteIsolationTest.php
docker compose exec -T app php artisan test tests/Feature/AdminGeoInclusionCheckPhaseTwoTest.php
docker compose exec -T app php artisan test tests/Feature/PlatformPlanSubscriptionTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/GeoFlow/WorkerExecutionService.php app/Services/GeoFlow/AiGeneratedArticleImageService.php app/Http/Controllers/Admin/TitleLibraryController.php app/Http/Controllers/Admin/UrlImportController.php app/Http/Controllers/Admin/KeywordLibraryController.php app/Http/Controllers/Admin/ApiTokenController.php tests/Feature
git commit -m "feat: consume quotas by admin account"
```

---

## Task 15: Extend Owner Scoping to Remaining User-Owned Models

**Files:**
- Modify models listed in Task 9 that are not part of Task 10
- Test: `tests/Feature/AdminAccountResourceIsolationTest.php`

- [ ] **Step 1: Add table-by-table isolation tests**

Add tests for:

```php
test_site_user_only_sees_own_articles_inside_same_site()
test_site_user_only_sees_own_image_libraries_inside_same_site()
test_site_user_only_sees_own_brand_diagnosis_runs_inside_same_site()
test_agent_admin_can_see_all_user_resources_inside_agent_site()
```

Use this assertion shape:

```php
$this->actingAs($siteUser, 'admin');
$this->assertSame(['Owned Row'], ModelClass::query()->pluck('name_or_title_column')->all());

$this->actingAs($agentAdmin, 'admin');
$this->assertCount(2, ModelClass::query()->get());
```

- [ ] **Step 2: Run tests to verify failures**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountResourceIsolationTest.php
```

Expected: FAIL for models without owner scoping.

- [ ] **Step 3: Add BelongsToAdminOwner to remaining models**

For each remaining user-owned model:

```php
use App\Models\Concerns\BelongsToAdminOwner;
```

And:

```php
use BelongsToSite, BelongsToAdminOwner;
```

Do not add this trait to pure site configuration tables where all users should share configuration, such as `site_settings`, unless the product explicitly changes that behavior.

- [ ] **Step 4: Run isolation tests**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountResourceIsolationTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models tests/Feature/AdminAccountResourceIsolationTest.php
git commit -m "feat: isolate user resources inside shared sites"
```

---

## Task 16: Update Admin Pages to Show Account-Level Balance and Quota

**Files:**
- Modify: `app/Http/Controllers/Admin/MediaDistribution/SubmissionController.php`
- Modify: `app/Http/Controllers/Admin/MediaDistribution/CreditController.php`
- Modify: `resources/views/admin/media-distribution/submissions.blade.php`
- Modify: `resources/views/admin/media-distribution/credits.blade.php`
- Test: `tests/Feature/AdminMediaDistributionTest.php`

- [ ] **Step 1: Add failing page assertion**

In the relevant media distribution page test, assert that a site user sees account balance text:

```php
$response->assertSee('账号积分');
```

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminMediaDistributionTest.php --filter=credit
```

Expected: FAIL if pages still show only site credits.

- [ ] **Step 3: Load account credit for non-super admins**

In controllers, use:

```php
$admin = auth('admin')->user();
$siteId = app(\App\Support\CurrentSite::class)->id();
$account = $admin instanceof \App\Models\Admin && $siteId !== null
    ? app(\App\Services\MediaDistribution\AdminCreditService::class)->accountForAdmin((int) $admin->id, (int) $siteId)
    : null;
```

For super admin reports, show both site-level historical balance and account-level ledgers where available.

- [ ] **Step 4: Update Blade labels**

Use labels:

```blade
账号积分
站点历史积分
```

Only show `站点历史积分` to super admin.

- [ ] **Step 5: Run media page tests**

Run:

```bash
docker compose exec -T app php artisan test tests/Feature/AdminMediaDistributionTest.php
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/MediaDistribution/SubmissionController.php app/Http/Controllers/Admin/MediaDistribution/CreditController.php resources/views/admin/media-distribution/submissions.blade.php resources/views/admin/media-distribution/credits.blade.php tests/Feature/AdminMediaDistributionTest.php
git commit -m "feat: show account-level credit balances"
```

---

## Task 17: Full Regression Run

**Files:**
- No source edits unless tests reveal failures.

- [ ] **Step 1: Run billing and account tests**

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountPlanSubscriptionTest.php tests/Feature/AdminAccountResourceIsolationTest.php tests/Feature/PlatformPlanSubscriptionTest.php tests/Feature/AgentUserManagementTest.php
```

Expected: PASS.

- [ ] **Step 2: Run business module tests**

```bash
docker compose exec -T app php artisan test tests/Feature/BrandDiagnosisDoubaoFlowTest.php tests/Feature/AdminMediaDistributionTest.php tests/Feature/WorkerExecutionSiteIsolationTest.php tests/Feature/AdminGeoInclusionCheckPhaseTwoTest.php
```

Expected: PASS.

- [ ] **Step 3: Run broad feature test suite if runtime is acceptable**

```bash
docker compose exec -T app php artisan test tests/Feature
```

Expected: PASS.

- [ ] **Step 4: Verify local migrations**

```bash
docker compose exec -T app php artisan migrate:fresh --seed
```

Expected: migrations run without errors and seed completes.

- [ ] **Step 5: Commit verification-only fixes**

If test-only fixes were required:

```bash
git add .
git commit -m "test: cover account-level plan isolation"
```

If no fixes were required, do not create an empty commit.

---

## Rollout Notes

Production deployment after implementation should use:

```bash
git pull
docker compose --env-file .env.prod -f docker-compose.prod.yml build app
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d
docker compose --env-file .env.prod -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose --env-file .env.prod -f docker-compose.prod.yml exec app php artisan optimize:clear
```

Important rollout checks:

- Confirm `admin_plan_subscriptions` has rows for every active agent/direct owner.
- Confirm newly created agent users get their own `admin_plan_subscriptions`.
- Confirm newly created agent users get independent `admin_credit_accounts`.
- Confirm the same agent site can contain two users with separate quota usage rows.
- Confirm direct admins still cannot access agent user management.
- Confirm media publish deducts `admin_credit_accounts`, not shared `site_credit_accounts`.

## Risks and Guards

- Existing production data may have resources without a reliable creator field. The backfill keeps `owner_admin_id` nullable and only scopes rows once ownership is known.
- Super admin and agent admin visibility must remain broader than ordinary site users. `BelongsToAdminOwner` intentionally bypasses owner scope for `super_admin` and `agent_admin`.
- Old site-level billing is retained. This allows rollback of business entry points if a module-specific account migration exposes missing ownership.
- Account-level credit balances are newly granted when opening/inheriting subscriptions. Do not copy shared site balance into every user, otherwise legacy sites could multiply old balances unexpectedly.

## Self-Review

- Spec coverage: covers independent per-user plan quota, direct customer restriction, agent user inheritance, data isolation, media credits, new video and CreBee resources, and compatibility with existing site-level structures.
- Placeholder scan: no implementation steps rely on undefined tasks; deferred production cleanup is explicitly excluded from this plan.
- Type consistency: account-level services use `admin_id`, `site_id`, and `subscription_id` consistently; site-level services remain compatibility paths.
- Scope check: this is a large migration but split into independently testable tasks. The first stable checkpoint is Task 8, where new customer creation and agent user inheritance both produce account-level subscriptions.
