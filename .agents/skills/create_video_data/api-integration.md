# MoneyPrinterTurbo 外部系统接口对接文档

本文档面向需要通过 HTTP API 调用 MoneyPrinterTurbo 的外部系统。当前 API 服务默认监听：

```text
http://127.0.0.1:8080
```

接口统一前缀：

```text
/api/v1
```

在线 Swagger 文档：

```text
http://127.0.0.1:8080/docs
```

如果通过域名或 Nginx 对外提供服务，建议在 `config.toml` 的 `[app] endpoint` 中配置外部访问地址，例如：

```toml
endpoint = "https://videoapi.3737.cc.cd"
```

这样任务查询接口返回的视频地址会带外部域名。

## 1. 通用约定

### 1.1 请求格式

普通接口使用 JSON：

```http
Content-Type: application/json
```

上传文件接口使用 multipart：

```http
Content-Type: multipart/form-data
```

### 1.2 请求头

| Header | 必填 | 说明 |
|---|---:|---|
| `x-task-id` | 否 | 外部系统自定义请求追踪 ID。不传时服务端自动生成。 |
| `x-api-key` | 视部署而定 | 当前代码默认未开启接口鉴权。如果启用 `verify_token` 依赖，则需要传 `config.toml [app].api_key`。 |

当前项目的 `app/controllers/v1/video.py` 和 `app/controllers/v1/llm.py` 中鉴权依赖默认是注释状态：

```python
# router = new_router(dependencies=[Depends(base.verify_token)])
router = new_router()
```

如需对外开放，建议启用鉴权并通过 HTTPS 暴露服务。

### 1.3 响应格式

所有 JSON API 统一返回：

```json
{
  "status": 200,
  "message": "success",
  "data": {}
}
```

常见错误：

| HTTP 状态 | 说明 |
|---:|---|
| `400` | 请求参数错误、上传文件类型不支持 |
| `401` | 启用鉴权后 `x-api-key` 不正确 |
| `403` | 文件路径非法 |
| `404` | 任务或文件不存在 |
| `429` | 任务并发和队列已满 |

### 1.4 任务状态

视频生成是异步任务。创建任务后先拿到 `task_id`，再轮询任务状态。

| state | 含义 |
|---:|---|
| `4` | 处理中 |
| `1` | 完成 |
| `-1` | 失败 |

## 2. 推荐调用流程

### 2.1 在线素材生成视频

1. 调用 `POST /api/v1/videos` 创建任务。
2. 调用 `GET /api/v1/tasks/{task_id}` 轮询状态。
3. 当 `state=1` 且 `progress=100` 时，读取 `data.videos[0]` 获取成片地址。

### 2.2 本地素材生成视频

1. 调用 `POST /api/v1/video_materials` 上传本地视频/图片素材。
2. 记录返回的 `data.file` 文件名。
3. 调用 `POST /api/v1/videos`，设置：
   - `video_source: "local"`
   - `local_material_files: ["上传返回的文件名"]`
4. 轮询 `GET /api/v1/tasks/{task_id}`。

### 2.3 本地素材为主 + 网络素材补充

适合品牌、车型、产品等强约束内容。主体镜头来自本地素材，辅助镜头从 Pexels/Pixabay/Coverr 补充。

1. 先上传或准备本地素材。
2. 创建任务时设置：
   - `video_source: "local"`
   - `local_material_files`
   - `local_material_mix_enabled: true`
   - `local_material_mix_ratio: 70`
   - `local_material_online_source: "pexels"`
3. `local_material_mix_ratio=70` 表示约 70% 本地素材、30% 网络素材。

注意：混合网络素材需要对应素材源的 API Key 已在 `config.toml` 中配置。

## 3. 生成视频

### 3.1 创建视频任务

```http
POST /api/v1/videos
```

请求体核心字段：

