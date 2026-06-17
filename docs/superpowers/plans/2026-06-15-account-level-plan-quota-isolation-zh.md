# 账号级规格额度与资源隔离实施计划

> **给后续执行者：** 按任务逐步执行。每个任务都要先补测试，再改实现，再跑测试。不要一次性把所有业务入口同时改掉。

**目标：** 将当前“分站点共享规格、共享额度、共享积分、共享资源”的逻辑，升级为“账号级独立规格、独立额度、独立积分、资源按账号隔离”。

**核心规则：**

- 超管创建代理，并给代理分配规格。
- 代理创建普通用户时，普通用户继承代理规格，但每个普通用户独立计数。
- 例如规格包含 `1000` 积分，代理下面每个普通用户都各自拥有 `1000` 积分，不共享。
- 直客不能创建普通用户。
- 代理管理员可以管理和查看自己站点下用户的数据。
- 普通用户只能看到和使用自己的数据。
- 超管仍然可以查看全局数据、切换分站点。
- 媒体发布只消耗积分，不新增“媒体发布次数”限制。
- 规格资源新增：`生成视频次数`、`CreBee 发布次数`。

---

## 一、现状判断

当前系统是站点级逻辑：

- `ResourceQuotaService` 按 `site_id` 找有效规格。
- `PlanSubscriptionService` 写入 `site_plan_subscriptions`。
- `SiteCreditService` 写入 `site_credit_accounts` 和 `site_credit_ledger`。
- `BelongsToSite` 通过 `CurrentSite` 按 `site_id` 做数据隔离。
- 代理创建普通用户时，只是把用户挂到 `site_members`，没有给用户单独开通规格和积分账户。

这会导致同一个代理站点下的多个普通用户共用资源库、共用额度、共用积分，不符合新的业务要求。

---

## 二、总体设计

保留现有站点级表作为兼容层，不删除：

- `site_plan_subscriptions`
- `site_resource_usages`
- `site_resource_ledger`
- `site_credit_accounts`
- `site_credit_ledger`

新增账号级表作为新业务主路径：

- `admin_plan_subscriptions`：账号级规格订阅
- `admin_resource_usages`：账号级额度用量
- `admin_resource_ledger`：账号级额度流水
- `admin_credit_accounts`：账号级积分账户
- `admin_credit_ledger`：账号级积分流水

业务数据增加 `owner_admin_id`：

- 同一个 `site_id` 下，普通用户之间通过 `owner_admin_id` 隔离数据。
- 代理管理员和超管绕过普通用户隔离，可以看更大的范围。
- 普通用户只看 `owner_admin_id = 当前登录用户 id` 的数据。

---

## 三、需要新增的文件

### 迁移

- `database/migrations/2026_06_15_000000_create_admin_plan_subscription_tables.php`
- `database/migrations/2026_06_15_000100_add_owner_admin_id_to_business_resources.php`
- `database/migrations/2026_06_15_000200_backfill_admin_account_ownership.php`

### 模型

- `app/Models/AdminPlanSubscription.php`
- `app/Models/AdminResourceUsage.php`
- `app/Models/AdminResourceLedger.php`
- `app/Models/AdminCreditAccount.php`
- `app/Models/AdminCreditLedger.php`
- `app/Models/Concerns/BelongsToAdminOwner.php`

### 服务

- `app/Services/Billing/AdminPlanSubscriptionService.php`
- `app/Services/Billing/AdminResourceQuotaService.php`
- `app/Services/MediaDistribution/AdminCreditService.php`

### 测试

- `tests/Feature/AdminAccountPlanSubscriptionTest.php`
- `tests/Feature/AdminAccountResourceIsolationTest.php`

---

## 四、需要修改的核心文件

### 规格与用户

- `app/Models/Admin.php`
  - 增加账号规格、账号积分关系。
- `app/Models/PlatformPlan.php`
  - 增加 `video_generations` 和 `crebee_publishes`。
- `app/Http/Controllers/Admin/AdminUserController.php`
  - 超管创建代理/直客并同步开通规格时，同时创建账号级规格。
