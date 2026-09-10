# Product Cases Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a global public product case module with super-admin management and monitoring-center data blocks.

**Architecture:** Add a dedicated `product_cases` table and model for manual case content. Public pages read only published cases; super-admin management pages handle CRUD. A focused summary service adapts existing monitoring reports into lightweight case metrics without reusing monitoring-center report HTML.

**Tech Stack:** Laravel, Eloquent, Blade, Tailwind utility classes, existing admin layout, existing `MonitoringReportDataService`, PHPUnit feature tests.

---

## File Structure

- Create: `database/migrations/2026_09_02_000000_create_product_cases_table.php`
  - Defines the independent product case table, indexes, soft deletes, and JSON tags.
- Create: `app/Models/ProductCase.php`
  - Eloquent model, casts, relationships, public query scope, slug helper, image URL normalization helper.
- Create: `app/Services/ProductCases/ProductCaseReportSummaryService.php`
  - Converts `MonitoringReportDataService` enterprise and industry reports into case-page metrics.
- Create: `app/Http/Controllers/ProductCaseController.php`
  - Public list and detail pages, no auth middleware.
- Create: `app/Http/Controllers/Admin/ProductCaseController.php`
  - Super-admin CRUD, status toggle, soft delete.
- Create: `resources/views/product-cases/index.blade.php`
  - Public case list page.
- Create: `resources/views/product-cases/show.blade.php`
  - Public case detail page.
- Create: `resources/views/admin/product-cases/index.blade.php`
  - Super-admin case management list.
- Create: `resources/views/admin/product-cases/create.blade.php`
  - Super-admin create form wrapper.
- Create: `resources/views/admin/product-cases/edit.blade.php`
  - Super-admin edit form wrapper.
- Create: `resources/views/admin/product-cases/_form.blade.php`
  - Shared create/edit form.
- Modify: `routes/web.php`
  - Add public case routes and admin management routes.
- Modify: `resources/views/admin/partials/header.blade.php`
  - Add public `产品案例` top-nav link for all logged-in users and right-menu `产品案例管理` for super admins.
- Create: `tests/Feature/ProductCaseModuleTest.php`
  - Public visibility, super-admin CRUD, permissions, navigation, report data summary.

---

## Task 1: Data Model And Migration

**Files:**
- Create: `database/migrations/2026_09_02_000000_create_product_cases_table.php`
- Create: `app/Models/ProductCase.php`
- Test: `tests/Feature/ProductCaseModuleTest.php`

- [ ] **Step 1: Write failing model and public visibility tests**

Add this test file:

```php
<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\ProductCase;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCaseModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_published_cases_are_public_and_drafts_are_hidden(): void
    {
        $published = ProductCase::query()->create([
            'title' => '聚福楼 GEO 成效案例',
            'slug' => 'jufulou-geo-case',
            'company_name' => '聚福楼',
            'summary' => '聚福楼通过 GEO 内容和 AI 搜索监测提升品牌曝光。',
            'content' => '## 品牌介绍'."\n\n".'聚福楼是一家本地餐饮品牌。',
            'status' => ProductCase::STATUS_PUBLISHED,
            'published_at' => now()->subMinute(),
        ]);

        ProductCase::query()->create([
            'title' => '草稿案例',
            'slug' => 'draft-case',
            'company_name' => '草稿品牌',
            'summary' => '草稿不可公开访问。',
            'content' => '草稿内容',
            'status' => ProductCase::STATUS_DRAFT,
        ]);

        $this->get(route('product-cases.index'))
            ->assertOk()
            ->assertSee($published->title)
            ->assertDontSee('草稿案例');

        $this->get(route('product-cases.show', ['slug' => 'jufulou-geo-case']))
            ->assertOk()
            ->assertSee('聚福楼 GEO 成效案例')
            ->assertSee('品牌介绍');

        $this->get(route('product-cases.show', ['slug' => 'draft-case']))
            ->assertNotFound();
    }

    public function test_product_case_model_relationships_and_scope(): void
    {
        $owner = Admin::query()->create([
            'username' => 'case_owner',
            'password' => 'password',
            'display_name' => '案例客户',
            'role' => 'direct_admin',
            'status' => 'active',
        ]);
        $site = Site::query()->create([
            'owner_admin_id' => $owner->id,
            'name' => '案例站点',
            'domain' => 'case.example.test',
            'status' => 'active',
        ]);

        $case = ProductCase::query()->create([
            'site_id' => $site->id,
            'owner_admin_id' => $owner->id,
            'title' => '案例站点绑定案例',
            'slug' => 'bound-site-case',
            'company_name' => '案例品牌',
            'module_tags' => ['AI 搜索监测', '品牌诊断'],
            'status' => ProductCase::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
        ]);

        $this->assertTrue($case->site->is($site));
        $this->assertTrue($case->owner->is($owner));
        $this->assertSame(['AI 搜索监测', '品牌诊断'], $case->module_tags);
        $this->assertSame(1, ProductCase::query()->published()->count());
    }
}
```

- [ ] **Step 2: Run the new test and verify it fails**

Run:

```bash
php artisan test tests/Feature/ProductCaseModuleTest.php
```

Expected: FAIL because `App\Models\ProductCase` and product case routes do not exist.

- [ ] **Step 3: Create migration**

Create `database/migrations/2026_09_02_000000_create_product_cases_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->foreignId('owner_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('title', 180);
            $table->string('slug', 220)->unique();
            $table->string('company_name', 180)->default('');
            $table->string('logo_url', 500)->default('');
            $table->string('cover_url', 500)->default('');
            $table->string('industry', 120)->default('');
            $table->string('region', 120)->default('');
            $table->string('business_mode', 120)->default('');
            $table->json('module_tags')->nullable();
            $table->string('summary', 1000)->default('');
            $table->longText('content')->nullable();
            $table->string('customer_level', 80)->default('');
            $table->date('started_at')->nullable();
            $table->string('status', 32)->default('draft')->index();
            $table->integer('sort_order')->default(0)->index();
            $table->unsignedBigInteger('view_count')->default(0);
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'published_at', 'sort_order']);
            $table->index(['industry', 'region']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_cases');
    }
};
```

- [ ] **Step 4: Create `ProductCase` model**

Create `app/Models/ProductCase.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProductCase extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_HIDDEN = 'hidden';

    protected $fillable = [
        'site_id',
        'owner_admin_id',
        'title',
        'slug',
        'company_name',
        'logo_url',
        'cover_url',
        'industry',
        'region',
        'business_mode',
        'module_tags',
        'summary',
        'content',
        'customer_level',
        'started_at',
        'status',
        'sort_order',
        'view_count',
        'published_at',
        'created_by_admin_id',
        'updated_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'owner_admin_id' => 'integer',
            'module_tags' => 'array',
            'started_at' => 'date',
            'sort_order' => 'integer',
            'view_count' => 'integer',
            'published_at' => 'datetime',
            'created_by_admin_id' => 'integer',
            'updated_by_admin_id' => 'integer',
            'deleted_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'owner_admin_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_id');
    }

    public static function uniqueSlug(string $title, ?self $ignore = null): string
    {
        $base = Str::slug($title);
        if ($base === '') {
            $base = 'case-'.Str::lower(Str::random(8));
        }

        $slug = $base;
        $suffix = 2;

        while (self::query()
            ->when($ignore instanceof self, fn (Builder $query): Builder => $query->whereKeyNot($ignore->id))
            ->where('slug', $slug)
            ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public function publicUrl(): string
    {
        return route('product-cases.show', ['slug' => $this->slug]);
    }
}
```

- [ ] **Step 5: Run migration and focused test**

