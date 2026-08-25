# 平台规格与代理/直客模式技术实施方案

## 1. 背景与目标

当前系统已经支持多站点：平台可创建分站点，用户通过站点成员关系访问各自站点数据，超级管理员可以切换分站点查看对应数据。现有业务包括文章生成、媒体发布、素材管理、品牌诊断、GEO 检测等，数据主体基本都带有 `site_id`。

本方案新增“平台规格 + 代理模式 + 直客模式”能力：

- 平台超级管理员可以维护可售规格。
- 平台可以创建代理客户，也可以创建直客客户。
- 代理客户登录后是自己站点的站点超管，可以管理自己站点下的普通用户。
- 直客客户登录后使用自己站点的业务能力。
- 用户线下转账后，平台后台人工开通规格或续费，不做在线支付、不做支付订单、不接支付回调。
- 规格必须有服务时间，到期后业务能力不可用。
- 规格资源可配置，资源使用统一走规格校验。
- 当前媒体发布只使用积分，不单独设计“媒体发布次数”资源，避免积分和次数双重限制。

第一版目标是把“手动开通规格 + 有效期 + 资源额度限制 + 代理/直客权限边界”跑通，不做自动支付、自动续费、分销结算、发票等复杂商业系统。

## 2. 现有系统基础

当前可复用能力：

- `admins`：后台用户表，已有 `super_admin` 和普通 `admin` 角色。
- `sites`：分站点表。
- `site_members`：用户与站点关系表，已有 `owner/admin` 站点成员角色。
- `CurrentSite`：当前站点上下文。普通用户只能访问自己所属站点，超级管理员可以切换站点。
- `BelongsToSite`：多业务模型已有站点隔离能力。
- `site_credit_accounts` / `site_credit_ledger`：站点积分账户和积分流水，当前主要用于媒体发布。
- `BrandDiagnosisUsagePolicy`：品牌诊断当前每日免费次数限制。
- `MediaSubmissionService`：媒体发布前校验积分，提交失败时退积分。

新增规格体系应复用这些结构，而不是另起一套租户体系。

## 3. 业务角色

### 3.1 平台超级管理员

角色标识沿用：

- `super_admin`
- `superadmin` 兼容历史脏数据

权限：

- 管理全部站点。
- 管理全部用户。
- 创建代理客户。
- 创建直客客户。
- 创建、编辑、启停规格。
- 给站点开通规格、续费、调整到期时间、调整资源额度、调整积分。
- 查看所有站点订阅和资源消耗流水。
- 不受规格限制。

### 3.2 代理管理员

建议新增后台角色：

- `agent_admin`

业务含义：

代理管理员是自己站点的“站点超管”，但仍受平台管辖。

权限：

- 只能访问自己所属站点。
- 可以管理自己站点下普通用户。
- 可以为自己创建的普通用户选择平台分配给该代理可用的规格；如果代理只有一个可用规格，创建用户时可默认继承。
- 不能创建平台级规格。
- 不能管理其他代理或直客。
- 不能切换到非自己授权站点。
- 不能绕过规格有效期和资源限制。

### 3.3 直客管理员

建议新增后台角色：

- `direct_admin`

业务含义：

直客管理员是平台直接服务的客户账号。

权限：

- 只能访问自己站点。
- 第一版不允许创建普通用户；直客管理员只使用自己站点的业务能力。
- 不能创建代理。
- 不能创建或编辑平台规格。
- 不能管理其他站点。

### 3.4 站点普通用户

建议新增后台角色：

- `site_user`

权限：

- 使用所在站点的业务功能。
- 不管理用户。
- 不管理规格。
- 不管理积分或订阅。
- 所有资源使用都消耗所属站点订阅额度。

## 4. 客户模式

站点新增客户模式：

- `agent`：代理模式
- `direct`：直客模式
- `internal`：平台内部站点或历史兼容

建议在 `sites` 表新增字段：

- `customer_mode`：`agent/direct/internal`
- `agent_admin_id`：代理模式下的代理管理员 ID，可空
- `plan_status`：冗余展示字段，可选，真实状态以订阅表为准

也可以将这些信息完全放在订阅表，但放在 `sites` 上有利于列表筛选和后台展示。

