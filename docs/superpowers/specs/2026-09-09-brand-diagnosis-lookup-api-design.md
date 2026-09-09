# 品牌诊断查询 API 设计方案

## 1. 目标

新增一个供三方服务端调用的品牌诊断查询接口，满足以下场景：

1. 根据品牌词跨站点查询系统中的品牌诊断存量数据。
2. 支持精确、规范化、前缀和包含匹配，匹配结果取最新一条诊断。
3. 支持按模块返回品牌介绍、AI 问题、品牌表现、模型回答、AI 信源和对话快照。
4. 如果系统没有该品牌词的诊断存量数据，只生成品牌介绍和 AI 问题并返回。
5. 非存量生成不创建诊断记录、不创建问题记录、不进入完整诊断队列、不扣品牌诊断额度。
6. 如果模型无法核实可靠的品牌介绍，立即返回明确错误。

现有品牌诊断 OpenAPI（`POST /api/v1/brand-diagnoses`、`GET /api/v1/brand-diagnoses/{taskKey}`）的路由、鉴权、数据结构和行为保持不变。

## 2. 当前品牌诊断流程

### 2.1 后台和 MCP 用户流程

1. `BrandDiagnosisRunService::create()` 创建 Run，要求当前站点存在，并将状态设为 `questions_generating`。
2. `GenerateBrandDiagnosisQuestionsJob` 投递到 `geoflow` 队列。
3. Job 使用 `BrandProfileResolver` 调用品牌核实模型进行联网搜索，写入 `brand_profile`、来源、模型和状态。
4. Job 使用 `DoubaoBrandDiagnosisClient` 提取核心词并生成问题池，保存 `BrandDiagnosisQuestion`，Run 状态变为 `questions_ready`。
5. 后台用户确认问题后，`BrandDiagnosisRunService::confirm()` 将 Run 设为 `running`，根据套餐策略预留/扣减额度，并投递 `ProcessBrandDiagnosisJob`。
6. `ProcessBrandDiagnosisJob` 对每个问题和平台调用模型，写入 `BrandDiagnosisResult`、`BrandDiagnosisSource`、`BrandDiagnosisBrandMention`，并捕获脱敏 `snapshot_payload`。
7. `BrandDiagnosisMetricsCalculator::refreshRun()` 汇总品牌提及率、排名、提及次数、情感率和综合分数，Run 最终变为 `completed` 或 `failed`。

### 2.2 现有开放 API 流程

1. `POST /api/v1/brand-diagnoses` 使用独立 `X-Api-Key` 创建 `site_id=null` 的系统级 Run，并生成不暴露内部 ID 的 `bdg_*` task key。
2. 生成问题后，`AutoConfirmBrandDiagnosisRunJob` 自动确认问题，设置 `billing_mode=open_api`，再投递完整诊断 Job。
3. `GET /api/v1/brand-diagnoses/{taskKey}` 使用 `BrandDiagnosisApiResultPresenter` 返回进度或完整结果。

### 2.3 新查询 API 的流程差异

新 API 是查询/预览接口，不是新的完整诊断入口：

- 存量命中：只读已有 Run 及其关联数据。
- 非存量：同步调用品牌介绍和问题生成能力，但不启动完整多模型诊断。
- 非存量结果只代表本次查询生成的预览数据，不能被当作系统诊断存量。

## 3. 接口与鉴权

### 3.1 路由

```http
GET /api/v1/brand-diagnoses/search?brand_word=策影GEO
```

该静态路由必须定义在旧路由 `GET /api/v1/brand-diagnoses/{taskKey}` 之前，避免 `search` 被当成旧接口的 task key。

### 3.2 公共 API Key

新接口使用一把系统级共享 `X-Api-Key`，不绑定站点，不要求 Sanctum Bearer Token，也不使用后台 Token 管理页。

建议配置：

```env
BRAND_DIAGNOSIS_LOOKUP_API_ENABLED=false
BRAND_DIAGNOSIS_LOOKUP_API_KEY=
BRAND_DIAGNOSIS_LOOKUP_CACHE_TTL=21600
BRAND_DIAGNOSIS_LOOKUP_RATE_LIMIT=10
```

旧 OpenAPI 继续使用：

```env
BRAND_DIAGNOSIS_OPEN_API_ENABLED=false
BRAND_DIAGNOSIS_OPEN_API_KEY=
```

