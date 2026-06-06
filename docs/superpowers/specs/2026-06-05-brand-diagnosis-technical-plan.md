# 品牌诊断/报告技术实施方案

## 1. 目标

在后台新增可真实运行的“品牌诊断/报告”能力。管理员输入品牌名称，选择平台后，系统向对应大模型或联网检索能力发起诊断请求，沉淀回答、引用来源、品牌提及、排名、情感倾向等数据，并生成可下载的 PDF 报告。

品牌诊断首版平台以现有 GEO 报表三平台为基础，额外增加文心一言：

- 豆包：`doubao`
- 千问：`qianwen`
- DeepSeek：`deepseek`
- 文心一言：`wenxin`

元宝首版不接入，因为现有 GEO 报表平台集合也没有元宝。后续如果 GEO 报表增加元宝，再统一扩展平台目录和客户端。

说明：GEO 报表现有检测链路暂不改，仍保持 `doubao/qianwen/deepseek`。文心一言只加入品牌诊断模块首版平台集合；如果后续要让 GEO 报表也支持文心一言，需要单独扩展 `KeywordLibraryController::normalizePlatforms()` 和 `ChatAiSearchPlatformChecker` 的真实平台实现。

## 2. 现有 GEO 报表逻辑梳理

### 2.1 数据入口

GEO 报表数据不是直接在报表页采集，而是来自关键词库详情页的“收录检测”。

主要链路：

1. 管理员在关键词库中维护项目/品牌信息、关键词和问题变体。
2. 在关键词库详情页发起收录检测。
3. `KeywordLibraryController::storeInclusionCheck()` 读取全部问题变体。
4. 平台集合通过 `KeywordLibraryController::normalizePlatforms()` 归一化。
5. 当前允许的平台固定为 `doubao`、`qianwen`、`deepseek`。
6. 系统创建 `geo_inclusion_check_runs` 检测批次。
7. 每个“问题 x 平台”投递一个 `ProcessGeoInclusionCheckJob` 到 `geoflow` 队列。

### 2.2 检测执行

`ProcessGeoInclusionCheckJob` 执行时：

1. 加载检测批次、关键词、关键词库、问题变体。
2. 调用 `AiSearchPlatformChecker` 接口。
3. 当前实现是 `ChatAiSearchPlatformChecker`。
4. 检测成功后写入 `geo_inclusion_check_results`。
5. 每完成一条结果，更新批次进度和状态。
6. 通过 `KeywordLibraryInclusionUpdated` 事件尽力推送实时进度。

### 2.3 ChatAiSearchPlatformChecker 的真实能力边界

`ChatAiSearchPlatformChecker` 会真实调用系统里配置的一个 active chat AI model，但它不是分别调用豆包、千问、DeepSeek 的真实搜索平台。

当前实现方式：

1. 从 `ai_models` 选择一个启用的 chat 模型。
2. 解密模型 API Key。
3. 使用 `OpenAiRuntimeProvider` 注册运行时 provider。
4. 使用 `MarkdownContentWriterAgent` 发起一次普通聊天生成。
5. Prompt 中加入 `Platform role: doubao/qianwen/deepseek`，让模型“模拟该平台口吻”回答。
6. 对返回文本做字符串包含判断：
   - 回答包含关键词：`keyword_hit = true`
   - 回答包含品牌名：`brand_hit = true`
7. 不解析引用来源。
8. 不计算提及排名。
9. 不做情感倾向分析。

结论：现有 GEO 报表是“基于一个真实聊天模型的模拟平台检测”，不是“真实豆包/千问/DeepSeek 平台联网引用采集”。

### 2.4 GEO 报表聚合

`GeoReportQueryService` 只读取 `geo_inclusion_check_results` 做聚合。

现有指标包括：

- 项目数：`keyword_libraries` 数量
- 关键词数：`keywords` 数量
- 检测次数：`geo_inclusion_check_results` 数量
- 关键词命中率：`keyword_hit / checks`
- 品牌命中率：`brand_hit / checks`
- 平台分布：按 `platform` 聚合检测数、关键词命中数、品牌命中数
- 关键词排行：按关键词聚合命中表现
- 项目排行：按关键词库聚合命中表现
- 近 7 天趋势：按 `checked_at` 日期聚合

## 3. 品牌诊断首版产品范围

### 3.1 用户流程

1. 管理员进入“品牌诊断/报告”页面。
2. 输入品牌名称。
3. 选择平台：豆包、千问、DeepSeek、文心一言。
4. 点击搜索/开始诊断。
5. 系统生成固定问题集合。
6. 系统按“平台 x 问题”异步执行诊断。
7. 页面展示进度、核心指标、AI 问题、引用来源、AI 对话记录。
8. 诊断完成后可下载 PDF 报告。

诊断记录规则：

- 每点击一次“搜索/开始诊断”，都必须新建一条 `brand_diagnosis_runs` 诊断记录。
- 即使品牌名称、平台集合、问题集合完全相同，也不能覆盖上一条诊断记录。
- 同一个品牌可以存在多条历史诊断记录，按创建时间倒序展示。
- 每条诊断记录的详情、来源、回答和 PDF 都通过 `run_id` 绑定，历史报告可重复查看和下载。
- 只有管理员明确执行删除操作时，才允许删除历史诊断记录；首版可先不做删除功能。

诊断次数与费用控制规则：

- 品牌诊断会产生大模型 API 调用费用，创建诊断前必须先做使用资格校验。
- 超级管理员不受每日次数限制。
- 普通用户首版每天只能创建 1 次品牌诊断，不按品牌名、不按平台分别计算；同一天内第二次点击“搜索/开始诊断”时应阻止创建 run，并提示“普通用户每天只能进行 1 次品牌诊断”。
- “每天”按系统业务时区计算，默认使用 `config('app.timezone')`，当前生产建议保持 `Asia/Shanghai`。
- 每日限制按 `site_id + admin_id + usage_date` 维度统计，避免不同站点数据互相影响。
- 只有成功创建的 `brand_diagnosis_runs` 才计入当日使用次数；表单校验失败、平台未选择等未创建 run 的请求不计入。
- 已创建 run 后即计入当天使用次数，即使后续平台 API 失败、部分失败或 PDF 失败，也不回退次数；避免用户重复消耗外部平台请求。
- 首版不做积分扣减，但必须预留积分抵扣兼容能力：后续如果用户积分足够并选择积分支付，可按“每次诊断消耗 N 积分”创建诊断，此时不再受每日 1 次限制。
- 积分抵扣后续版本实现前，所有普通用户创建 run 的 `billing_mode` 统一记为 `daily_free`；超级管理员记为 `admin_unlimited`；未来积分抵扣记为 `points`。
- 后续积分抵扣必须使用事务和幂等交易记录，先扣减/冻结积分，再创建诊断 run，避免并发重复诊断或重复扣积分。

