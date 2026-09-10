# 品牌诊断查询 API

该接口供三方平台查询品牌诊断数据。接口不绑定站点，会在数据范围内检索，并按匹配规则返回最新一条诊断记录。

## 请求方式

```http
GET /api/v1/brand-diagnoses/search
```

## 鉴权与 API Key

请求头中传入 API Key：

```http
X-Api-Key: <BRAND_DIAGNOSIS_LOOKUP_API_KEY>
```

请向接口提供方获取有效的 API Key。Key 只应保存在三方服务端，不要写入浏览器、移动端或前端代码。

## 请求示例

查询品牌介绍、AI 问题、品牌表现和竞品数据：

```http
GET /api/v1/brand-diagnoses/search?brand_word=策影GEO&include=profile,questions,performance,competitors
Accept: application/json
X-Api-Key: <lookup-key>
```

也可以只查询单个模块，例如只取 AI 问题：

```http
GET /api/v1/brand-diagnoses/search?brand_word=策影GEO&include=questions
```

只查询竞品数据：

```http
GET /api/v1/brand-diagnoses/search?brand_word=策影GEO&include=competitors
```

如果品牌词没有匹配到存量诊断，接口会自动创建异步查询任务：

```http
GET /api/v1/brand-diagnoses/search?brand_word=我的品牌&include=profile,questions
Accept: application/json
X-Api-Key: <lookup-key>
```

`brand_word` 必填，会自动去除首尾空格，最长 120 个字符。`include` 可选，为英文逗号分隔的模块名；不传或传空值时返回全部模块，重复模块会自动去重。

## 请求字段释义

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `brand_word` | string | 是 | 要检索的品牌词。会去除首尾空格，最长 120 个字符。 |
| `include` | string | 否 | 需要返回的模块，多个模块用英文逗号分隔。不传或为空表示返回全部模块。 |

### `include` 可选值

| 值 | 返回内容 |
| --- | --- |
| `profile` | 品牌介绍及品牌介绍使用的网页来源 |
| `questions` | AI 问题池 |
| `performance` | 品牌表现得分、提及率、平均排名等指标 |
| `model_results` | 各问题对应的模型回答和提及指标 |
| `sources` | AI 回答中引用的信源 |
| `snapshots` | 脱敏后的 AI 对话快照 |
| `competitors` | 竞品提及聚合数据 |

传入未知模块名会直接返回 HTTP `422`，不会查询数据库，也不会调用模型。

## 检索规则与数据来源

1. 在全部站点范围内检索品牌诊断记录，并排除软删除记录。
2. 匹配优先级依次为：完全匹配、品牌实体规范化匹配、前缀匹配、包含匹配。
3. 同一匹配级别内按 `created_at` 倒序，再按 `id` 倒序，取最新一条诊断。

检索到诊断记录时，响应中的 `data_source` 为 `stored`，返回该记录当前状态及已生成的数据。

检索不到存量数据时，接口不会在当前请求中调用模型，而是自动创建异步查询任务并立即返回。异步任务会核实品牌词、生成品牌介绍和 AI 问题；任务完成后，使用状态查询接口获取完整结果。此时 `data_source` 为 `generated_not_stock`。

如果模型核实后无法获得品牌介绍，状态查询接口返回 `422 brand_profile_not_found`，不会返回不完整的生成结果。

## 异步查询

### 创建异步查询

当请求没有匹配到存量数据时，接口返回 HTTP `202`：

```json
{
  "success": true,
  "data": {
    "lookup_id": "bdl_xxxxxxxxx",
    "brand_word": "我的品牌",
    "data_source": "generated_not_stock",
    "status": "pending",
    "retry_after": 3
  },
  "error": null,
  "meta": {
    "request_id": "...",
    "timestamp": "...",
    "included": ["profile", "questions"],
    "omitted": ["performance", "model_results", "sources", "snapshots", "competitors"]
  }
}
```

如果请求命中存量数据，直接返回 HTTP `200` 的查询结果，不会创建异步任务。

### 查询异步状态

```http
GET /api/v1/brand-diagnoses/search/status/{lookup_id}
Accept: application/json
X-Api-Key: <lookup-key>
```

任务处理中返回 HTTP `202`，`data.status` 为 `pending` 或 `processing`。建议按照 `retry_after` 秒数再次请求。任务完成后返回 HTTP `200`，响应结构与同步查询一致；任务失败时返回对应错误码。任务超过保留时间后，状态接口返回 `404 brand_diagnosis_lookup_not_found`。

## 响应格式

响应使用统一 API 外层结构。`meta.included` 和 `meta.omitted` 表示本次请求包含和省略的模块；`data.module_status` 表示每个模块的实际状态。