## 5. 规格设计

### 5.1 规格模板

新增表：`platform_plans`

建议字段：

- `id`
- `name`：规格名称，例如“专业版季度版”
- `code`：唯一编码，例如 `pro_quarter`
- `audience`：适用对象，`agent/direct/both`
- `duration_days`：默认服务时长，例如 90、365
- `price`：成交参考价，可为空
- `market_price`：市场指导价，可为空
- `description`：说明
- `status`：`active/inactive`
- `sort_order`
- `created_by`
- `created_at`
- `updated_at`

说明：

- 规格模板只定义“默认卖什么、默认多久、默认给多少资源”。
- 客户实际是否可用，以站点订阅的 `starts_at` / `ends_at` 为准。
- 修改规格模板不应直接影响已开通客户，所以开通时必须保存规格快照。

### 5.2 规格资源

新增表：`platform_plan_entitlements`

建议字段：

- `id`
- `plan_id`
- `resource_key`
- `enabled`
- `quota_value`
- `quota_period`
- `unit`
- `meta`
- `created_at`
- `updated_at`

`quota_period` 建议枚举：

- `cycle`：服务周期内总量，用完为止
- `day`：每日重置
- `month`：每月重置
- `year`：每年重置
- `unlimited`：不限制

第一版建议大部分资源使用 `cycle`，减少复杂度。

### 5.3 第一版资源项

第一版规格资源建议固定枚举，不允许后台随意新增未知资源 key。

保留资源：

- `credits`：积分，用于媒体发布扣费
- `article_generations`：AI 文章生成次数
- `brand_diagnoses`：品牌诊断次数
- `ai_title_generations`：AI 爆款标题生成次数
- `url_imports`：URL 采集次数
- `keyword_question_generations`：关键词问题生成次数
- `inclusion_checks`：GEO 收录/引用检测次数
- `ai_image_generations`：AI 配图次数
- `team_members`：子账号数量，仅用于代理模式创建普通用户；直客模式不启用
- `api_tokens`：API Token 数量

不设置：

- `media_submissions`

原因：

当前媒体发布只消耗积分。如果再增加媒体发布次数，会形成“积分 + 次数”双重限制，容易造成客户理解和运营配置混乱。媒体发布统一按积分余额扣费即可。

后台规格页面可以展示：

```text
媒体发布：按积分余额扣费
```

但不作为独立可勾选资源。

## 6. 站点订阅

新增表：`site_plan_subscriptions`

建议字段：

- `id`
- `site_id`
- `plan_id`
- `mode`：`agent/direct/internal`
- `owner_admin_id`
- `agent_admin_id`
- `assigned_by_admin_id`
- `status`：`active/expired/cancelled`
- `starts_at`
- `ends_at`
- `entitlements_snapshot`
- `remark`
- `created_at`
- `updated_at`

### 6.1 有效期规则

业务操作前必须检查：

```text
status = active
starts_at <= now()
ends_at >= now()
```

如果没有有效订阅：

- 后台允许登录。
- 业务操作不可用。
- 页面提示“当前规格已到期，请联系平台续费”。
- 平台超级管理员不受限制。

### 6.2 开通规则

平台线下收款后，平台超级管理员手动开通：

1. 创建代理或直客账号。
2. 创建或选择站点。
3. 选择规格。
4. 系统按规格 `duration_days` 自动计算默认 `ends_at`。
5. 平台超管可手动调整 `starts_at` 和 `ends_at`。
6. 保存订阅。
7. 保存 `entitlements_snapshot`。
8. 如果规格包含 `credits`，自动给站点积分账户增加对应积分，并写入积分流水。
9. 写入订阅日志。

### 6.3 续费规则

续费由平台超管手动操作：

1. 选择站点当前订阅。
2. 选择续费规格，默认沿用当前规格。
3. 选择续费方式：
   - 按规格时长续费
   - 手动设置新的到期时间
4. 未过期时：从当前 `ends_at` 往后叠加。
5. 已过期时：从当前时间重新计算。
6. 可选择是否追加规格中的积分：
   - 追加积分
   - 只延长时间，不追加积分