### 3.2 首版问题集合

首版先使用固定模板，不做复杂 AI 自动扩展，保证结果可控：

1. `{品牌名} 是什么？主要解决什么问题？`
2. `{品牌名} 在所属行业里有哪些优势和不足？`
3. `选择 {品牌名} 这类服务或产品时应该重点看哪些评价、案例和来源？`
4. `{品牌名} 和同类品牌相比，适合哪些企业或场景？`
5. `有哪些公开资料、文章或报道提到了 {品牌名}？`

后续可扩展为：

- 支持自定义问题。
- 结合关键词库行业字段生成行业化问题。
- 结合 GEO 报表表现自动生成“弱项补强问题”。

### 3.3 核心指标定义

所有指标只基于成功完成的诊断结果计算，失败请求不进入分母，但会在报告里展示失败数。

#### 品牌提及率

公式：

```text
品牌提及率 = 提及目标品牌的成功回答数 / 成功回答总数 * 100
```

#### 品牌提及次数

公式：

```text
品牌提及次数 = 所有成功回答中目标品牌出现次数总和
```

目标品牌匹配需要做基础归一化：

- 去除首尾空白。
- 中英文大小写不敏感。
- 支持配置别名，首版可先不开放 UI，在后端预留 `brand_aliases` JSON 字段。

#### 平均提及排名

公式：

```text
平均提及排名 = 目标品牌在推荐/列表型回答中的首次排名平均值
```

识别规则：

- 回答中出现 `1.`、`2.`、`第一`、`TOP 1` 等列表结构时，提取目标品牌所在条目的序号。
- 无法识别排名时，`first_mention_rank = null`。
- 计算平均值时只统计非空排名。
- 全部无排名时页面显示“暂无排名”，报告中解释为“回答有提及但未形成明确推荐顺序”。

#### 正面/中性情感倾向

公式：

```text
正面/中性情感倾向 = 正面或中性的目标品牌提及数 / 目标品牌提及总数 * 100
```

首版分类：

- `positive`
- `neutral`
- `negative`
- `mixed`
- `unknown`

`positive` 和 `neutral` 计入正面/中性。`mixed`、`negative`、`unknown` 不计入。

#### 品牌得分

建议首版使用 100 分制，便于解释：

```text
品牌得分 =
  品牌提及率 * 45%
  + 排名得分 * 25%
  + 提及次数得分 * 15%
  + 正面/中性情感得分 * 15%
```

排名得分：

```text
排名得分 = max(0, (11 - 平均提及排名) / 10 * 100)
```

提及次数得分：

```text
提及次数得分 = min(100, 品牌提及次数 / (成功回答数 * 2) * 100)
```

如果没有可识别排名，排名得分按 0 计，但报告里要明确说明原因。

## 4. 真实平台接入方案

### 4.1 总原则

1. 不抓取豆包、千问、DeepSeek、文心一言的网页端或 App。
2. 不用浏览器自动化模拟用户登录。
3. 只使用官方 API 或明确可合规使用的联网检索 API。
4. 只有 API 响应里结构化返回的来源，才记为“已验证引用来源”。
5. 模型回答正文里自己编出来的“参考来源”不能作为真实来源落库。
6. DeepSeek 如果不能返回原生搜索引用，必须标注为“DeepSeek 生成 + 外部检索增强来源”，不能写成“DeepSeek 原生引用”。

### 4.1.1 模拟兜底模式

允许增加一个可配置兜底策略：当真实平台 API 因余额不足、调用次数不足、限流、鉴权失败、超时或服务异常导致诊断失败时，可切换到 GEO 当前启用的聊天模型，按现有 GEO 报表的方式模拟对应平台进行回答和指标计算。

兜底原则：

- 兜底默认关闭，由后台平台配置显式开启。
- 兜底只用于保证诊断流程不中断，不用于替代真实平台数据。
- 兜底结果必须标记为 `fallback_mode = simulated_chat`。
- 兜底结果的 `source_mode` 必须为 `simulated_fallback`，不能标记为 `native_citation`。
- 兜底结果不能生成真实引用来源，只能保存模型回答文本。
- 页面、详情和 PDF 必须明确展示“模拟兜底结果”，避免用户误认为是豆包、千问、文心一言或 DeepSeek 的真实平台返回。
- 如果真实平台失败且未开启兜底，则该平台该问题记录为 failed。

建议触发兜底的错误类型：

- API Key 未配置或解密失败。
- 鉴权失败，例如 401、403。
- 余额不足、欠费、调用次数不足。
- 限流，例如 429。
- 请求超时或连接失败。
- 平台返回空回答。
- 平台返回非 JSON 或结构异常。

兜底调用方式：

1. 复用现有 GEO 报表的 active chat model 解析逻辑。
2. Prompt 加入目标平台角色，例如 `Platform role: doubao/qianwen/deepseek/wenxin`。
3. 要求模型“像该平台回答”，但不要求编造引用。
4. 保存回答后继续执行品牌提及、提及次数、排名和情感分析。
5. 引用来源列表展示为空，并显示“该条为模拟兜底，无真实引用来源”。

兜底结果参与指标的建议：

- 默认参与“兜底版指标”，但不参与“真实来源指标”。
- 页面指标区建议拆分或标注：
  - `真实平台结果`
  - `包含兜底结果`
- 首版如果只展示一套指标，必须在指标旁标注“包含模拟兜底数据”。

### 4.2 豆包

推荐接入：

- 火山方舟 Ark Responses API
- `tools` 中启用 `web_search`

参考官方文档：

- `https://www.volcengine.com/docs/82379/1756990`

请求方向：

```json
{
  "model": "从 brand_diagnosis_platform_settings.model_id 读取",
  "input": [
    {
      "role": "user",
      "content": "品牌诊断问题"
    }
  ],
  "tools": [
    {
      "type": "web_search",
      "limit": 10
    }
  ]
}
```

需要落库的数据：

- 回答正文。
- 工具调用状态。
- 搜索结果标题。
- 搜索结果 URL。
- 搜索结果摘要。
- 回答中的引用标记或 annotations。
- 原始响应 JSON，保存到 `raw_meta` 便于后续排查。

### 4.3 千问

推荐接入：

- 阿里百炼 DashScope 调用方式。
- 启用 `enable_search`。
- `search_options.enable_source = true`。
- `search_options.enable_citation = true`。
- 必要时 `forced_search = true`，确保诊断请求会触发搜索。

