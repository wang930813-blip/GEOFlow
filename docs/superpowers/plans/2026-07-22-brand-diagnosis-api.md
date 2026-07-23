# 品牌诊断 API 外放实施计划

> **给执行 Agent 的要求：** 实施本计划时必须使用 `superpowers:subagent-driven-development`（推荐）或 `superpowers:executing-plans`，并按任务逐项执行。所有步骤使用复选框（`- [ ]`）跟踪状态。

**目标：** 将品牌诊断能力开放为独立 REST API。外部系统使用系统统一配置的固定 API Key 调用接口，传入品牌词和所选诊断模型后，系统创建异步诊断任务，并通过不暴露系统 ID 的任务字符串查询诊断状态和结果。

**架构：** 复用现有 `brand_diagnosis_runs`、问题、结果、来源和品牌提及表，让 API 和后台页面共用同一套诊断与计算链路。给诊断记录增加一个不透明的 API 任务 key，API 创建的任务不区分站点，`site_id` 固定为空，归属到配置的超管账号或系统内第一个启用超管账号；问题生成后自动确认并进入诊断，查询结果通过独立 Presenter 统一序列化。后台展示上，普通品牌诊断记录页排除 OpenAPI 任务，超管通过独立的 OpenAPI 诊断记录列表查看。外部鉴权不使用现有站点 Token 和 scope，改用可配置的固定开放 API Key。

**技术栈：** Laravel Controller、Form Request 校验、独立 API Key 中间件、队列任务、Eloquent 模型、现有 API 响应结构、现有 `BrandDiagnosisRunService`、`GenerateBrandDiagnosisQuestionsJob`、`ProcessBrandDiagnosisJob` 和 `BrandDiagnosisMetricsCalculator`。

---

## 文件结构

- 修改：`routes/api.php`
  - 在 `/api/v1/brand-diagnoses` 增加创建和查询路由，使用独立开放 API Key 中间件，不挂现有 `api.auth`。
- 修改：`routes/web.php`
  - 增加超管专属 OpenAPI 诊断记录列表路由。
- 修改：`bootstrap/app.php`
  - 注册 `brand-diagnosis.api-key` 路由中间件。
- 修改：`config/brand_diagnosis.php`
  - 增加开放 API 开关、固定 API Key、归属超管 ID 配置。
- 修改：`.env.example` 和 `.env.prod.example`
  - 增加品牌诊断开放 API 配置示例。
- 新建：`app/Http/Middleware/AuthenticateBrandDiagnosisOpenApi.php`
  - 校验固定开放 API Key。
- 新建：`database/migrations/2026_07_22_000000_add_api_task_key_to_brand_diagnosis_runs_table.php`
  - 增加可为空且唯一的 `api_task_key` 字段。
- 修改：`app/Models/BrandDiagnosisRun.php`
  - 将 `api_task_key` 加入 `$fillable`。
- 新建：`app/Http/Requests/Api/V1/StoreBrandDiagnosisRequest.php`
  - 校验创建诊断接口的请求体。
- 新建：`app/Http/Controllers/Api/V1/BrandDiagnosisController.php`
  - 实现创建诊断和查询诊断结果两个接口。
- 新建：`app/Services/BrandDiagnosis/BrandDiagnosisApiTaskKey.php`
  - 生成和校验不透明任务 key。
- 新建：`app/Services/BrandDiagnosis/BrandDiagnosisApiService.php`
  - 创建开放 API 诊断任务，并通过任务 key 查询任务；不按站点隔离。
- 新建：`app/Services/BrandDiagnosis/BrandDiagnosisApiResultPresenter.php`
  - 将诊断中、失败、完成三类结果序列化为 JSON。
- 新建：`app/Jobs/AutoConfirmBrandDiagnosisRunJob.php`
  - API 创建的任务在问题生成后自动确认。
- 修改：`app/Jobs/GenerateBrandDiagnosisQuestionsJob.php`
  - 当诊断记录存在 `api_task_key` 时派发自动确认任务。
- 修改：`app/Http/Controllers/Admin/BrandDiagnosisController.php`
  - 普通诊断记录页排除 OpenAPI 任务；新增超管 OpenAPI 诊断记录列表。
- 新建：`resources/views/admin/brand-diagnosis/open-api.blade.php`
  - 给超管展示 OpenAPI 创建的诊断任务、状态、品牌词、模型、任务 ID、报告入口。
- 修改：`resources/views/admin/partials/header.blade.php`
  - 给超管增加 OpenAPI 诊断记录入口，普通用户和代理不显示。
- 新建或修改测试：
  - `tests/Feature/BrandDiagnosisApiTest.php`
  - `tests/Unit/BrandDiagnosisApiTaskKeyTest.php`
  - `tests/Unit/BrandDiagnosisApiResultPresenterTest.php`
  - `tests/Feature/AdminBrandDiagnosisPageTest.php`
- 功能完成后新建：`docs/api/brand-diagnosis.md`
  - 对外 API 文档，包含请求和响应示例。

---

### 任务 1：增加不透明 API 任务 Key 存储

**文件：**
- 新建：`database/migrations/2026_07_22_000000_add_api_task_key_to_brand_diagnosis_runs_table.php`
- 修改：`app/Models/BrandDiagnosisRun.php`
- 新建：`tests/Unit/BrandDiagnosisApiTaskKeyTest.php`
- 新建：`app/Services/BrandDiagnosis/BrandDiagnosisApiTaskKey.php`

- [ ] **步骤 1：先写失败的任务 key 单元测试**

新建 `tests/Unit/BrandDiagnosisApiTaskKeyTest.php`：

```php
<?php

namespace Tests\Unit;

use App\Services\BrandDiagnosis\BrandDiagnosisApiTaskKey;
use Tests\TestCase;

class BrandDiagnosisApiTaskKeyTest extends TestCase
{
    public function test_task_key_is_opaque_prefixed_and_validatable(): void
    {
        $service = new BrandDiagnosisApiTaskKey;

        $key = $service->generate(123);

        $this->assertMatchesRegularExpression('/^bdg_[a-f0-9]{32}$/', $key);
        $this->assertTrue($service->isValid($key));
        $this->assertFalse($service->isValid('123'));
        $this->assertFalse($service->isValid('bdg_invalid'));
        $this->assertStringNotContainsString('123', $key);
    }
}
```

- [ ] **步骤 2：运行测试并确认失败**

运行：

```bash
docker compose exec app php artisan test tests/Unit/BrandDiagnosisApiTaskKeyTest.php
```

预期：失败，因为 `BrandDiagnosisApiTaskKey` 还不存在。

- [ ] **步骤 3：增加迁移文件**