| 字段 | 类型 | 必填 | 默认值 | 说明 |
|---|---|---:|---|---|
| `video_subject` | string | 是 | - | 视频主题。 |
| `video_script` | string | 否 | `""` | 自定义文案。不传则由 LLM 生成。 |
| `video_terms` | string/list | 否 | `null` | 视频素材关键词。支持中文精准关键词，会优化成英文素材搜索词。 |
| `video_negative_terms` | string/list | 否 | `null` | 排除关键词，例如 `苹果，摘花，宝马`。 |
| `video_source` | string | 否 | `pexels` | `pexels`、`pixabay`、`coverr`、`local`。 |
| `video_aspect` | string | 否 | `9:16` | `9:16`、`16:9`、`1:1`。 |
| `video_concat_mode` | string | 否 | `random` | `random` 或 `sequential`。 |
| `video_transition_mode` | string/null | 否 | `null` | `Shuffle`、`FadeIn`、`FadeOut`、`SlideIn`、`SlideOut` 或 `null`。 |
| `video_clip_duration` | integer | 否 | `5` | 单个素材片段最大秒数。 |
| `video_count` | integer | 否 | `1` | 生成视频数量。 |
| `voice_name` | string | 否 | `""` | 配音名称，例如 `zh-CN-XiaoxiaoNeural-Female`。 |
| `voice_rate` | number | 否 | `1.0` | 语速倍率。 |
| `voice_volume` | number | 否 | `1.0` | 人声音量。 |
| `bgm_type` | string | 否 | `random` | `random`、`none`、或项目支持的其它值。 |
| `bgm_file` | string | 否 | `""` | 背景音乐文件名。 |
| `bgm_volume` | number | 否 | `0.2` | 背景音乐音量。 |
| `subtitle_enabled` | boolean | 否 | `true` | 是否生成字幕。 |
| `subtitle_position` | string | 否 | `bottom` | `top`、`center`、`bottom`、`custom`。 |
| `custom_position` | number | 否 | `70.0` | 自定义字幕位置百分比。 |
| `font_name` | string | 否 | `STHeitiMedium.ttc` | 字体文件名。 |
| `text_fore_color` | string | 否 | `#FFFFFF` | 字幕文字颜色。 |
| `text_background_color` | boolean/string | 否 | `true` | 字幕背景。可传 `false` 或颜色值。 |
| `rounded_subtitle_background` | boolean | 否 | `false` | 是否使用圆角字幕背景。 |
| `font_size` | integer | 否 | `60` | 字幕字号。 |
| `stroke_color` | string | 否 | `#000000` | 字幕描边颜色。 |
| `stroke_width` | number | 否 | `1.5` | 字幕描边宽度。 |
| `n_threads` | integer | 否 | `2` | 视频处理线程数。 |
| `paragraph_number` | integer | 否 | `1` | 自动生成文案段落数，范围 1-10。 |
| `video_script_prompt` | string | 否 | `""` | 文案生成附加提示词。 |
| `custom_system_prompt` | string | 否 | `""` | 自定义系统提示词。 |

本地素材相关字段：

| 字段 | 类型 | 说明 |
|---|---|---|
| `video_materials` | array | 直接指定本地素材，元素格式见下方。 |
| `local_material_files` | array | 指定 `POST /video_materials` 返回的文件名。 |
| `local_material_brand` | string | 按本地素材库 metadata 中的品牌/素材包筛选。 |
| `local_material_tags` | string/list | 按本地素材库标签筛选。 |
| `local_material_mix_enabled` | boolean | 是否混合网络素材。 |
| `local_material_mix_ratio` | integer | 本地素材占比，0-100，默认 70。 |
| `local_material_online_source` | string | 混合时的网络素材源：`pexels`、`pixabay`、`coverr`。 |

`video_materials` 元素格式：

```json
{
  "provider": "local",
  "url": "example.mp4",
  "duration": 0
}
```

### 3.2 在线素材示例

```bash
curl -X POST "http://127.0.0.1:8080/api/v1/videos" \
  -H "Content-Type: application/json" \
  -H "x-task-id: order-10001" \
  -d '{
    "video_subject": "收小麦",
    "video_script": "金色麦田里，联合收割机正在收割成熟的小麦。",
    "video_terms": "收割小麦，联合收割机，金色麦田",
    "video_negative_terms": "苹果，摘花，玉米",
    "video_source": "pexels",
    "video_aspect": "9:16",
    "video_clip_duration": 4,
    "video_count": 1,
    "voice_name": "zh-CN-XiaoxiaoNeural-Female",
    "subtitle_enabled": true
  }'
```

成功响应：

```json
{
  "status": 200,
  "message": "success",
  "data": {
    "task_id": "6c85c8cc-a77a-42b9-bc30-947815aa0558",
    "request_id": "order-10001",
    "params": {}
  }
}
```

### 3.3 本地素材示例

先上传素材：

```bash
curl -X POST "http://127.0.0.1:8080/api/v1/video_materials" \
  -F "file=@/path/to/porsche-911.mp4"
```

响应：

```json
{
  "status": 200,
  "message": "success",
  "data": {
    "file": "porsche-911.mp4"
  }
}
```

再创建视频：

```bash
curl -X POST "http://127.0.0.1:8080/api/v1/videos" \
  -H "Content-Type: application/json" \
  -d '{
    "video_subject": "保时捷 911 城市驾驶",
    "video_script": "保时捷 911 穿行在城市道路中，展现经典跑车线条。",
    "video_source": "local",
    "local_material_files": ["porsche-911.mp4"],
    "video_aspect": "9:16",
    "video_concat_mode": "sequential",
    "voice_name": "zh-CN-XiaoxiaoNeural-Female"
  }'
```

也可以直接使用 `video_materials`：

