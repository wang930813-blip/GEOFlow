# 品牌诊断 OpenAPI

品牌诊断开放 API 使用固定 `X-Api-Key` 鉴权，不绑定站点，不使用后台登录态或现有 Bearer Token scope。

## 鉴权

请求头：

```http
X-Api-Key: <configured-open-api-key>
Content-Type: application/json
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

完成响应包含 `brand_performance`、`questions`、`model_results`、`sources`、`rankings`。其中 `brand_performance` 的计算口径与系统内品牌诊断报告一致。

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
| 500 | `open_api_admin_not_found` | 服务端配置不可用，请联系平台处理 |
