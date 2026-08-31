# 国际版品牌诊断模型分流实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将品牌诊断流程切换为国际版模型入口，外部可选模型仅保留 ChatGPT / Grok / Gemini；保持品牌诊断的业务内容、结果结构、报表口径、状态流转和 API 返回形状不变。

**Architecture:** 品牌诊断保留现有“品牌词 -> 品牌介绍 -> 核心词 -> 候选问题 -> 正式诊断 -> 汇总展示”的业务链路，但把“调用哪个模型”从国内四模型硬编码改成国际版 provider 层。`BrandDiagnosisPlatform` 只负责平台目录、展示文案和验证，真实请求由 provider / factory 负责；品牌介绍、核心词提取和诊断回答都走同一套国际 provider 适配器，不再继续依赖 `DoubaoBrandDiagnosisClient` 这类国内命名的实现。`OpenAiRuntimeProvider` 不作为这次改造的主入口，避免把品牌诊断和其他 AI 流程耦合在一起。

**Tech Stack:** Laravel Controller / FormRequest / Queue Job / Eloquent / Blade / Http Fake tests / markdown docs.

---

## 文件结构

- 修改：`app/Services/BrandDiagnosis/BrandDiagnosisPlatform.php`
  - 国际版平台目录、标签、官方链接、白名单域名、默认平台、图标映射。
- 修改：`config/brand_diagnosis.php`
  - 国际版 provider 配置、默认平台、能力开关、官方链接白名单。
- 新建：`app/Services/BrandDiagnosis/Contracts/BrandDiagnosisProvider.php`
  - 统一品牌介绍、核心词、问题、问答的 provider 接口。
- 新建：`app/Services/BrandDiagnosis/BrandDiagnosisProviderFactory.php`
  - 按平台 key 解析 provider。
- 新建：`app/Services/BrandDiagnosis/BrandDiagnosisPromptBuilder.php`
  - 统一保存现有品牌诊断 prompt 文本，避免三个 provider 各写一份。
- 新建：`app/Services/BrandDiagnosis/Providers/OpenAiBrandDiagnosisProvider.php`
- 新建：`app/Services/BrandDiagnosis/Providers/GrokBrandDiagnosisProvider.php`
- 新建：`app/Services/BrandDiagnosis/Providers/GeminiBrandDiagnosisProvider.php`
- 修改或删除：`app/Services/BrandDiagnosis/DoubaoBrandDiagnosisClient.php`
  - 迁移完成后删除，或只保留过渡期内部工具，不能再暴露为主实现。
- 修改：`app/Services/BrandDiagnosis/BrandProfileResolver.php`
- 修改：`app/Jobs/GenerateBrandDiagnosisQuestionsJob.php`
- 修改：`app/Jobs/ProcessBrandDiagnosisJob.php`
- 修改：`app/Services/BrandDiagnosis/BrandDiagnosisRunService.php`
- 修改：`app/Services/BrandDiagnosis/BrandDiagnosisMentionBackfillService.php`
- 修改：`app/Services/BrandDiagnosis/McpBrandDiagnosisService.php`
- 修改：`app/Http/Controllers/Admin/BrandDiagnosisController.php`
- 修改：`app/Http/Controllers/Admin/BrandDiagnosisOfficialLinkController.php`
- 修改：`app/Http/Requests/Admin/UpdateBrandDiagnosisOfficialLinksRequest.php`
- 修改：`app/Http/Controllers/Admin/SnapshotVoucherController.php`
- 修改：`app/Http/Controllers/BrandDiagnosisSnapshotController.php`
- 修改：`app/Services/BrandDiagnosis/BrandDiagnosisPdfService.php`
- 修改：`app/Services/MonitoringCenter/MonitoringReportDataService.php`
- 修改：`app/Http/Requests/Api/V1/StoreBrandDiagnosisRequest.php`
- 修改：`app/Http/Requests/Api/V1/StoreMcpBrandDiagnosisRequest.php`
- 修改：`app/Http/Controllers/Api/V1/McpBrandDiagnosisController.php`
- 修改：`docs/api/brand-diagnosis.md`
- 修改：`docs/mcp-server.md`
- 修改：`resources/skills/ceying-geo-content-operations/references/brand-diagnosis.md`
- 新增测试：`tests/Unit/BrandDiagnosisPlatformTest.php`
- 新增测试：`tests/Unit/BrandDiagnosisProviderFactoryTest.php`
- 新增测试：`tests/Feature/BrandDiagnosisInternationalFlowTest.php`
- 新增测试：`tests/Feature/BrandDiagnosisInternationalAdminUiTest.php`