Run:

```bash
php artisan migrate
php artisan test tests/Feature/ProductCaseModuleTest.php --filter=product_case_model
```

Expected: migration succeeds; model test PASS after routes are added in Task 2. If route-dependent public test still fails, keep going to Task 2.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_09_02_000000_create_product_cases_table.php app/Models/ProductCase.php tests/Feature/ProductCaseModuleTest.php
git commit -m "feat: add product case model"
```

---

## Task 2: Public Case Pages

**Files:**
- Create: `app/Http/Controllers/ProductCaseController.php`
- Create: `resources/views/product-cases/index.blade.php`
- Create: `resources/views/product-cases/show.blade.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/ProductCaseModuleTest.php`

- [ ] **Step 1: Extend public page tests**

Append this test method to `tests/Feature/ProductCaseModuleTest.php`:

```php
public function test_public_case_list_filters_by_keyword_industry_region_and_tag(): void
{
    ProductCase::query()->create([
        'title' => '餐饮品牌案例',
        'slug' => 'restaurant-case',
        'company_name' => '聚福楼',
        'industry' => '餐饮',
        'region' => '北京',
        'business_mode' => '直营',
        'module_tags' => ['品牌诊断'],
        'summary' => '餐饮 GEO 案例',
        'status' => ProductCase::STATUS_PUBLISHED,
        'published_at' => now()->subDay(),
    ]);

    ProductCase::query()->create([
        'title' => '教育品牌案例',
        'slug' => 'education-case',
        'company_name' => '学术易',
        'industry' => '教育',
        'region' => '上海',
        'business_mode' => '平台',
        'module_tags' => ['AI 搜索收录'],
        'summary' => '教育 GEO 案例',
        'status' => ProductCase::STATUS_PUBLISHED,
        'published_at' => now()->subDay(),
    ]);

    $this->get(route('product-cases.index', ['keyword' => '聚福楼']))
        ->assertOk()
        ->assertSee('餐饮品牌案例')
        ->assertDontSee('教育品牌案例');

    $this->get(route('product-cases.index', ['industry' => '教育', 'region' => '上海', 'tag' => 'AI 搜索收录']))
        ->assertOk()
        ->assertSee('教育品牌案例')
        ->assertDontSee('餐饮品牌案例');
}
```

- [ ] **Step 2: Run tests and verify they fail**

Run:

```bash
php artisan test tests/Feature/ProductCaseModuleTest.php --filter=public_case
```

Expected: FAIL because public controller, views, and routes are missing.

- [ ] **Step 3: Add public controller**

Create `app/Http/Controllers/ProductCaseController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\ProductCase;
use App\Services\ProductCases\ProductCaseReportSummaryService;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductCaseController extends Controller
{
    public function index(Request $request, ProductCaseReportSummaryService $reports): View
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $industry = trim((string) $request->query('industry', ''));
        $region = trim((string) $request->query('region', ''));
        $businessMode = trim((string) $request->query('business_mode', ''));
        $tag = trim((string) $request->query('tag', ''));

        $query = ProductCase::query()->published()->with(['site:id,name,owner_admin_id']);
        $this->applyFilters($query, $keyword, $industry, $region, $businessMode, $tag);

        $cases = $query
            ->orderByDesc('sort_order')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        $filterOptions = $this->filterOptions();
        $caseMetrics = [];
        foreach ($cases as $case) {
            if ($case instanceof ProductCase) {
                $caseMetrics[$case->id] = $reports->cardMetrics($case);
            }
        }

        return view('product-cases.index', [
            'cases' => $cases,
            'caseMetrics' => $caseMetrics,
            'filterOptions' => $filterOptions,
            'filters' => compact('keyword', 'industry', 'region', 'businessMode', 'tag'),
            'pageTitle' => '产品案例',
            'pageDescription' => '查看 GEO 与 AI 搜索优化产品案例。',
        ]);
    }

    public function show(string $slug, ProductCaseReportSummaryService $reports): View
    {
        $case = ProductCase::query()
            ->published()
            ->with(['site:id,name,owner_admin_id', 'owner:id,username,display_name'])
            ->where('slug', $slug)
            ->firstOrFail();

        $case->increment('view_count');

        return view('product-cases.show', [
            'case' => $case,
            'contentHtml' => ArticleHtmlPresenter::markdownToHtml((string) $case->content),
            'report' => $reports->detail($case),
            'pageTitle' => $case->title,
            'pageDescription' => trim((string) $case->summary) !== '' ? (string) $case->summary : (string) $case->company_name,
        ]);
    }

    private function applyFilters(Builder $query, string $keyword, string $industry, string $region, string $businessMode, string $tag): void
    {
        if ($keyword !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($keyword)).'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(company_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(summary) LIKE ?', [$like]);
            });
        }

        if ($industry !== '') {
            $query->where('industry', $industry);
        }

        if ($region !== '') {
            $query->where('region', $region);
        }

        if ($businessMode !== '') {
            $query->where('business_mode', $businessMode);
        }

        if ($tag !== '') {
            $query->whereJsonContains('module_tags', $tag);
        }
    }

    private function filterOptions(): array
    {
        $published = ProductCase::query()->published();

        return [
            'industries' => (clone $published)->where('industry', '<>', '')->distinct()->orderBy('industry')->pluck('industry')->all(),
            'regions' => (clone $published)->where('region', '<>', '')->distinct()->orderBy('region')->pluck('region')->all(),
            'business_modes' => (clone $published)->where('business_mode', '<>', '')->distinct()->orderBy('business_mode')->pluck('business_mode')->all(),
            'tags' => ProductCase::query()
                ->published()
                ->pluck('module_tags')
                ->flatten()
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
```

- [ ] **Step 4: Add public routes**

Modify `routes/web.php` near the existing public shared routes, before the `site.domain` group:

```php
use App\Http\Controllers\ProductCaseController;
```

Add:

```php
Route::get('/product-cases', [ProductCaseController::class, 'index'])->name('product-cases.index');
Route::get('/product-cases/{slug}', [ProductCaseController::class, 'show'])
    ->name('product-cases.show')
    ->where('slug', '[A-Za-z0-9_-]+');
```

- [ ] **Step 5: Add public list view**

Create `resources/views/product-cases/index.blade.php`:

```blade
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle }} - {{ config('geoflow.site_name', config('app.name')) }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    <script src="{{ asset('js/lucide.min.js') }}"></script>
</head>
<body class="bg-slate-50 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <a href="{{ route('product-cases.index') }}" class="text-lg font-semibold">产品案例</a>
            <a href="{{ route('site.home') }}" class="text-sm text-slate-600 hover:text-slate-900">返回首页</a>
        </div>
    </header>

    <main>
        <section class="bg-white">
            <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
                <p class="text-sm font-semibold text-orange-600">GEO CASES</p>
                <h1 class="mt-3 text-3xl font-bold tracking-tight text-slate-950 sm:text-4xl">产品案例</h1>
                <p class="mt-4 max-w-3xl text-base leading-7 text-slate-600">查看品牌在 AI 搜索、品牌诊断和内容曝光中的真实优化案例。</p>
            </div>
        </section>

        <section class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
            <form method="GET" action="{{ route('product-cases.index') }}" class="grid gap-3 rounded-lg bg-white p-4 shadow-sm ring-1 ring-slate-200 md:grid-cols-6">
                <input name="keyword" value="{{ $filters['keyword'] }}" placeholder="搜索品牌或案例" class="rounded-md border border-slate-200 px-3 py-2 text-sm md:col-span-2">
                <select name="industry" class="rounded-md border border-slate-200 px-3 py-2 text-sm">
                    <option value="">全部行业</option>
                    @foreach($filterOptions['industries'] as $option)
                        <option value="{{ $option }}" @selected($filters['industry'] === $option)>{{ $option }}</option>
                    @endforeach
                </select>
                <select name="region" class="rounded-md border border-slate-200 px-3 py-2 text-sm">
                    <option value="">全部地区</option>
                    @foreach($filterOptions['regions'] as $option)
                        <option value="{{ $option }}" @selected($filters['region'] === $option)>{{ $option }}</option>
                    @endforeach
                </select>
                <select name="tag" class="rounded-md border border-slate-200 px-3 py-2 text-sm">
                    <option value="">全部模块</option>
                    @foreach($filterOptions['tags'] as $option)
                        <option value="{{ $option }}" @selected($filters['tag'] === $option)>{{ $option }}</option>
                    @endforeach
                </select>
                <button type="submit" class="inline-flex items-center justify-center gap-2 rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800">
                    <i data-lucide="search" class="h-4 w-4"></i>
                    筛选
                </button>
            </form>

            <div class="mt-6 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
                @forelse($cases as $case)
                    @php($metrics = $caseMetrics[$case->id] ?? [])
                    <a href="{{ route('product-cases.show', ['slug' => $case->slug]) }}" class="group flex min-h-[260px] flex-col rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200 transition hover:-translate-y-0.5 hover:shadow-md">
                        <div class="flex items-start gap-4">
                            <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-slate-100">
                                @if(trim((string) $case->logo_url) !== '')
                                    <img src="{{ $case->logo_url }}" alt="{{ $case->company_name }}" class="max-h-12 max-w-12 object-contain">
                                @else
                                    <span class="text-lg font-semibold text-slate-500">{{ mb_substr((string) $case->company_name, 0, 1) }}</span>
                                @endif
                            </div>
                            <div class="min-w-0">
                                <p class="truncate text-sm text-slate-500">{{ $case->company_name }}</p>
                                <h2 class="mt-1 line-clamp-2 text-lg font-semibold leading-6 text-slate-950 group-hover:text-orange-600">{{ $case->title }}</h2>
                            </div>
                        </div>
                        <p class="mt-4 line-clamp-3 text-sm leading-6 text-slate-600">{{ $case->summary }}</p>
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach(array_filter([$case->industry, $case->region, $case->business_mode]) as $label)
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600">{{ $label }}</span>
                            @endforeach
                        </div>
                        @if($metrics !== [])
                            <div class="mt-auto grid grid-cols-3 gap-3 pt-5 text-center">
                                @foreach($metrics as $metric)
                                    <div class="rounded-md bg-slate-50 px-2 py-3">
                                        <div class="text-base font-semibold text-slate-950">{{ $metric['value'] }}</div>
                                        <div class="mt-1 text-xs text-slate-500">{{ $metric['label'] }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </a>
                @empty
                    <div class="rounded-lg bg-white p-8 text-center text-sm text-slate-500 md:col-span-2 xl:col-span-3">暂无产品案例</div>
                @endforelse
            </div>

            <div class="mt-6">{{ $cases->links() }}</div>
        </section>
    </main>

    <script>lucide.createIcons();</script>
</body>
</html>
```

- [ ] **Step 6: Add public detail view**

Create `resources/views/product-cases/show.blade.php`:

```blade
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle }} - 产品案例</title>
    <meta name="description" content="{{ $pageDescription }}">
    <link rel="canonical" href="{{ route('product-cases.show', ['slug' => $case->slug]) }}">
    <script src="{{ asset('js/tailwindcss.play-cdn.js') }}"></script>
    <script src="{{ asset('js/lucide.min.js') }}"></script>