7. 写入订阅日志。
8. 如追加积分，写入积分流水。

### 6.4 停用与到期

停用：

- 平台超管手动将订阅设为 `cancelled`。
- 业务立刻不可用。

到期：

- 可通过查询时动态判断。
- 也可以增加定时任务每天将过期订阅标记为 `expired`，便于列表筛选。

建议第一版两者都做：

- 操作校验以 `ends_at` 为准，保证准确。
- 定时任务更新 `status`，提升后台展示体验。

## 7. 资源用量与流水

### 7.1 资源用量表

新增表：`site_resource_usages`

建议字段：

- `id`
- `site_id`
- `subscription_id`
- `resource_key`
- `period_key`
- `used_amount`
- `reserved_amount`
- `last_used_at`
- `created_at`
- `updated_at`

唯一索引：

```text
site_id + subscription_id + resource_key + period_key
```

`period_key` 生成规则：

- `cycle`：`subscription:{subscription_id}`
- `day`：`YYYY-MM-DD`
- `month`：`YYYY-MM`
- `year`：`YYYY`
- `unlimited`：不需要写入或只写流水

### 7.2 资源流水表

新增表：`site_resource_ledger`

建议字段：

- `id`
- `site_id`
- `subscription_id`
- `resource_key`
- `type`：`grant/consume/refund/adjust`
- `amount`
- `balance_after`
- `actor_admin_id`
- `subject_type`
- `subject_id`
- `idempotency_key`
- `remark`
- `created_at`

说明：

- 用量表用于快速判断剩余额度。
- 流水表用于审计和排查。
- 关键扣减必须支持幂等，避免队列重试重复扣减。

### 7.3 订阅日志表

新增表：`site_subscription_logs`

建议字段：

- `id`
- `site_id`
- `subscription_id`
- `action`：`open/renew/adjust/cancel/expire`
- `before_payload`
- `after_payload`
- `operator_admin_id`
- `remark`
- `created_at`

用途：

- 查看谁给客户开通了什么规格。
- 查看谁给客户续了多久。
- 查看是否追加了积分。
- 查看手动调整原因。

## 8. 统一规格校验服务

新增服务：

`App\Services\Billing\ResourceQuotaService`

核心方法：

- `activeSubscriptionForSite(int $siteId): SitePlanSubscription`
- `assertSubscriptionActive(int $siteId): void`
- `assertCanUse(int $siteId, string $resourceKey, int $amount = 1): void`
- `consume(int $siteId, string $resourceKey, int $amount = 1, array $context = []): SiteResourceLedger`
- `refund(int $siteId, string $resourceKey, int $amount = 1, array $context = []): SiteResourceLedger`
- `remaining(int $siteId, string $resourceKey): array`
- `summary(int $siteId): array`

处理规则：

- 平台超级管理员可绕过。
- 非超级管理员必须有有效订阅。
- 资源未启用时，提示“当前规格不包含该功能”。
- 资源额度不足时，提示“当前规格额度不足，请联系平台升级或续费”。
- `unlimited` 不扣减额度，但可写流水。
- 所有扣减必须在数据库事务内执行，并使用行锁。

## 9. 积分处理

当前积分只用于媒体发布。

保留现有：

- `SiteCreditService`
- `site_credit_accounts`
- `site_credit_ledger`
- 媒体发布成功扣积分
- 媒体发布失败/取消/拒稿退积分

调整：

- 规格开通或续费时，如果规格包含 `credits`，调用 `SiteCreditService::recharge()` 或新增专用 `grantFromPlan()` 给站点增加积分。
- 媒体发布不接入 `media_submissions` 次数限制。
- 媒体发布只校验积分余额。
- 积分流水 remark 应写明“规格开通赠送”“规格续费赠送”“平台手动调整”等来源。

## 10. 业务接入点

### 10.1 品牌诊断

修改：

- `BrandDiagnosisUsagePolicy`

新逻辑：

- 超级管理员不限。
- 普通用户确认诊断时，校验订阅有效。
- 消耗 `brand_diagnoses`。
- 如果队列后续失败，第一版不退次数。原因是确认诊断后已经发生外部模型资源消耗。