参考官方文档：

- `https://help.aliyun.com/zh/model-studio/web-search/`
- `https://help.aliyun.com/zh/model-studio/qwen-api-via-dashscope`

请求方向：

```json
{
  "model": "qwen-plus",
  "input": {
    "messages": [
      {
        "role": "user",
        "content": "品牌诊断问题"
      }
    ]
  },
  "parameters": {
    "enable_search": true,
    "search_options": {
      "forced_search": true,
      "enable_source": true,
      "enable_citation": true,
      "citation_format": "[<number>]",
      "search_strategy": "turbo"
    },
    "result_format": "message"
  }
}
```

需要注意：

- 引用角标和搜索来源能力以 DashScope 原生调用为准。
- 如果走 OpenAI 兼容模式，部分来源字段可能拿不到，不作为首选方案。

### 4.4 DeepSeek

DeepSeek 首版不承诺“原生联网引用”。原因：

- 当前项目已有 DeepSeek OpenAI-compatible Chat Completion 路径。
- 普通 Chat Completion 可以生成回答，但不稳定提供类似豆包/千问的标准 citation/source 字段。
- 如果后续官方 API 明确开放稳定 Web Search 引用字段，再新增原生 DeepSeek 来源客户端。

首版推荐实现分两种路径，优先使用路径 A：

路径 A：火山方舟托管 DeepSeek，减少 API Key 数量。

1. 使用火山方舟 API Key 调用方舟上架的 DeepSeek 模型。
2. 模型 ID 从配置读取，候选值：
   - `deepseek-v4-pro-260425`
   - `deepseek-v4-flash-260425`
3. 注意是 `flash`，不是 `flush`。
4. 使用火山方舟 Web Search 获取真实检索结果，或在 Responses API 中启用 Web Search 工具。
5. 将检索结果作为上下文传给 DeepSeek 模型生成诊断回答。
6. 页面和报告里标注：

```text
DeepSeek 生成，provider：火山方舟，来源类型：火山方舟 Web Search / 外部检索增强
```

这条路径的好处：

- 不需要单独准备 DeepSeek 官方 API Key。
- 豆包和 DeepSeek 可共用 `ARK_API_KEY`。
- 检索来源来自火山方舟 Web Search，属于真实检索来源，不是模拟兜底。

这条路径的限制：

- 不能写成“DeepSeek 官方原生联网引用”。
- `platform` 仍是 `deepseek`，但 `provider` 应保存为 `volcengine_ark`。
- `source_mode` 建议保存为 `external_retrieval`，`raw_meta` 中记录 `search_provider = volcengine_ark_web_search`。

路径 B：DeepSeek 官方 API + 外部检索增强。

1. 使用 DeepSeek 官方 API 生成诊断回答。
2. 在调用 DeepSeek 前，先通过 `SearchProvider` 获取真实检索结果。
3. 将检索结果作为上下文传给 DeepSeek。
4. 要求 DeepSeek 只能基于这些来源回答。
5. 页面和报告里标注：

```text
DeepSeek 生成，来源类型：外部检索增强
```

可选检索来源：

- 火山方舟 Web Search 的搜索结果。
- 阿里百炼联网搜索返回的搜索结果。
- 后续单独接入合规搜索 API。

参考官方入口：

- `https://api-docs.deepseek.com/zh-cn/`
- `https://api-docs.deepseek.com/guides/function_calling`

### 4.5 文心一言

推荐接入：

- 百度智能云千帆。
- 优先使用可返回网页引用和角标的 AI 搜索类 API，而不是普通文心大模型对话接口。
- 具体能力可选：
  - 千帆 `AISearch`，适合直接问答并返回引用来源。
  - 千帆 `AISearchLoc`，适合需要智能搜索生成能力的场景。

参考官方文档：

- `https://cloud.baidu.com/doc/qianfan/s/0mh4stqhc`
- `https://cloud.baidu.com/doc/qianfan/s/Kmh4sutww`

请求方向：

```json
{
  "messages": [
    {
      "role": "user",
      "content": "品牌诊断问题"
    }
  ],
  "search_source": "baidu_search",
  "enable_citation": true
}
```

需要落库的数据：

- 回答正文。
- 网页引用角标。
- 搜索结果标题。
- 搜索结果 URL。
- 搜索结果摘要。
- 百度搜索或智能搜索生成返回的原始引用字段。
- 原始响应 JSON，保存到 `raw_meta`。

注意：

- 如果调用的是普通 ERNIE/Wenxin Chat Completion，不应宣称拿到了文心一言原生引用。
- 只有 AI 搜索 API 结构化返回的网页引用才记为 `native_citation`。
- 如果千帆账号没有开通对应 AI 搜索能力，文心一言平台应在配置页标记为未启用或连接失败。
- 具体使用 `AISearch`、`AISearchLoc`、MCP-SSE 组件，还是千帆直接 API，需要在实施前以当前账号可用的官方控制台和 API 文档再次确认。

## 5. 数据库设计

### 5.1 brand_diagnosis_runs

诊断批次表。

记录策略：

- 该表是品牌诊断历史记录主表。
- 创建诊断时必须使用 `create()` 新增记录，不能按品牌名使用 `updateOrCreate()` 覆盖旧记录。
- `normalized_brand_name` 只用于搜索、筛选和聚合，不作为唯一约束。
- 不建立 `site_id + normalized_brand_name` 唯一索引，因为同一站点同一品牌需要保留多次诊断历史。

字段建议：

- `id`
- `site_id`：站点隔离，nullable，跟随当前后台站点上下文。
- `admin_id`：发起人，nullable。
- `brand_name`
- `normalized_brand_name`
- `brand_aliases`：JSON，预留。
- `platforms`：JSON，例如 `["doubao","qianwen","deepseek","wenxin"]`。
- `status`：`pending/running/completed/completed_with_errors/failed/cancelled`。
- `question_count`
- `total_checks`
- `completed_checks`
- `failed_checks`
- `brand_score`
- `mention_rate`
- `mention_count`
- `average_rank`
- `positive_neutral_rate`
- `usage_date`：普通用户每日限制统计日期，按业务时区写入 `YYYY-MM-DD`。
- `billing_mode`：`daily_free/admin_unlimited/points/manual_adjustment`；首版只写入 `daily_free` 或 `admin_unlimited`，为后续积分抵扣预留。
- `points_cost`：本次诊断消耗积分，首版默认 `0`。
- `points_transaction_id`：后续积分流水 ID，首版为 `null`。
- `limit_bypassed`：是否绕过每日限制，超级管理员为 `true`。
- `limit_bypass_reason`：`super_admin/points/admin_manual/null`；首版超级管理员写 `super_admin`，普通用户写 `null`。
- `started_at`
- `completed_at`
- `error_message`
- `meta`：JSON。
- `created_at`
- `updated_at`

