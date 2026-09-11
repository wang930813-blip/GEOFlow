# GEOFlow 本地项目与上游 v3.1.0 差异化升级清单

> 文档状态：分析清单（不代表已经执行升级）  
> 生成日期：2026-09-11  
> 适用原则：以当前本地项目为基座，仅选择性回迁上游能力，不执行整仓覆盖、强制合并或历史迁移重放。

## 1. 结论摘要

当前本地项目与 `yaojingang/GEOFlow` 已经形成较大程度的独立演进：

- 本地版本标识为 `2.0`，本地 HEAD 为 `2d27234`。
- 上游稳定分析基线为 `v3.1.0`，发布日期为 `2026-09-09`，对应 Core commit：
  `6c963783bbf49f0b5ec9d0121a924ee54005b60d`。
- 上游 `main` 仍可能包含未发布变更，本清单不把 `main` 的变化视为可直接升级内容。
- 以源码路径和文件哈希做只读比较（排除 `vendor`、`node_modules`、运行时缓存后）：
  - 共享文件：574 个；
  - 共享但内容不同：322 个；
  - 仅本地存在：639 个；
  - 仅上游存在：2,448 个。

因此，本项目不适合执行以下操作：

```text
git pull
git merge upstream/main
直接覆盖 app、resources、routes、database、config
直接替换 composer.lock、package-lock.json、docker-compose 文件
```

推荐采用：

```text
本地代码作为唯一基座
上游 v3.1.0 作为参考源
按模块建立回迁项
每次只回迁一个边界清晰的能力
回迁前后分别做迁移、接口、队列和页面回归
```

## 2. 对比基线

| 项目 | 当前本地项目 | 上游参考项目 |
|---|---|---|
| 仓库 | `wang930813-blip/GEOFlow` | `yaojingang/GEOFlow` |
| 当前分支/版本 | `feature/20260911-system-upgrade` / `2.0` | `v3.1.0` |
| 当前代码基线 | `2d27234` | `6c963783bbf49f0b5ec9d0121a924ee54005b60d` |
| 发布日期 | `2026-05-21` | `2026-09-09` |
| PHP 约束 | `^8.2` | `^8.3` |
| `laravel/ai` | `^0.6.0` | `^0.10.3` |
| 许可证标识 | Apache-2.0 | AGPL-3.0-only |
| 对比方式 | 本地已跟踪文件 | 上游 v3.1.0 源码快照 |

### 合规提醒

上游 v3.x 已将许可证标识调整为 AGPL-3.0-only，而本地项目仍保留 Apache-2.0 标识。后续如果把上游源码直接复制到对外发布的版本中，需要先进行许可证、版权声明和分发方式确认。技术上可以借鉴算法和接口设计，但不能忽略源码来源和许可证义务。

## 3. 本地功能保护边界

下列模块属于本地新增或明显二开功能，升级过程中默认“保护、不覆盖”：