两套 Key 必须由独立配置项和独立中间件校验，不能共用配置值或鉴权分支。

Key 由命令生成并输出给部署人员，例如：

```bash
php artisan geoflow:brand-diagnosis-lookup-key
```

命令只生成密码学安全的随机 Key，不将明文 Key 写入数据库，也不通过 HTTP 接口返回。部署人员将其写入 `.env`、容器 Secret 或外部密钥管理系统。轮换时生成新 Key、更新配置并重启/刷新配置缓存。

“公共”仅表示三方服务端共用一把平台 Key，不表示接口可以无密钥公开访问。Key 不应嵌入浏览器、前端 JavaScript 或移动端应用。

### 3.3 中间件与限流

- 在 `bootstrap/app.php` 注册 `brand-diagnosis.lookup-api-key` 中间件别名。
- 中间件校验 Key 是否存在、功能是否启用；失败沿用统一 API 错误信封。
- 路由使用 `api.request_id`、`brand-diagnosis.lookup-api-key` 和独立限流器。
- 限流按来源 IP，默认每分钟 10 次；命中存量和触发模型生成都计入限流。
- 不改动现有 `brand-diagnosis.api-key` 中间件和旧开放 API 路由。

## 4. 请求参数

```http
GET /api/v1/brand-diagnoses/search
    ?brand_word=策影GEO
    &include=profile,questions,performance
X-Api-Key: <lookup-api-key>
Accept: application/json
```

参数：

| 参数 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `brand_word` | string | 是 | 品牌词，trim 后不能为空，最长 120 个字符 |
| `include` | string | 否 | 逗号分隔的模块列表；不传表示返回全部模块 |

允许模块：

| 值 | 返回内容 |
| --- | --- |
| `profile` | 品牌介绍和品牌介绍的联网来源 |
| `questions` | AI 问题 |
| `performance` | 品牌表现统计 |
| `model_results` | 各问题/平台的模型回答及指标 |
| `sources` | 模型回答引用的 AI 信源 |
| `snapshots` | 脱敏后的对话快照 |

未知模块返回 `422 validation_failed`，不执行数据库查询或模型调用。

## 5. 存量匹配策略

新增 `BrandDiagnosisLookupService`，所有存量查询显式使用：

```php
BrandDiagnosisRun::query()->withoutGlobalScopes(['current_site', 'admin_owner'])
```

不依赖 session 中的 `CurrentSite`，覆盖所有站点、后台 Run 和旧开放 API Run；默认排除软删除记录。

匹配过程：

1. trim、合并连续空白、统一中英文括号。
2. 使用输入原词与 `brand_name` 做精确匹配。
3. 使用 `BrandEntityResolver::canonicalKey()` 做规范化匹配，兼容公司后缀、地域前缀、括号信息等差异。
4. 再做前缀匹配。
5. 最后做包含匹配。
6. 同一匹配等级内按 `created_at DESC, id DESC` 取最新 Run。

建议先用 PostgreSQL `ILIKE` 缩小候选集，再在 PHP 中按匹配等级排序，避免把全部历史 Run 加载到内存。候选集上限应配置为固定值，例如 100。

返回 `match_type`：`exact`、`canonical`、`prefix`、`contains`；没有存量时为 `none`。

如果最新 Run 状态是 `questions_generating`、`questions_ready`、`running` 或 `failed`，仍返回这条最新记录的当前状态；已有字段返回已有值，不补造品牌表现或模型结果。

## 6. 非存量生成策略

当没有任何存量 Run 命中时：

1. 构造一个未持久化的内存 `BrandDiagnosisRun`，只填充 `brand_name` 和默认平台 `doubao`。
2. 调用 `BrandProfileResolver` 的严格模式执行品牌介绍核实。当前品牌介绍联网搜索固定使用豆包，保持与现有流程一致。
3. 解析品牌介绍并校验可用性；少于最小有效长度或明确表示无法确认时视为不可用。
4. 调用 `DoubaoBrandDiagnosisClient::extractBrandCoreTerms()` 和 `generateQuestionPool()` 生成 AI 问题。
5. 将结果直接组装为 API 响应，不写入 `brand_diagnosis_runs`、`brand_diagnosis_questions` 或其他诊断表。
6. 不 dispatch `GenerateBrandDiagnosisQuestionsJob`、`ProcessBrandDiagnosisJob` 或任何完整诊断 Job。
7. 不执行 `BrandDiagnosisUsagePolicy`，不消耗品牌诊断额度。