替代当前每日免费一次逻辑：

- 第一版规格上线后，普通用户主要按规格次数限制。
- 如需兼容历史，可给旧站点绑定 `legacy_unlimited` 或 `legacy_daily_free` 规格。

### 10.2 媒体发布

修改：

- `MediaSubmissionService::submit()`

新逻辑：

- 提交前校验订阅有效。
- 不扣媒体发布次数。
- 继续按媒体价格扣积分。
- 提交失败时继续退积分。

### 10.3 AI 文章生成

建议接入：

- `WorkerExecutionService`
- 或任务开始/文章保存成功处

扣减规则：

- 成功生成并保存一篇文章后，消耗 `article_generations = 1`。
- 失败不扣。
- 如果批量任务要生成多篇文章，可在任务启动前检查剩余额度是否足够，实际成功保存后逐篇扣减。

### 10.4 AI 爆款标题

接入：

- `TitleLibraryController::generateWithAi()`

扣减规则：

- 按实际保存成功标题数量扣 `ai_title_generations`。
- 重复标题未保存的不扣。

### 10.5 URL 采集

接入：

- `UrlImportController::store()` 或正式提交入库时

建议：

- 第一版按发起一次采集扣 `url_imports = 1`。
- 如果只是预览失败，可以不扣；如果已实际抓取并消耗模型，可扣。

### 10.6 关键词问题生成

接入：

- `KeywordLibraryController::generateQuestions()`

扣减：

- 每次生成请求扣 `keyword_question_generations = 1`。

### 10.7 GEO 收录/引用检测

接入：

- `KeywordLibraryController::storeInclusionCheck()`

扣减：

- 可以按检测批次扣 `inclusion_checks = 1`。
- 如果后续需要更精细，可按“问题 x 平台”扣。
- 第一版建议按批次扣，用户更容易理解。

### 10.8 AI 配图

接入：

- `AiGeneratedArticleImageService`

扣减：

- 成功生成并上传一张图，扣 `ai_image_generations = 1`。

### 10.9 子账号数量

接入：

- 新增代理用户管理服务

扣减/限制：

- 不做消耗流水。
- 创建用户前统计当前站点 active 成员数。
- 超过 `team_members` 限制则阻止创建。
- 停用用户是否释放名额：第一版建议释放，统计 active 用户即可。
- 直客管理员不能创建普通用户，因此直客模式不检查、不展示 `team_members` 创建入口。

### 10.10 API Token 数量

接入：

- `ApiTokenController::store()`

限制：

- 创建前统计当前站点未撤销 token 数量。
- 超过 `api_tokens` 限制则阻止创建。

## 11. 后台页面

### 11.1 平台规格管理

仅平台超级管理员可访问。

页面：

- 规格列表
- 新增规格
- 编辑规格
- 启用/停用
- 资源勾选与数量配置

表单字段：

- 规格名称
- 规格编码
- 适用对象：代理/直客/全部
- 服务时长
- 指导价
- 状态
- 资源项勾选
- 资源数量
- 资源周期

### 11.2 客户开通管理

仅平台超级管理员可访问。

能力：

- 创建代理客户
- 创建直客客户
- 选择规格
- 设置开始时间和到期时间
- 给代理分配可用规格
- 开通时是否发放积分
- 查看订阅状态
- 续费
- 调整到期时间
- 调整额度
- 停用订阅

### 11.3 代理用户管理

代理管理员可访问。

能力：

- 查看自己站点用户。
- 创建普通用户。
- 停用普通用户。
- 重置普通用户密码。
- 为普通用户选择规格。

限制：

- 代理不能创建代理。
- 代理不能创建直客。
- 代理不能分配平台未授权给他的规格。
- 代理不能越权查看其他站点。

### 11.4 直客用户管理

第一版不做直客用户管理能力。

限制：

- 直客管理员不能创建普通用户。
- 直客管理员不能停用普通用户。
- 直客管理员不能重置普通用户密码。
- 直客管理员不能访问代理用户管理页面。
- `team_members` 对直客模式无效，后台创建直客规格时不建议展示该资源项。

### 11.5 站点资源面板

后台首页或新增“规格与额度”页面展示：