| 本地模块 | 主要路径 | 保护要求 |
|---|---|---|
| 品牌诊断及查询开放 API | `app/Services/BrandDiagnosis/`、`app/Http/Controllers/Api/V1/BrandDiagnosis*`、`app/Models/BrandDiagnosis*` | 保留现有存量查询、非存量异步状态、模块筛选、模型区分、竞品数据和第三方 API 契约 |
| 品牌诊断后台 | `app/Http/Controllers/Admin/BrandDiagnosisController.php`、`resources/views/admin/brand-diagnosis/` | 不用上游页面或数据结构覆盖 |
| 监测中心 | `app/Services/MonitoringCenter/`、`app/Http/Controllers/Admin/MonitoringCenterController.php` | 保留行业竞争力分析报表和现有图表数据块 |
| 自媒体发布 | `app/Services/SelfMedia/`、`app/Models/SelfMedia*`、相关控制器和任务 | 保留现有发布、轮询、账号映射和状态链路 |
| CreBee / AiToEarn | `app/Services/Crebee/`、`app/Services/AiToEarn/`、`config/aitoearn.php` | 不用上游浏览器发布能力直接替换 |
| MCP | `app/Services/Mcp/`、`app/Http/Controllers/Api/V1/Mcp*` | 保留 MCP Token、权限范围和现有工具协议 |
| 视频生成 | `app/Services/VideoGeneration/`、`app/Models/VideoGenerationJob.php` | 保留本地视频任务和异步流程 |
| 多站点和域名 | `app/Models/Site.php`、`app/Support/Site/`、站点中间件 | 不直接引入上游 hosted site 结构覆盖本地站点模型 |
| 计费、套餐、额度 | `app/Services/Billing/`、相关模型和迁移 | 本地业务模型优先 |
| 产品案例 | `app/Services/ProductCases/`、相关后台页面 | 保留本地业务模块 |
| 科技模板及近期模板调整 | `resources/views/theme/`、站点首页/底部渲染逻辑 | 主题能力只能在现有模板体系上兼容增强 |

## 4. 上游升级候选总表

状态含义：

- `A`：建议第一批回迁，边界清晰、风险较低；
- `B`：有价值，但必须兼容适配后回迁；
- `C`：只借鉴设计或算法，暂不复制完整实现；
- `D`：当前不建议升级，容易改变本地架构或部署方式。

| 编号 | 上游能力 | 上游主要来源 | 本地关联范围 | 状态 | 风险 | 默认建议 |
|---|---|---|---|---|---|---|
| U-01 | AI/任务稳定性修复 | `app/Services/GeoFlow/`、相关测试和迁移 | 品牌诊断、任务队列、AI 调用 | A | 低 | 第一批回迁 |
| U-02 | AI 可见性、竞品检测、信源分析 | `app/Services/GeoFlow/AiVisibility/`、`app/Services/Admin/Analytics/` | 品牌诊断、监测中心、查询 API | B/C | 中高 | 借鉴逻辑，适配本地模型 |
| U-03 | 主题包导入、预览、安装、导出 | `SiteThemePackage*`、`SiteThemePreview*`、主题复制服务 | 本地主题、科技模板、多站点 | B | 中高 | 单独设计兼容层 |
| U-04 | 管理员 AI 模型隔离/共享 | `app/Services/Admin/AdminAi*`、`AiModel` 扩展 | 本地 AI 模型、品牌诊断模型选择 | B | 高 | 专项评估后再做 |
| U-05 | AI Workspace 对话建任务 | `app/Ai/Agents/`、`app/Ai/Workspace/` | 本地后台、任务、知识库 | C | 高 | 暂不纳入第一批 |
| U-06 | AI 质检、优化、三层检索、原子事实 | `ArticleAiQuality*`、`ArticleAiOptimization*`、`KnowledgeFacts/` | 文章生成、知识库、发布链路 | C | 很高 | 作为独立项目评估 |
| U-07 | CLI `bin/geoflow` | `bin/geoflow`、API v1 相关命令 | 本地开放 API、MCP API | B | 中 | 可作为外部工具单独接入 |
| U-08 | 浏览器发布助手/Manual Publication | `browser-extension/`、`BrowserOperations`、Manual Publication | CreBee、AiToEarn、自媒体发布 | C | 高 | 不直接合并 |
| U-09 | Updater 0.4.0、蓝绿部署、恢复 | `SystemUpdater`、`deploy-scripts/`、外部 updater | 本地 Docker 正式部署 | D | 很高 | 暂不升级 |
| U-10 | Admin UI V3、繁中语言、页面体验 | `resources/views/admin/`、`resources/js/`、`lang/` | 本地后台全部页面 | D/B | 高 | 只挑单项视觉修复 |
| U-11 | PHP/AI SDK/前端依赖升级 | `composer.json`、`composer.lock`、`package.json` | 全项目运行时 | D | 很高 | 单独做兼容矩阵 |
| U-12 | 上游数据库迁移和回填 | `database/migrations/`、命令行回填命令 | 本地全部业务表 | D | 很高 | 禁止批量复制 |
| U-13 | 上游部署文档、运维检查、测试方法 | `docs/deployment/`、runbook、scripts | 本地部署和发布流程 | A/B | 低中 | 可整理为本地运维文档 |