索引：

- `site_id, created_at`
- `status, created_at`
- `normalized_brand_name, created_at`
- `site_id, admin_id, usage_date, billing_mode`

每日限制实现建议：

- 不建议只依赖应用层 `count()` 判断，否则并发点击可能创建多条 run。
- 首版推荐在创建 run 时使用数据库事务，并在事务中对当前用户当天的使用计数做锁定校验。
- 如果项目当前没有独立 usage 表，首版可以通过 `brand_diagnosis_runs` 查询 `billing_mode = daily_free` 且 `usage_date = today` 的记录数。
- 更稳的扩展方案是新增 `brand_diagnosis_usage_counters` 表，用 `site_id + admin_id + usage_date` 唯一索引做原子计数；后续积分抵扣也可以共用该表记录免费次数和积分次数。
- 积分抵扣后续实现时，`billing_mode = points` 的 run 不计入每日 1 次限制，但要写入 `points_cost` 和 `points_transaction_id`。

### 5.2 brand_diagnosis_questions

诊断问题表。

字段建议：

- `id`
- `run_id`
- `sequence`
- `question`
- `question_type`：`definition/comparison/source/recommendation/scenario`
- `intent`
- `created_at`
- `updated_at`

索引：

- `run_id, sequence`

### 5.3 brand_diagnosis_results

单个平台单个问题的回答结果。

字段建议：

- `id`
- `run_id`
- `question_id`
- `site_id`
- `platform`
- `provider`
- `model_id`
- `question`
- `answer`
- `status`：`pending/running/success/failed`
- `error_message`
- `brand_mentioned`
- `mention_count`
- `first_mention_rank`
- `sentiment`：`positive/neutral/negative/mixed/unknown`
- `sentiment_confidence`
- `source_mode`：`native_citation/external_retrieval/model_text_only/simulated_fallback/none`
- `fallback_mode`：`none/simulated_chat`
- `fallback_reason`：兜底触发原因，例如 `api_key_missing/auth_failed/quota_exhausted/rate_limited/timeout/empty_response/invalid_response/provider_error`
- `real_platform_failed`：真实平台是否先失败后进入兜底。
- `real_platform_error_code`：真实平台失败状态码或内部错误类型，需脱敏。
- `real_platform_error_message`：真实平台失败说明，需脱敏，不保存 API Key、请求头和完整密钥。
- `fallback_provider`：兜底实际调用的聊天模型 provider，非兜底时为空。
- `fallback_model_id`：兜底实际调用的聊天模型 ID，非兜底时为空。
- `checked_at`
- `raw_meta`：JSON。
- `created_at`
- `updated_at`

唯一索引：

- `run_id, question_id, platform`

普通索引：

- `site_id, platform, checked_at`
- `run_id, status`
- `run_id, fallback_mode`
- `platform, checked_at`

### 5.4 brand_diagnosis_sources

引用来源表。

字段建议：

- `id`
- `result_id`
- `run_id`
- `platform`
- `source_mode`
- `source_rank`
- `citation_marker`
- `title`
- `url`
- `domain`
- `snippet`
- `site_name`
- `published_at`
- `raw`
- `created_at`
- `updated_at`

索引：

- `run_id, platform`
- `result_id, source_rank`
- `domain`

URL 处理规则：

- 只展示 `http` 和 `https` URL。
- 展示时使用 Blade 转义。
- 不在首版主动抓取 URL 内容，避免 SSRF 和网络稳定性问题。

### 5.5 brand_diagnosis_mentions

品牌提及明细表。

字段建议：

- `id`
- `result_id`
- `run_id`
- `brand_name`
- `is_target`
- `mention_count`
- `first_rank`
- `sentiment`
- `confidence`
- `evidence`
- `created_at`
- `updated_at`

用途：

- 支持目标品牌指标。
- 支持后续竞品榜单。
- 支持报告里展开“为什么判定为正面/中性/负面”。

### 5.6 brand_diagnosis_reports

报告表。

字段建议：

- `id`
- `run_id`
- `status`：`pending/generated/failed`
- `title`
- `summary`
- `metrics`：JSON。
- `content_html`
- `pdf_disk`
- `pdf_path`
- `generated_at`
- `error_message`
- `created_at`
- `updated_at`

说明：

- 报告内容先生成 HTML。
- PDF 生成成功后写入 `pdf_disk/pdf_path`。
- 如果 PDF 生成失败，页面仍可展示 HTML 报告，并提示 PDF 失败原因。

### 5.7 brand_diagnosis_platform_settings

平台配置表，避免复用 `ai_models` 造成平台语义混乱。

配置来源策略：

- 开发环境可以直接从 Laravel 配置文件读取 API Key，便于本地联调。
- 生产环境优先从 `brand_diagnosis_platform_settings` 读取加密后的 API Key。
- 代码中不得直接调用 `env()`，统一通过 `config('brand-diagnosis.platforms')` 或平台配置解析服务读取。
- 如果数据库平台配置不存在，非生产环境可回退到配置文件；生产环境不建议自动回退明文配置，避免误把临时 Key 当正式配置。

建议新增配置文件：

```text
config/brand-diagnosis.php
```

开发环境配置示例：

```php
return [
    'platforms' => [
        'doubao' => [
            'provider' => 'volcengine_ark',
            'api_base_url' => env('BRAND_DIAGNOSIS_ARK_BASE_URL', 'https://ark.cn-beijing.volces.com/api/v3'),
            'api_key' => env('BRAND_DIAGNOSIS_ARK_API_KEY'),
            'model_id' => env('BRAND_DIAGNOSIS_DOUBAO_MODEL_ID'),
            'search_strategy' => 'web_search',
        ],
        'deepseek' => [
            'provider' => env('BRAND_DIAGNOSIS_DEEPSEEK_PROVIDER', 'volcengine_ark'),
            'api_base_url' => env('BRAND_DIAGNOSIS_ARK_BASE_URL', 'https://ark.cn-beijing.volces.com/api/v3'),
            'api_key' => env('BRAND_DIAGNOSIS_ARK_API_KEY'),
            'model_id' => env('BRAND_DIAGNOSIS_DEEPSEEK_MODEL_ID', 'deepseek-v4-flash-260425'),
            'search_strategy' => 'ark_web_search_external_retrieval',
        ],
        'qianwen' => [
            'provider' => 'dashscope',
            'api_key' => env('BRAND_DIAGNOSIS_DASHSCOPE_API_KEY'),
            'model_id' => env('BRAND_DIAGNOSIS_QWEN_MODEL_ID', 'qwen-plus'),
            'search_strategy' => 'dashscope_search',
        ],
        'wenxin' => [
            'provider' => 'baidu_qianfan',
            'api_key' => env('BRAND_DIAGNOSIS_QIANFAN_API_KEY'),
            'model_id' => env('BRAND_DIAGNOSIS_WENXIN_MODEL_ID'),
            'search_strategy' => 'qianfan_ai_search',
        ],
    ],
];
```