```json
{
  "video_source": "local",
  "video_materials": [
    {"provider": "local", "url": "porsche-911.mp4", "duration": 0}
  ]
}
```

### 3.4 本地素材 + 网络补充示例

```bash
curl -X POST "http://127.0.0.1:8080/api/v1/videos" \
  -H "Content-Type: application/json" \
  -d '{
    "video_subject": "保时捷 911 城市驾驶",
    "video_script": "保时捷 911 从车库出发，驶过城市街道，最终抵达海边公路。",
    "video_terms": "城市道路，海边公路，豪华跑车驾驶",
    "video_negative_terms": "宝马，奔驰，普通轿车",
    "video_source": "local",
    "local_material_files": ["porsche-911.mp4"],
    "local_material_mix_enabled": true,
    "local_material_mix_ratio": 70,
    "local_material_online_source": "pexels",
    "video_aspect": "9:16",
    "video_concat_mode": "random",
    "video_clip_duration": 4
  }'
```

说明：

- `local_material_mix_ratio=70`：本地素材约占 70%，网络素材约占 30%。
- 网络素材下载失败时，任务会继续使用已通过校验的本地素材。
- `local_material_mix_ratio=100` 等同纯本地素材，不会下载网络素材。

## 4. 查询任务

### 4.1 查询单个任务

```http
GET /api/v1/tasks/{task_id}
```

示例：

```bash
curl "http://127.0.0.1:8080/api/v1/tasks/6c85c8cc-a77a-42b9-bc30-947815aa0558"
```

处理中响应：

```json
{
  "status": 200,
  "message": "success",
  "data": {
    "task_id": "6c85c8cc-a77a-42b9-bc30-947815aa0558",
    "state": 4,
    "progress": 40
  }
}
```

完成响应：

```json
{
  "status": 200,
  "message": "success",
  "data": {
    "task_id": "6c85c8cc-a77a-42b9-bc30-947815aa0558",
    "state": 1,
    "progress": 100,
    "videos": [
      "/tasks/6c85c8cc-a77a-42b9-bc30-947815aa0558/final-1.mp4"
    ],
    "combined_videos": [
      "/tasks/6c85c8cc-a77a-42b9-bc30-947815aa0558/combined-1.mp4"
    ],
    "script": "生成或传入的视频文案",
    "terms": ["wheat harvest", "golden wheat field"],
    "audio_file": "/path/to/audio.mp3",
    "audio_duration": 30,
    "subtitle_path": "/path/to/subtitle.srt",
    "materials": ["/path/to/material.mp4"]
  }
}
```

如果未配置 `endpoint`，`videos` 通常返回 `/tasks/...` 相对地址。外部系统可以拼接 API 域名访问：

```text
http://127.0.0.1:8080/tasks/{task_id}/final-1.mp4
```

### 4.2 查询任务列表

```http
GET /api/v1/tasks?page=1&page_size=10
```

示例：

```bash
curl "http://127.0.0.1:8080/api/v1/tasks?page=1&page_size=10"
```

响应：

```json
{
  "status": 200,
  "message": "success",
  "data": {
    "tasks": [],
    "total": 0,
    "page": 1,
    "page_size": 10
  }
}
```

### 4.3 删除任务

```http
DELETE /api/v1/tasks/{task_id}
```

删除任务会同时删除任务目录下的生成产物。

```bash
curl -X DELETE "http://127.0.0.1:8080/api/v1/tasks/6c85c8cc-a77a-42b9-bc30-947815aa0558"
```

## 5. 下载和播放视频

任务完成后优先直接访问 `data.videos` 中的地址。

也可以使用接口：

```http
GET /api/v1/download/{task_relative_path}
GET /api/v1/stream/{task_relative_path}
```

例如任务产物是：

```text
/tasks/6c85c8cc-a77a-42b9-bc30-947815aa0558/final-1.mp4
```

下载接口：

```bash
curl -L -o final.mp4 \
  "http://127.0.0.1:8080/api/v1/download/6c85c8cc-a77a-42b9-bc30-947815aa0558/final-1.mp4"
```

在线播放接口支持 `Range` 请求：

```text
http://127.0.0.1:8080/api/v1/stream/6c85c8cc-a77a-42b9-bc30-947815aa0558/final-1.mp4
```

## 6. 仅生成脚本、关键词、音频、字幕

### 6.1 生成视频脚本

```http
POST /api/v1/scripts
```

请求：

```json
{
  "video_subject": "春天的花海",
  "video_language": "zh-CN",
  "paragraph_number": 1,
  "video_script_prompt": "语气轻松，适合短视频旁白",
  "custom_system_prompt": ""
}
```

响应：

```json
{
  "status": 200,
  "message": "success",
  "data": {
    "video_script": "..."
  }
}
```

### 6.2 生成素材关键词

```http
POST /api/v1/terms
```

请求：

