# MoneyPrinterTurbo 公网 API 测试用例

本文档基于 `docs/api-integration.md`，使用公网 API 地址：

```text
https://videoapi.3737.cc.cd
```

统一接口前缀：

```text
/api/v1
```

## 测试准备

**测试目标**

验证外部系统可以通过公网域名调用 MoneyPrinterTurbo API，完成连通性检查、任务列表查询、视频任务创建、任务状态轮询，并确认生成结果返回公网可访问地址。

**前置条件**

- Cloudflare Tunnel 已将 `https://videoapi.3737.cc.cd` 转发到本地 API 服务 `http://localhost:8080`。
- MoneyPrinterTurbo API 容器正常运行。
- `config.toml` 中已配置：

```toml
endpoint = "https://videoapi.3737.cc.cd"
```

- 如果启用了 API 鉴权，调用方需要传入请求头：

```http
x-api-key: <你的 API KEY>
```

当前代码默认未启用鉴权时，可以不传。

## TC-001 公网 API 文档可访问

**测试目标**

验证公网域名已正确转发到 API 服务。

**用户角色**

外部系统调用方 / QA。

**测试步骤**

1. 请求公网 Swagger 文档：

```bash
curl -I "https://videoapi.3737.cc.cd/docs"
```

2. 请求 OpenAPI JSON：

```bash
curl -sS "https://videoapi.3737.cc.cd/openapi.json" | jq '.info.title, .info.version'
```

**预期结果**

- `/docs` 返回 HTTP `200`。
- `/openapi.json` 返回 HTTP `200`。
- OpenAPI 标题为 `MoneyPrinterTurbo`。

## TC-002 查询任务列表

**测试目标**

验证公网 API 的基础 JSON 接口可调用。

**测试步骤**

```bash
curl -sS "https://videoapi.3737.cc.cd/api/v1/tasks?page=1&page_size=1" | jq
```

**预期结果**

返回结构包含：

```json
{
  "status": 200,
  "message": "success",
  "data": {
    "tasks": [],
    "total": 0,
    "page": 1,
    "page_size": 1
  }
}
```

`tasks` 可以为空，但 `status` 应为 `200`。

## TC-003 创建公开视频任务并轮询

**测试目标**

验证外部系统可以通过公网域名创建视频任务，并通过任务查询接口获取最终视频地址。

**测试数据**

```json
{
  "video_subject": "收小麦",
  "video_script": "金色麦田里，联合收割机正在收割成熟的小麦，麦浪在阳光下起伏。",
  "video_terms": "收割小麦，联合收割机，金色麦田",
  "video_negative_terms": "苹果，摘花，玉米，果园，宝马，奔驰",
  "video_source": "pexels",
  "video_aspect": "9:16",
  "video_concat_mode": "random",
  "video_clip_duration": 4,
  "video_count": 1,
  "voice_name": "zh-CN-XiaoxiaoNeural-Female",
  "subtitle_enabled": true,
  "bgm_type": "none"
}
```

**测试步骤**

1. 创建任务：

```bash
curl -sS -X POST "https://videoapi.3737.cc.cd/api/v1/videos" \
  -H "Content-Type: application/json" \
  -H "x-task-id: public-api-tc-003" \
  -d '{
    "video_subject": "收小麦",
    "video_script": "金色麦田里，联合收割机正在收割成熟的小麦，麦浪在阳光下起伏。",
    "video_terms": "收割小麦，联合收割机，金色麦田",
    "video_negative_terms": "苹果，摘花，玉米，果园，宝马，奔驰",
    "video_source": "pexels",
    "video_aspect": "9:16",
    "video_concat_mode": "random",
    "video_clip_duration": 4,
    "video_count": 1,
    "voice_name": "zh-CN-XiaoxiaoNeural-Female",
    "subtitle_enabled": true,
    "bgm_type": "none"
  }' | jq
```

2. 记录响应中的 `data.task_id`。

3. 轮询任务状态：

```bash
TASK_ID="<上一步返回的 task_id>"

curl -sS "https://videoapi.3737.cc.cd/api/v1/tasks/${TASK_ID}" | jq
```

4. 每 10-15 秒重复查询，直到 `data.state` 为 `1` 或 `-1`。

**预期结果**

- 创建任务响应 `status=200`。
- 响应中存在 `data.task_id`。
- 查询任务时：
  - `data.state=4` 表示处理中。
  - `data.state=1` 表示完成。
  - `data.state=-1` 表示失败，需要查看 API 日志。
- 完成后 `data.progress=100`。
- 完成后 `data.videos[0]` 或 `data.combined_videos[0]` 应该以公网域名开头：

```text
https://videoapi.3737.cc.cd/tasks/
```

## TC-004 查询本地素材列表

**测试目标**

验证外部系统可以读取本地素材库列表。

**测试步骤**

```bash
curl -sS "https://videoapi.3737.cc.cd/api/v1/video_materials" | jq
```

**预期结果**

- HTTP 返回 `200`。
- JSON 中 `status=200`。
- `data.files` 为数组，可以为空。
- 如果已有素材，每个元素应至少包含 `name`、`size`、`file`。

## TC-005 错误任务 ID 查询

**测试目标**

验证外部系统可以正确识别不存在任务的错误响应。

**测试步骤**

```bash
curl -sS "https://videoapi.3737.cc.cd/api/v1/tasks/not-exist-task-id" | jq
```

**预期结果**

- 返回错误响应。
- HTTP 状态通常为 `404`。
- 错误信息中包含 `task not found`。

## 一键测试脚本

项目内已提供脚本：

```bash
./scripts/test_public_api.sh smoke
```

执行基础连通性和只读接口测试。

如需真实创建视频任务并轮询：

```bash
./scripts/test_public_api.sh video
```

如果已开启 API 鉴权：

```bash
API_KEY="<你的 API KEY>" ./scripts/test_public_api.sh smoke
API_KEY="<你的 API KEY>" ./scripts/test_public_api.sh video
```