建议 `.env.example` 预留：

```dotenv
BRAND_DIAGNOSIS_ARK_BASE_URL=https://ark.cn-beijing.volces.com/api/v3
BRAND_DIAGNOSIS_ARK_API_KEY=
BRAND_DIAGNOSIS_DOUBAO_MODEL_ID=
BRAND_DIAGNOSIS_DEEPSEEK_PROVIDER=volcengine_ark
BRAND_DIAGNOSIS_DEEPSEEK_MODEL_ID=deepseek-v4-flash-260425
BRAND_DIAGNOSIS_DASHSCOPE_API_KEY=
BRAND_DIAGNOSIS_QWEN_MODEL_ID=qwen-plus
BRAND_DIAGNOSIS_QIANFAN_API_KEY=
BRAND_DIAGNOSIS_WENXIN_MODEL_ID=
```

字段建议：

- `id`
- `site_id`
- `platform`
- `display_name`
- `api_base_url`
- `model_id`
- `api_key_ciphertext`
- `search_strategy`
- `timeout_seconds`
- `connect_timeout_seconds`
- `fallback_enabled`：是否允许真实平台失败后切到 GEO 当前聊天模型模拟，默认 `false`。
- `fallback_on_errors`：JSON 数组，允许触发兜底的错误类型，建议默认 `["quota_exhausted", "rate_limited", "timeout", "provider_error"]`。
- `fallback_model_strategy`：`geo_active_chat_model`，首版只支持复用 GEO 当前启用聊天模型。
- `status`
- `meta`
- `created_at`
- `updated_at`

安全要求：

- API Key 使用现有 `ApiKeyCrypto` 加密。
- 列表页只显示脱敏 Key。
- 日志里不记录 Key。
- 兜底开关必须按平台单独配置，不能全局默认开启。
- 配置页需要说明：开启兜底后，该平台失败时会生成“模拟兜底结果”，没有真实引用来源。

## 6. 服务拆分

### 6.1 控制器和请求

建议新增或扩展：

- `app/Http/Controllers/Admin/BrandDiagnosisController.php`
- `app/Http/Requests/Admin/StoreBrandDiagnosisRunRequest.php`
- `app/Http/Requests/Admin/UpdateBrandDiagnosisPlatformSettingRequest.php`

路由建议：

- `GET admin/brand-diagnosis`：列表和搜索页。
- `POST admin/brand-diagnosis/runs`：创建诊断批次。
- `GET admin/brand-diagnosis/runs/{run}`：查看诊断详情。
- `GET admin/brand-diagnosis/runs/{run}/snapshot`：轮询进度。
- `GET admin/brand-diagnosis/runs/{run}/report`：HTML 报告。
- `GET admin/brand-diagnosis/runs/{run}/report.pdf`：下载 PDF。
- `GET admin/brand-diagnosis/settings`：平台配置。
- `POST admin/brand-diagnosis/settings`：保存平台配置。

### 6.2 核心服务

建议新增目录：

```text
app/Services/BrandDiagnosis
```

核心类：

- `BrandDiagnosisPlatform`
  - 平台常量、标签、首版允许平台。
- `BrandDiagnosisRunService`
  - 每次诊断请求都创建新的 run、生成问题、投递任务；禁止复用或覆盖同品牌旧 run。
- `BrandDiagnosisQuestionService`
  - 根据品牌名生成首版固定问题。
- `BrandDiagnosisPlatformClient`
  - 平台客户端接口。
- `DoubaoArkWebSearchClient`
  - 火山方舟豆包联网搜索。
- `QwenDashScopeWebSearchClient`
  - DashScope 千问联网搜索。
- `DeepSeekRetrievalEnhancedClient`
  - DeepSeek + 外部检索增强。
- `WenxinQianfanAiSearchClient`
  - 百度千帆 AI 搜索，接入文心一言相关搜索生成能力。
- `SimulatedPlatformFallbackClient`
  - 真实平台调用失败且配置允许兜底时，复用 GEO 当前 active chat model，按 `Platform role` 模拟目标平台回答；返回 `fallback_mode = simulated_chat` 和 `source_mode = simulated_fallback`。
- `BrandDiagnosisFallbackPolicy`
  - 根据平台配置和异常类型判断是否允许兜底，避免在 job 中硬编码错误分支。
- `SearchProvider`
  - DeepSeek 需要的外部检索来源接口。
- `BrandDiagnosisSourceNormalizer`
  - 归一化不同平台返回的 source 字段。
- `BrandDiagnosisMentionAnalyzer`
  - 提及次数、排名、情感倾向分析。
- `BrandDiagnosisScoreCalculator`
  - 汇总指标和 100 分得分。
- `BrandDiagnosisReportQueryService`
  - 给页面和报告读取聚合数据。
- `BrandDiagnosisPdfService`
  - 根据 Blade 报告模板生成 PDF。

### 6.3 队列任务

建议新增：

- `app/Jobs/ProcessBrandDiagnosisCheckJob.php`
- `app/Jobs/GenerateBrandDiagnosisReportJob.php`

队列：

- 首版可继续使用 `geoflow`，减少生产配置改动。
- 如果后续诊断量大，再独立为 `brand-diagnosis` 队列。

任务策略：

- HTTP 请求设置 `connectTimeout` 和 `timeout`。
- 使用 `tries = 2` 或 `tries = 3`。
- 使用退避：`[10, 30, 60]`。
- 单个任务失败只标记该结果失败，不影响整个批次继续执行。
- 所有结果完成后，批次状态为：
  - 全成功：`completed`
  - 部分失败：`completed_with_errors`
  - 全失败：`failed`

## 7. 平台响应标准化

所有客户端最终返回统一 DTO：

```php
BrandDiagnosisPlatformResponse {
    platform: string
    provider: string
    modelId: string
    answer: string
    sources: array
    sourceMode: string
    fallbackMode: string
    fallbackReason: string|null
    realPlatformFailed: bool
    realPlatformErrorCode: string|null
    realPlatformErrorMessage: string|null
    raw: array
}
```

source 统一字段：