## 5. 第一批建议回迁：U-01 稳定性修复

这一批不改变本地业务模型，建议优先逐项检查并回迁。

### U-01a：Doubao 空域名过滤修复

- 上游参考：AI 可见性/搜索请求构造中对空 domain filter 的处理。
- 目标：避免请求携带空域名过滤器时导致搜索失败。
- 本地检查范围：
  - `app/Services/BrandDiagnosis/`
  - `app/Services/GeoFlow/`
  - AI 搜索和联网调用相关客户端
- 回迁方式：只移植请求参数清洗和空数组处理，不替换本地模型选择、提示词和异步流程。
- 验收：
  - 空域名配置不再触发 provider 请求错误；
  - 正常域名过滤行为不变；
  - ChatGPT、Grok 现有品牌诊断流程不变。

### U-01b：GLM / MiniMax 返回解析兼容

- 上游参考：AI 质量结果、文章输出和结构化响应解析。
- 目标：兼容模型返回 JSON、Markdown 包裹 JSON、普通文本列表等格式。
- 回迁方式：提取解析器或补充本地解析分支，不改动品牌诊断 API 返回字段。
- 验收：
  - 合法 JSON 继续正常解析；
- `json` 代码块包裹的内容可以解析；
  - 非结构化响应返回可定位错误，而不是 PHP 异常；
  - 现有 ChatGPT/Grok 调用结果不受影响。

### U-01c：MiniMax reasoning 文本清理

- 上游参考：文章生成结果处理。
- 目标：将模型的思考过程与最终正文分离，避免 reasoning 文本进入业务内容。
- 回迁方式：只在文章生成结果归一化层处理，不扩散到品牌诊断快照和对话快照。
- 验收：正文不出现 reasoning 标签、内部思考字段或调试文本。

### U-01d：PostgreSQL UUID lease 空字符串比较修复

- 上游参考：任务恢复、URL 导入、标题生成等 lease/recovery 服务。
- 目标：避免 PostgreSQL UUID 字段与空字符串比较产生数据库异常。
- 回迁方式：
  - 用 `whereNull`、有效 UUID 判断或应用层空值归一化；
  - 不修改本地已有迁移的历史内容；
  - 如果本地没有对应任务表，则只记录为不适用。
- 验收：
  - 队列任务恢复不触发 UUID 类型错误；
  - SQLite 测试路径仍通过；
  - 失效 lease、空 lease、有效 lease 三种状态均有测试。

### U-01e：任务恢复和 worker 健康检查

- 上游参考：恢复命令、worker heartbeat、队列超时和启动健康检查。
- 本地关联：品牌诊断非存量异步任务、媒体发布任务、视频生成任务。
- 回迁原则：先对照本地任务状态机，不直接复制上游命令。
- 验收：
  - 队列异常中断后可以进入明确的失败/可重试状态；
  - 不重复执行已完成的品牌诊断；
  - 任务状态查询 API 的状态语义不变。

## 6. 第二批建议评估：U-02 AI 可见性兼容到本地品牌诊断

这是最有业务价值、也最需要适配的一项。不能把上游 `AiVisibility` 当成本地品牌诊断的替代实现。

### 上游能力

上游主要包含：

- AI 回答采集；
- AI 信源整理；
- 竞品识别和竞品提及统计；
- 批量关键词采集；
- 结果归一化；
- 分模型执行和结果隔离。

主要参考路径：

