# 品牌诊断 OpenAPI

品牌诊断开放 API 使用固定 `X-Api-Key` 鉴权，不绑定站点，不使用后台登录态或现有 Bearer Token scope。

## 鉴权

请求头：

```http
X-Api-Key: <your-api-key>
Content-Type: application/json
Accept: application/json
```

## 创建品牌诊断

`POST /api/v1/brand-diagnoses`

请求体：

```json
{
  "brand_name": "武城煊饼",
  "models": ["doubao", "qianwen"]
}
```

请求字段：

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `brand_name` | string | 是 | 需要诊断的品牌词，最长 120 个字符 |
| `models` | array<string> | 是 | 需要参与诊断的模型，可多选，最多 4 个 |
| `models[]` | string | 是 | 支持 `doubao`、`deepseek`、`qianwen`、`wenxin` |

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
    "created_at": "2026-07-23 15:30:00"
  },
  "error": null,
  "meta": {
    "request_id": "request-id",
    "timestamp": "2026-07-23T15:30:00+08:00"
  }
}
```

创建响应字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `success` | boolean | 请求是否成功 |
| `data.task_id` | string | 对外任务 ID，用于查询诊断结果，不暴露系统内部 ID |
| `data.status` | string | 对外状态，创建后通常为 `diagnosing` |
| `data.raw_status` | string | 系统内部任务状态，便于排查进度 |
| `data.brand_name` | string | 本次诊断的品牌词 |
| `data.models` | array<string> | 本次诊断选择的模型列表 |
| `data.created_at` | string | 任务创建时间，格式 `YYYY-MM-DD HH:mm:ss` |
| `error` | object|null | 成功时为 `null`，失败时返回错误对象 |
| `meta.request_id` | string | 请求追踪 ID |
| `meta.timestamp` | string | 响应时间，ISO 8601 格式 |

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
    "brand_name": "武城煊饼",
    "models": ["doubao", "qianwen"],
    "progress": {
      "total_questions": 6,
      "completed_questions": 3,
      "failed_questions": 0
    }
  },
  "error": null,
  "meta": {
    "request_id": "request-id",
    "timestamp": "2026-07-23T15:31:00+08:00"
  }
}
```

完成响应会在基础字段外额外返回 `brand_performance`、`questions`、`model_results`、`sources`、`rankings`。

## 查询结果字段说明

基础字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `task_id` | string | 对外任务 ID |
| `status` | string | 对外状态：`diagnosing`、`completed`、`failed` |
| `raw_status` | string | 系统内部任务状态 |
| `brand_name` | string | 诊断品牌词 |
| `models` | array<string> | 参与诊断的模型 |
| `created_at` | string | 任务创建时间 |
| `progress.total_questions` | integer | 本次诊断问题总数 |
| `progress.completed_questions` | integer | 已完成的问题数 |
| `progress.failed_questions` | integer | 失败的问题数 |
| `error_message` | string | 任务失败或局部失败时的错误信息；无错误时为空字符串 |

品牌表现 `brand_performance`：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `score` | integer | 品牌综合得分，满分 100 |
| `mention_rate` | integer | 品牌提及率，百分比整数 |
| `average_rank` | string | 平均提及排名；无有效排名时为 `"0"` |
| `mention_count` | integer | 品牌被提及的总次数 |
| `sentiment_rate` | integer | 正面或中性倾向占比，百分比整数 |

诊断问题 `questions[]`：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | integer | 系统内问题 ID |
| `question` | string | 实际提交给模型的诊断问题 |
| `type` | string | 问题类型，例如门店选择、排行查询、消费指引等 |
| `core_term` | string | 问题对应的核心词 |
| `sort_order` | integer | 问题排序，从 1 开始 |
| `status` | string | 问题状态：`pending`、`running`、`completed`、`failed` 等 |

模型回答 `model_results[]`：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `question_id` | integer | 对应 `questions[].id` |
| `platform` | string | 模型标识：`doubao`、`deepseek`、`qianwen`、`wenxin` |
| `status` | string | 本条模型回答状态：`success` 或 `failed` |
| `answer` | string | 模型回答正文；失败时通常为空字符串 |
| `brand_mentioned` | boolean | 回答中是否提及目标品牌 |
| `mention_count` | integer | 目标品牌在该回答中的提及次数 |
| `mention_rank` | integer | 目标品牌在该回答中的提及排名；未提及时为 0 |
| `sentiment` | string | 情感倾向：`positive`、`neutral`、`negative` |
| `error_message` | string | 该模型回答失败时的错误信息 |
| `checked_at` | string | 模型回答完成或失败时间 |

引用来源 `sources[]`：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `question_id` | integer | 对应 `questions[].id` |
| `result_id` | integer | 对应模型回答记录 ID |
| `platform` | string | 来源所属模型 |
| `title` | string | 来源标题 |
| `url` | string | 来源链接 |
| `domain` | string | 来源域名 |
| `source_type` | string | 来源类型，例如 `url_citation` |

品牌排名 `rankings`：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `rankings.mention_count` | array<object> | 按品牌提及次数排序的品牌列表 |
| `rankings.average_rank` | array<object> | 按最佳提及排名排序的品牌列表 |
| `rankings.source_count` | array<object> | 按引用来源数量排序的品牌列表 |

`rankings.*[]` 每个对象字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `brand_name` | string | 品牌名称 |
| `mention_count` | integer | 该品牌被提及的总次数 |
| `best_rank` | integer | 该品牌出现过的最好排名；无排名时为 0 |
| `source_count` | integer | 该品牌关联的来源数量 |
| `is_target_brand` | boolean | 是否为本次诊断的目标品牌 |

## 状态说明

| status | 说明 |
| --- | --- |
| `diagnosing` | 生成问题或模型诊断中 |
| `completed` | 诊断完成 |
| `failed` | 诊断失败 |

## 常见错误

| HTTP | code | 说明 |
| --- | --- | --- |
| 401 | `invalid_api_key` | `X-Api-Key` 缺失或无效 |
| 403 | `brand_diagnosis_api_disabled` | 开放 API 未启用 |
| 404 | `diagnosis_not_found` | 任务不存在或任务 ID 格式不正确 |
| 422 | `validation_failed` | 请求参数不合法 |
| 500 | `open_api_admin_not_found` | 服务端配置不可用，请联系平台处理 |