---

### Task 1: 收口国际版平台目录与配置

**Files:**
- Modify: `app/Services/BrandDiagnosis/BrandDiagnosisPlatform.php`
- Modify: `config/brand_diagnosis.php`
- Modify: `app/Http/Requests/Api/V1/StoreBrandDiagnosisRequest.php`
- Modify: `app/Http/Requests/Api/V1/StoreMcpBrandDiagnosisRequest.php`
- Modify: `app/Http/Controllers/Admin/BrandDiagnosisController.php`
- Modify: `app/Http/Controllers/Admin/BrandDiagnosisOfficialLinkController.php`
- Modify: `app/Http/Requests/Admin/UpdateBrandDiagnosisOfficialLinksRequest.php`
- Modify: `app/Http/Controllers/Admin/SnapshotVoucherController.php`
- Modify: `app/Http/Controllers/BrandDiagnosisSnapshotController.php`
- Modify: `app/Services/BrandDiagnosis/BrandDiagnosisPdfService.php`
- Modify: `app/Services/MonitoringCenter/MonitoringReportDataService.php`
- Modify: `docs/api/brand-diagnosis.md`
- Modify: `docs/mcp-server.md`
- Modify: `resources/skills/ceying-geo-content-operations/references/brand-diagnosis.md`
- Test: `tests/Unit/BrandDiagnosisPlatformTest.php`

- [ ] **Step 1: 先写平台收口测试**
  - 新增断言：国际版只允许 `chatgpt`、`grok`、`gemini`。
  - 新增断言：`doubao`、`deepseek`、`qianwen`、`wenxin` 在请求校验里全部拒绝。
  - 新增断言：平台标签、聊天入口、官方域名白名单都来自国际版配置。

- [ ] **Step 2: 收口平台枚举和默认值**
  - 把 `BrandDiagnosisPlatform::keys()`、`validationRule()`、`label()`、`chatUrl()`、`officialShareDomains()`、`isOfficialShareUrl()` 改成国际版目录。
  - 增加配置驱动的默认平台，不再硬编码 `doubao` 作为 fallback。
  - `config/brand_diagnosis.php` 增加每个平台的 `base_url`、`api_key`、`model`、`enabled`、`supports_web_search`、`official_share_domains`、`chat_url`。

- [ ] **Step 3: 改掉所有表单和后台文案里的国内模型名**
  - `BrandDiagnosisController::models()` 改为国际版三模型。
  - `StoreBrandDiagnosisRequest`、`StoreMcpBrandDiagnosisRequest`、`UpdateBrandDiagnosisOfficialLinksRequest` 的报错文案改成国际版模型名。
  - `BrandDiagnosisOfficialLinkController`、`SnapshotVoucherController`、`BrandDiagnosisSnapshotController`、`BrandDiagnosisPdfService`、`MonitoringReportDataService` 只保留国际版展示文案。

- [ ] **Step 4: 同步文档和技能引用**
  - `docs/api/brand-diagnosis.md`、`docs/mcp-server.md`、技能参考文档中的模型列表改成国际版三模型。
  - 文档示例里的 `models`、`platform`、错误提示、白名单域名全部同步改掉。