```php
BrandDiagnosisSourceData {
    title: string
    url: string
    domain: string
    snippet: string
    siteName: string
    citationMarker: string|null
    sourceRank: int|null
    publishedAt: Carbon|null
    raw: array
}
```

source_mode 定义：

- `native_citation`：平台 API 原生返回 citation/source。
- `external_retrieval`：外部检索结果作为上下文传给模型。
- `model_text_only`：只有模型回答文本，没有可信结构化来源。
- `simulated_fallback`：真实平台失败后使用 GEO 当前聊天模型模拟目标平台回答，没有真实引用来源。
- `none`：没有来源。

首版验收标准：

- 豆包成功时应尽量为 `native_citation`。
- 千问成功时应尽量为 `native_citation`。
- DeepSeek 首版默认是 `external_retrieval`。
- 文心一言成功时应尽量为 `native_citation`。
- 兜底成功时必须为 `simulated_fallback`，且 `sources` 必须为空。

## 8. 分析逻辑

### 8.1 品牌提及

先做确定性规则：

1. 目标品牌和别名统一转小写。
2. 中文品牌直接 `mb_stripos`。
3. 英文品牌按单词边界辅助判断，避免短词误匹配。
4. 统计出现次数。

### 8.2 排名识别

优先做规则解析：

- Markdown 有序列表。
- 中文序号：`第一`、`第二`。
- 常见排行标记：`TOP 1`、`No.1`。
- 表格第一列为排名时识别。

无法确定时不强行猜测，保存 `null`。

### 8.3 情感倾向

首版建议使用“规则 + AI 结构化判定”：

1. 如果回答没有提及目标品牌，情感为 `unknown`。
2. 如果回答包含明显负向词，可先标记候选 negative。
3. 调用一个内部分析器，让模型只输出 JSON：

```json
{
  "sentiment": "positive",
  "confidence": 0.82,
  "evidence": "回答中描述品牌优势、适用场景和案例"
}
```

4. JSON 解析失败时回退 `unknown`。

注意：情感分析模型只用于“解释回答内容”，不能补充来源，也不能生成未返回的引用。

## 9. UI 实施方案

现有静态页面：

- `resources/views/admin/brand-diagnosis/index.blade.php`
- `app/Http/Controllers/Admin/BrandDiagnosisController.php`

首版改造要求：

1. 移除元宝模型卡片和所有元宝下拉选项。
2. 保留当前后台视觉风格：白底、浅灰边框、橙色主按钮、紧凑信息卡。
3. 顶部搜索区改为真实表单：
   - 品牌名称 input。
   - 平台 checkbox：豆包、千问、DeepSeek、文心一言。
   - 开始诊断 button。
4. 新增历史诊断列表：
   - 品牌名。
   - 平台集合。
   - 状态。
   - 进度。
   - 创建时间。
   - 查看报告。
   - 同品牌多次诊断时显示多条记录，不合并、不覆盖。
5. 详情页展示：
   - 核心指标卡。
   - 按平台筛选。
   - AI 问题列表。
   - 每个平台每个问题的回答。
   - 引用来源列表。
   - 失败原因。
   - 真实平台失败后兜底成功的结果，必须显示“模拟兜底结果”标签。
   - 兜底结果的引用来源区域显示“该条为模拟兜底，无真实引用来源”，不能展示模型正文里编出来的来源。
   - PDF 下载按钮。
6. 指标区如果混入兜底结果，必须显示“包含模拟兜底数据”；如果同时展示真实指标和兜底指标，两个指标组要分开命名。
7. 运行中状态使用轮询 snapshot 接口，避免一开始引入复杂实时依赖。

## 10. PDF 报告方案

当前项目没有 PDF 依赖。文章导出 Word 是 HTML 兼容 Word 的方式，不能满足“PDF形式”的需求。

推荐实施：

1. 增加 `barryvdh/laravel-dompdf`。
2. 在 Docker 镜像中准备中文字体，例如 Noto Sans CJK SC。
3. 新增 PDF 专用 Blade 模板：
   - `resources/views/admin/brand-diagnosis/report-pdf.blade.php`
4. PDF 模板使用简单 CSS，避免复杂 flex/grid。
5. 生成后保存到 `storage/app/private/brand-diagnosis-reports/{run_id}.pdf`。
6. 下载时通过受保护路由读取，不公开 storage 路径。
7. PDF 中对兜底结果单独标注“模拟兜底结果”，并写明“无真实引用来源”。
8. PDF 指标如果包含兜底数据，指标标题或说明必须展示“包含模拟兜底数据”。

如果 PDF 字体在生产 Docker 中不可用，中文可能乱码或缺字。实施前需要确认生产镜像字体，或在 Dockerfile 中安装字体。

## 11. 权限和安全

1. 所有页面必须走现有 `admin.auth`、`admin.site`、`admin.activity` 中间件。
2. 普通管理员只能看当前站点数据。
3. 超级管理员根据现有站点上下文看对应站点数据。
4. 创建诊断前必须调用 `BrandDiagnosisUsagePolicy` 或同等服务做额度校验，不能只在前端按钮禁用。
5. 超级管理员跳过每日 1 次限制，但 run 中必须记录 `billing_mode = admin_unlimited` 和 `limit_bypassed = true`。
6. 普通用户首版每天只能创建 1 次诊断，限制维度为 `site_id + admin_id + usage_date`。
7. 普通用户达到每日限制时返回可读错误，不创建 `brand_diagnosis_runs`，不投递队列 job，不调用任何外部平台 API。
8. 后续积分抵扣功能预留 `billing_mode = points`、`points_cost`、`points_transaction_id`；首版不扣积分、不展示积分支付入口。
9. 后续积分抵扣启用后，积分足够并成功扣减/冻结的诊断不受每日 1 次限制，但必须有积分流水幂等记录。
10. 平台 API Key 只在服务端保存和调用。
11. API Key 使用 `ApiKeyCrypto` 加密。
12. 表单使用 Laravel CSRF。
13. Blade 输出使用 `{{ }}` 转义。
14. 外部 URL 只作为链接展示，不在首版抓取正文。
15. 日志不得写入完整请求头、API Key、原始密钥。
16. 平台失败信息要面向管理员可读，但避免暴露敏感响应。

## 12. 错误处理

页面需要区分这些情况：

- 普通用户当天已使用 1 次免费品牌诊断。
- 额度校验服务异常或并发锁获取失败。
- 未配置平台 API Key。
- 平台 API Key 解密失败。
- 平台 API 返回鉴权失败。
- 平台 API 超时。
- 平台 API 限流。
- 平台返回空回答。
- 平台返回回答但没有来源。
- 平台失败且未开启兜底。
- 平台失败但已启用兜底，最终生成模拟结果。
- DeepSeek 外部检索失败。
- PDF 生成失败。