```json
{
  "video_subject": "收小麦",
  "video_script": "农民驾驶联合收割机收割成熟的小麦。",
  "amount": 5
}
```

响应：

```json
{
  "status": 200,
  "message": "success",
  "data": {
    "video_terms": ["wheat harvest", "combine harvester"]
  }
}
```

### 6.3 生成社媒发布文案

```http
POST /api/v1/social-metadata
```

请求：

```json
{
  "video_subject": "A day in Shanghai",
  "video_script": "A quick city walk through Shanghai...",
  "language": "auto",
  "platform": "tiktok"
}
```

响应：

```json
{
  "status": 200,
  "message": "success",
  "data": {
    "title": "A Day in Shanghai You Should Not Miss",
    "caption": "Save this quick Shanghai inspiration...",
    "hashtags": ["#shorts", "#travel", "#shanghai"]
  }
}
```

### 6.4 仅生成音频

```http
POST /api/v1/audio
```

请求：

```json
{
  "video_script": "这是一段需要合成语音的文案。",
  "video_language": "zh-CN",
  "voice_name": "zh-CN-XiaoxiaoNeural-Female",
  "voice_rate": 1.0,
  "voice_volume": 1.0,
  "bgm_type": "none"
}
```

返回 `task_id`，通过 `GET /api/v1/tasks/{task_id}` 查询，完成后状态里会包含 `audio_file` 和 `audio_duration`。

### 6.5 仅生成字幕

```http
POST /api/v1/subtitle
```

请求：

```json
{
  "video_script": "这是一段需要生成字幕的文案。",
  "video_language": "zh-CN",
  "voice_name": "zh-CN-XiaoxiaoNeural-Female",
  "subtitle_position": "bottom",
  "font_name": "STHeitiMedium.ttc",
  "font_size": 60
}
```

返回 `task_id`，通过任务查询接口获取 `subtitle_path`。

## 7. 本地素材和背景音乐接口

### 7.1 查询本地素材列表

```http
GET /api/v1/video_materials
```

响应：

```json
{
  "status": 200,
  "message": "success",
  "data": {
    "files": [
      {
        "name": "porsche-911.mp4",
        "size": 12345678,
        "brand": "保时捷",
        "tags": ["911", "跑车"],
        "file": "porsche-911.mp4"
      }
    ]
  }
}
```

说明：

- `file` 是创建视频任务时要传的文件名。
- API 上传接口当前只负责保存文件，不负责写入品牌/标签 metadata。
- 品牌/标签筛选依赖 WebUI 或 `storage/local_videos/materials.json` 中已有 metadata。

### 7.2 上传本地素材

```http
POST /api/v1/video_materials
```

表单字段：

| 字段 | 类型 | 说明 |
|---|---|---|
| `file` | file | 支持 `mp4`、`mov`、`avi`、`flv`、`mkv`、`jpg`、`jpeg`、`png`。 |

示例：

```bash
curl -X POST "http://127.0.0.1:8080/api/v1/video_materials" \
  -F "file=@/path/to/material.mp4"
```

### 7.3 查询背景音乐列表

```http
GET /api/v1/musics
```

响应：

```json
{
  "status": 200,
  "message": "success",
  "data": {
    "files": [
      {
        "name": "output013.mp3",
        "size": 1891269,
        "file": "output013.mp3"
      }
    ]
  }
}
```

### 7.4 上传背景音乐

```http
POST /api/v1/musics
```

只支持 `.mp3`。

```bash
curl -X POST "http://127.0.0.1:8080/api/v1/musics" \
  -F "file=@/path/to/bgm.mp3"
```

创建视频时使用：

```json
{
  "bgm_type": "custom",
  "bgm_file": "bgm.mp3"
}
```

## 8. 外部系统轮询建议

建议轮询间隔 2-5 秒：

```pseudo
task_id = POST /api/v1/videos

while true:
    task = GET /api/v1/tasks/{task_id}
    if task.data.state == 1:
        return task.data.videos
    if task.data.state == -1:
        throw "video generation failed"
    sleep(3000)
```

如果外部系统对并发敏感，需要注意服务端配置：

```toml
max_concurrent_tasks = 5
max_queued_tasks = 100
```

超过队列上限时接口返回 `429`。

## 9. 对接前配置检查

上线前建议确认：

- API 服务可访问：`GET /docs`
- 对外域名已配置到 `[app] endpoint`
- 外部访问建议启用 `x-api-key` 鉴权
- 需要在线素材时已配置对应 API Key：
  - `pexels_api_keys`
  - `pixabay_api_keys`
  - `coverr_api_keys`
- 需要云端 LLM/TTS 时已配置对应 provider 的 Key
- 如使用本地素材，外部系统上传后使用返回的文件名，不传宿主机绝对路径
- 如要使用品牌/标签筛选，先维护本地素材库 metadata