</head>
<body class="bg-slate-50 text-slate-900">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-4 sm:px-6 lg:px-8">
            <a href="{{ route('product-cases.index') }}" class="text-lg font-semibold">产品案例</a>
            <a href="{{ route('product-cases.index') }}" class="text-sm text-slate-600 hover:text-slate-900">返回案例列表</a>
        </div>
    </header>

    <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <section class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-slate-200">
            @if(trim((string) $case->cover_url) !== '')
                <img src="{{ $case->cover_url }}" alt="{{ $case->title }}" class="h-56 w-full object-cover">
            @endif
            <div class="p-6 lg:p-8">
                <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                    <div class="flex gap-4">
                        <div class="flex h-16 w-16 shrink-0 items-center justify-center rounded-lg bg-slate-100">
                            @if(trim((string) $case->logo_url) !== '')
                                <img src="{{ $case->logo_url }}" alt="{{ $case->company_name }}" class="max-h-14 max-w-14 object-contain">
                            @else
                                <span class="text-xl font-semibold text-slate-500">{{ mb_substr((string) $case->company_name, 0, 1) }}</span>
                            @endif
                        </div>
                        <div>
                            <p class="text-sm font-medium text-orange-600">{{ $case->company_name }}</p>
                            <h1 class="mt-2 text-3xl font-bold tracking-tight text-slate-950">{{ $case->title }}</h1>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @foreach(array_filter([$case->customer_level, $case->industry, $case->region, $case->business_mode]) as $label)
                                    <span class="rounded-full bg-slate-100 px-3 py-1 text-xs text-slate-600">{{ $label }}</span>
                                @endforeach
                            </div>
                        </div>
                    </div>
                    <button type="button" onclick="navigator.clipboard?.writeText(location.href)" class="inline-flex h-10 items-center justify-center gap-2 rounded-md border border-slate-200 bg-white px-4 text-sm font-medium text-slate-700 hover:bg-slate-50">
                        <i data-lucide="link" class="h-4 w-4"></i>
                        复制链接
                    </button>
                </div>

                <div class="mt-6 grid gap-4 md:grid-cols-3">
                    <div class="rounded-lg bg-slate-50 p-4">
                        <div class="text-2xl font-semibold">{{ number_format((int) $case->view_count) }}</div>
                        <div class="mt-1 text-sm text-slate-500">浏览量</div>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-4">
                        <div class="text-2xl font-semibold">{{ $case->started_at ? $case->started_at->diffInDays(now()) + 1 : '-' }}</div>
                        <div class="mt-1 text-sm text-slate-500">合作天数</div>
                    </div>
                    <div class="rounded-lg bg-slate-50 p-4">
                        <div class="text-2xl font-semibold">{{ (int) data_get($report, 'summary.platform_count', 0) }}</div>
                        <div class="mt-1 text-sm text-slate-500">AI 平台覆盖</div>
                    </div>
                </div>
            </div>
        </section>

        <div class="mt-8 grid gap-8 lg:grid-cols-[minmax(0,1fr)_340px]">
            <article class="rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200 lg:p-8">
                <h2 class="text-xl font-semibold text-slate-950">品牌介绍</h2>
                @if(trim((string) $case->summary) !== '')
                    <p class="mt-4 rounded-lg bg-orange-50 p-4 text-sm leading-7 text-orange-900">{{ $case->summary }}</p>
                @endif
                <div class="prose prose-slate mt-6 max-w-none">
                    {!! $contentHtml !!}
                </div>
            </article>

            <aside class="space-y-4">
                @foreach((array) data_get($report, 'summary.metrics', []) as $metric)
                    <div class="rounded-lg bg-white p-5 shadow-sm ring-1 ring-slate-200">
                        <div class="text-2xl font-semibold text-slate-950">{{ $metric['value'] }}</div>
                        <div class="mt-1 text-sm text-slate-500">{{ $metric['label'] }}</div>
                    </div>
                @endforeach
            </aside>
        </div>

        @if(!empty($report['platforms']))
            <section class="mt-8 rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-xl font-semibold text-slate-950">AI 平台表现</h2>
                <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    @foreach($report['platforms'] as $platform)
                        <div class="rounded-lg border border-slate-200 p-4">
                            <div class="font-medium text-slate-950">{{ $platform['platform'] }}</div>
                            <div class="mt-3 text-sm text-slate-600">分析数：{{ $platform['analysis_count'] }}</div>
                            <div class="mt-1 text-sm text-slate-600">正向率：{{ $platform['positive_sentiment_rate'] }}%</div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if(!empty($report['search_rows']))
            <section class="mt-8 rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200">
                <h2 class="text-xl font-semibold text-slate-950">搜索报表样例</h2>
                <div class="mt-5 overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <thead class="bg-slate-50 text-left text-xs text-slate-500">
                            <tr>
                                <th class="px-4 py-3">问题</th>
                                <th class="px-4 py-3">平台</th>
                                <th class="px-4 py-3">终端</th>
                                <th class="px-4 py-3">凭证</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($report['search_rows'] as $row)
                                <tr>
                                    <td class="px-4 py-3 text-slate-800">{{ $row['question'] }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ $row['platform'] }}</td>
                                    <td class="px-4 py-3 text-slate-600">{{ $row['terminal'] }}</td>
                                    <td class="px-4 py-3">
                                        @if(trim((string) ($row['snapshot_url'] ?? '')) !== '')
                                            <a href="{{ $row['snapshot_url'] }}" target="_blank" class="text-orange-600 hover:text-orange-700">查看快照</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </main>

    <script>lucide.createIcons();</script>