- [ ] **Step 5: 跑这一轮基础校验**
  - `docker compose exec app php artisan test tests/Unit/BrandDiagnosisPlatformTest.php`
  - `docker compose exec app php artisan test tests/Feature/BrandDiagnosisInternationalAdminUiTest.php`
  - `docker compose exec app php -l app/Services/BrandDiagnosis/BrandDiagnosisPlatform.php`
  - `docker compose exec app php -l app/Http/Controllers/Admin/BrandDiagnosisController.php`
  - `docker compose exec app php -l app/Http/Requests/Api/V1/StoreBrandDiagnosisRequest.php`

---

### Task 2: 增加国际版 provider 抽象层

**Files:**
- New: `app/Services/BrandDiagnosis/Contracts/BrandDiagnosisProvider.php`
- New: `app/Services/BrandDiagnosis/BrandDiagnosisProviderFactory.php`
- New: `app/Services/BrandDiagnosis/BrandDiagnosisPromptBuilder.php`
- New: `app/Services/BrandDiagnosis/Providers/OpenAiBrandDiagnosisProvider.php`
- New: `app/Services/BrandDiagnosis/Providers/GrokBrandDiagnosisProvider.php`
- New: `app/Services/BrandDiagnosis/Providers/GeminiBrandDiagnosisProvider.php`
- Modify or delete: `app/Services/BrandDiagnosis/DoubaoBrandDiagnosisClient.php`
- Test: `tests/Unit/BrandDiagnosisProviderFactoryTest.php`
- Test: `tests/Unit/BrandDiagnosisProviderPayloadTest.php`

- [ ] **Step 1: 先把 provider 选择规则写成测试**
  - 用测试把“平台 key -> provider class”的映射固定下来。
  - 用测试把“OpenAI / Grok 走 Responses 风格，Gemini 走 generateContent 风格”固定下来。
  - 用测试把 provider 的统一返回结构固定为：`text`、`sources`、`raw_response`、`platform`、`usage`。

- [ ] **Step 2: 把现有 prompt 文本抽出来**
  - 现有品牌介绍、核心词、问题生成、问答、品牌提及抽取的 prompt 文本统一移动到 `BrandDiagnosisPromptBuilder`。
  - prompt 语义保持不变，只删除国内模型的示例和平台说明。
  - 不要把 prompt 分散到三个 provider 里重复维护。

- [ ] **Step 3: 实现三个 provider**
  - `OpenAiBrandDiagnosisProvider`：按 OpenAI 官方文本生成流程发请求。
  - `GrokBrandDiagnosisProvider`：按 xAI 文本生成流程发请求。
  - `GeminiBrandDiagnosisProvider`：按 Gemini REST 文本生成流程发请求。
  - 三个 provider 都必须返回统一的诊断结果结构，避免上层 job 和 controller 再区分平台细节。

- [ ] **Step 4: 保留一次性入口，不要改上层业务形状**
  - 上层还是只拿到 `BrandDiagnosisProviderFactory`，不直接 new 某个厂商 client。
  - 业务层不关心 SDK、endpoint、鉴权头名，只关心 `brand profile`、`core terms`、`questions`、`answer`。
  - `OpenAiRuntimeProvider` 不接入这条链路，避免把其他 AI 功能带进来。

- [ ] **Step 5: 跑 provider 单测和 lint**
  - `docker compose exec app php artisan test tests/Unit/BrandDiagnosisProviderFactoryTest.php`
  - `docker compose exec app php artisan test tests/Unit/BrandDiagnosisProviderPayloadTest.php`
  - `docker compose exec app php -l app/Services/BrandDiagnosis/BrandDiagnosisProviderFactory.php`
  - `docker compose exec app php -l app/Services/BrandDiagnosis/Providers/OpenAiBrandDiagnosisProvider.php`
  - `docker compose exec app php -l app/Services/BrandDiagnosis/Providers/GrokBrandDiagnosisProvider.php`
  - `docker compose exec app php -l app/Services/BrandDiagnosis/Providers/GeminiBrandDiagnosisProvider.php`

---