- `app/Http/Controllers/Admin/AgentUserController.php`
  - 代理创建普通用户时，给普通用户继承一份独立规格和独立积分。

### 额度与积分

- `app/Services/Billing/PlanSubscriptionService.php`
  - 保留站点级能力，复用规格快照生成逻辑。
- `app/Services/Billing/ResourceQuotaService.php`
  - 暂时保留站点级兼容，不作为新逻辑主入口。
- `app/Services/MediaDistribution/SiteCreditService.php`
  - 暂时保留站点历史积分能力。
- `app/Services/MediaDistribution/MediaSubmissionService.php`
  - 改为使用账号级积分。

### 业务扣减入口

- `app/Services/BrandDiagnosis/BrandDiagnosisUsagePolicy.php`
  - 品牌诊断次数改为账号级扣减。
- `app/Services/GeoFlow/WorkerExecutionService.php`
  - 文章生成次数改为账号级扣减。
- `app/Services/GeoFlow/AiGeneratedArticleImageService.php`
  - AI 配图次数改为账号级扣减。
- `app/Http/Controllers/Admin/TitleLibraryController.php`
  - AI 标题生成次数改为账号级扣减。
- `app/Http/Controllers/Admin/UrlImportController.php`
  - URL 采集次数改为账号级扣减。
- `app/Http/Controllers/Admin/KeywordLibraryController.php`
  - 关键词问题生成、GEO 收录检测改为账号级扣减。
- `app/Http/Controllers/Admin/ApiTokenController.php`
  - API Token 数量后续按账号级限制。

---

## 五、账号级表结构设计

### 1. `admin_plan_subscriptions`

用途：记录每个后台账号自己的有效规格。

关键字段：

- `admin_id`
- `site_id`
- `plan_id`
- `source_subscription_id`
- `inherited_from_admin_id`
- `mode`
- `status`
- `starts_at`
- `ends_at`
- `entitlements_snapshot`
- `remark`

`mode` 建议值：

- `agent_owner`：代理管理员本人的规格
- `agent_user`：代理创建的普通用户继承规格
- `direct_owner`：直客本人的规格
- `internal`：历史或内部兼容账号

### 2. `admin_resource_usages`

用途：快速判断某账号某资源已用多少。

关键字段：

- `admin_id`
- `site_id`
- `subscription_id`
- `resource_key`
- `period_key`
- `used_amount`
- `reserved_amount`
- `last_used_at`

第一版 `period_key` 统一用服务周期：

```text
subscription:{subscription_id}
```

### 3. `admin_resource_ledger`

用途：记录额度消耗流水，便于审计和排查。

关键字段：

- `admin_id`
- `site_id`
- `subscription_id`
- `resource_key`
- `type`
- `amount`
- `balance_after`
- `actor_admin_id`
- `subject_type`
- `subject_id`
- `idempotency_key`
- `remark`
- `created_at`

### 4. `admin_credit_accounts`

用途：账号级积分余额。

关键字段：

- `admin_id`
- `site_id`
- `balance`
- `frozen_balance`
- `total_granted`
- `total_consumed`

唯一约束：

```text
admin_id + site_id
```

### 5. `admin_credit_ledger`

用途：账号级积分流水。

关键字段：

- `admin_id`
- `site_id`
- `submission_id`
- `type`
- `amount`
- `balance_after`
- `frozen_after`
- `operator_admin_id`
- `remark`
- `created_at`

---

## 六、业务流程

### 1. 超管创建代理

流程：

1. 创建 `agent_admin`。
2. 创建分站点 `sites`。
3. 写入 `site_members`，角色为 `owner`。
4. 继续创建兼容用的 `site_plan_subscriptions`。
5. 创建 `admin_plan_subscriptions`，`mode = agent_owner`。
6. 如果规格包含积分，给代理账号创建 `admin_credit_accounts` 并发放积分。

注意：

- 站点级订阅保留，用于兼容已有页面和历史逻辑。
- 新业务扣减以账号级订阅为准。

### 2. 代理创建普通用户