</body>
</html>
```

- [ ] **Step 7: Run public tests**

Run:

```bash
php artisan test tests/Feature/ProductCaseModuleTest.php --filter=public_case
```

Expected: PASS after Task 4 service exists; before Task 4, failures mention missing `ProductCaseReportSummaryService`.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/ProductCaseController.php resources/views/product-cases/index.blade.php resources/views/product-cases/show.blade.php routes/web.php tests/Feature/ProductCaseModuleTest.php
git commit -m "feat: add public product case pages"
```

---

## Task 3: Super Admin Case Management

**Files:**
- Create: `app/Http/Controllers/Admin/ProductCaseController.php`
- Create: `resources/views/admin/product-cases/index.blade.php`
- Create: `resources/views/admin/product-cases/create.blade.php`
- Create: `resources/views/admin/product-cases/edit.blade.php`
- Create: `resources/views/admin/product-cases/_form.blade.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/ProductCaseModuleTest.php`

- [ ] **Step 1: Add management permission and CRUD tests**

Append these tests to `tests/Feature/ProductCaseModuleTest.php`:

```php
public function test_only_super_admin_can_access_case_management(): void
{
    $super = Admin::query()->create([
        'username' => 'super_case_admin',
        'password' => 'password',
        'display_name' => '超管',
        'role' => 'super_admin',
        'status' => 'active',
    ]);
    $user = Admin::query()->create([
        'username' => 'normal_case_user',
        'password' => 'password',
        'display_name' => '普通用户',
        'role' => 'direct_admin',
        'status' => 'active',
    ]);

    $this->actingAs($super, 'admin')
        ->get(route('admin.product-cases.index'))
        ->assertOk()
        ->assertSee('产品案例管理');

    $this->actingAs($user, 'admin')
        ->get(route('admin.product-cases.index'))
        ->assertForbidden();
}

public function test_super_admin_can_create_update_hide_and_delete_case(): void
{
    $super = Admin::query()->create([
        'username' => 'super_case_crud',
        'password' => 'password',
        'display_name' => '超管',
        'role' => 'super_admin',
        'status' => 'active',
    ]);
    $owner = Admin::query()->create([
        'username' => 'case_customer',
        'password' => 'password',
        'display_name' => '客户',
        'role' => 'direct_admin',
        'status' => 'active',
    ]);
    $site = Site::query()->create([
        'owner_admin_id' => $owner->id,
        'name' => '客户站点',
        'domain' => 'customer.example.test',
        'status' => 'active',
    ]);

    $this->actingAs($super, 'admin')
        ->post(route('admin.product-cases.store'), [
            'site_id' => $site->id,
            'title' => '客户产品案例',
            'slug' => '',
            'company_name' => '客户品牌',
            'logo_url' => '/images/logo.png',
            'cover_url' => 'https://cdn.example.test/case.jpg',
            'industry' => '餐饮',
            'region' => '北京',
            'business_mode' => '直营',
            'module_tags' => '品牌诊断,AI 搜索监测',
            'summary' => '客户品牌案例摘要',
            'content' => '## 公司介绍'."\n\n".'客户品牌介绍正文',
            'customer_level' => '标杆客户',
            'started_at' => '2026-08-01',
            'sort_order' => 10,
            'status' => ProductCase::STATUS_PUBLISHED,
        ])
        ->assertRedirect(route('admin.product-cases.index'));

    $case = ProductCase::query()->where('title', '客户产品案例')->firstOrFail();
    $this->assertSame($site->id, $case->site_id);
    $this->assertSame($owner->id, $case->owner_admin_id);
    $this->assertSame(['品牌诊断', 'AI 搜索监测'], $case->module_tags);
    $this->assertNotNull($case->published_at);

    $this->actingAs($super, 'admin')
        ->put(route('admin.product-cases.update', $case), [
            'site_id' => $site->id,
            'title' => '客户产品案例更新',
            'slug' => $case->slug,
            'company_name' => '客户品牌',
            'summary' => '更新摘要',
            'content' => '更新正文',
            'status' => ProductCase::STATUS_HIDDEN,
        ])
        ->assertRedirect(route('admin.product-cases.index'));

    $case->refresh();
    $this->assertSame(ProductCase::STATUS_HIDDEN, $case->status);

    $this->actingAs($super, 'admin')
        ->delete(route('admin.product-cases.destroy', $case))
        ->assertRedirect(route('admin.product-cases.index'));

    $this->assertSoftDeleted('product_cases', ['id' => $case->id]);
}
```

- [ ] **Step 2: Run management tests and verify they fail**

Run:

```bash
php artisan test tests/Feature/ProductCaseModuleTest.php --filter=case_management
php artisan test tests/Feature/ProductCaseModuleTest.php --filter=super_admin_can_create
```

Expected: FAIL because admin routes and controller are missing.

- [ ] **Step 3: Add admin routes**

Modify `routes/web.php` imports:

```php
use App\Http\Controllers\Admin\ProductCaseController as AdminProductCaseController;
```

Inside the protected admin route group, add this `admin.super` group near other super-admin operations:

```php
Route::prefix('product-cases')->name('product-cases.')->middleware('admin.super')->group(function (): void {
    Route::get('/', [AdminProductCaseController::class, 'index'])->name('index');
    Route::get('create', [AdminProductCaseController::class, 'create'])->name('create');
    Route::post('/', [AdminProductCaseController::class, 'store'])->name('store');
    Route::get('{productCase}/edit', [AdminProductCaseController::class, 'edit'])->name('edit')->whereNumber('productCase');
    Route::put('{productCase}', [AdminProductCaseController::class, 'update'])->name('update')->whereNumber('productCase');
    Route::post('{productCase}/toggle-status', [AdminProductCaseController::class, 'toggleStatus'])->name('toggle-status')->whereNumber('productCase');
    Route::delete('{productCase}', [AdminProductCaseController::class, 'destroy'])->name('destroy')->whereNumber('productCase');
});
```

- [ ] **Step 4: Add admin controller**