批次级处理：

- 额度不足属于创建前错误，不创建批次，不进入平台调用流程。
- 每日次数一旦成功创建 run 即视为已使用，不因平台失败或用户关闭页面而回退。
- 一个结果失败，不中断其他结果。
- 如果真实平台失败且允许兜底，先记录真实平台失败原因，再调用 `SimulatedPlatformFallbackClient`。
- 兜底成功时该 result 可记为 `success`，但 `fallback_mode` 必须为 `simulated_chat`。
- 兜底也失败时该 result 记为 `failed`，`error_message` 展示真实平台失败和兜底失败的脱敏摘要。
- 部分失败时状态为 `completed_with_errors`。
- 页面指标按成功结果计算，同时展示失败数量。
- PDF 报告里附带失败平台和失败问题。

## 13. 测试方案

### 13.1 单元测试

建议新增：

- `tests/Unit/BrandDiagnosisQuestionServiceTest.php`
- `tests/Unit/BrandDiagnosisSourceNormalizerTest.php`
- `tests/Unit/BrandDiagnosisMentionAnalyzerTest.php`
- `tests/Unit/BrandDiagnosisScoreCalculatorTest.php`
- `tests/Unit/BrandDiagnosisFallbackPolicyTest.php`
- `tests/Unit/BrandDiagnosisUsagePolicyTest.php`

覆盖：

- 平台集合只允许豆包、千问、DeepSeek、文心一言。
- 品牌问题生成稳定。
- 豆包响应 source 归一化。
- 千问 `search_info.search_results` 归一化。
- DeepSeek external retrieval source 标记正确。
- 文心一言千帆 AI 搜索响应 source 归一化。
- `BrandDiagnosisFallbackPolicy` 在未开启兜底时拒绝所有兜底。
- `BrandDiagnosisFallbackPolicy` 在余额不足、限流、超时等配置允许的错误类型上返回允许兜底。
- `SimulatedPlatformFallbackClient` 返回 `fallback_mode = simulated_chat`、`source_mode = simulated_fallback`，且 `sources = []`。
- 品牌提及次数计算。
- 平均排名计算。
- 情感结果解析失败时回退 unknown。
- 品牌得分公式。
- 超级管理员不受每日诊断次数限制。
- 普通用户当天没有诊断记录时允许创建。
- 普通用户当天已有 `billing_mode = daily_free` 的诊断记录时拒绝创建。
- 普通用户昨天的诊断记录不影响今天创建。
- `billing_mode = points` 的历史记录不计入每日免费次数限制，为后续积分抵扣预留。
- 额度校验使用业务时区计算 `usage_date`。

### 13.2 Feature 测试

建议新增：

- `tests/Feature/AdminBrandDiagnosisFlowTest.php`
- `tests/Feature/AdminBrandDiagnosisSettingsTest.php`
- `tests/Feature/AdminBrandDiagnosisReportTest.php`

覆盖：

- 页面不展示元宝。
- 创建诊断批次会生成 run/questions/results jobs。
- 连续两次用同一品牌和同一平台创建诊断，会生成两条不同的 run 记录。
- 新诊断完成后不会覆盖旧 run 的 metrics、results、sources、report。
- 普通用户第一次创建诊断成功，并写入 `billing_mode = daily_free`、`usage_date`、`points_cost = 0`。
- 普通用户同一天第二次创建诊断会被拒绝，不创建 run，不投递 jobs，不调用外部 API。
- 超级管理员同一天可以创建多次诊断，并写入 `billing_mode = admin_unlimited`、`limit_bypassed = true`。
- 普通用户使用后续预留的 `billing_mode = points` 创建路径时，不受每日 1 次限制；首版该路径只做服务层兼容测试，不开放 UI 入口。
- 未选择平台时默认使用四平台。
- 非法平台会被过滤。
- snapshot 返回进度。
- 详情页展示来源和回答。
- 真实平台余额不足且平台开启兜底时，会创建成功 result，保存 `real_platform_failed = true` 和 `fallback_mode = simulated_chat`。
- 真实平台余额不足但平台未开启兜底时，该 result 记录为 failed。
- 兜底 result 的 sources 表不新增记录。
- 详情页和 PDF 对兜底 result 展示“模拟兜底结果”标签。
- 指标包含兜底 result 时展示“包含模拟兜底数据”。
- PDF 路由未完成时返回可读错误。
- 站点隔离。

### 13.3 外部 API 测试策略

自动化测试不请求真实平台。

使用：

- `Http::fake()` 模拟豆包、千问、DeepSeek、文心一言响应。
- fixture JSON 保存典型响应。
- `Http::preventStrayRequests()` 防止测试误打真实接口。

本地运行命令：

```bash
docker exec geoflow-app php artisan test tests/Unit/BrandDiagnosisQuestionServiceTest.php
docker exec geoflow-app php artisan test tests/Unit/BrandDiagnosisSourceNormalizerTest.php
docker exec geoflow-app php artisan test tests/Unit/BrandDiagnosisMentionAnalyzerTest.php
docker exec geoflow-app php artisan test tests/Unit/BrandDiagnosisScoreCalculatorTest.php
docker exec geoflow-app php artisan test tests/Feature/AdminBrandDiagnosisFlowTest.php
docker exec geoflow-app php artisan test tests/Feature/AdminBrandDiagnosisSettingsTest.php
docker exec geoflow-app php artisan test tests/Feature/AdminBrandDiagnosisReportTest.php
```

## 14. 分阶段实施顺序

### 阶段 1：平台口径和静态页修正

目标：

- 品牌诊断页面只展示豆包、千问、DeepSeek、文心一言。
- 元宝从首版 UI 和后端数据源移除。

涉及文件：

- `app/Http/Controllers/Admin/BrandDiagnosisController.php`
- `resources/views/admin/brand-diagnosis/index.blade.php`

### 阶段 2：数据库和模型

目标：

- 增加品牌诊断相关表。
- 增加 Eloquent 模型和关系。
- `brand_diagnosis_runs` 不对品牌名做唯一约束，支持同一品牌多次诊断历史。

新增模型：

- `BrandDiagnosisRun`
- `BrandDiagnosisQuestion`
- `BrandDiagnosisResult`
- `BrandDiagnosisSource`
- `BrandDiagnosisMention`
- `BrandDiagnosisReport`
- `BrandDiagnosisPlatformSetting`

### 阶段 3：平台配置

目标：

- 后台可配置豆包、千问、DeepSeek、文心一言的 API Key、模型、Base URL、搜索策略和超时。
- API Key 加密保存。