### Task 3: 改造品牌介绍、核心词和问题生成链路

**Files:**
- Modify: `app/Services/BrandDiagnosis/BrandProfileResolver.php`
- Modify: `app/Jobs/GenerateBrandDiagnosisQuestionsJob.php`
- Modify: `app/Services/BrandDiagnosis/BrandDiagnosisRunService.php`
- Modify: `app/Services/BrandDiagnosis/BrandDiagnosisMentionBackfillService.php`
- Modify: `app/Services/BrandDiagnosis/McpBrandDiagnosisService.php`
- Test: `tests/Feature/BrandDiagnosisInternationalFlowTest.php`

- [ ] **Step 1: 先写链路级测试**
  - 品牌介绍查询必须走所选 provider，而不是固定的国内 client。
  - 核心词提取必须基于品牌介绍结果，不允许再直接编造。
  - 问题生成必须保持现有题量、题型和过滤逻辑，但模型入口要换成国际版 provider。

- [ ] **Step 2: 把品牌介绍解析改成 provider 驱动**
  - `BrandProfileResolver` 不再返回硬编码 `doubao`。
  - 选择规则：优先用 run 里选中的平台里第一个可用且支持搜索的 provider；如果都不可用，再走配置里的默认平台一次。
  - 失败时只保留一次明确报错，不要继续把所有平台都轮一遍。

- [ ] **Step 3: 把核心词和问题生成接到同一个 provider**
  - `GenerateBrandDiagnosisQuestionsJob` 用同一个 provider 完成品牌介绍、核心词提炼、问题池生成。
  - 现有 `question_count`、`question_candidate_multiplier`、过滤规则保持不变。
  - `brand_profile_meta.core_terms_model` 改为真实 provider key。

- [ ] **Step 4: 修正复用逻辑**
  - `BrandDiagnosisRunService::latestReusableQuestionRun()` 不要再按 Doubao 标签过滤。
  - 复用判断只看当前品牌、当前站点和有效的成功记录，不要把国际版 run 排除在外。
  - `normalizePlatforms()` 的默认值改成国际版配置，不再回落到国内模型。
  - `BrandDiagnosisMentionBackfillService` 和 `McpBrandDiagnosisService` 也要改成 provider 驱动，不能继续直接依赖旧的国内 client。

- [ ] **Step 5: 验证这条链路**
  - `docker compose exec app php artisan test tests/Feature/BrandDiagnosisInternationalFlowTest.php --filter=profile`
  - `docker compose exec app php artisan test tests/Feature/BrandDiagnosisInternationalFlowTest.php --filter=question_generation`
  - `docker compose exec app php artisan test tests/Feature/BrandDiagnosisInternationalFlowTest.php --filter=reuse`
  - `docker compose exec app php -l app/Services/BrandDiagnosis/BrandProfileResolver.php`
  - `docker compose exec app php -l app/Jobs/GenerateBrandDiagnosisQuestionsJob.php`
  - `docker compose exec app php -l app/Services/BrandDiagnosis/BrandDiagnosisRunService.php`

---

### Task 4: 改造正式诊断执行和结果归一化

**Files:**
- Modify: `app/Jobs/ProcessBrandDiagnosisJob.php`
- Modify: `app/Services/BrandDiagnosis/BrandDiagnosisSnapshotPayload.php`
- Modify: `app/Services/BrandDiagnosis/BrandDiagnosisPdfService.php`
- Modify: `app/Services/BrandDiagnosis/BrandDiagnosisMentionBackfillService.php`
- Test: `tests/Feature/BrandDiagnosisInternationalFlowTest.php`

- [ ] **Step 1: 先补执行链路测试**
  - 每个题目 × 每个平台仍然要生成一条结果记录。
  - 单个平台失败不能影响其他平台结果落库。
  - 完成态、失败态、快照 token、PDF 输出都保持原结构。