新建迁移：

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brand_diagnosis_runs')) {
            return;
        }

        Schema::table('brand_diagnosis_runs', function (Blueprint $table): void {
            if (! Schema::hasColumn('brand_diagnosis_runs', 'api_task_key')) {
                $table->string('api_task_key', 40)->nullable()->unique()->after('id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('brand_diagnosis_runs') || ! Schema::hasColumn('brand_diagnosis_runs', 'api_task_key')) {
            return;
        }

        Schema::table('brand_diagnosis_runs', function (Blueprint $table): void {
            $table->dropUnique(['api_task_key']);
            $table->dropColumn('api_task_key');
        });
    }
};
```

- [ ] **步骤 4：更新模型 `$fillable`**

在 `app/Models/BrandDiagnosisRun.php` 中加入：

```php
'api_task_key',
```

到 `$fillable`。

- [ ] **步骤 5：实现任务 key 服务**

新建 `app/Services/BrandDiagnosis/BrandDiagnosisApiTaskKey.php`：

```php
<?php

namespace App\Services\BrandDiagnosis;

use Illuminate\Support\Str;

class BrandDiagnosisApiTaskKey
{
    public function generate(int $runId): string
    {
        $secret = (string) config('app.key');
        $payload = $runId.'|'.Str::ulid()->toString().'|'.Str::random(16);

        return 'bdg_'.substr(hash_hmac('sha256', $payload, $secret), 0, 32);
    }

    public function isValid(string $key): bool
    {
        return preg_match('/^bdg_[a-f0-9]{32}$/', trim($key)) === 1;
    }
}
```

- [ ] **步骤 6：运行单元测试和语法检查**

运行：

```bash
docker compose exec app php artisan test tests/Unit/BrandDiagnosisApiTaskKeyTest.php
docker compose exec app php -l app/Services/BrandDiagnosis/BrandDiagnosisApiTaskKey.php
```

预期：测试通过，且没有语法错误。

- [ ] **步骤 7：提交**

```bash
git add database/migrations/2026_07_22_000000_add_api_task_key_to_brand_diagnosis_runs_table.php app/Models/BrandDiagnosisRun.php app/Services/BrandDiagnosis/BrandDiagnosisApiTaskKey.php tests/Unit/BrandDiagnosisApiTaskKeyTest.php
git commit -m "feat: add brand diagnosis api task key"
```

---

### 任务 2：增加固定 API Key 鉴权和路由

**文件：**
- 修改：`config/brand_diagnosis.php`
- 修改：`.env.example`
- 修改：`.env.prod.example`
- 修改：`bootstrap/app.php`
- 修改：`routes/api.php`
- 新建：`app/Http/Middleware/AuthenticateBrandDiagnosisOpenApi.php`
- 新建：`app/Http/Requests/Api/V1/StoreBrandDiagnosisRequest.php`
- 新建：`app/Http/Controllers/Api/V1/BrandDiagnosisController.php`
- 新建：`tests/Feature/BrandDiagnosisApiTest.php`

- [ ] **步骤 1：先写失败的固定 API Key 和路由测试**

新建 `tests/Feature/BrandDiagnosisApiTest.php`，先写以下测试：

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class BrandDiagnosisApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('brand_diagnosis.open_api.enabled', true);
        Config::set('brand_diagnosis.open_api.api_key', 'test-open-api-key');
        Config::set('brand_diagnosis.open_api.admin_id', null);
    }

    public function test_brand_diagnosis_api_requires_configured_key(): void
    {
        $this->postJson('/api/v1/brand-diagnoses', [
            'brand_name' => '武城煊饼',
            'models' => ['doubao'],
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'invalid_api_key');
    }

    public function test_brand_diagnosis_api_accepts_configured_key(): void
    {
        $this->withHeader('X-Api-Key', 'test-open-api-key')
            ->postJson('/api/v1/brand-diagnoses', [
                'brand_name' => '武城煊饼',
                'models' => ['doubao'],
            ])
            ->assertCreated();
    }
}
```

- [ ] **步骤 2：运行测试并确认失败**

运行：

```bash
docker compose exec app php artisan test tests/Feature/BrandDiagnosisApiTest.php --filter=brand_diagnosis
```

预期：失败，因为固定 key 中间件、配置和路由还不存在。

- [ ] **步骤 3：增加开放 API 配置**

在 `config/brand_diagnosis.php` 中加入：

```php
'open_api' => [
    'enabled' => filter_var(env('BRAND_DIAGNOSIS_OPEN_API_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'api_key' => trim((string) env('BRAND_DIAGNOSIS_OPEN_API_KEY', '')),
    'admin_id' => (int) env('BRAND_DIAGNOSIS_OPEN_API_ADMIN_ID', 0) ?: null,
],
```

在 `.env.example` 和 `.env.prod.example` 中加入：

```dotenv
BRAND_DIAGNOSIS_OPEN_API_ENABLED=false
BRAND_DIAGNOSIS_OPEN_API_KEY=
BRAND_DIAGNOSIS_OPEN_API_ADMIN_ID=
```

- [ ] **步骤 4：增加固定 key 中间件**

新建 `app/Http/Middleware/AuthenticateBrandDiagnosisOpenApi.php`：

```php
<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBrandDiagnosisOpenApi
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('brand_diagnosis.open_api.enabled', false)) {
            throw new ApiException('brand_diagnosis_api_disabled', '品牌诊断开放 API 未启用', 403);
        }

        $expected = trim((string) config('brand_diagnosis.open_api.api_key', ''));
        $provided = trim((string) $request->header('X-Api-Key', ''));
        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            throw new ApiException('invalid_api_key', 'API Key 无效', 401);
        }

        return $next($request);
    }
}
```

在 `bootstrap/app.php` 中注册路由中间件别名：

```php
'brand-diagnosis.api-key' => \App\Http\Middleware\AuthenticateBrandDiagnosisOpenApi::class,
```

- [ ] **步骤 5：增加请求校验**

新建 `app/Http/Requests/Api/V1/StoreBrandDiagnosisRequest.php`：

```php
<?php

namespace App\Http\Requests\Api\V1;

use App\Services\BrandDiagnosis\BrandDiagnosisPlatform;
use Illuminate\Foundation\Http\FormRequest;

class StoreBrandDiagnosisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'brand_name' => ['required', 'string', 'max:120'],
            'models' => ['required', 'array', 'min:1', 'max:4'],
            'models.*' => ['string', BrandDiagnosisPlatform::validationRule()],
        ];
    }

    public function messages(): array
    {
        return [
            'brand_name.required' => '品牌词不能为空',
            'models.required' => '请选择至少一个诊断模型',
            'models.*.in' => '诊断模型仅支持 doubao、deepseek、qianwen、wenxin',
        ];
    }
}
```

- [ ] **步骤 6：增加控制器骨架**

新建 `app/Http/Controllers/Api/V1/BrandDiagnosisController.php`：

```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\V1\StoreBrandDiagnosisRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandDiagnosisController extends BaseApiController
{
    public function store(StoreBrandDiagnosisRequest $request): JsonResponse
    {
        return $this->success($request, [
            'message' => 'brand diagnosis create endpoint is wired',
        ], 201);
    }

    public function show(Request $request, string $taskKey): JsonResponse
    {
        return $this->success($request, [
            'task_id' => $taskKey,
        ]);
    }
}
```

- [ ] **步骤 7：注册路由**

在 `routes/api.php` 中引入：

```php
use App\Http\Controllers\Api\V1\BrandDiagnosisController;
```

在 `/api/v1` 组下加入独立路由组，不挂现有 `api.auth`，只挂固定 key 中间件和限流：

```php
Route::middleware(['throttle:machine-api', 'brand-diagnosis.api-key'])->group(function (): void {
    Route::post('brand-diagnoses', [BrandDiagnosisController::class, 'store']);
    Route::get('brand-diagnoses/{taskKey}', [BrandDiagnosisController::class, 'show'])
        ->where('taskKey', 'bdg_[a-f0-9]{32}');
});
```

- [ ] **步骤 8：运行路由和固定 key 测试**

运行：

```bash
docker compose exec app php artisan test tests/Feature/BrandDiagnosisApiTest.php --filter=brand_diagnosis
docker compose exec app php -l app/Http/Middleware/AuthenticateBrandDiagnosisOpenApi.php
docker compose exec app php -l app/Http/Controllers/Api/V1/BrandDiagnosisController.php
docker compose exec app php -l app/Http/Requests/Api/V1/StoreBrandDiagnosisRequest.php
```

预期：初始路由和固定 key 测试通过，且没有语法错误。

- [ ] **步骤 9：提交**

```bash
git add config/brand_diagnosis.php .env.example .env.prod.example bootstrap/app.php routes/api.php app/Http/Middleware/AuthenticateBrandDiagnosisOpenApi.php app/Http/Requests/Api/V1/StoreBrandDiagnosisRequest.php app/Http/Controllers/Api/V1/BrandDiagnosisController.php tests/Feature/BrandDiagnosisApiTest.php
git commit -m "feat: add brand diagnosis open api routes"
```

---

### 任务 3：实现 API 创建品牌诊断

**文件：**
- 修改：`app/Services/BrandDiagnosis/BrandDiagnosisRunService.php`
- 新建：`app/Services/BrandDiagnosis/BrandDiagnosisApiService.php`
- 新建：`app/Services/BrandDiagnosis/BrandDiagnosisApiResultPresenter.php`
- 修改：`app/Http/Controllers/Api/V1/BrandDiagnosisController.php`
- 修改：`tests/Feature/BrandDiagnosisApiTest.php`

- [ ] **步骤 1：先写失败的创建接口测试**

追加到 `BrandDiagnosisApiTest`：

```php
public function test_brand_diagnosis_api_creates_opaque_system_level_task(): void
{
    $superAdmin = \App\Models\Admin::query()->create([
        'username' => 'brand_diagnosis_open_api_super_admin',
        'password' => 'secret-123',
        'email' => 'brand-diagnosis-open-api-super@example.com',
        'display_name' => '品牌诊断开放 API 超管',
        'role' => 'super_admin',
        'status' => 'active',
    ]);
    \Illuminate\Support\Facades\Config::set('brand_diagnosis.open_api.admin_id', (int) $superAdmin->id);

    $response = $this->withHeader('X-Api-Key', 'test-open-api-key')
        ->postJson('/api/v1/brand-diagnoses', [
            'brand_name' => '武城煊饼',
            'models' => ['doubao', 'qianwen'],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.brand_name', '武城煊饼')
        ->assertJsonPath('data.models.0', 'doubao')
        ->assertJsonPath('data.models.1', 'qianwen')
        ->assertJsonPath('data.status', 'diagnosing')
        ->assertJsonStructure(['data' => ['task_id']]);

    $taskId = (string) $response->json('data.task_id');
    $this->assertMatchesRegularExpression('/^bdg_[a-f0-9]{32}$/', $taskId);
    $this->assertDatabaseHas('brand_diagnosis_runs', [
        'site_id' => null,
        'owner_admin_id' => (int) $superAdmin->id,
        'admin_id' => (int) $superAdmin->id,
        'brand_name' => '武城煊饼',
        'api_task_key' => $taskId,
        'status' => 'questions_generating',
    ]);
}
```

- [ ] **步骤 2：运行创建接口测试并确认失败**

运行：

```bash
docker compose exec app php artisan test tests/Feature/BrandDiagnosisApiTest.php --filter=brand_diagnosis_api_create
```

预期：失败，因为控制器仍然返回骨架响应。

- [ ] **步骤 3：给运行服务增加 API 创建方法**

保持 `BrandDiagnosisRunService::create()` 与现有后台页面兼容，新增一个公开方法：

```php
public function createForApi(Admin $admin, string $brandName, array $platforms, string $apiTaskKey): BrandDiagnosisRun
```

实现规则：

- 校验 `$apiTaskKey` 不能为空。
- `$brandName` 和 `$platforms` 的规范化方式与 `create()` 保持一致。
- 不要求 `CurrentSite::id()` 存在，API 诊断记录 `site_id` 固定写入 `null`。
- `owner_admin_id` 和 `admin_id` 写入开放 API 归属超管 ID。
- 创建 `questions_generating` run 的同一个事务内写入 `api_task_key`。
- 只有在 run 行已经包含最终不透明 key 后，才派发 `GenerateBrandDiagnosisQuestionsJob`。
- API 创建暂不启用问题复用；除非后续 API 契约明确增加复用参数，否则每次都重新生成问题。

同时新增 API 专用确认方法：

```php
public function confirmForApi(BrandDiagnosisRun $sourceRun): BrandDiagnosisRun
```

`confirmForApi()` 不调用 `BrandDiagnosisUsagePolicy::reserve()`，直接将问题更新为待执行并设置：

```php
'status' => 'running',
'billing_mode' => 'open_api',
'points_cost' => 0,
'limit_bypassed' => true,
'limit_bypass_reason' => 'open_api',
'usage_date' => now()->toDateString(),
'started_at' => now(),
```

- [ ] **步骤 4：创建 API 服务**

新建 `app/Services/BrandDiagnosis/BrandDiagnosisApiService.php`：

```php
<?php

namespace App\Services\BrandDiagnosis;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\BrandDiagnosisRun;

class BrandDiagnosisApiService
{
    public function __construct(
        private readonly BrandDiagnosisRunService $runs,
        private readonly BrandDiagnosisApiTaskKey $taskKeys,
    ) {}

    public function create(string $brandName, array $models): BrandDiagnosisRun
    {
        return $this->runs->createForApi($this->resolveOwnerAdmin(), $brandName, $models, $this->uniqueTaskKey());
    }

    private function uniqueTaskKey(): string
    {
        do {
            $taskKey = $this->taskKeys->generate(0);
        } while (BrandDiagnosisRun::query()->withoutGlobalScope('current_site')->where('api_task_key', $taskKey)->exists());

        return $taskKey;
    }

    private function resolveOwnerAdmin(): Admin
    {
        $configuredAdminId = (int) config('brand_diagnosis.open_api.admin_id', 0);
        $query = Admin::query()->where('status', 'active')->where('role', 'super_admin');
        $admin = $configuredAdminId > 0
            ? (clone $query)->whereKey($configuredAdminId)->first()
            : $query->orderBy('id')->first();

        if (! $admin instanceof Admin) {
            throw new ApiException('open_api_admin_not_found', '品牌诊断开放 API 未配置可用超管账号', 500);
        }

        return $admin;
    }
}
```

- [ ] **步骤 5：创建最小版 API 结果 Presenter**

新建 `app/Services/BrandDiagnosis/BrandDiagnosisApiResultPresenter.php`，先提供创建接口需要的 `summary()` 方法：

```php
<?php

namespace App\Services\BrandDiagnosis;

use App\Models\BrandDiagnosisRun;

class BrandDiagnosisApiResultPresenter
{
    public function summary(BrandDiagnosisRun $run): array
    {
        return [
            'task_id' => (string) $run->api_task_key,
            'status' => $this->publicStatus((string) $run->status),
            'raw_status' => (string) $run->status,
            'brand_name' => (string) $run->brand_name,
            'models' => array_values((array) $run->platforms),
            'created_at' => $run->created_at?->format('Y-m-d H:i:s') ?? '',
        ];
    }

    private function publicStatus(string $status): string
    {
        return match ($status) {
            'completed' => 'completed',
            'failed' => 'failed',
            default => 'diagnosing',
        };
    }
}
```

- [ ] **步骤 6：实现控制器 `store` 方法**

在 `BrandDiagnosisController::store()` 中：

```php
public function store(StoreBrandDiagnosisRequest $request, BrandDiagnosisApiService $service, BrandDiagnosisApiResultPresenter $presenter): JsonResponse
{
    $run = $service->create(
        (string) $request->validated('brand_name'),
        (array) $request->validated('models')
    );

    return $this->success($request, $presenter->summary($run), 201);
}
```

- [ ] **步骤 7：运行创建接口测试**

运行：

```bash
docker compose exec app php artisan test tests/Feature/BrandDiagnosisApiTest.php --filter=brand_diagnosis_api_create
docker compose exec app php -l app/Services/BrandDiagnosis/BrandDiagnosisApiResultPresenter.php
```

预期：测试通过。

- [ ] **步骤 8：提交**

```bash
git add app/Services/BrandDiagnosis/BrandDiagnosisRunService.php app/Services/BrandDiagnosis/BrandDiagnosisApiService.php app/Services/BrandDiagnosis/BrandDiagnosisApiResultPresenter.php app/Http/Controllers/Api/V1/BrandDiagnosisController.php tests/Feature/BrandDiagnosisApiTest.php
git commit -m "feat: create brand diagnosis via api"
```

---

### 任务 4：问题生成后自动确认 API 诊断任务

**文件：**
- 新建：`app/Jobs/AutoConfirmBrandDiagnosisRunJob.php`
- 修改：`app/Jobs/GenerateBrandDiagnosisQuestionsJob.php`
- 修改：`tests/Feature/BrandDiagnosisApiTest.php`

- [ ] **步骤 1：先写失败的自动确认测试**

追加一个使用 `Queue::fake()` 的偏集成测试。该测试只创建 `site_id = null` 的开放 API 任务，验证自动确认不会走站点配额扣减，并会派发真正的诊断任务：

```php
public function test_auto_confirm_turns_api_questions_ready_run_into_running(): void
{
    \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ProcessBrandDiagnosisJob::class]);

    $superAdmin = \App\Models\Admin::query()->create([
        'username' => 'brand_diagnosis_auto_confirm_super_admin',
        'password' => 'secret-123',
        'email' => 'brand-diagnosis-auto-confirm@example.com',
        'display_name' => '品牌诊断开放 API 超管',
        'role' => 'super_admin',
        'status' => 'active',
    ]);
    $run = \App\Models\BrandDiagnosisRun::query()->create([
        'site_id' => null,
        'owner_admin_id' => (int) $superAdmin->id,
        'admin_id' => (int) $superAdmin->id,
        'brand_name' => '武城煊饼',
        'platforms' => ['doubao'],
        'api_task_key' => 'bdg_'.str_repeat('2', 32),
        'status' => 'questions_ready',
        'total_questions' => 1,
        'billing_mode' => 'pending_confirmation',
    ]);
    $question = $run->questions()->create([
        'site_id' => null,
        'owner_admin_id' => (int) $superAdmin->id,
        'question' => '武城哪家煊饼店最好吃',
        'question_type' => '推荐选择',
        'core_term' => '武城煊饼',
        'sort_order' => 1,
        'status' => 'pending',
    ]);

    (new \App\Jobs\AutoConfirmBrandDiagnosisRunJob((int) $run->id))->handle(app(\App\Services\BrandDiagnosis\BrandDiagnosisRunService::class));

    $this->assertDatabaseHas('brand_diagnosis_runs', [
        'id' => (int) $run->id,
        'site_id' => null,
        'status' => 'running',
        'billing_mode' => 'open_api',
        'points_cost' => 0,
        'limit_bypassed' => true,
        'limit_bypass_reason' => 'open_api',
    ]);
    $this->assertDatabaseHas('brand_diagnosis_questions', [
        'id' => (int) $question->id,
        'site_id' => null,
        'status' => 'pending',
    ]);
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessBrandDiagnosisJob::class);
}
```

- [ ] **步骤 2：运行测试并确认失败**

运行：

```bash
docker compose exec app php artisan test tests/Feature/BrandDiagnosisApiTest.php --filter=auto_confirm
```

预期：失败，因为任务类还不存在。

- [ ] **步骤 3：实现自动确认 Job**

新建 `app/Jobs/AutoConfirmBrandDiagnosisRunJob.php`：

```php
<?php

namespace App\Jobs;

use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandDiagnosisRunService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class AutoConfirmBrandDiagnosisRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $runId) {}

    public function handle(BrandDiagnosisRunService $service): void
    {
        $run = BrandDiagnosisRun::query()
            ->withoutGlobalScope('current_site')
            ->with(['questions' => fn ($query) => $query->orderBy('sort_order')])
            ->whereKey($this->runId)
            ->first();

        if (! $run || trim((string) $run->api_task_key) === '') {
            return;
        }

        if (! in_array((string) $run->status, ['questions_ready', 'awaiting_confirmation'], true)) {
            return;
        }

        try {
            $service->confirmForApi($run);
        } catch (Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'error_message' => mb_strimwidth($exception->getMessage(), 0, 1000, '...', 'UTF-8'),
                'completed_at' => now(),
            ]);
        }
    }
}
```

- [ ] **步骤 4：在问题生成完成后派发自动确认任务**

在 `GenerateBrandDiagnosisQuestionsJob` 成功生成问题并把 run 更新为 `questions_ready` 后加入：

```php
if (trim((string) $run->api_task_key) !== '') {
    AutoConfirmBrandDiagnosisRunJob::dispatch((int) $run->id)->onQueue('geoflow');
}
```

引入任务类：

```php
use App\Jobs\AutoConfirmBrandDiagnosisRunJob;
```

同时修正问题创建时的站点写入，避免 API 任务的 `site_id = null` 被 `(int) null` 转成 `0`：

```php
'site_id' => $run->site_id !== null ? (int) $run->site_id : null,
```

- [ ] **步骤 5：增加 API 任务派发边界测试**

追加一个轻量测试，确保带 `api_task_key` 的开放 API 任务可以派发自动确认 Job：

```php
public function test_question_generation_dispatches_auto_confirm_only_for_api_runs(): void
{
    \Illuminate\Support\Facades\Queue::fake([\App\Jobs\AutoConfirmBrandDiagnosisRunJob::class]);

    $superAdmin = \App\Models\Admin::query()->create([
        'username' => 'brand_diagnosis_dispatch_super_admin',
        'password' => 'secret-123',
        'email' => 'brand-diagnosis-dispatch@example.com',
        'display_name' => '品牌诊断开放 API 超管',
        'role' => 'super_admin',
        'status' => 'active',
    ]);
    $apiRun = \App\Models\BrandDiagnosisRun::query()->create([
        'site_id' => null,
        'owner_admin_id' => (int) $superAdmin->id,
        'admin_id' => (int) $superAdmin->id,
        'brand_name' => '武城煊饼',
        'platforms' => ['doubao'],
        'api_task_key' => 'bdg_'.str_repeat('1', 32),
        'status' => 'questions_ready',
        'total_questions' => 1,
    ]);

    \App\Jobs\AutoConfirmBrandDiagnosisRunJob::dispatch((int) $apiRun->id);

    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\AutoConfirmBrandDiagnosisRunJob::class, 1);
}
```

- [ ] **步骤 6：运行自动确认测试**

运行：

```bash
docker compose exec app php artisan test tests/Feature/BrandDiagnosisApiTest.php --filter=auto_confirm
docker compose exec app php -l app/Jobs/AutoConfirmBrandDiagnosisRunJob.php
docker compose exec app php -l app/Jobs/GenerateBrandDiagnosisQuestionsJob.php
```

预期：测试通过，且没有语法错误。

- [ ] **步骤 7：提交**

```bash
git add app/Jobs/AutoConfirmBrandDiagnosisRunJob.php app/Jobs/GenerateBrandDiagnosisQuestionsJob.php tests/Feature/BrandDiagnosisApiTest.php
git commit -m "feat: auto confirm brand diagnosis api runs"
```

---

### 任务 5：实现结果查询和 JSON Presenter

**文件：**
- 修改：`app/Services/BrandDiagnosis/BrandDiagnosisApiResultPresenter.php`
- 修改：`app/Services/BrandDiagnosis/BrandDiagnosisApiService.php`
- 修改：`app/Http/Controllers/Api/V1/BrandDiagnosisController.php`
- 修改：`tests/Unit/BrandDiagnosisApiResultPresenterTest.php`
- 修改：`tests/Feature/BrandDiagnosisApiTest.php`

- [ ] **步骤 1：先写 Presenter 单元测试**

新建 `tests/Unit/BrandDiagnosisApiResultPresenterTest.php`：

```php
<?php

namespace Tests\Unit;

use App\Models\Admin;
use App\Models\BrandDiagnosisRun;
use App\Services\BrandDiagnosis\BrandDiagnosisApiResultPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandDiagnosisApiResultPresenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_run_payload_contains_brand_performance(): void
    {
        $admin = Admin::query()->create([
            'username' => 'brand_api_presenter_admin',
            'password' => 'secret-123',
            'email' => 'brand-api-presenter@example.com',
            'display_name' => 'Brand API Presenter Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $run = BrandDiagnosisRun::query()->create([
            'site_id' => null,
            'owner_admin_id' => (int) $admin->id,
            'admin_id' => (int) $admin->id,
            'brand_name' => '武城煊饼',
            'platforms' => ['doubao'],
            'api_task_key' => 'bdg_'.str_repeat('3', 32),
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'brand_score' => 88,
            'mention_rate' => 75,
            'average_rank' => 2.5,
            'mention_count' => 6,
            'sentiment_rate' => 100,
        ]);

        $payload = app(BrandDiagnosisApiResultPresenter::class)->detail($run);

        $this->assertSame('completed', $payload['status']);
        $this->assertSame('武城煊饼', $payload['brand_name']);
        $this->assertSame(88, $payload['brand_performance']['score']);
        $this->assertSame(75, $payload['brand_performance']['mention_rate']);
        $this->assertSame('2.5', $payload['brand_performance']['average_rank']);
        $this->assertSame(6, $payload['brand_performance']['mention_count']);
        $this->assertSame(100, $payload['brand_performance']['sentiment_rate']);
    }
}
```

- [ ] **步骤 2：运行 Presenter 测试并确认失败**

运行：

```bash
docker compose exec app php artisan test tests/Unit/BrandDiagnosisApiResultPresenterTest.php
```

预期：失败，因为完整的 Presenter 查询能力还不存在。

- [ ] **步骤 3：扩展 Presenter**

扩展 `app/Services/BrandDiagnosis/BrandDiagnosisApiResultPresenter.php`，增加以下方法：

```php
public function summary(BrandDiagnosisRun $run): array
public function detail(BrandDiagnosisRun $run): array
private function publicStatus(string $status): string
private function brandPerformance(BrandDiagnosisRun $run): array
private function questions(BrandDiagnosisRun $run): array
private function modelResults(BrandDiagnosisRun $run): array
private function sources(BrandDiagnosisRun $run): array
private function brandRankings(BrandDiagnosisRun $run): array
```

`brandPerformance()` 方法必须返回：

```php
[
    'score' => (int) $run->brand_score,
    'mention_rate' => (int) $run->mention_rate,
    'average_rank' => $this->formatAverageRank((float) $run->average_rank),
    'mention_count' => (int) $run->mention_count,
    'sentiment_rate' => (int) $run->sentiment_rate,
]
```

对外状态映射必须为：

```php
return match ($status) {
    'completed' => 'completed',
    'failed' => 'failed',
    default => 'diagnosing',
};
```

- [ ] **步骤 4：增加 API 服务查询方法**

在 `BrandDiagnosisApiService` 中加入：

```php
public function findByTaskKey(string $taskKey): BrandDiagnosisRun
{
    if (! $this->taskKeys->isValid($taskKey)) {
        throw new ApiException('diagnosis_not_found', '诊断任务不存在', 404);
    }

    $run = BrandDiagnosisRun::query()
        ->withoutGlobalScope('current_site')
        ->with([
            'questions' => fn ($query) => $query->orderBy('sort_order'),
            'questions.results.sources',
            'questions.results.brandMentions',
            'brandMentions',
            'sources',
        ])
        ->where('api_task_key', $taskKey)
        ->first();

    if (! $run instanceof BrandDiagnosisRun) {
        throw new ApiException('diagnosis_not_found', '诊断任务不存在', 404);
    }

    return $run;
}
```

- [ ] **步骤 5：实现控制器 `show` 方法**

在 `BrandDiagnosisController::show()` 中：

```php
public function show(Request $request, string $taskKey, BrandDiagnosisApiService $service, BrandDiagnosisApiResultPresenter $presenter): JsonResponse
{
    $run = $service->findByTaskKey($taskKey);

    return $this->success($request, $presenter->detail($run));
}
```

- [ ] **步骤 6：增加查询接口功能测试**

追加：

```php
public function test_brand_diagnosis_query_returns_running_state(): void
{
    $superAdmin = \App\Models\Admin::query()->create([
        'username' => 'brand_diagnosis_query_super_admin',
        'password' => 'secret-123',
        'email' => 'brand-diagnosis-query@example.com',
        'display_name' => '品牌诊断开放 API 超管',
        'role' => 'super_admin',
        'status' => 'active',
    ]);
    $taskKey = 'bdg_'.str_repeat('4', 32);
    \App\Models\BrandDiagnosisRun::query()->create([
        'site_id' => null,
        'owner_admin_id' => (int) $superAdmin->id,
        'admin_id' => (int) $superAdmin->id,
        'brand_name' => '武城煊饼',
        'platforms' => ['doubao'],
        'api_task_key' => $taskKey,
        'status' => 'running',
        'total_questions' => 6,
        'completed_questions' => 2,
        'failed_questions' => 0,
    ]);

    $this->withHeader('X-Api-Key', 'test-open-api-key')
        ->getJson('/api/v1/brand-diagnoses/'.$taskKey)
        ->assertOk()
        ->assertJsonPath('data.status', 'diagnosing')
        ->assertJsonPath('data.raw_status', 'running')
        ->assertJsonPath('data.progress.total_questions', 6)
        ->assertJsonPath('data.progress.completed_questions', 2);
}
```

- [ ] **步骤 7：运行 Presenter 和查询测试**

运行：

```bash
docker compose exec app php artisan test tests/Unit/BrandDiagnosisApiResultPresenterTest.php
docker compose exec app php artisan test tests/Feature/BrandDiagnosisApiTest.php --filter=brand_diagnosis_query
docker compose exec app php -l app/Services/BrandDiagnosis/BrandDiagnosisApiResultPresenter.php
```

预期：测试通过，且没有语法错误。

- [ ] **步骤 8：提交**

```bash
git add app/Services/BrandDiagnosis/BrandDiagnosisApiResultPresenter.php app/Services/BrandDiagnosis/BrandDiagnosisApiService.php app/Http/Controllers/Api/V1/BrandDiagnosisController.php tests/Unit/BrandDiagnosisApiResultPresenterTest.php tests/Feature/BrandDiagnosisApiTest.php
git commit -m "feat: query brand diagnosis api results"
```

---

### 任务 6：增加超管 OpenAPI 诊断记录列表

**文件：**
- 修改：`routes/web.php`
- 修改：`app/Http/Controllers/Admin/BrandDiagnosisController.php`
- 新建：`resources/views/admin/brand-diagnosis/open-api.blade.php`
- 修改：`resources/views/admin/partials/header.blade.php`
- 修改：`tests/Feature/AdminBrandDiagnosisPageTest.php`

- [ ] **步骤 1：先写失败的超管独立列表测试**

追加到 `tests/Feature/AdminBrandDiagnosisPageTest.php`：

```php
public function test_open_api_brand_diagnosis_records_are_separated_for_super_admin(): void
{
    $superAdmin = \App\Models\Admin::query()->create([
        'username' => 'brand_diagnosis_open_api_list_super_admin',
        'password' => 'secret-123',
        'email' => 'brand-diagnosis-open-api-list@example.com',
        'display_name' => '品牌诊断开放 API 超管',
        'role' => 'super_admin',
        'status' => 'active',
    ]);
    \App\Models\BrandDiagnosisRun::query()->create([
        'site_id' => null,
        'owner_admin_id' => (int) $superAdmin->id,
        'admin_id' => (int) $superAdmin->id,
        'brand_name' => '开放 API 诊断品牌',
        'platforms' => ['doubao', 'qianwen'],
        'api_task_key' => 'bdg_'.str_repeat('5', 32),
        'status' => 'completed',
        'total_questions' => 6,
        'completed_questions' => 6,
        'failed_questions' => 0,
        'brand_score' => 82,
        'mention_rate' => 67,
        'average_rank' => 3.4,
        'mention_count' => 8,
        'sentiment_rate' => 90,
    ]);

    $this->actingAs($superAdmin, 'admin')
        ->get(route('admin.brand-diagnosis.index'))
        ->assertOk()
        ->assertDontSee('开放 API 诊断品牌');

    $this->actingAs($superAdmin, 'admin')
        ->get(route('admin.brand-diagnosis.open-api.index'))
        ->assertOk()
        ->assertSee('OpenAPI 诊断记录')
        ->assertSee('开放 API 诊断品牌')
        ->assertSee('bdg_'.str_repeat('5', 32));
}

public function test_non_super_admin_cannot_open_open_api_brand_diagnosis_records(): void
{
    [$admin] = $this->createAdminWithSite('brand_diagnosis_open_api_denied_admin');

    $this->actingAs($admin, 'admin')
        ->get(route('admin.brand-diagnosis.open-api.index'))
        ->assertForbidden();
}
```

- [ ] **步骤 2：运行测试并确认失败**

运行：

```bash
docker compose exec app php artisan test tests/Feature/AdminBrandDiagnosisPageTest.php --filter=open_api_brand_diagnosis
```

预期：失败，因为路由、控制器方法和视图还不存在。

- [ ] **步骤 3：增加超管列表路由**

在 `routes/web.php` 的后台品牌诊断路由组中，放在 `brand-diagnosis/{run}/report` 等参数路由之前：

```php
Route::get('brand-diagnosis/open-api', [BrandDiagnosisController::class, 'openApiIndex'])
    ->name('brand-diagnosis.open-api.index');
```

- [ ] **步骤 4：扩展后台控制器查询**

在 `app/Http/Controllers/Admin/BrandDiagnosisController.php` 中新增超管列表方法：

```php
public function openApiIndex(Request $request): View
{
    $admin = auth('admin')->user();
    if (! $admin instanceof Admin || ! $admin->isSuperAdmin()) {
        abort(403);
    }

    $filters = $this->diagnosisRecordFilters($request);
    $paginator = $this->openApiDiagnosisRecords($filters);

    return view('admin.brand-diagnosis.open-api', [
        'pageTitle' => 'OpenAPI 诊断记录',
        'activeMenu' => 'brand_diagnosis_open_api',
        'adminSiteName' => AdminWeb::siteName(),
        'records' => $paginator->getCollection()->all(),
        'recordPaginator' => $paginator,
        'recordFilters' => $filters,
    ]);
}
```

新增 OpenAPI 专用分页查询：

```php
private function openApiDiagnosisRecords(array $filters): LengthAwarePaginator
{
    $paginator = $this->openApiDiagnosisRunQuery()
        ->when($filters['brand'] !== '', fn (Builder $query): Builder => $query->where('brand_name', 'like', '%'.$filters['brand'].'%'))
        ->when($filters['start_date'] !== '', fn (Builder $query): Builder => $query->whereDate('created_at', '>=', $filters['start_date']))
        ->when($filters['end_date'] !== '', fn (Builder $query): Builder => $query->whereDate('created_at', '<=', $filters['end_date']))
        ->orderByDesc('created_at')
        ->paginate(10)
        ->withQueryString();

    $paginator->setCollection(
        $paginator->getCollection()
            ->values()
            ->map(fn (BrandDiagnosisRun $run): array => $this->formatRecord($run, false))
    );

    return $paginator;
}
```

新增 OpenAPI 专用基础查询：

```php
private function openApiDiagnosisRunQuery(): Builder
{
    return BrandDiagnosisRun::query()
        ->withoutGlobalScope('current_site')
        ->whereNotNull('api_task_key')
        ->where('api_task_key', '<>', '')
        ->with([
            'questions' => fn ($query) => $query->orderBy('sort_order')->with(['results.sources', 'results.brandMentions']),
            'sources',
            'brandMentions',
        ]);
}
```

同时修改普通 `diagnosisRunQuery()`，让普通品牌诊断页面不混入 OpenAPI 记录：

```php
->where(function (Builder $query): void {
    $query->whereNull('api_task_key')
        ->orWhere('api_task_key', '');
})
```

该条件放在 `with([...])` 之前，保留现有超管 `withoutGlobalScope('current_site')` 和普通用户站点过滤逻辑。

- [ ] **步骤 5：新增 OpenAPI 列表视图**

新建 `resources/views/admin/brand-diagnosis/open-api.blade.php`：

```blade
@extends('layouts.admin')

@section('title', $pageTitle ?? 'OpenAPI 诊断记录')

@section('content')
@php
    $filters = $recordFilters ?? ['brand' => '', 'start_date' => '', 'end_date' => ''];
@endphp
<div class="space-y-6">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-slate-900">OpenAPI 诊断记录</h1>
            <p class="mt-1 text-sm text-slate-500">仅展示通过开放 API 创建的品牌诊断任务，不混入后台手动诊断记录。</p>
        </div>
        <a href="{{ route('admin.brand-diagnosis.index') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">返回品牌诊断</a>
    </div>

    <form method="GET" action="{{ route('admin.brand-diagnosis.open-api.index') }}" class="grid gap-3 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[1fr_160px_160px_auto_auto]">
        <input type="search" name="brand" value="{{ $filters['brand'] ?? '' }}" placeholder="搜索品牌词" class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-100">
        <input type="date" name="start_date" value="{{ $filters['start_date'] ?? '' }}" class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-100">
        <input type="date" name="end_date" value="{{ $filters['end_date'] ?? '' }}" class="h-10 rounded-md border border-slate-200 px-3 text-sm outline-none focus:border-orange-400 focus:ring-2 focus:ring-orange-100">
        <button type="submit" class="inline-flex h-10 items-center justify-center rounded-md bg-orange-600 px-4 text-sm font-semibold text-white hover:bg-orange-700">筛选</button>
        <a href="{{ route('admin.brand-diagnosis.open-api.index') }}" class="inline-flex h-10 items-center justify-center rounded-md border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-600 hover:bg-slate-50">重置</a>
    </form>

    <section class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-4 py-3">品牌词</th>
                        <th class="px-4 py-3">任务 ID</th>
                        <th class="px-4 py-3">模型</th>
                        <th class="px-4 py-3">状态</th>
                        <th class="px-4 py-3">品牌表现</th>
                        <th class="px-4 py-3">创建时间</th>
                        <th class="px-4 py-3 text-right">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($records as $record)
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-semibold text-slate-900">{{ $record['brand'] }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-slate-500">{{ $record['api_task_key'] ?? '' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ collect($record['platform_options'] ?? [])->pluck('name')->filter()->implode('、') }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $record['status_label'] ?? $record['status'] }}</td>
                            <td class="px-4 py-3 text-slate-600">评分 {{ $record['metrics']['score'] ?? 0 }} / 提及率 {{ $record['metrics']['mention_rate'] ?? 0 }}%</td>
                            <td class="px-4 py-3 text-slate-500">{{ $record['created_at'] }}</td>
                            <td class="px-4 py-3 text-right">
                                @if (! empty($record['has_report']))
                                    <a href="{{ route('admin.brand-diagnosis.report', ['run' => $record['id']]) }}" class="font-semibold text-orange-700 hover:text-orange-800">查看报告</a>
                                @else
                                    <span class="text-slate-400">诊断中</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-sm text-slate-500">暂无 OpenAPI 诊断记录</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($recordPaginator && $recordPaginator->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">
                {{ $recordPaginator->links() }}
            </div>
        @endif
    </section>
</div>
@endsection
```

如果 `formatRecord()` 当前没有返回 `api_task_key`，在该方法返回数组中补充：

```php
'api_task_key' => (string) ($run->api_task_key ?? ''),
```

- [ ] **步骤 6：给超管增加菜单入口**

在 `resources/views/admin/partials/header.blade.php` 的“全域数析”分组里，在品牌诊断后加入：

```php
['key' => 'brand_diagnosis_open_api', 'route' => 'admin.brand-diagnosis.open-api.index', 'name' => 'OpenAPI 诊断记录', 'visible' => $isSuperAdmin],
```

在 `$subMap` 中加入：

```php
'admin.brand-diagnosis.open-api.index' => 'brand_diagnosis_open_api',
```

- [ ] **步骤 7：运行超管 OpenAPI 列表测试和语法检查**

运行：

```bash
docker compose exec app php artisan test tests/Feature/AdminBrandDiagnosisPageTest.php --filter=open_api_brand_diagnosis
docker compose exec app php -l app/Http/Controllers/Admin/BrandDiagnosisController.php
docker compose exec app php artisan route:list --path=geo_admin/brand-diagnosis/open-api
```

预期：测试通过；路由列表能看到 `admin.brand-diagnosis.open-api.index`；普通诊断页不展示 OpenAPI 记录。

- [ ] **步骤 8：提交**

```bash
git add routes/web.php app/Http/Controllers/Admin/BrandDiagnosisController.php resources/views/admin/brand-diagnosis/open-api.blade.php resources/views/admin/partials/header.blade.php tests/Feature/AdminBrandDiagnosisPageTest.php
git commit -m "feat: add super admin open api diagnosis list"
```

---

### 任务 7：端到端验证和回归覆盖

**文件：**
- 只有在验证发现真实缺口时才修改测试。

- [ ] **步骤 1：运行 API 聚焦测试**

运行：

```bash
docker compose exec app php artisan test tests/Feature/BrandDiagnosisApiTest.php
docker compose exec app php artisan test tests/Unit/BrandDiagnosisApiTaskKeyTest.php
docker compose exec app php artisan test tests/Unit/BrandDiagnosisApiResultPresenterTest.php
docker compose exec app php artisan test tests/Feature/AdminBrandDiagnosisPageTest.php --filter=open_api_brand_diagnosis
```

预期：全部测试通过。

- [ ] **步骤 2：运行现有品牌诊断回归测试**

运行：

```bash
docker compose exec app php artisan test tests/Feature/AdminBrandDiagnosisPageTest.php
docker compose exec app php artisan test tests/Feature/BrandDiagnosisDoubaoFlowTest.php --filter=brand_profile
docker compose exec app php artisan test tests/Unit/BrandDiagnosisQuestionLabelerTest.php
```

预期：全部测试通过。

- [ ] **步骤 3：运行语法检查**

运行：

```bash
docker compose exec app php -l routes/api.php
docker compose exec app php -l app/Http/Middleware/AuthenticateBrandDiagnosisOpenApi.php
docker compose exec app php -l app/Services/BrandDiagnosis/BrandDiagnosisApiService.php
docker compose exec app php -l app/Services/BrandDiagnosis/BrandDiagnosisRunService.php
docker compose exec app php -l app/Jobs/GenerateBrandDiagnosisQuestionsJob.php
docker compose exec app php -l app/Jobs/AutoConfirmBrandDiagnosisRunJob.php
docker compose exec app php -l app/Http/Controllers/Api/V1/BrandDiagnosisController.php
docker compose exec app php -l app/Http/Controllers/Admin/BrandDiagnosisController.php
```

预期：没有语法错误。

- [ ] **步骤 4：运行 diff 检查**

运行：

```bash
git diff --check
```

预期：没有空白字符错误。

- [ ] **步骤 5：必要时提交验证修复**

如果验证发现真实缺陷，先补失败测试，再修复并提交：

```bash
git add <changed-files>
git commit -m "fix: stabilize brand diagnosis api"
```

---

### 任务 8：功能完成后编写对外 API 文档

**文件：**
- 新建：`docs/api/brand-diagnosis.md`
- 仅当创建目录后仓库中已有 `docs/api/README.md` 时，才修改该 README。

- [ ] **步骤 1：创建 API 文档目录**

如果 `docs/api/` 不存在，则创建该目录。

- [ ] **步骤 2：编写 `docs/api/brand-diagnosis.md`**

文档必须包含：

````markdown
# 品牌诊断 API

## 鉴权

所有接口使用系统统一配置的固定 API Key，不使用用户登录态、站点 Token 或 scope。

请求头：

```http
X-Api-Key: <configured-open-api-key>
Content-Type: application/json
```

开放 API 不区分站点。API 创建的诊断记录 `site_id` 固定为 `null`，归属到 `BRAND_DIAGNOSIS_OPEN_API_ADMIN_ID` 配置的超管账号；未配置时归属到系统内第一个启用的超管账号。

## 创建品牌诊断

`POST /api/v1/brand-diagnoses`

请求体：

```json
{
  "brand_name": "武城煊饼",
  "models": ["doubao", "qianwen"]
}
```

支持模型：

| 值 | 模型 |
| --- | --- |
| `doubao` | 豆包 |
| `deepseek` | DeepSeek |
| `qianwen` | 千问 |
| `wenxin` | 文心一言 |

成功响应：

```json
{
  "success": true,
  "data": {
    "task_id": "bdg_7f3c9d8a4e2b4c1f9a0d6e8b12345678",
    "status": "diagnosing",
    "raw_status": "questions_generating",
    "brand_name": "武城煊饼",
    "models": ["doubao", "qianwen"],
    "created_at": "2026-07-22 15:30:00"
  },
  "error": null,
  "meta": {
    "request_id": "request-id",
    "timestamp": "2026-07-22T15:30:00+08:00"
  }
}
```

## 查询诊断结果

`GET /api/v1/brand-diagnoses/{task_id}`

诊断中响应：

```json
{
  "success": true,
  "data": {
    "task_id": "bdg_7f3c9d8a4e2b4c1f9a0d6e8b12345678",
    "status": "diagnosing",
    "raw_status": "running",
    "progress": {
      "total_questions": 6,
      "completed_questions": 3,
      "failed_questions": 0
    }
  },
  "error": null,
  "meta": {
    "request_id": "request-id",
    "timestamp": "2026-07-22T15:31:00+08:00"
  }
}
```

成功响应：

```json
{
  "success": true,
  "data": {
    "task_id": "bdg_7f3c9d8a4e2b4c1f9a0d6e8b12345678",
    "status": "completed",
    "raw_status": "completed",
    "brand_name": "武城煊饼",
    "models": ["doubao", "qianwen"],
    "brand_performance": {
      "score": 86,
      "mention_rate": 75,
      "average_rank": "3.2",
      "mention_count": 12,
      "sentiment_rate": 92
    },
    "questions": [],
    "model_results": [],
    "sources": [],
    "rankings": {
      "mention_rate": [],
      "mention_count": [],
      "average_rank": []
    }
  },
  "error": null,
  "meta": {
    "request_id": "request-id",
    "timestamp": "2026-07-22T15:35:00+08:00"
  }
}
```

失败响应：

```json
{
  "success": true,
  "data": {
    "task_id": "bdg_7f3c9d8a4e2b4c1f9a0d6e8b12345678",
    "status": "failed",
    "raw_status": "failed",
    "error_message": "未检索到可用品牌介绍，请更换更明确的品牌词后重试。"
  },
  "error": null,
  "meta": {
    "request_id": "request-id",
    "timestamp": "2026-07-22T15:32:00+08:00"
  }
}
```

## 状态说明

| status | 说明 |
| --- | --- |
| `diagnosing` | 生成问题或模型诊断中 |
| `completed` | 诊断完成 |
| `failed` | 诊断失败 |

## 常见错误

| HTTP | code | 说明 |
| --- | --- | --- |
| 401 | `invalid_api_key` | `X-Api-Key` 缺失或与配置不一致 |
| 403 | `brand_diagnosis_api_disabled` | 开放 API 未启用 |
| 404 | `diagnosis_not_found` | 任务不存在或任务 ID 格式不正确 |
| 422 | `validation_failed` | 请求参数不合法 |
| 500 | `open_api_admin_not_found` | 未配置可用超管账号，且系统内没有启用的超管账号 |
````

- [ ] **步骤 3：运行 Markdown 和路由检查**

运行：

```bash
docker compose exec app php artisan route:list --path=api/v1/brand-diagnoses
git diff --check docs/api/brand-diagnosis.md
```

预期：路由列表能看到两个品牌诊断 API 路由，diff 检查没有空白字符错误。

- [ ] **步骤 4：提交 API 文档**

```bash
git add docs/api/brand-diagnosis.md
git commit -m "docs: add brand diagnosis api documentation"
```

---

## 最终验证清单

- [ ] `POST /api/v1/brand-diagnoses` 需要配置正确的 `X-Api-Key`。
- [ ] `GET /api/v1/brand-diagnoses/{task_id}` 需要配置正确的 `X-Api-Key`。
- [ ] API 任务 ID 使用 `bdg_` 开头的不透明字符串，不暴露数字 run ID。
- [ ] API 创建的诊断任务不区分站点，`site_id` 固定为 `null`。
- [ ] API 创建的诊断任务归属到配置的超管账号或系统内第一个启用超管账号。
- [ ] 普通品牌诊断记录页不展示 OpenAPI 诊断记录。
- [ ] 超管可以在 `admin.brand-diagnosis.open-api.index` 单独查看 OpenAPI 诊断记录；普通用户和代理不能访问。
- [ ] API 创建的诊断任务在问题生成后会自动确认。
- [ ] 查询接口返回 `diagnosing`、`completed` 或 `failed`。
- [ ] 完成态响应包含 `brand_performance`，且指标来自现有品牌诊断计算后落库的数据。
- [ ] 已软删除的诊断记录不会被查询接口返回。
- [ ] API 文档位于 `docs/api/brand-diagnosis.md`。