```text
app/Services/GeoFlow/AiVisibility/
app/Services/Admin/Analytics/AiVisibilityAnalyticsService.php
app/Services/Admin/Analytics/AiVisibilityCompetitorDetectionService.php
app/Services/Admin/Analytics/AiVisibilityCompetitorReportService.php
app/Http/Controllers/Admin/AiVisibilityAnalyticsController.php
database/migrations/*ai_visibility*
database/migrations/*competitor*
```

### 本地对应能力

```text
app/Services/BrandDiagnosis/
app/Http/Controllers/Api/V1/BrandDiagnosisController.php
app/Http/Controllers/Api/V1/BrandDiagnosisLookupController.php
app/Models/BrandDiagnosis*
app/Jobs/ProcessBrandDiagnosisJob.php
app/Jobs/GenerateBrandDiagnosisQuestionsJob.php
app/Jobs/GenerateBrandDiagnosisLookupJob.php
app/Services/MonitoringCenter/
app/Http/Controllers/Admin/MonitoringCenterController.php
```

### 推荐兼容方案

1. 保留本地 `BrandDiagnosisRun`、结果快照、问题、信源和竞品数据结构。
2. 将上游竞品识别算法抽象为本地 `BrandDiagnosisCompetitorAnalyzer` 适配器。
3. 将上游信源归一化逻辑映射到本地 API 的“AI 信源”模块。
4. 将模型维度映射到当前本地支持的四种模型参数，不改变外部 API 字段。
5. 将竞品统计映射到已新增的 `competitor_visibility` 模块。
6. 对监测中心图 1、图 2 和行业竞争力报表，只复用统计规则，不复制上游控制器和页面。
7. 新增迁移时必须使用本地新的时间戳，且只创建本地缺失字段/索引。

### 不能直接做的事情

- 不能直接复制上游 `ai_visibility_runs` 表替换本地品牌诊断运行表。
- 不能直接复制上游控制器覆盖本地 `BrandDiagnosisLookupController`。
- 不能把上游模型权限解析器直接接到本地第三方公开 API。
- 不能改变当前“存量直接返回、非存量返回异步状态并通过状态接口查询”的契约。

### U-02 决策项

- [ ] 只回迁竞品识别算法；
- [ ] 回迁竞品识别 + 信源归一化；
- [ ] 回迁竞品识别 + 信源归一化 + 分模型统计；
- [ ] 暂不回迁，仅保留当前本地实现。

默认建议：先选择“竞品识别 + 信源归一化”，分模型统计在本地字段映射完成后再做。

## 7. 第三批候选：U-03 主题包能力

上游新增主题包导入、兼容性校验、隔离预览、安装、导出，并通过已安装主题目录保证升级后仍然存在。

主要参考路径：

```text
app/Services/Admin/SiteThemePackageGuard.php
app/Services/Admin/SiteThemePackageService.php
app/Support/Site/SiteThemePackageStorage.php
app/Support/Site/InstalledSiteThemeRepository.php
app/Http/Controllers/Admin/SiteThemePackageController.php
app/Http/Controllers/Admin/SiteThemePreviewController.php
app/Services/Admin/SiteThemeReplication/
docs/site-themes/theme-packages.md
```

本地已经存在站点主题、科技模板、官网备注/轮播图等定制逻辑，因此建议只做以下兼容版：

1. 增加主题包 manifest 和 Core 版本兼容检查；
2. 增加导入前解压路径、文件类型、目录穿越和大小校验；
3. 使用独立的已安装主题存储目录；
4. 预览使用隔离上下文，不写入当前站点配置；
5. 安装/激活继续走本地 `SiteSetting` 和站点上下文；
6. 导出时保留本地主题所需的轮播图、官网备注和资源引用声明。

不建议：

- 直接覆盖 `resources/views/theme/`；
- 直接覆盖本地 `SiteThemeCatalog`；
- 直接把上游主题复制/复制流水线接管本地模板；
- 在没有迁移兼容测试前启用主题包自动安装。