流程：

1. 创建 `site_user`。
2. 写入 `site_members`，角色为 `member`。
3. 查找代理管理员当前有效的 `admin_plan_subscriptions`。
4. 复制规格快照给普通用户。
5. 创建普通用户自己的 `admin_plan_subscriptions`，`mode = agent_user`。
6. 如果规格包含积分，给普通用户创建自己的 `admin_credit_accounts` 并发放积分。

关键规则：

- 每个普通用户都是独立计数。
- 普通用户 A 消耗品牌诊断次数，不影响普通用户 B。
- 普通用户 A 消耗积分，不影响普通用户 B。

### 3. 超管创建直客

流程：

1. 创建 `direct_admin`。
2. 创建分站点。
3. 写入 `site_members`，角色为 `owner`。
4. 创建兼容用的 `site_plan_subscriptions`。
5. 创建 `admin_plan_subscriptions`，`mode = direct_owner`。
6. 如果规格包含积分，给直客账号创建账号积分账户。

限制：

- 直客不能创建普通用户。
- 直客使用自己的账号级额度和账号级积分。

---

## 七、资源隔离方案

业务表新增 `owner_admin_id`。

第一批优先隔离：

- `knowledge_bases`
- `knowledge_chunks`
- `image_libraries`
- `images`
- `articles`
- `media_submissions`
- `brand_diagnosis_runs`
- `brand_diagnosis_questions`
- `brand_diagnosis_results`
- `brand_diagnosis_sources`
- `brand_diagnosis_brand_mentions`

第二批继续隔离：

- `categories`
- `authors`
- `tasks`
- `task_runs`
- `task_schedules`
- `title_libraries`
- `titles`
- `keyword_libraries`
- `keywords`
- `keyword_question_variants`
- `url_import_jobs`
- `url_import_job_logs`
- `geo_inclusion_check_runs`
- `geo_inclusion_check_results`

不要第一版隔离：

- `site_settings`
- 站点基础配置
- 站点域名配置

原因：这些属于站点公共配置，不属于普通用户私有资源。

---

## 八、`BelongsToAdminOwner` 规则

新增模型 trait：

```php
App\Models\Concerns\BelongsToAdminOwner
```

查询规则：

- 未登录后台账号：不处理。
- 超管：不加 `owner_admin_id` 限制。
- 代理管理员：不加 `owner_admin_id` 限制，可以查看本代理站点用户数据。
- 直客：只看自己的数据。
- 普通用户：只看自己的数据。

创建规则：

- 创建业务数据时，如果没有显式传入 `owner_admin_id`，自动写入当前登录后台账号 ID。

---

## 九、服务设计

### 1. `AdminPlanSubscriptionService`

负责账号级规格开通和继承。

核心方法：

- `openOwner(...)`
  - 给代理/直客账号开通自己的规格。
- `inheritForAgentUser(...)`
  - 代理创建普通用户时，复制代理规格快照给普通用户。
- `activeSubscriptionForAdmin(int $adminId, int $siteId)`
  - 查找账号当前有效规格。
- `backfillFromSiteSubscription(...)`
  - 历史站点规格迁移为账号规格。

### 2. `AdminResourceQuotaService`

负责账号级资源额度校验和扣减。

核心方法：

- `assertSubscriptionActive(int $adminId, int $siteId, ?Admin $admin = null)`
- `assertCanUse(int $adminId, int $siteId, string $resourceKey, int $amount = 1, ?Admin $admin = null)`
- `consume(int $adminId, int $siteId, string $resourceKey, int $amount = 1, array $context = [])`
- `remaining(int $adminId, int $siteId, string $resourceKey)`

幂等规则：

- 关键扣减必须传 `idempotency_key`。
- 同一个 `idempotency_key` 重复执行，不重复扣减。

### 3. `AdminCreditService`

负责账号级积分。

核心方法：