```json
{
  "success": true,
  "data": {
    "requested_brand_word": "策影GEO",
    "data_source": "stored",
    "match_type": "canonical",
    "diagnosis": {
      "brand_name": "策影GEO",
      "status": "completed",
      "created_at": "2026-09-09 10:00:00",
      "started_at": "2026-09-09 10:01:00",
      "completed_at": "2026-09-09 10:08:00",
      "error_message": ""
    },
    "brand_profile": {
      "text": "...",
      "source": "web_search",
      "model": "豆包",
      "status": "success",
      "sources": []
    },
    "questions": [],
    "brand_performance": {
      "score": 88,
      "mention_rate": 75,
      "average_rank": "2.5",
      "mention_count": 6,
      "sentiment_rate": 100
    },
    "model_results": [],
    "ai_sources": [],
    "conversation_snapshots": [],
    "competitors": [
      {
        "brand_name": "竞品甲",
        "mention_count": 12,
        "best_rank": 2,
        "source_count": 5,
        "sentiment": "positive"
      }
    ],
    "module_status": {
      "profile": "included",
      "questions": "included",
      "performance": "included",
      "model_results": "not_available",
      "sources": "omitted",
      "snapshots": "omitted",
      "competitors": "included"
    }
  },
  "error": null,
  "meta": {
    "request_id": "...",
    "timestamp": "...",
    "included": ["profile", "questions", "performance", "competitors"],
    "omitted": ["model_results", "sources", "snapshots"]
  }
}
```

### 统一外层字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `success` | boolean | 请求是否成功。成功为 `true`，失败为 `false`。 |
| `data` | object/null | 成功时为查询结果，失败时为 `null`。 |
| `error` | object/null | 失败时包含错误信息，成功时为 `null`。 |
| `error.code` | string | 稳定的错误码，供三方程序判断。 |
| `error.message` | string | 面向调用方的中文错误说明。 |
| `error.details` | object/null | 可选的校验错误详情。 |
| `meta.request_id` | string | 请求追踪 ID，同时通过响应头 `X-Request-Id` 返回。 |
| `meta.timestamp` | string | ISO-8601 格式的响应时间。 |
| `meta.included` | string[] | 本次实际请求并序列化返回的模块。 |
| `meta.omitted` | string[] | 支持但本次未请求的模块。 |

### `data` 字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `requested_brand_word` | string | 调用方传入并去除首尾空格后的品牌词。 |
| `data_source` | string | `stored` 表示存量诊断；`generated_not_stock` 表示本次实时生成、没有对应存量记录。 |
| `match_type` | string | 匹配类型：`exact`、`canonical`、`prefix`、`contains` 或 `none`。 |
| `diagnosis` | object | 诊断记录的基本信息和状态。生成预览时 `status=not_run`。 |
| `brand_profile` | object/null | 请求包含 `profile` 时返回品牌介绍。 |
| `questions` | array/null | 请求包含 `questions` 时返回 AI 问题数组。 |
| `brand_performance` | object/null | 请求包含 `performance` 时返回品牌表现；生成预览时为 `null`。 |
| `model_results` | array/null | 请求包含 `model_results` 时返回模型结果数组。 |
| `ai_sources` | array/null | 请求包含 `sources` 时返回 AI 信源数组。 |
| `conversation_snapshots` | array/null | 请求包含 `snapshots` 时返回对话快照数组。 |
| `competitors` | array/null | 请求包含 `competitors` 时返回竞品提及聚合数组。 |
| `module_status` | object | 七个模块的状态，可能为 `included`、`omitted`、`not_run`、`not_available` 或 `failed`。 |

异步处理中响应的 `data` 字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `lookup_id` | string | 异步查询任务 ID，用于调用状态查询接口。 |
| `brand_word` | string | 本次异步查询的品牌词。 |
| `data_source` | string | 异步非存量查询固定为 `generated_not_stock`。 |
| `status` | string | 任务状态：`pending` 或 `processing`。 |
| `retry_after` | integer | 建议客户端等待的轮询间隔，单位为秒。 |

### `diagnosis` 字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `brand_name` | string | 存量诊断中的品牌名称；生成预览时为请求的品牌词。 |
| `status` | string | 存量诊断状态，例如 `completed`、`running`、`failed`、`questions_ready`；生成预览为 `not_run`。 |
| `created_at` | string | 诊断创建时间；生成预览为空字符串。 |
| `started_at` | string | 诊断开始时间；未开始时为空字符串。 |
| `completed_at` | string | 诊断完成时间；未完成时为空字符串。 |
| `error_message` | string | 诊断失败时的脱敏错误信息，正常时为空字符串。 |

### `brand_profile` 字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `text` | string | 经模型核实后的品牌介绍正文。 |
| `source` | string | 品牌介绍来源，通常为 `web_search`。 |
| `model` | string | 生成或核实所使用的模型标识。 |
| `status` | string | 存量数据通常为 `success`；实时生成时为 `generated`。 |
| `sources` | array | 品牌介绍使用的来源列表，每项包含 `title`、`url`、`domain`；只返回 HTTP(S) 地址。 |