- [ ] **Step 2: 把诊断调用改成 provider 驱动**
  - `ProcessBrandDiagnosisJob` 通过 provider factory 选择对应平台的实现。
  - 不再依赖 `DoubaoBrandDiagnosisClient::ask()`。
  - 平台 key 仅作为路由参数，不再代表国内厂商名。

- [ ] **Step 3: 保持结果结构和评分逻辑不变**
  - `BrandDiagnosisMetricsCalculator` 的评分、提及率、排名、情绪分布不改。
  - `BrandDiagnosisSnapshotPayload` 继续输出同一份展示结构。
  - `BrandDiagnosisPdfService` 继续用现有报告格式，只替换平台文案和图标来源。

- [ ] **Step 4: 统一失败语义**
  - 某个平台或某次请求失败时，只在该平台结果里写失败原因。
  - 不能把 provider 失败扩散成整场诊断结构改变。
  - 完成态仍然按现有规则计算，失败原因只作为辅助信息。

- [ ] **Step 5: 跑执行链路验证**
  - `docker compose exec app php artisan test tests/Feature/BrandDiagnosisInternationalFlowTest.php --filter=execution`
  - `docker compose exec app php artisan test tests/Feature/BrandDiagnosisInternationalFlowTest.php --filter=pdf`
  - `docker compose exec app php -l app/Jobs/ProcessBrandDiagnosisJob.php`
  - `docker compose exec app php -l app/Services/BrandDiagnosis/BrandDiagnosisPdfService.php`

---

### Task 5: 清理后台、OpenAPI、监测中心和快照展示

**Files:**
- Modify: `app/Http/Controllers/Admin/BrandDiagnosisController.php`
- Modify: `app/Http/Controllers/Admin/BrandDiagnosisOfficialLinkController.php`
- Modify: `app/Http/Controllers/Admin/SnapshotVoucherController.php`
- Modify: `app/Http/Controllers/BrandDiagnosisSnapshotController.php`
- Modify: `app/Services/MonitoringCenter/MonitoringReportDataService.php`
- Modify: `app/Http/Requests/Admin/UpdateBrandDiagnosisOfficialLinksRequest.php`
- Modify: `app/Http/Controllers/Api/V1/McpBrandDiagnosisController.php`
- Modify: `app/Http/Requests/Api/V1/StoreMcpBrandDiagnosisRequest.php`
- Modify: `resources/views/admin/brand-diagnosis/index.blade.php`
- Modify: `resources/views/admin/brand-diagnosis/open-api.blade.php`
- Modify: `resources/views/admin/brand-diagnosis/report.blade.php`
- Modify: `resources/views/admin/partials/header.blade.php`
- Test: `tests/Feature/BrandDiagnosisInternationalAdminUiTest.php`

- [ ] **Step 1: 先写 UI 和 API 可见性测试**
  - 后台品牌诊断页面只展示国际版三模型。
  - OpenAPI 诊断记录页、报告页、快照页、官方链接管理页都不能再出现国内模型名。
  - MCP 请求校验也只能接受国际版模型 key。

- [ ] **Step 2: 更新后台页面和开放 API 页**
  - `BrandDiagnosisController` 的模型卡片、筛选按钮、提示文案都改成国际版。
  - `open-api` 页面不再出现国内模型按钮或说明。
  - 侧边栏 / 顶部菜单如果出现模型名，也要同步替换。
  - `McpBrandDiagnosisController` 和 `StoreMcpBrandDiagnosisRequest` 的模型说明改成国际版三模型。

- [ ] **Step 3: 更新官方链接和快照展示**
  - `UpdateBrandDiagnosisOfficialLinksRequest` 的域名白名单改成国际版官方域名。
  - `BrandDiagnosisSnapshotController` 和 `SnapshotVoucherController` 的平台跳转文案改成国际版。
  - 手动设置的官方链接保留，但校验规则要按新平台域名判断。