- `accountForAdmin(int $adminId, int $siteId)`
- `ensureSufficient(int $adminId, int $siteId, mixed $amount)`
- `grant(int $adminId, int $siteId, string $amount, ?int $operatorAdminId, string $remark = '')`
- `deductForSubmission(MediaSubmission $submission, int $adminId, ?int $operatorAdminId)`
- `refundForSubmission(MediaSubmission $submission, int $adminId, ?int $operatorAdminId, string $remark = '')`

---

## 十、规格资源项

保留：

- `credits`：积分
- `article_generations`：AI 文章生成次数
- `brand_diagnoses`：品牌诊断次数
- `ai_title_generations`：AI 爆款标题生成次数
- `url_imports`：URL 采集次数
- `keyword_question_generations`：关键词问题生成次数
- `inclusion_checks`：GEO 收录/引用检测次数
- `ai_image_generations`：AI 配图次数
- `team_members`：子账号数量
- `api_tokens`：API Token 数量

新增：

- `video_generations`：生成视频次数
- `crebee_publishes`：CreBee 发布次数

不新增：

- `media_submissions`

原因：

媒体发布当前已经通过积分扣费，不要再加发布次数限制，避免形成“积分 + 次数”的双重扣减。

---

## 十一、实施任务

### Task 1：新增账号级规格和积分表

新增迁移：

```text
database/migrations/2026_06_15_000000_create_admin_plan_subscription_tables.php
```

创建表：

- `admin_plan_subscriptions`
- `admin_resource_usages`
- `admin_resource_ledger`
- `admin_credit_accounts`
- `admin_credit_ledger`

测试文件：

```text
tests/Feature/AdminAccountPlanSubscriptionTest.php
```

测试重点：

- 五张新表存在。
- 关键字段存在。
- SQLite 测试库可正常 migrate。