## 8. 暂不升级的大型模块

### U-04：管理员 AI 模型隔离/共享

上游新增模型所有者、共享范围、执行身份快照、用量归因、访问版本和历史回填。它会影响：

- `app/Models/AiModel.php`；
- 管理员模型配置页面；
- 任务、TaskRun、队列和恢复任务；
- 品牌诊断模型选择；
- API、CLI 和知识库 AI 调用。

本地已经存在 ChatGPT/Grok 品牌诊断模型策略和公开查询 API，建议先画出本地权限模型，再决定是否只回迁“模型可用性检查”这一小部分。

### U-05：AI Workspace

上游 `app/Ai/Agents/` 和 `app/Ai/Workspace/` 是完整的对话式任务创建系统，依赖大量表、事件、SSE、权限和系统知识。不能按几个控制器复制处理。

建议：暂不升级，等本地任务、知识库、AI 模型权限边界稳定后，另立专项。

### U-06：AI 质检/优化/三层检索/原子事实

该模块涉及大量迁移、任务队列、模型调用、结果失效、知识库版本、审计和回填。它与本地文章生成、发布、知识库及额度体系均有潜在冲突。

建议：只把“结果校验、超时处理、重试边界”等通用设计记录下来，不直接复制代码。

### U-08：浏览器发布助手

上游 `browser-extension` 与 `Manual Publication` 是一套新的浏览器设备授权与人工发布协议，本地已经有 CreBee、AiToEarn 和自媒体发布任务。两套系统目标相近但授权、账号、状态机不同。

建议：不直接合并；如后续确实需要浏览器人工发布，再评估把上游浏览器协议适配成 CreBee 的一个 provider。

### U-09：Updater/蓝绿部署

上游 Updater 0.4.0 不只是一个 Laravel 功能，而是包含独立宿主机组件、Unix socket、签名计划、备份、恢复、维护窗口和蓝绿拓扑的部署系统。

本地近期已经遇到 Docker 网络、iptables 和正式部署问题，当前应先稳定现有 Compose 部署。直接引入 Updater 会扩大部署故障面。

建议：暂不升级代码；只提取上游的备份、健康检查和升级验收清单，整理成本地运维 SOP。

## 9. 依赖、配置和迁移升级规则

### 9.1 Composer

上游与本地的关键差异：

```text
本地 PHP              ^8.2
上游 PHP              ^8.3
本地 laravel/ai       ^0.6.0
上游 laravel/ai       ^0.10.3
本地额外 tcpdf        ^6.10
```

规则：

- 不直接复制上游 `composer.lock`；
- 不在未确认生产 PHP 版本前将约束改为 `^8.3`；
- 先检查 `laravel/ai` API 破坏性变更；
- 保留本地 TCPDF 以及业务所需依赖；
- 依赖升级必须单独建兼容分支并跑完整测试。

### 9.2 前端依赖

上游新增或升级了 `cropperjs`、`dompurify`、`marked`、`qrcode`、`vditor` 等依赖，同时升级了 Echo/Pusher 版本。本地后台页面已经有大量定制，不能直接复制 `package-lock.json`。

规则：

- 只按实际功能新增依赖；
- 每次只升级一个依赖族；
- 先跑 `npm run build`；
- 检查后台页面、品牌诊断、主题预览和实时消息。

### 9.3 配置文件

以下文件都存在本地和上游差异，不可直接覆盖：

```text
.env.example
.env.prod.example
config/geoflow.php
config/queue.php
config/horizon.php
config/reverb.php
docker-compose.yml
docker-compose.prod.yml
deploy-scripts/*
```

应采用“逐键对照、缺项补充、已有项保留”的方式处理。

### 9.4 数据库迁移

禁止将上游迁移目录批量复制到本地。原因：