- 当前规格名称
- 客户模式
- 开始时间
- 到期时间
- 剩余天数
- 各资源总量、已用、剩余
- 积分余额
- 最近资源消耗流水

## 12. 权限与安全边界

必须满足：

- 只有平台超级管理员能维护规格模板。
- 只有平台超级管理员能开通、续费、取消站点订阅。
- 代理管理员只能管理自己站点普通用户。
- 直客管理员不能管理普通用户，只能使用自己站点业务功能。
- 普通用户不能管理用户和规格。
- 非超级管理员所有业务操作都必须检查当前站点有效订阅。
- 所有资源消耗必须带 `site_id`。
- 所有订阅变更和资源调整必须记录操作者。
- `.env` 中不增加规格配置，规格全部在数据库维护。

## 13. 兼容与迁移

上线时需要保护现有客户不受影响：

1. 新增默认规格 `legacy_unlimited`。
2. 给现有所有站点创建有效订阅。
3. `legacy_unlimited` 可设置长期有效，例如 2099-12-31。
4. 资源全部设为 `unlimited`。
5. 后续平台超管可手动把旧站点切换到正式规格。

已有媒体积分：

- 保留当前站点积分余额。
- 创建 `legacy_unlimited` 不自动重置积分。

已有品牌诊断每日限制：

- 规格体系上线后，品牌诊断限制改为规格资源。
- 如果担心直接变化影响用户，可以先让旧站点走 `legacy_unlimited`。

## 14. 实施阶段

### 阶段一：数据结构与底层服务

- 新增规格、规格资源、站点订阅、资源用量、资源流水、订阅日志表。
- 新增模型。
- 新增 `ResourceQuotaService`。
- 新增 `PlanSubscriptionService`。
- 创建 `legacy_unlimited` 数据迁移。

### 阶段二：平台超管后台

- 新增规格管理页面。
- 新增客户开通/续费页面。
- 改造站点管理页面，展示客户模式、规格、到期时间。
- 支持手动续费、调整到期时间、调整额度。

### 阶段三：代理用户管理

- 扩展 `admins.role`。
- 扩展站点成员角色。
- 新增代理用户管理页面。
- 实现代理只能管理本站点用户。
- 实现代理模式子账号数量限制。
- 实现直客不能访问代理用户管理页面、不能创建普通用户。

### 阶段四：业务资源接入

- 品牌诊断接入 `brand_diagnoses`。
- 媒体发布接入订阅有效期检查，继续只走积分。
- AI 文章生成接入 `article_generations`。
- AI 标题生成接入 `ai_title_generations`。
- URL 采集接入 `url_imports`。
- 关键词问题生成接入 `keyword_question_generations`。
- GEO 检测接入 `inclusion_checks`。
- AI 配图接入 `ai_image_generations`。
- API Token 创建接入 `api_tokens`。

### 阶段五：展示、审计与定时任务

- 新增规格与额度面板。
- 新增资源流水列表。
- 新增订阅日志列表。
- 新增订阅到期定时任务。
- 后台列表增加到期提醒。

## 15. 测试重点

必须覆盖：

- 超级管理员不受规格限制。
- 代理只能管理自己站点用户。
- 代理不能使用未分配规格。
- 直客不能访问代理管理能力。
- 直客不能创建普通用户。
- 站点订阅到期后业务操作被拒绝。
- 规格开通时正确发放积分。
- 续费未过期时从原到期时间延长。
- 续费已过期时从当前时间重新计算。
- 媒体发布只扣积分，不扣发布次数。
- 媒体发布失败时退积分。
- 品牌诊断消耗 `brand_diagnoses`。
- 文章生成成功才消耗 `article_generations`。
- 代理子账号数量超额时阻止创建。
- 历史站点绑定 `legacy_unlimited` 后不受影响。

## 16. 第一版不做内容

第一版明确不做：

- 在线支付
- 支付订单
- 支付回调
- 自动到账
- 发票
- 分销佣金
- 代理财务结算
- 客户自助购买页面
- 自动升级套餐

第一版只做：

```text
线下收款 + 平台后台人工开通/续费 + 系统按规格时间和资源额度自动限制使用
```