严格模式只供 Lookup 使用，现有 `BrandProfileResolver::resolve()` 的宽松返回行为不变。建议区分：

- `brand_profile_not_found`（422）：模型没有核实出可靠品牌介绍。
- `brand_profile_provider_failed`（502）：Provider 超时、鉴权失败、网络失败或响应无法解析。
- `brand_questions_generation_failed`（502）：品牌介绍成功，但问题池生成失败。

非存量生成结果可缓存：

- Key：规范化品牌词，例如 `brand-diagnosis-lookup:{canonical_key}`。
- 内容：品牌介绍、品牌介绍来源、核心词和问题池。
- 默认 TTL：6 小时，可配置。
- 使用 Cache Lock 防止并发请求重复调用模型。
- 只缓存成功结果；缓存命中仍返回 `data_source=generated_not_stock`，不能伪装成存量。

## 7. 响应契约

响应沿用现有 `ApiResponse`，公共字段始终存在：

```json
{
  "success": true,
  "data": {
    "requested_brand_word": "策影GEO",
    "data_source": "stored",
    "match_type": "canonical",
    "diagnosis": {
      "brand_name": "策影GEO网络科技有限公司",
      "status": "completed",
      "created_at": "2026-09-09 10:00:00",
      "started_at": "2026-09-09 10:01:00",
      "completed_at": "2026-09-09 10:08:00",
      "error_message": ""
    },
    "brand_profile": {},
    "questions": [],
    "brand_performance": {},
    "model_results": [],
    "ai_sources": [],
    "conversation_snapshots": [],
    "module_status": {
      "profile": "included",
      "questions": "included",
      "performance": "included",
      "model_results": "included",
      "sources": "included",
      "snapshots": "included"
    }
  },
  "error": null,
  "meta": {
    "request_id": "…",
    "timestamp": "…",
    "included": ["profile", "questions"],
    "omitted": ["performance", "model_results", "sources", "snapshots"]
  }
}
```

### 7.1 模块字段

- `brand_profile`：`text`、`source`、`model`、`status`、`sources[]`。
- `questions[]`：`id`、`question`、`type`、`core_term`、`sort_order`、`status`。非存量生成的 `id` 为 `null`，状态为 `generated`。
- `brand_performance`：沿用现有 `score`、`mention_rate`、`average_rank`、`mention_count`、`sentiment_rate`。
- `model_results[]`：问题 ID、平台、状态、回答、品牌提及、提及次数、排名、情感、错误和完成时间。
- `ai_sources[]`：问题 ID、结果 ID、平台、标题、URL、域名和来源类型。
- `conversation_snapshots[]`：结果 ID、问题 ID、平台、问题、脱敏回答、来源、状态和检查时间。

### 7.2 `include` 规则

- 不传 `include`：六个模块全部返回。
- 传入模块：只序列化指定模块，并在 `meta.included/omitted` 中明确列出。
- 请求但当前不可用的模块返回 `null` 或空数组，并在 `module_status` 标记 `not_run`、`not_available` 或 `failed`。
- 被省略的模块不参与关联数据预加载。
- `snapshots` 使用已有 `snapshot_payload`；如果历史结果没有快照，则只在内存中使用 `BrandDiagnosisSnapshotPayload::displayAnswer()` 生成展示数据，不回写数据库。
- 不返回 `raw_response`、Provider 原始调试字段和品牌提及内部 `meta`。

### 7.3 非存量响应

非存量成功响应必须明确：

```json
{
  "data_source": "generated_not_stock",
  "match_type": "none",
  "diagnosis": {"status": "not_run"},
  "brand_profile": {},
  "questions": [],
  "brand_performance": null,
  "model_results": [],
  "ai_sources": [],
  "conversation_snapshots": []
}
```

如果调用方只请求 `performance`、`sources` 或 `snapshots`，这些模块状态为 `not_run`，不启动完整诊断。

## 8. 错误契约