- 两边早期表结构可能已经不同；
- 本地存在品牌诊断、站点、MCP、CreBee、视频、套餐、监测中心等本地迁移；
- 同一业务表的字段、索引和外键可能已经被本地调整；
- 上游回填命令可能假设上游数据结构和权限模型。

允许的方式：

1. 先确认本地目标表和字段；
2. 为本地创建新的唯一时间戳迁移；
3. 使用 `Schema::hasTable` / `Schema::hasColumn` 等保护；
4. 为迁移提供升级前后断言；
5. 回填命令必须可重复执行、可中断、可观察。

## 10. 建议实施顺序

### 阶段 0：基线和安全保护

- [ ] 固定本地基座 commit；
- [ ] 固定上游 `v3.1.0` commit，不使用浮动 `main`；
- [ ] 备份数据库、`.env`、uploads、storage 和 Docker 配置；
- [ ] 记录当前队列、定时任务和品牌诊断异步任务状态；
- [ ] 为每一个回迁项建立独立分支；
- [ ] 记录当前测试基线。

### 阶段 1：安全修复

- [ ] U-01a Doubao 空域名过滤；
- [ ] U-01b GLM/MiniMax 解析兼容；
- [ ] U-01c MiniMax reasoning 清理；
- [ ] U-01d PostgreSQL UUID lease；
- [ ] U-01e 任务恢复和 worker 健康检查。

### 阶段 2：业务兼容

- [ ] U-02 竞品识别；
- [ ] U-02 信源归一化；
- [ ] U-02 分模型统计；
- [ ] U-03 主题包 manifest 和安全校验；
- [ ] U-03 隔离预览；
- [ ] U-07 CLI 外部工具。

### 阶段 3：专项评估

- [ ] U-04 管理员 AI 模型隔离/共享；
- [ ] U-05 AI Workspace；
- [ ] U-06 AI 质检和知识事实；
- [ ] U-08 浏览器发布助手。

### 阶段 4：部署体系另行立项

- [ ] U-09 Updater；
- [ ] 蓝绿部署；
- [ ] 完整备份和恢复；
- [ ] 宿主机签名验证和维护窗口。

## 11. 每个回迁项的验收门槛

只有同时满足以下条件，才认为某个上游能力可以进入本地：

1. 本地原有接口路径和响应字段不变；
2. 本地品牌诊断、自媒体、MCP、视频和站点功能回归通过；
3. 新迁移可以在全新数据库和现有数据库分别执行；
4. 队列任务支持失败、重试、恢复和幂等；
5. 生产依赖版本与 Docker 镜像兼容；
6. 关键日志不泄露 API Key、Token、密码和模型原始敏感配置；
7. 文档明确标注“已回迁能力”和“未回迁能力”；
8. 上游来源、许可证和版权声明已经完成确认。

## 12. 当前建议用户确认的选项

为了进入下一步，建议按编号确认：

### 第一优先级

- [ ] U-01：先回迁安全修复；
- [ ] U-02：把竞品识别和信源分析兼容到本地品牌诊断；
- [ ] U-13：整理上游部署/备份/健康检查文档。

### 第二优先级

- [ ] U-03：主题包导入、预览、导出；
- [ ] U-07：CLI 工具；
- [ ] U-10：只挑选页面体验或语言补丁。

### 暂不处理

- [ ] U-04：管理员 AI 模型隔离/共享；
- [ ] U-05：AI Workspace；
- [ ] U-06：AI 质检/优化；
- [ ] U-08：浏览器发布助手；
- [ ] U-09：Updater/蓝绿部署；
- [ ] U-11：整体依赖升级；
- [ ] U-12：上游迁移批量复制。

## 13. 本轮不涉及的文件

本分析不修改、不删除以下现有未跟踪文件：

```text
docs/*.docx
geo_flow
tools/crebee-bridge-agent.rar
.codex/tmp/
```

这些文件不属于本次上游差异化代码清单的升级范围。