首版也可以先用数据库 seed 或命令行写入配置，但最终应有后台配置页，便于生产维护。

### 阶段 4：平台客户端

目标：

- 实现统一接口。
- 豆包、千问和文心一言拿真实 citation/source。
- DeepSeek 走 retrieval-enhanced，明确标记来源类型。

客户端：

- `DoubaoArkWebSearchClient`
- `QwenDashScopeWebSearchClient`
- `DeepSeekRetrievalEnhancedClient`
- `WenxinQianfanAiSearchClient`

### 阶段 5：任务流和指标聚合

目标：

- 创建 run。
- 创建 run 前执行额度校验：超级管理员不限次数，普通用户每天 1 次。
- 生成问题。
- 投递 jobs。
- 保存 result/source/mention。
- 聚合 run 指标。
- 每次搜索都新增 run，不复用同品牌旧 run。

关键服务：

- `BrandDiagnosisRunService`
- `BrandDiagnosisUsagePolicy`
- `BrandDiagnosisMentionAnalyzer`
- `BrandDiagnosisScoreCalculator`
- `BrandDiagnosisReportQueryService`

### 阶段 6：动态 UI

目标：

- 替换静态展示为真实数据。
- 支持进度轮询。
- 支持历史记录和详情。
- 展示来源和回答。

### 阶段 7：PDF 报告

目标：

- 生成 HTML 报告。
- 生成 PDF 文件。
- 支持下载。
- PDF 中展示：
  - 品牌名称。
  - 诊断时间。
  - 平台集合。
  - 品牌得分。
  - 品牌提及率。
  - 平均提及排名。
  - 品牌提及次数。
  - 正面/中性情感倾向。
  - 问题和回答摘要。
  - 引用来源列表。
  - 失败项说明。

## 15. 能做和不能做

### 可以做

- 真实接入豆包火山方舟联网搜索。
- 真实接入千问 DashScope 联网搜索。
- 真实接入文心一言相关的百度千帆 AI 搜索能力。
- DeepSeek 生成品牌诊断回答。
- DeepSeek 使用外部检索结果增强，并清晰标注来源类型。
- 展示真实 API 返回的引用来源。
- 保存每个问题、每个平台的回答。
- 计算品牌提及率、提及次数、平均排名、情感倾向和品牌得分。
- 生成 PDF 报告。
- 保留历史诊断记录。
- 每次诊断搜索都新增一条历史诊断记录，不覆盖之前记录。
- 控制诊断费用：超级管理员不限次数，普通用户首版每天只能诊断 1 次。
- 为后续“积分抵扣一次诊断”预留 `billing_mode/points_cost/points_transaction_id` 等兼容字段；首版不实现积分扣减。
- 后续扩展竞品榜单和自定义问题。

### 首版不能承诺

- 不能保证结果等同于豆包/千问/DeepSeek/文心一言网页端或 App 的用户体验。
- 不能抓取需要登录的网页端对话内容。
- 不能保证每次回答都有引用来源，平台可能返回空来源。
- 不能把模型正文里自称的来源当作真实来源。
- 不能承诺 DeepSeek 原生返回 citation，除非官方 API 稳定提供结构化来源字段。
- 不能保证所有 URL 永久可访问。
- 不能保证情感倾向 100% 准确，只能给出基于回答文本的机器判定和证据。

## 16. 和现有 GEO 报表的关系

首版品牌诊断建议独立建表，不复用 `geo_inclusion_check_results`。

原因：

1. GEO 报表当前是关键词/问题变体维度，品牌诊断是品牌/run/report 维度。
2. GEO 报表没有 source/citation 数据结构。
3. GEO 报表没有情感、排名、PDF 报告。
4. 品牌诊断需要真实平台客户端，不能混入当前模拟 checker。

后续可选升级：

- 将 GEO 报表的 `ChatAiSearchPlatformChecker` 替换或扩展为真实平台 checker。
- 将品牌诊断的平台客户端抽象复用给 GEO 报表。
- 在 GEO 报表里增加“引用来源”和“真实平台模式/模拟模式”标识。

## 17. 实施前需要确认的信息

执行代码前需要确认：

1. 豆包火山方舟 API Key 是否已准备好。
2. 豆包使用哪个模型 ID。
3. 千问 DashScope API Key 是否已准备好。
4. 千问使用哪个模型 ID，建议首版 `qwen-plus` 或当前账号可用的联网搜索模型。
5. DeepSeek 是否优先走火山方舟托管模型，推荐首版选择是。
6. DeepSeek 使用哪个火山方舟模型 ID，建议先确认账号是否可用 `deepseek-v4-pro-260425` 或 `deepseek-v4-flash-260425`。
7. 如果不用火山方舟托管 DeepSeek，DeepSeek 官方 API Key 是否已准备好。
8. DeepSeek 的外部检索来源优先使用火山方舟 Web Search、千问，还是另接搜索 API。
9. 百度千帆 API Key 或 Access Token 获取方式是否已准备好。
10. 文心一言使用千帆 `AISearch` 还是 `AISearchLoc`。
11. PDF 是否允许新增 Composer 依赖 `barryvdh/laravel-dompdf`。
12. 生产 Docker 是否允许安装中文字体。
13. PDF 报告示例提供后，是否需要完全按示例版式还原。

## 18. 推荐决策

推荐按以下口径实施：

1. 首版平台固定为豆包、千问、DeepSeek、文心一言。
2. 元宝不展示、不入库、不调度。
3. 豆包、千问和文心一言作为真实 native citation 平台。
4. DeepSeek 首版优先走火山方舟托管 DeepSeek 模型 + 火山方舟 Web Search 外部检索增强，这样可以复用 `ARK_API_KEY`，不强制单独准备 DeepSeek 官方 Key。
5. DeepSeek 结果不假装原生引用；`platform = deepseek`，`provider = volcengine_ark`，`source_mode = external_retrieval`。
6. 品牌诊断独立建表。
7. 每次诊断搜索新增一条 `brand_diagnosis_runs`，同品牌历史记录按时间保留，不覆盖。
8. 首版增加诊断费用控制：超级管理员不限次数，普通用户每天 1 次；后续积分抵扣可绕过每日限制。
9. 首版不实现积分扣减 UI 和积分流水，但 run 表预留 `billing_mode`、`points_cost`、`points_transaction_id`。
10. 平台客户端和 source normalizer 做成可复用服务，为后续升级 GEO 报表预留。
11. PDF 使用独立模板和受保护下载路由。

这个方案能最大程度满足“真实引用来源”的业务要求，同时避免把模拟平台能力包装成真实平台数据。