- [ ] **Step 4: 确认监测中心的共享展示不会泄漏旧模型名**
  - `MonitoringReportDataService` 里凡是借用了品牌诊断平台映射的地方都要跟着换。
  - 如果某些报表模板是静态写死的平台名，也同步切到国际版文案。
  - 不改报表计算公式，只改显示层和平台名称。

- [ ] **Step 5: 跑页面和接口验证**
  - `docker compose exec app php artisan test tests/Feature/BrandDiagnosisInternationalAdminUiTest.php`
  - `docker compose exec app php artisan test tests/Feature/BrandDiagnosisApiTest.php`
  - `docker compose exec app php artisan test tests/Feature/AdminBrandDiagnosisPageTest.php`
  - `docker compose exec app php artisan route:list --path=brand-diagnosis`

---

### Task 6: 同步文档、技能参考和 MCP 说明

**Files:**
- Modify: `docs/api/brand-diagnosis.md`
- Modify: `docs/mcp-server.md`
- Modify: `resources/skills/ceying-geo-content-operations/references/brand-diagnosis.md`

- [ ] **Step 1: 先写文档校验**
  - 文档里的请求示例、返回示例、支持模型列表、错误码说明都只保留国际版三模型。
  - 不再出现 `doubao / deepseek / qianwen / wenxin`。
  - 任务 ID、开放 API、品牌诊断状态流转都保持和代码一致。

- [ ] **Step 2: 更新对外文档**
  - `docs/api/brand-diagnosis.md` 改成国际版模型文档。
  - `docs/mcp-server.md` 的品牌诊断模型列表同步改掉。
  - 技能参考文档改成国际版说明，避免后续 agent 再把国内模型写回去。

- [ ] **Step 3: 跑文档 diff 检查**
  - `git diff --check docs/api/brand-diagnosis.md docs/mcp-server.md resources/skills/ceying-geo-content-operations/references/brand-diagnosis.md`

---

### Task 7: 最终回归和上线前检查

**Files:**
- All changed brand diagnosis files

- [ ] **Step 1: 跑核心回归**
  - `docker compose exec app php artisan test tests/Unit/BrandDiagnosisPlatformTest.php`
  - `docker compose exec app php artisan test tests/Unit/BrandDiagnosisProviderFactoryTest.php`
  - `docker compose exec app php artisan test tests/Feature/BrandDiagnosisInternationalFlowTest.php`
  - `docker compose exec app php artisan test tests/Feature/BrandDiagnosisInternationalAdminUiTest.php`

- [ ] **Step 2: 跑语法检查**
  - `docker compose exec app php -l app/Services/BrandDiagnosis/BrandDiagnosisPlatform.php`
  - `docker compose exec app php -l app/Services/BrandDiagnosis/BrandDiagnosisProviderFactory.php`
  - `docker compose exec app php -l app/Services/BrandDiagnosis/BrandProfileResolver.php`
  - `docker compose exec app php -l app/Jobs/GenerateBrandDiagnosisQuestionsJob.php`
  - `docker compose exec app php -l app/Jobs/ProcessBrandDiagnosisJob.php`
  - `docker compose exec app php -l app/Http/Controllers/Admin/BrandDiagnosisController.php`
  - `docker compose exec app php -l app/Http/Controllers/Admin/BrandDiagnosisOfficialLinkController.php`

- [ ] **Step 3: 跑 diff 和人工检查**
  - `git diff --check`
  - 手工确认后台、API 文档、快照页、报告页只出现 ChatGPT / Grok / Gemini。
  - 手工确认没有改动品牌诊断结果字段、计算方式和报表结构。

- [ ] **Step 4: 提交**
  - `git add <changed-files>`
  - `git commit -m "feat: route brand diagnosis to international providers"`

---

## 约束

- 不改品牌诊断结果 schema，不改评分公式，不改报告结构。
- 不新增数据库迁移，现有表结构可以承载新平台 key。
- 不把品牌诊断逻辑塞进 `OpenAiRuntimeProvider`。
- 不再把国内模型名留在国际版 UI、API、MCP 文档和技能参考里。