执行：

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountPlanSubscriptionTest.php
```

### Task 2：新增账号级模型

新增模型：

- `AdminPlanSubscription`
- `AdminResourceUsage`
- `AdminResourceLedger`
- `AdminCreditAccount`
- `AdminCreditLedger`

修改：

```text
app/Models/Admin.php
```

增加关系：

- `accountPlanSubscriptions()`
- `creditAccount()`

测试重点：

- `Admin` 可以访问账号级规格关系。
- `Admin` 可以访问账号级积分账户。

### Task 3：新增账号级规格服务

新增：

```text
app/Services/Billing/AdminPlanSubscriptionService.php
```

实现：

- 给代理/直客开通账号规格。
- 给代理普通用户继承规格。
- 查找账号有效规格。
- 从旧站点规格回填账号规格。

测试重点：

- 开通代理/直客账号规格后生成 `admin_plan_subscriptions`。
- 规格包含积分时生成 `admin_credit_accounts`。

### Task 4：新增账号级积分服务

新增：

```text
app/Services/MediaDistribution/AdminCreditService.php
```

实现：

- 账号积分账户创建。
- 账号积分发放。
- 媒体投稿扣积分。
- 媒体投稿失败/取消退积分。
- 防止重复退款。

测试重点：

- 用户 A 扣积分不影响用户 B。
- 同一投稿只退款一次。

### Task 5：新增账号级额度服务

新增：

```text
app/Services/Billing/AdminResourceQuotaService.php
```

实现：

- 账号级有效规格校验。
- 账号级额度剩余查询。
- 账号级资源扣减。
- 幂等扣减。

测试重点：

- 同一代理站点下两个普通用户各有独立额度。
- 用户 A 用完品牌诊断次数，不影响用户 B。
- 同一 `idempotency_key` 不重复扣减。

### Task 6：新增规格资源项

修改：

```text
app/Models/PlatformPlan.php
```

新增常量：

```php
public const RESOURCE_VIDEO_GENERATIONS = 'video_generations';
public const RESOURCE_CREBEE_PUBLISHES = 'crebee_publishes';
```

新增资源目录：

```php
self::RESOURCE_VIDEO_GENERATIONS => ['label' => '生成视频次数', 'unit' => 'times'],
self::RESOURCE_CREBEE_PUBLISHES => ['label' => 'CreBee 发布次数', 'unit' => 'times'],
```

测试重点：

- 平台规格页面可以配置这两个资源。
- 保存后 `platform_plan_entitlements` 正确写入。

### Task 7：超管创建代理/直客时同步创建账号规格

修改：

```text
app/Http/Controllers/Admin/AdminUserController.php
```

逻辑：

- 原本同步创建站点和站点规格继续保留。
- 额外调用 `AdminPlanSubscriptionService::openOwner()`。
- 代理使用 `agent_owner`。
- 直客使用 `direct_owner`。

测试重点：

- 超管创建代理并开通规格后，同时存在：
  - `site_plan_subscriptions`
  - `admin_plan_subscriptions`
  - `admin_credit_accounts`

### Task 8：代理创建普通用户时继承独立账号规格

修改：

```text
app/Http/Controllers/Admin/AgentUserController.php
```

逻辑：

- 创建 `site_user`。
- 绑定 `site_members`。
- 调用 `AdminPlanSubscriptionService::inheritForAgentUser()`。
- 给普通用户生成自己的账号级规格和积分账户。

测试重点：

- 代理创建普通用户后，该用户有 `admin_plan_subscriptions`。
- `inherited_from_admin_id` 指向代理管理员。
- 用户有自己的 `admin_credit_accounts`。
- 子账号数量仍受 `team_members` 限制。

### Task 9：业务表增加 `owner_admin_id`

新增迁移：

```text
database/migrations/2026_06_15_000100_add_owner_admin_id_to_business_resources.php
```

给核心业务表增加：

```text
owner_admin_id
```

测试重点：

- 核心业务表存在 `owner_admin_id`。
- 索引包含 `site_id + owner_admin_id`。

### Task 10：新增用户级资源隔离 Trait

新增：

```text
app/Models/Concerns/BelongsToAdminOwner.php
```

第一批接入模型：

- `KnowledgeBase`
- `ImageLibrary`
- `Article`
- `BrandDiagnosisRun`
- `MediaSubmission`

测试重点：

- 普通用户只能看到自己的知识库。
- 代理管理员可以看到本代理站点下所有普通用户知识库。
- 超管不受限制。

### Task 11：历史数据回填

新增迁移：

```text
database/migrations/2026_06_15_000200_backfill_admin_account_ownership.php
```

回填内容：

- 旧 `site_plan_subscriptions` 生成对应 `admin_plan_subscriptions`。
- `articles.owner_admin_id` 优先从已有 owner 字段回填。
- `media_submissions.owner_admin_id` 从 `submitted_by_admin_id` 回填。
- `brand_diagnosis_runs.owner_admin_id` 从 `admin_id` 回填。

注意：

- 不可靠的数据保持 `owner_admin_id = null`。
- 不要把站点历史积分余额复制给每个用户，否则会放大历史余额。

### Task 12：品牌诊断改为账号级扣减

修改：

```text
app/Services/BrandDiagnosis/BrandDiagnosisUsagePolicy.php
```

逻辑：

- 使用 `AdminResourceQuotaService`。
- 扣减 `brand_diagnoses`。
- `admin_id` 使用发起诊断的账号。

测试重点：

- 用户 A 诊断一次，只增加用户 A 的 `admin_resource_usages`。
- 用户 B 不受影响。

### Task 13：媒体发布改为账号级积分

修改：

```text
app/Services/MediaDistribution/MediaSubmissionService.php
```

逻辑：

- 发布前校验当前账号积分。
- 发布扣 `admin_credit_accounts`。
- 失败/取消退回到同一账号。
- 不再从共享站点积分扣除。

测试重点：

- 同一站点两个普通用户积分独立。
- 用户 A 投稿扣自己的积分。
- 用户 A 投稿失败退自己的积分。
- 用户 B 积分不变。

### Task 14：剩余资源扣减入口切换为账号级

逐个修改：

- `WorkerExecutionService`
- `AiGeneratedArticleImageService`
- `TitleLibraryController`
- `UrlImportController`
- `KeywordLibraryController`
- `ApiTokenController`

规则：

- Web 请求优先使用当前登录账号。
- 队列任务没有登录态时，使用业务记录上的 `owner_admin_id`。
- 如果老数据没有 `owner_admin_id`，第一版走兼容逻辑，不强行扣错账号。

测试重点：

- 每个资源都写入 `admin_resource_usages`。
- 同一代理站点下不同用户互不影响。

### Task 15：扩展资源隔离到剩余模型

继续给剩余用户资源模型接入：

```text
BelongsToAdminOwner
```

不要接入站点公共配置模型。

测试重点：

- 文章、图片库、品牌诊断记录都按账号隔离。
- 代理管理员可查看本代理站点下所有用户数据。
- 普通用户只能看自己的。

### Task 16：后台页面展示账号级余额和额度

修改：

- 媒体发布页面
- 积分管理页面
- 规格额度展示页面

展示规则：

- 普通用户看到“账号积分”。
- 代理管理员查看用户时看到用户自己的额度。
- 超管可以看到账号级积分，也可以保留查看站点历史积分入口。

文案建议：

```text
账号积分
站点历史积分
账号规格额度
```

### Task 17：完整回归

重点测试：

```bash
docker compose exec -T app php artisan test tests/Feature/AdminAccountPlanSubscriptionTest.php tests/Feature/AdminAccountResourceIsolationTest.php tests/Feature/PlatformPlanSubscriptionTest.php tests/Feature/AgentUserManagementTest.php
```

业务测试：

```bash
docker compose exec -T app php artisan test tests/Feature/BrandDiagnosisDoubaoFlowTest.php tests/Feature/AdminMediaDistributionTest.php tests/Feature/WorkerExecutionSiteIsolationTest.php tests/Feature/AdminGeoInclusionCheckPhaseTwoTest.php
```

迁移测试：

```bash
docker compose exec -T app php artisan migrate:fresh --seed
```

---

## 十二、上线步骤

正式环境建议执行：

```bash
git pull
docker compose --env-file .env.prod -f docker-compose.prod.yml build app
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d
docker compose --env-file .env.prod -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose --env-file .env.prod -f docker-compose.prod.yml exec app php artisan optimize:clear
```

上线后检查：

- `admin_plan_subscriptions` 是否有代理/直客账号记录。
- 新创建代理用户是否自动生成账号规格。
- 新创建代理用户是否自动生成账号积分账户。
- 同一代理站点下两个普通用户额度是否独立。
- 直客是否仍然不能访问代理用户管理。
- 媒体发布是否扣 `admin_credit_accounts`，不是扣共享 `site_credit_accounts`。

---

## 十三、风险点

### 1. 历史数据归属不完整

有些老数据可能没有明确创建人。处理方式：

- 能从 `admin_id`、`submitted_by_admin_id`、`created_by_admin_id` 回填的就回填。
- 无法判断的保持 `owner_admin_id = null`。
- 后续由超管或迁移脚本人工修复。

### 2. 不要复制站点历史积分给每个用户

站点历史积分是共享旧余额，不能直接复制给代理下每个普通用户。

新账号积分只在：

- 新开通规格
- 代理用户继承规格
- 后台人工调整

这些场景发放。

### 3. 代理管理员权限范围

代理管理员不是普通用户，他需要能管理站点下用户，所以不能被 `owner_admin_id` 限制住。

### 4. 旧站点级逻辑不能马上删除

需要保留一段时间兼容：

- 历史页面
- 历史统计
- 旧数据排查
- 回滚风险

---

## 十四、第一阶段验收标准

第一阶段做到 Task 8 即可形成一个稳定检查点：

- 超管创建代理/直客时，有账号级规格。
- 代理创建普通用户时，普通用户能继承独立规格。
- 每个普通用户有自己的账号积分。
- 同一代理站点下多个普通用户额度互不影响。
- 直客仍然不能创建普通用户。

第二阶段再继续做：

- 业务资源 `owner_admin_id` 隔离。
- 品牌诊断、媒体发布、文章生成等业务扣减改账号级。

这样拆分风险最小，也方便生产灰度验证。