### `questions[]` 字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | integer/null | 存量问题 ID；实时生成的问题没有持久化 ID，返回 `null`。 |
| `question` | string | AI 问题文本。 |
| `type` | string | 问题分类。 |
| `core_term` | string | 生成问题时使用的核心词。 |
| `sort_order` | integer | 展示顺序，从 1 开始。 |
| `status` | string | 存量问题状态；实时生成时为 `generated`。 |

### `brand_performance` 字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `score` | integer | 综合品牌表现得分。 |
| `mention_rate` | integer | 成功模型回答中提及目标品牌的百分比。 |
| `average_rank` | string | 品牌平均提及排名，格式化为字符串；`0` 表示没有排名。 |
| `mention_count` | integer | 经校验的目标品牌提及总次数。 |
| `sentiment_rate` | integer | 目标品牌提及结果为正面或中性的百分比。 |

### `model_results[]` 字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `question_id` | integer | 关联的存量问题 ID。 |
| `platform` | string | 模型平台，例如 `doubao`、`deepseek`、`qianwen`、`wenxin`。 |
| `status` | string | 模型结果状态。 |
| `answer` | string | 脱敏后的模型回答正文，不包含内部结构化载荷。 |
| `brand_mentioned` | boolean | 模型回答是否提及目标品牌。 |
| `mention_count` | integer | 经校验的品牌提及次数。 |
| `mention_rank` | integer | 检测到的首次或最佳提及排名；`0` 表示不可用。 |
| `sentiment` | string | 情感结果：`positive`、`neutral` 或 `negative`。 |
| `error_message` | string | 模型结果失败时的脱敏错误信息。 |
| `checked_at` | string | 模型检查时间。 |

### `ai_sources[]` 字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `question_id` | integer | 关联的问题 ID。 |
| `result_id` | integer | 关联的模型结果 ID。 |
| `platform` | string | 产生信源的模型平台。 |
| `title` | string | 引用来源标题。 |
| `url` | string | 引用来源 URL，只返回 HTTP(S) 地址。 |
| `domain` | string | 引用来源域名。 |
| `source_type` | string | 来源类型，例如 `web_search_result`。 |

### `conversation_snapshots[]` 字段

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `result_id` | integer | 关联的模型结果 ID。 |
| `question_id` | integer | 关联的问题 ID。 |
| `platform` | string | 模型平台。 |
| `question` | string | 快照中的原始问题。 |
| `answer` | string | 脱敏后的历史回答。 |
| `sources` | array | 脱敏后的来源列表，只包含 HTTP(S) 来源。 |
| `status` | string | 生成快照时的模型结果状态。 |
| `checked_at` | string | 快照或模型检查时间。 |

### `competitors[]` 字段

竞品按品牌名称聚合去重，目标品牌自身不会出现在该数组中。排序规则为提及次数倒序、最佳排名正序、引用来源数倒序。

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `brand_name` | string | 竞品名称。 |
| `mention_count` | integer | 该竞品在所有模型回答中的提及次数合计。 |
| `best_rank` | integer | 该竞品的最佳提及排名；`0` 表示没有可用排名。 |
| `source_count` | integer | 该竞品关联的引用来源数合计。 |
| `sentiment` | string | 按提及次数加权后的整体情感倾向：`positive`、`neutral` 或 `negative`。 |

请求的模块没有可用数据时，对应字段返回 `null` 或空数组，并通过 `module_status` 说明原因。未请求的模块不会预加载，状态始终为 `omitted`。

模型回答、来源和对话快照仅返回接口定义的展示字段，不返回原始供应商响应或内部元数据。

## 模块状态

| 状态 | 含义 |
| --- | --- |
| `included` | 调用方请求了该模块，且已返回数据。 |
| `omitted` | 调用方未请求该模块。 |
| `not_run` | 本次没有执行该模块，例如未找到存量诊断而生成预览，或没有竞品提及数据。 |
| `not_available` | 存量记录中没有可用数据。 |
| `failed` | 读取或格式化该模块时发生错误。 |

## 错误码

错误响应仍使用统一外层结构，HTTP 状态码和 `error.code` 如下：

| HTTP | `error.code` | 说明 |
| --- | --- | --- |
| 401 | `invalid_api_key` | 未传入或传入的 API Key 不正确。 |
| 403 | `brand_diagnosis_lookup_api_disabled` | 查询 API 未启用。 |
| 422 | `validation_failed` | 请求参数校验失败，包括品牌词为空或 `include` 含未知模块。 |
| 422 | `brand_profile_not_found` | 品牌词不在存量数据中，且模型核实后无法获得品牌介绍。 |
| 429 | `lookup_rate_limited` | 查询频率超过配置限制。 |
| 502 | `brand_profile_provider_failed` | 品牌核实模型或外部信源调用失败。 |
| 502 | `brand_questions_generation_failed` | AI 问题生成失败。 |
| 503 | `brand_diagnosis_lookup_not_ready` | 查询服务依赖尚未准备完成。 |
| 503 | `brand_diagnosis_lookup_busy` | 异步查询任务正在创建，请稍后重试。 |
| 404 | `brand_diagnosis_lookup_not_found` | 异步查询任务不存在或已过期。 |