| HTTP | code | 场景 |
| --- | --- | --- |
| 401 | `invalid_api_key` | Key 缺失或不匹配 |
| 403 | `brand_diagnosis_lookup_api_disabled` | 新接口未启用 |
| 422 | `validation_failed` | 品牌词或 include 不合法 |
| 422 | `brand_profile_not_found` | 无存量且模型未核实出可靠品牌介绍 |
| 429 | `lookup_rate_limited` | 查询频率超过限制 |
| 502 | `brand_profile_provider_failed` | 品牌核实 Provider 失败 |
| 502 | `brand_questions_generation_failed` | 问题池生成失败 |
| 503 | `brand_diagnosis_lookup_not_ready` | 必要配置或服务未就绪 |

所有错误使用现有 request ID 和 API 错误信封；错误中不暴露 API Key、完整 Prompt 或 Provider 原始响应。

## 9. 代码边界

建议新增：

- `app/Http/Controllers/Api/V1/BrandDiagnosisLookupController.php`
- `app/Http/Requests/Api/V1/BrandDiagnosisLookupRequest.php`
- `app/Http/Middleware/AuthenticateBrandDiagnosisLookupApiKey.php`
- `app/Services/BrandDiagnosis/BrandDiagnosisLookupService.php`
- `app/Services/BrandDiagnosis/BrandDiagnosisLookupPresenter.php`
- `app/Console/Commands/GenerateBrandDiagnosisLookupApiKeyCommand.php`

建议修改：

- `routes/api.php`：增加静态 search 路由，放在旧 task key 路由前。
- `bootstrap/app.php`：注册新中间件别名。
- `config/brand_diagnosis.php`：增加 lookup API、缓存和限流配置。
- `app/Providers/AppServiceProvider.php`：注册 lookup 限流器。
- `app/Services/BrandDiagnosis/BrandProfileResolver.php`：增加仅供 Lookup 使用的严格解析入口，保留旧方法行为。

不修改：

- 现有 `BrandDiagnosisController` 的旧 OpenAPI 方法。
- 现有 `BrandDiagnosisApiService` 的创建和 task key 查询逻辑。
- 现有 `brand-diagnosis.api-key` 中间件。
- 现有 `docs/api/brand-diagnosis.md`。

正常情况下不需要数据库迁移；非存量结果不落库，Key 存在环境配置或密钥管理系统中。

## 10. 测试计划

### 10.1 Feature 测试

1. 新接口缺少/错误/正确 Key 的 401、403 行为。
2. 精确、规范化、前缀、包含匹配和匹配等级优先级。
3. 跨站点读取，且同等级按最新 `created_at/id` 选择。
4. 软删除 Run 不参与匹配。
5. `include` 全量、单模块、多模块和非法模块。
6. 存量结果完整输出，包含 profile、问题、表现、结果、信源和快照。
7. 历史结果没有 `snapshot_payload` 时只内存回退，不产生数据库写入。
8. 非存量成功时只调用 profile/question 逻辑，不创建 Run/Question、不 dispatch Job、不扣额度。
9. 非存量 profile 找不到、Provider 失败、问题生成失败的错误码。
10. 缓存命中和 Cache Lock 下不会重复调用模型。
11. 旧 `BrandDiagnosisApiTest`、MCP 品牌诊断测试全部回归通过。

### 10.2 Unit 测试

- 品牌词归一化和匹配评分。
- `include` 解析和模块状态。
- 新 Presenter 的字段脱敏和快照回退。
- 严格品牌介绍结果分类。
- API Key 中间件配置开关和 Key 比较。

## 11. 上线步骤

1. 部署代码和独立配置项，保持旧 OpenAPI 配置不变。
2. 使用 Artisan 命令生成 lookup Key，并写入生产 Secret。
3. 设置 `BRAND_DIAGNOSIS_LOOKUP_API_ENABLED=true`，刷新配置缓存并重启应用/队列相关服务。
4. 向三方提供接口文档、Key 和服务端调用示例。
5. 观察 request ID、命中率、非存量生成率、Provider 失败率和限流日志。
6. 轮换 Key 时更新 Secret、刷新配置并通知三方切换。

## 12. 非目标

- 不自动完成非存量品牌的完整多模型诊断。
- 不把非存量预览结果写入品牌诊断历史。
- 不改变现有品牌诊断 OpenAPI 的 `X-Api-Key`。
- 不新增站点绑定、用户级 Token 或 MCP 权限模型。
- 不把该接口设计成无密钥的匿名公共服务。