Create `app/Http/Controllers/Admin/ProductCaseController.php` with validation that syncs owner from selected site:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProductCase;
use App\Models\Site;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProductCaseController extends Controller
{
    public function index(Request $request): View
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $status = trim((string) $request->query('status', ''));
        $siteId = max(0, (int) $request->query('site_id', 0));

        $cases = ProductCase::query()
            ->with(['site:id,name,owner_admin_id', 'owner:id,username,display_name'])
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($keyword)).'%';
                $query->where(function ($inner) use ($like): void {
                    $inner->whereRaw('LOWER(title) LIKE ?', [$like])
                        ->orWhereRaw('LOWER(company_name) LIKE ?', [$like]);
                });
            })
            ->when(in_array($status, $this->statuses(), true), fn ($query) => $query->where('status', $status))
            ->when($siteId > 0, fn ($query) => $query->where('site_id', $siteId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.product-cases.index', [
            'pageTitle' => '产品案例管理',
            'activeMenu' => 'product_cases_manage',
            'adminSiteName' => AdminWeb::siteName(),
            'cases' => $cases,
            'sites' => $this->sites(),
            'filters' => compact('keyword', 'status', 'siteId'),
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    public function create(): View
    {
        return view('admin.product-cases.create', [
            'pageTitle' => '新增产品案例',
            'activeMenu' => 'product_cases_manage',
            'adminSiteName' => AdminWeb::siteName(),
            'case' => new ProductCase(['status' => ProductCase::STATUS_DRAFT]),
            'sites' => $this->sites(),
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validatedPayload($request);
        $payload['created_by_admin_id'] = (int) $request->user('admin')->id;
        $payload['updated_by_admin_id'] = (int) $request->user('admin')->id;

        ProductCase::query()->create($payload);

        return redirect()->route('admin.product-cases.index')->with('message', '产品案例已创建');
    }

    public function edit(ProductCase $productCase): View
    {
        return view('admin.product-cases.edit', [
            'pageTitle' => '编辑产品案例',
            'activeMenu' => 'product_cases_manage',
            'adminSiteName' => AdminWeb::siteName(),
            'case' => $productCase,
            'sites' => $this->sites(),
            'statusLabels' => $this->statusLabels(),
        ]);
    }

    public function update(ProductCase $productCase, Request $request): RedirectResponse
    {
        $payload = $this->validatedPayload($request, $productCase);
        $payload['updated_by_admin_id'] = (int) $request->user('admin')->id;

        $productCase->update($payload);

        return redirect()->route('admin.product-cases.index')->with('message', '产品案例已更新');
    }

    public function toggleStatus(ProductCase $productCase): RedirectResponse
    {
        $nextStatus = $productCase->status === ProductCase::STATUS_PUBLISHED
            ? ProductCase::STATUS_HIDDEN
            : ProductCase::STATUS_PUBLISHED;

        $productCase->update([
            'status' => $nextStatus,
            'published_at' => $nextStatus === ProductCase::STATUS_PUBLISHED
                ? ($productCase->published_at ?? now())
                : $productCase->published_at,
            'updated_by_admin_id' => (int) auth('admin')->id(),
        ]);

        return redirect()->route('admin.product-cases.index')->with('message', '产品案例状态已更新');
    }

    public function destroy(ProductCase $productCase): RedirectResponse
    {
        $productCase->delete();

        return redirect()->route('admin.product-cases.index')->with('message', '产品案例已删除');
    }

    private function validatedPayload(Request $request, ?ProductCase $case = null): array
    {
        $payload = $request->validate([
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')],
            'title' => ['required', 'string', 'max:180'],
            'slug' => ['nullable', 'string', 'max:220', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('product_cases', 'slug')->ignore($case?->id)],
            'company_name' => ['nullable', 'string', 'max:180'],
            'logo_url' => ['nullable', 'string', 'max:500'],
            'cover_url' => ['nullable', 'string', 'max:500'],
            'industry' => ['nullable', 'string', 'max:120'],
            'region' => ['nullable', 'string', 'max:120'],
            'business_mode' => ['nullable', 'string', 'max:120'],
            'module_tags' => ['nullable', 'string', 'max:500'],
            'summary' => ['nullable', 'string', 'max:1000'],
            'content' => ['nullable', 'string'],
            'customer_level' => ['nullable', 'string', 'max:80'],
            'started_at' => ['nullable', 'date'],
            'sort_order' => ['nullable', 'integer', 'min:-999999', 'max:999999'],
            'status' => ['required', Rule::in($this->statuses())],
        ]);

        $site = null;
        $siteId = (int) ($payload['site_id'] ?? 0);
        if ($siteId > 0) {
            $site = Site::query()->find($siteId);
            if (! $site instanceof Site) {
                throw ValidationException::withMessages(['site_id' => '关联站点不存在']);
            }
        }

        $status = (string) $payload['status'];
        $publishedAt = $case?->published_at;
        if ($status === ProductCase::STATUS_PUBLISHED && $publishedAt === null) {
            $publishedAt = now();
        }

        return [
            ...Arr::only($payload, [
                'title',
                'company_name',
                'logo_url',
                'cover_url',
                'industry',
                'region',
                'business_mode',
                'summary',
                'content',
                'customer_level',
                'started_at',
                'status',
            ]),
            'slug' => trim((string) ($payload['slug'] ?? '')) !== ''
                ? trim((string) $payload['slug'])
                : ProductCase::uniqueSlug((string) $payload['title'], $case),
            'site_id' => $site?->id,
            'owner_admin_id' => $site?->owner_admin_id,
            'module_tags' => $this->tags((string) ($payload['module_tags'] ?? '')),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'published_at' => $publishedAt,
        ];
    }

    private function tags(string $value): array
    {
        return collect(preg_split('/[,，\r\n]+/u', $value) ?: [])
            ->map(fn (string $tag): string => trim($tag))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function sites()
    {
        return Site::query()
            ->with('owner:id,username,display_name')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'name', 'domain', 'owner_admin_id']);
    }

    private function statuses(): array
    {
        return array_keys($this->statusLabels());
    }

    private function statusLabels(): array
    {
        return [
            ProductCase::STATUS_DRAFT => '草稿',
            ProductCase::STATUS_PUBLISHED => '已发布',
            ProductCase::STATUS_HIDDEN => '已下架',
        ];
    }
}
```

- [ ] **Step 5: Add admin views**

Create `resources/views/admin/product-cases/index.blade.php`:

```blade
@extends('admin.layouts.app')

@section('content')
<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">产品案例管理</h1>
            <p class="mt-1 text-sm text-gray-600">维护公开展示的产品案例，并关联站点读取监测数据。</p>
        </div>
        <a href="{{ route('admin.product-cases.create') }}" class="inline-flex h-10 items-center justify-center gap-2 rounded-md bg-slate-900 px-4 text-sm font-medium text-white hover:bg-slate-800">
            <i data-lucide="plus" class="h-4 w-4"></i>
            新增案例
        </a>
    </div>

    <form method="GET" action="{{ route('admin.product-cases.index') }}" class="grid gap-3 rounded-lg bg-white p-4 shadow-sm ring-1 ring-gray-200 md:grid-cols-4">
        <input name="keyword" value="{{ $filters['keyword'] }}" placeholder="标题或品牌" class="rounded-md border border-gray-200 px-3 py-2 text-sm">
        <select name="status" class="rounded-md border border-gray-200 px-3 py-2 text-sm">
            <option value="">全部状态</option>
            @foreach($statusLabels as $value => $label)
                <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="site_id" class="rounded-md border border-gray-200 px-3 py-2 text-sm">
            <option value="0">全部站点</option>
            @foreach($sites as $site)
                <option value="{{ $site->id }}" @selected((int) $filters['siteId'] === (int) $site->id)>{{ $site->name }}</option>
            @endforeach
        </select>
        <button type="submit" class="inline-flex items-center justify-center rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white">筛选</button>
    </form>

    <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-200">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-5 py-3">案例</th>
                        <th class="px-5 py-3">关联站点</th>
                        <th class="px-5 py-3">行业/地区</th>
                        <th class="px-5 py-3">状态</th>
                        <th class="px-5 py-3">时间</th>
                        <th class="px-5 py-3 text-right">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse($cases as $case)
                        <tr>
                            <td class="px-5 py-4">
                                <div class="font-medium text-gray-900">{{ $case->title }}</div>
                                <div class="mt-1 text-xs text-gray-500">{{ $case->company_name }} / {{ $case->slug }}</div>
                            </td>
                            <td class="px-5 py-4 text-sm text-gray-600">{{ $case->site?->name ?? '-' }}</td>
                            <td class="px-5 py-4 text-sm text-gray-600">{{ trim($case->industry.' '.$case->region) ?: '-' }}</td>
                            <td class="px-5 py-4 text-sm">{{ $statusLabels[$case->status] ?? $case->status }}</td>
                            <td class="px-5 py-4 text-sm text-gray-500">{{ optional($case->created_at)->format('Y-m-d H:i') }}</td>
                            <td class="px-5 py-4 text-right text-sm">
                                <a href="{{ route('product-cases.show', ['slug' => $case->slug]) }}" target="_blank" class="mr-3 text-indigo-600 hover:text-indigo-800">查看</a>
                                <a href="{{ route('admin.product-cases.edit', $case) }}" class="mr-3 text-blue-600 hover:text-blue-800">编辑</a>
                                <form method="POST" action="{{ route('admin.product-cases.toggle-status', $case) }}" class="mr-3 inline">
                                    @csrf
                                    <button class="text-amber-600 hover:text-amber-800" type="submit">{{ $case->status === \App\Models\ProductCase::STATUS_PUBLISHED ? '下架' : '发布' }}</button>
                                </form>
                                <form method="POST" action="{{ route('admin.product-cases.destroy', $case) }}" class="inline" onsubmit="return confirm('确定删除该产品案例吗？');">
                                    @csrf
                                    @method('DELETE')
                                    <button class="text-red-600 hover:text-red-800" type="submit">删除</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-5 py-8 text-center text-sm text-gray-500">暂无产品案例</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-5 py-4">{{ $cases->links() }}</div>
    </div>
</div>
@endsection
```

Create `resources/views/admin/product-cases/create.blade.php`:

```blade
@extends('admin.layouts.app')

@section('content')
    @include('admin.product-cases._form', [
        'action' => route('admin.product-cases.store'),
        'method' => 'POST',
        'submitLabel' => '创建案例',
    ])
@endsection
```

Create `resources/views/admin/product-cases/edit.blade.php`:

```blade
@extends('admin.layouts.app')

@section('content')
    @include('admin.product-cases._form', [
        'action' => route('admin.product-cases.update', $case),
        'method' => 'PUT',
        'submitLabel' => '保存案例',
    ])
@endsection
```

Create `resources/views/admin/product-cases/_form.blade.php`:

```blade
<div class="mx-auto max-w-5xl rounded-lg bg-white p-6 shadow-sm ring-1 ring-gray-200">
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-gray-900">{{ $pageTitle }}</h1>
            <p class="mt-1 text-sm text-gray-600">手工维护案例内容，选择站点后自动关联监测中心数据。</p>
        </div>
        <a href="{{ route('admin.product-cases.index') }}" class="text-sm text-gray-600 hover:text-gray-900">返回列表</a>
    </div>

    <form method="POST" action="{{ $action }}" class="space-y-5">
        @csrf
        @if($method !== 'POST')
            @method($method)
        @endif

        <div class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm font-medium text-gray-700">
                关联站点
                <select name="site_id" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2">
                    <option value="">不关联站点</option>
                    @foreach($sites as $site)
                        <option value="{{ $site->id }}" @selected((int) old('site_id', $case->site_id) === (int) $site->id)>{{ $site->name }} / {{ $site->owner?->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block text-sm font-medium text-gray-700">
                状态
                <select name="status" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2">
                    @foreach($statusLabels as $value => $label)
                        <option value="{{ $value }}" @selected(old('status', $case->status) === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm font-medium text-gray-700">
                案例标题
                <input name="title" value="{{ old('title', $case->title) }}" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2" required>
            </label>
            <label class="block text-sm font-medium text-gray-700">
                slug
                <input name="slug" value="{{ old('slug', $case->slug) }}" placeholder="留空自动生成" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2">
            </label>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <label class="block text-sm font-medium text-gray-700">
                公司/品牌名
                <input name="company_name" value="{{ old('company_name', $case->company_name) }}" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2">
            </label>
            <label class="block text-sm font-medium text-gray-700">
                行业
                <input name="industry" value="{{ old('industry', $case->industry) }}" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2">
            </label>
            <label class="block text-sm font-medium text-gray-700">
                地区
                <input name="region" value="{{ old('region', $case->region) }}" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2">
            </label>
        </div>

        <div class="grid gap-4 md:grid-cols-2">
            <label class="block text-sm font-medium text-gray-700">
                Logo URL
                <input name="logo_url" value="{{ old('logo_url', $case->logo_url) }}" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2">
            </label>
            <label class="block text-sm font-medium text-gray-700">
                封面 URL
                <input name="cover_url" value="{{ old('cover_url', $case->cover_url) }}" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2">
            </label>
        </div>

        <div class="grid gap-4 md:grid-cols-4">
            <label class="block text-sm font-medium text-gray-700">
                业务模式
                <input name="business_mode" value="{{ old('business_mode', $case->business_mode) }}" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2">
            </label>
            <label class="block text-sm font-medium text-gray-700">
                客户等级
                <input name="customer_level" value="{{ old('customer_level', $case->customer_level) }}" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2">
            </label>
            <label class="block text-sm font-medium text-gray-700">
                开始时间
                <input type="date" name="started_at" value="{{ old('started_at', optional($case->started_at)->format('Y-m-d')) }}" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2">
            </label>
            <label class="block text-sm font-medium text-gray-700">
                排序
                <input type="number" name="sort_order" value="{{ old('sort_order', $case->sort_order ?? 0) }}" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2">
            </label>
        </div>

        <label class="block text-sm font-medium text-gray-700">
            功能标签
            <input name="module_tags" value="{{ old('module_tags', implode(',', (array) $case->module_tags)) }}" placeholder="多个标签用逗号分隔" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2">
        </label>

        <label class="block text-sm font-medium text-gray-700">
            摘要
            <textarea name="summary" rows="3" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2">{{ old('summary', $case->summary) }}</textarea>
        </label>

        <label class="block text-sm font-medium text-gray-700">
            正文
            <textarea name="content" rows="14" class="mt-1 w-full rounded-md border border-gray-200 px-3 py-2 font-mono text-sm">{{ old('content', $case->content) }}</textarea>
        </label>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.product-cases.index') }}" class="inline-flex h-10 items-center rounded-md border border-gray-200 bg-white px-4 text-sm font-medium text-gray-700">取消</a>
            <button type="submit" class="inline-flex h-10 items-center rounded-md bg-slate-900 px-4 text-sm font-medium text-white">{{ $submitLabel }}</button>
        </div>
    </form>
</div>
```

- [ ] **Step 6: Run management tests**

Run:

```bash
php artisan test tests/Feature/ProductCaseModuleTest.php --filter=case_management
php artisan test tests/Feature/ProductCaseModuleTest.php --filter=super_admin_can_create
```

Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/ProductCaseController.php resources/views/admin/product-cases routes/web.php tests/Feature/ProductCaseModuleTest.php
git commit -m "feat: add product case management"
```

---

## Task 4: Monitoring Report Summary Service

**Files:**
- Create: `app/Services/ProductCases/ProductCaseReportSummaryService.php`
- Modify: `app/Http/Controllers/ProductCaseController.php`
- Modify: `tests/Feature/ProductCaseModuleTest.php`

- [ ] **Step 1: Add report summary test**

Append this test method:

```php
public function test_case_detail_uses_monitoring_summary_for_bound_site(): void
{
    $owner = Admin::query()->create([
        'username' => 'summary_owner',
        'password' => 'password',
        'display_name' => '摘要客户',
        'role' => 'direct_admin',
        'status' => 'active',
    ]);
    $site = Site::query()->create([
        'owner_admin_id' => $owner->id,
        'name' => '摘要站点',
        'domain' => 'summary.example.test',
        'status' => 'active',
    ]);

    ProductCase::query()->create([
        'site_id' => $site->id,
        'owner_admin_id' => $owner->id,
        'title' => '监测数据案例',
        'slug' => 'monitoring-summary-case',
        'company_name' => '摘要品牌',
        'summary' => '带监测数据的案例。',
        'content' => '案例正文',
        'status' => ProductCase::STATUS_PUBLISHED,
        'published_at' => now()->subDay(),
    ]);

    $this->get(route('product-cases.show', ['slug' => 'monitoring-summary-case']))
        ->assertOk()
        ->assertSee('GEO 成效总览')
        ->assertSee('AI 平台覆盖');
}
```

- [ ] **Step 2: Run report test and verify it fails**

Run:

```bash
php artisan test tests/Feature/ProductCaseModuleTest.php --filter=monitoring_summary
```

Expected: FAIL until the service and view heading are present.

- [ ] **Step 3: Add summary service**

Create `app/Services/ProductCases/ProductCaseReportSummaryService.php`:

```php
<?php

namespace App\Services\ProductCases;

use App\Models\Admin;
use App\Models\ProductCase;
use App\Models\Site;
use App\Services\MonitoringCenter\MonitoringReportDataService;
use Throwable;

class ProductCaseReportSummaryService
{
    public function __construct(private readonly MonitoringReportDataService $reports) {}

    public function cardMetrics(ProductCase $case): array
    {
        $detail = $this->detail($case);
        $summary = (array) ($detail['summary'] ?? []);

        return array_values(array_filter([
            ['label' => 'AI 平台', 'value' => (int) ($summary['platform_count'] ?? 0)],
            ['label' => '搜索报表', 'value' => (int) ($summary['search_report_count'] ?? 0)],
            ['label' => '问题词', 'value' => (int) ($summary['distillation_word_count'] ?? 0)],
        ], static fn (array $metric): bool => (int) $metric['value'] > 0));
    }

    public function detail(ProductCase $case): array
    {
        $site = $case->site;
        $owner = $case->owner;

        if (! $site instanceof Site || ! $owner instanceof Admin) {
            return $this->empty();
        }

        try {
            $enterprise = $this->reports->enterpriseReport($owner, $site);
            $industry = $this->reports->industryReport($owner, $site);
        } catch (Throwable) {
            return $this->empty();
        }

        $summary = (array) ($enterprise['summary'] ?? []);

        return [
            'summary' => [
                'platform_count' => (int) data_get($summary, 'platform_count.display', 0),
                'search_report_count' => (int) data_get($summary, 'search_report_count.display', 0),
                'distillation_word_count' => (int) data_get($summary, 'distillation_word_count.display', 0),
                'source_count' => (int) data_get($summary, 'source_count.display', 0),
                'metrics' => $this->metrics($summary),
            ],
            'platforms' => array_slice((array) ($industry['platforms'] ?? []), 0, 8),
            'search_rows' => array_slice((array) ($enterprise['search_rows'] ?? []), 0, 10),
            'trend' => (array) ($enterprise['trend'] ?? []),
            'brand_profile' => (array) ($industry['brand_profile'] ?? []),
            'overall' => (array) ($industry['overall'] ?? []),
            'competitors' => array_slice((array) ($industry['competitors'] ?? []), 0, 8),
            'sentiment' => (array) ($industry['sentiment'] ?? []),
        ];
    }

    private function metrics(array $summary): array
    {
        $items = [
            ['label' => 'AI 平台覆盖', 'value' => (int) data_get($summary, 'platform_count.display', 0)],
            ['label' => '搜索报表数量', 'value' => (int) data_get($summary, 'search_report_count.display', 0)],
            ['label' => 'AI 搜索词数量', 'value' => (int) data_get($summary, 'distillation_word_count.display', 0)],
            ['label' => '引用来源数量', 'value' => (int) data_get($summary, 'source_count.display', 0)],
        ];

        return array_values(array_filter($items, static fn (array $item): bool => (int) $item['value'] > 0));
    }

    private function empty(): array
    {
        return [
            'summary' => [
                'platform_count' => 0,
                'search_report_count' => 0,
                'distillation_word_count' => 0,
                'source_count' => 0,
                'metrics' => [],
            ],
            'platforms' => [],
            'search_rows' => [],
            'trend' => [],
            'brand_profile' => [],
            'overall' => [],
            'competitors' => [],
            'sentiment' => [],
        ];
    }
}
```

- [ ] **Step 4: Add heading and empty state in detail view**

Modify `resources/views/product-cases/show.blade.php` to include a visible data section heading before data blocks:

```blade
<section class="mt-8 rounded-lg bg-white p-6 shadow-sm ring-1 ring-slate-200">
    <div class="flex items-center justify-between gap-4">
        <div>
            <p class="text-sm font-semibold text-orange-600">GEO DATA</p>
            <h2 class="mt-2 text-xl font-semibold text-slate-950">GEO 成效总览</h2>
        </div>
    </div>
    @if(!empty(data_get($report, 'summary.metrics', [])))
        <div class="mt-5 grid gap-4 md:grid-cols-4">
            @foreach(data_get($report, 'summary.metrics', []) as $metric)
                <div class="rounded-lg bg-slate-50 p-4">
                    <div class="text-2xl font-semibold text-slate-950">{{ $metric['value'] }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $metric['label'] }}</div>
                </div>
            @endforeach
        </div>
    @else
        <p class="mt-4 rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-500">暂无监测数据</p>
    @endif
</section>
```

Remove duplicate sidebar metric cards if the page feels repetitive.

- [ ] **Step 5: Run report test**

Run:

```bash
php artisan test tests/Feature/ProductCaseModuleTest.php --filter=monitoring_summary
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Services/ProductCases/ProductCaseReportSummaryService.php resources/views/product-cases/show.blade.php tests/Feature/ProductCaseModuleTest.php
git commit -m "feat: add product case report summary"
```

---

## Task 5: Admin Navigation Entrances

**Files:**
- Modify: `resources/views/admin/partials/header.blade.php`
- Modify: `tests/Feature/ProductCaseModuleTest.php`

- [ ] **Step 1: Add navigation tests**

Append this test method:

```php
public function test_product_case_public_nav_is_visible_to_logged_in_users_and_management_only_to_super_admin(): void
{
    $super = Admin::query()->create([
        'username' => 'super_nav_case',
        'password' => 'password',
        'display_name' => '超管',
        'role' => 'super_admin',
        'status' => 'active',
    ]);
    $user = Admin::query()->create([
        'username' => 'normal_nav_case',
        'password' => 'password',
        'display_name' => '普通用户',
        'role' => 'direct_admin',
        'status' => 'active',
    ]);

    $this->actingAs($user, 'admin')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(route('product-cases.index'), false)
        ->assertSee('产品案例')
        ->assertDontSee(route('admin.product-cases.index'), false)
        ->assertDontSee('产品案例管理');

    $this->actingAs($super, 'admin')
        ->get(route('admin.dashboard'))
        ->assertOk()
        ->assertSee(route('product-cases.index'), false)
        ->assertSee(route('admin.product-cases.index'), false)
        ->assertSee('产品案例管理');
}
```

- [ ] **Step 2: Run navigation test and verify it fails**

Run:

```bash
php artisan test tests/Feature/ProductCaseModuleTest.php --filter=public_nav
```

Expected: FAIL until header is updated.

- [ ] **Step 3: Add top navigation public link**

Modify `resources/views/admin/partials/header.blade.php` `$primaryMenu` after dashboard or before operation guide:

```php
[
    'type' => 'link',
    'key' => 'product_cases',
    'route' => 'product-cases.index',
    'name' => '产品案例',
    'visible' => true,
],
```

The existing `$menuUrl` closure already calls `route($item['route'])`, so public named routes work.

- [ ] **Step 4: Add super-admin management link to right menu**

Add to the operations section of `$accountMenuSections`:

```php
['key' => 'product_cases_manage', 'route' => 'admin.product-cases.index', 'name' => '产品案例管理', 'icon' => 'briefcase-business', 'visible' => $isSuperAdmin],
```

Also add to `$accountMenu` if the non-section account menu is still rendered on a viewport:

```php
['key' => 'product_cases_manage', 'route' => 'admin.product-cases.index', 'name' => '产品案例管理', 'icon' => 'briefcase-business', 'visible' => $isSuperAdmin],
```

Update `$subMap`:

```php
'product-cases.index' => 'product_cases',
'product-cases.show' => 'product_cases',
'admin.product-cases.index' => 'product_cases_manage',
'admin.product-cases.create' => 'product_cases_manage',
'admin.product-cases.store' => 'product_cases_manage',
'admin.product-cases.edit' => 'product_cases_manage',
'admin.product-cases.update' => 'product_cases_manage',
'admin.product-cases.toggle-status' => 'product_cases_manage',
'admin.product-cases.destroy' => 'product_cases_manage',
```

- [ ] **Step 5: Run navigation test**

Run:

```bash
php artisan test tests/Feature/ProductCaseModuleTest.php --filter=public_nav
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/views/admin/partials/header.blade.php tests/Feature/ProductCaseModuleTest.php
git commit -m "feat: add product case navigation"
```

---

## Task 6: Polish, SEO, And Full Verification

**Files:**
- Modify: `resources/views/product-cases/index.blade.php`
- Modify: `resources/views/product-cases/show.blade.php`
- Modify: `tests/Feature/ProductCaseModuleTest.php`

- [ ] **Step 1: Add SEO JSON-LD assertions**

Append this test method:

```php
public function test_product_case_pages_include_basic_seo_metadata(): void
{
    ProductCase::query()->create([
        'title' => 'SEO 产品案例',
        'slug' => 'seo-product-case',
        'company_name' => 'SEO 品牌',
        'summary' => 'SEO 描述摘要',
        'content' => 'SEO 正文',
        'status' => ProductCase::STATUS_PUBLISHED,
        'published_at' => now()->subDay(),
    ]);

    $this->get(route('product-cases.index'))
        ->assertOk()
        ->assertSee('<script type="application/ld+json">', false)
        ->assertSee('CollectionPage');

    $this->get(route('product-cases.show', ['slug' => 'seo-product-case']))
        ->assertOk()
        ->assertSee('SEO 描述摘要')
        ->assertSee('<script type="application/ld+json">', false)
        ->assertSee('Article');
}
```

- [ ] **Step 2: Run SEO test and verify it fails**

Run:

```bash
php artisan test tests/Feature/ProductCaseModuleTest.php --filter=basic_seo
```

Expected: FAIL until JSON-LD is added.

- [ ] **Step 3: Add JSON-LD to list view**

In `resources/views/product-cases/index.blade.php` before `</head>`:

```blade
@php
    $schemaAtContext = chr(64).'context';
    $schemaAtType = chr(64).'type';
    $schemaItems = [];
    foreach($cases->getCollection()->take(10) as $schemaCase) {
        $schemaItems[] = [
            $schemaAtType => 'ListItem',
            'position' => count($schemaItems) + 1,
            'url' => route('product-cases.show', ['slug' => $schemaCase->slug]),
            'name' => $schemaCase->title,
        ];
    }
@endphp
<script type="application/ld+json">
{!! json_encode([
    $schemaAtContext => 'https://schema.org',
    $schemaAtType => 'CollectionPage',
    'name' => '产品案例',
    'description' => $pageDescription,
    'mainEntity' => [
        $schemaAtType => 'ItemList',
        'itemListElement' => $schemaItems,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
```

- [ ] **Step 4: Add JSON-LD to detail view**

In `resources/views/product-cases/show.blade.php` before `</head>`:

```blade
@php
    $schemaAtContext = chr(64).'context';
    $schemaAtType = chr(64).'type';
@endphp
<script type="application/ld+json">
{!! json_encode([
    $schemaAtContext => 'https://schema.org',
    $schemaAtType => 'Article',
    'headline' => $case->title,
    'description' => $pageDescription,
    'datePublished' => optional($case->published_at)->toAtomString(),
    'dateModified' => optional($case->updated_at)->toAtomString(),
    'author' => [
        $schemaAtType => 'Organization',
        'name' => $case->company_name,
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
```

- [ ] **Step 5: Run focused and related tests**

Run:

```bash
php artisan test tests/Feature/ProductCaseModuleTest.php
php artisan test tests/Feature/AdminHeaderNavigationTest.php tests/Feature/AdminSiteManagementTest.php tests/Unit/MonitoringReportDataServiceTest.php
```

Expected: PASS.

- [ ] **Step 6: Run route list smoke check**

Run:

```bash
php artisan route:list --name=product-cases
```

Expected: output includes `product-cases.index`, `product-cases.show`, and `admin.product-cases.*`.

- [ ] **Step 7: Commit**

```bash
git add resources/views/product-cases/index.blade.php resources/views/product-cases/show.blade.php tests/Feature/ProductCaseModuleTest.php
git commit -m "feat: polish product case pages"
```

---

## Final Verification

- [ ] Run all product case tests:

```bash
php artisan test tests/Feature/ProductCaseModuleTest.php
```

Expected: PASS.

- [ ] Run related regression tests:

```bash
php artisan test tests/Feature/AdminHeaderNavigationTest.php tests/Feature/AdminSiteManagementTest.php tests/Unit/MonitoringReportDataServiceTest.php
```

Expected: PASS.

- [ ] Check migrations:

```bash
php artisan migrate:status | findstr product_cases
```

Expected: migration line for `2026_09_02_000000_create_product_cases_table` is present and migrated after running `php artisan migrate`.

- [ ] Manual browser smoke test:

```bash
php artisan serve --host=127.0.0.1 --port=18081
```

Open:

- `http://127.0.0.1:18081/product-cases`
- `http://127.0.0.1:18081/geo_admin/product-cases`

Expected:

- Public list loads without login.
- Published case detail loads.
- Draft, hidden, and deleted cases are not public.
- Super admin can access management page.
- Non-super admin receives 403 on management page.

