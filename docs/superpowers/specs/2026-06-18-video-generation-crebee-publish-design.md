# 生成视频与自媒体视频发布技术方案

## 背景

系统已经具备文章生成、文章列表、自媒体账号绑定、CreBee Bridge Agent、自媒体文章发布记录、账号级规格额度等能力。现在需要新增独立的“生成视频”模块，并把生成后的视频接入现有自媒体发布流程。

视频生成依赖 MoneyPrinterTurbo API：

- 创建任务：`POST /api/v1/videos`
- 查询任务：`GET /api/v1/tasks/{task_id}`
- 成片地址：任务完成后从 `data.videos` 或 `data.combined_videos` 读取
- 播放/下载：可直接访问返回地址，或走 `/api/v1/stream/...`、`/api/v1/download/...`

CreBee 视频发布要求本机绝对路径：

- `videoPath`：视频本地文件路径
- `coverPath`：封面图本地文件路径

因此推荐架构是：云端负责视频生成、列表、预览、下载、额度和发布任务创建；运维电脑上的 Bridge Agent 负责发布前把云端视频和封面下载到本地缓存目录，再调用 CreBee 本地 API。

## 目标

1. 新增后台“生成视频”独立模块。
2. 用户可创建视频生成任务，任务异步执行。
3. 生成完成后，用户可在视频列表中在线播放、查看详情、下载视频。
4. 生成视频受账号规格 `video_generations` 限制。
5. 视频可发布到已绑定的自媒体平台账号。
6. 视频发布复用现有 CreBee 发布任务、发布事件、发布记录和额度统计。
7. 视频、自媒体发布记录均满足账号级数据隔离：普通用户只能看自己的数据；代理可看当前分站点下用户数据；超管按现有站点上下文和权限查看。

## 非目标

1. 本期不做复杂视频剪辑器。
2. 本期不做视频素材库完整管理，只接入 MoneyPrinterTurbo 的在线素材生成能力；本地素材上传可作为后续增强。
3. 本期不重写 CreBee 发布任务表。
4. 本期不做云端直接调用 CreBee，本地账号仍保存在运维电脑 CreBee 客户端。

## 方案选择

### 方案 A：云端生成，Agent 发布前下载本地缓存

云端保存 MoneyPrinterTurbo 生成结果 URL。用户发布视频时，云端创建 CreBee 发布任务，payload 中带视频 URL 和封面 URL。Agent 拉到任务后，下载视频和封面到本地缓存，再把 CreBee 参数里的路径替换成本机绝对路径。

优点：

- 与 CreBee 本地账号限制匹配。
- 云端可以正常预览、下载、统计额度。
- 复用现有自媒体发布任务和发布记录。
- 生产环境部署清晰。

缺点：

- Agent 需要新增资产下载和缓存清理能力。
- 视频文件较大，Agent 本地磁盘需要设置缓存上限。

结论：采用此方案。

### 方案 B：云端下载并存储视频，再由 Agent 下载云端存储文件

云端在任务完成后主动下载视频到 `storage`，Agent 再从 GEOFlow 下载。

优点：

- 云端可以控制文件生命周期。
- MoneyPrinterTurbo 地址失效风险低。

缺点：

- 云端存储压力大。
- 大文件下载、断点、清理策略复杂。
- 当前需求不需要系统长期托管所有视频原文件。

结论：可作为后续增强，不作为本期默认实现。

### 方案 C：云端只记录任务 ID，不提供预览下载

只在 MoneyPrinterTurbo 中保留生成结果，系统不负责视频资产访问。

优点：

- 实现最少。

缺点：

- 不满足用户在线预览和下载需求。
- 发布时仍需要 Agent 获取可下载地址。

结论：不采用。

## 数据模型

新增 `video_generation_jobs` 表。

核心字段：

- `id`
- `site_id`
- `owner_admin_id`
- `created_by_admin_id`
- `title`
- `subject`
- `script`
- `terms`
- `negative_terms`
- `video_source`
- `video_aspect`
- `video_count`
- `cover_image`
- `status`：`draft`、`queued`、`processing`、`success`、`failed`
- `progress`
- `api_task_id`
- `request_payload`
- `result_payload`
- `videos`
- `combined_videos`
- `failure_reason`
- `quota_ledger_id`
- `started_at`
- `finished_at`
- `created_at`
- `updated_at`

说明：

- `videos` 和 `combined_videos` 存 JSON 数组。
- `cover_image` 优先使用用户上传或填写的封面图 URL。
- 如果用户未提供封面图，系统在任务完成后尝试生成首帧封面；生成失败则视频仍可预览和下载，但不允许发布自媒体，页面提示先补充封面。
- `quota_ledger_id` 关联 `admin_resource_ledger`，用于追踪生成视频次数扣减。

可选后续表：

- `video_generation_assets`：如果后续需要对一个任务下多个视频做独立命名、封面、下载统计、删除管理，再拆出资产表。本期可以先用 JSON 字段降低复杂度。

## 配置

新增配置文件或扩展现有配置：

- `VIDEO_GENERATION_ENABLED=true`
- `VIDEO_GENERATION_BASE_URL=http://127.0.0.1:8080`
- `VIDEO_GENERATION_API_KEY=`
- `VIDEO_GENERATION_TIMEOUT=30`
- `VIDEO_GENERATION_CONNECT_TIMEOUT=10`
- `VIDEO_GENERATION_POLL_INTERVAL=10`
- `VIDEO_GENERATION_MAX_POLL_MINUTES=60`

如果 MoneyPrinterTurbo 开启 `x-api-key`，服务端统一通过配置发送请求头。

## 服务层

新增服务：

1. `VideoGenerationClient`
   - 封装 MoneyPrinterTurbo HTTP 请求。
   - 方法：
     - `createVideo(array $payload, string $requestId): array`
     - `getTask(string $taskId): array`
     - `streamUrl(string $path): string`
     - `downloadUrl(string $path): string`
   - 所有请求设置 `timeout`、`connectTimeout`。

2. `VideoGenerationService`
   - 负责创建本地任务、校验权限、校验额度、组装请求参数。
   - 创建 MoneyPrinterTurbo 任务成功后扣 `PlatformPlan::RESOURCE_VIDEO_GENERATIONS`。
   - 扣减数量按 `video_count` 计算。
   - 创建成功后投递轮询 Job。

3. `SelfMediaVideoPublishService`
   - 负责把生成视频转成 CreBee 发布任务。
   - 只允许发布 `status=success` 且有可访问视频地址的记录。
   - 必须有封面图；没有封面图时拒绝发布并提示补充封面。
   - 校验选择账号为当前用户已绑定账号，且平台支持视频发布。
   - 按选择的平台账号数量扣 `PlatformPlan::RESOURCE_CREBEE_PUBLISHES`。
   - 创建 `crebee_publish_jobs`，`content_type=video`，`content_source_type=video_generation`。

## 队列与异步流程

新增 Job：`PollVideoGenerationJob`

流程：

1. 用户提交生成任务。
2. 服务端创建 `video_generation_jobs`，状态为 `queued`。
3. 调用 `POST /api/v1/videos`，拿到 `task_id`。
4. 扣减 `video_generations` 额度，记录 `quota_ledger_id`。
5. 更新任务为 `processing`。
6. 投递 `PollVideoGenerationJob` 到 `geoflow` 队列。
7. Job 调用 `GET /api/v1/tasks/{task_id}`。
8. 如果 `state=4`，更新 `progress`，延迟下一次轮询。
9. 如果 `state=1`，写入 `videos`、`combined_videos`、`script`、`terms` 等结果，状态改为 `success`。
10. 如果 `state=-1` 或超过最大轮询时间，状态改为 `failed`。

Job 约束：

- 使用较短 timeout，例如 60 秒。
- 不在单个 Job 内长时间 while sleep。
- 每次查询后用 `dispatch()->delay()` 投递下一次轮询。
- 实现 `failed()`，确保异常时写入失败原因。

## 后台页面

新增菜单：`生成视频`

页面：

1. 视频列表
   - 标题/主题
   - 状态
   - 进度
   - 比例
   - 创建时间
   - 完成时间
   - 操作：查看、预览、下载、发布自媒体

2. 创建视频
   - 视频主题，必填
   - 视频脚本，可选
   - 素材关键词，可选
   - 排除关键词，可选
   - 视频比例：`9:16`、`16:9`、`1:1`
   - 生成数量，默认 1
   - 配音，先提供默认值
   - 字幕开关，默认开启
   - 封面图，可选

3. 视频详情
   - 在线预览
   - 下载按钮
   - 生成参数
   - 生成脚本
   - 结果地址
   - 失败原因
   - 发布自媒体入口

4. 发布自媒体弹窗
   - 列出当前用户已绑定且支持视频发布的平台账号。
   - 展示平台 logo、账号头像、账号名称。
   - 选择一个平台账号扣 1 次自媒体发布次数。

## 视频发布平台范围

首期支持 CreBee 文档中明确支持 `video` 的平台：

- 抖音：`douyin`
- B站：`bilibili`
- 快手：`kuaishou`
- 视频号：`shipinhao`
- 小红书：`xiaohongshu`
- 知乎：`zhihu`
- 微博：`weibo`
- 百家号：`baijiahao`
- 头条号：`toutiaohao`
- 企鹅号：`qiehao`
- 网易号：`wangyihao`

不包含公众号，因为公众号当前主要是文章发布。

## CreBee 发布 payload

视频发布任务示例：

```json
{
  "contentType": "video",
  "commonForm": {
    "title": "视频标题",
    "desc": "视频描述",
    "videoPath": "",
    "coverPath": "",
    "timing": 0
  },
  "assets": [
    {
      "key": "video",
      "type": "video",
      "url": "https://videoapi.example.com/tasks/task-id/final-1.mp4"
    },
    {
      "key": "cover",
      "type": "image",
      "url": "https://geo.example.com/storage/video-covers/cover.jpg"
    }
  ],
  "source": {
    "type": "video_generation",
    "video_generation_job_id": 123
  }
}
```

Agent 下载资产后，把任务发送给 CreBee 前替换：

- `commonForm.videoPath`
- `commonForm.coverPath`
- `tasks[*].params.videoPath`
- `tasks[*].params.coverPath`
- 百家号等平台需要的 `verticalCoverPath` 同步设置为封面本地路径。

## Agent 改造

新增能力：

1. 资产下载
   - 根据 job `assets` 下载远程文件。
   - 支持图片和视频。
   - 下载目录默认：`tools/crebee-bridge-agent/cache/assets`
   - 文件名使用 `jobId + asset key + hash + 扩展名`，避免冲突。

2. payload 本地化
   - 发布前检查 `job.contentType === 'video'`。
   - 找到 `assets.video` 和 `assets.cover`。
   - 下载后替换路径。
   - 如果下载失败，调用云端 `failed`，错误信息写入发布任务。

3. 缓存清理
   - 新增配置：
     - `ASSET_CACHE_DIR=cache/assets`
     - `ASSET_CACHE_MAX_AGE_HOURS=72`
   - 每次启动或每轮 tick 后清理过期缓存。

4. 状态回写
   - 继续使用现有 SSE。
   - 继续使用 publish-record 兜底轮询。
   - 发布记录仍写回 `crebee_publish_job_items`。

## 发布记录

复用 `crebee_publish_records` 页面和 `crebee_publish_job_items` 数据。

调整点：

- 页面标题保持“自媒体发布记录”。
- 内容列显示内容类型：文章 / 视频。
- 如果 `job.content_type=video`，展示视频标题和来源“生成视频”。
- 支持按内容类型筛选可作为后续优化，本期可先统一展示。

## 权限与隔离

生成视频任务：

- 普通用户：只能查看和操作自己的 `owner_admin_id`。
- 代理管理员：可查看当前站点下所有用户任务，但创建任务仍归属自己。
- 超管：按当前站点上下文查看，后续如需全站筛选再扩展。

视频发布：

- 只能发布自己拥有的视频。
- 代理管理员如果要代用户发布，需单独设计“代发布”入口，本期不默认开放。
- 可选账号必须是当前用户已绑定账号。

## 错误处理

1. MoneyPrinterTurbo API 不可用
   - 创建任务失败，不扣生成视频次数。
   - 页面提示“视频生成服务暂不可用”。

2. MoneyPrinterTurbo 任务失败
   - 状态改为 `failed`。
   - 保留失败原因。
   - 已扣的视频生成次数不自动退回，因为外部生成任务已实际消耗资源。

3. 视频完成但无成片地址
   - 状态改为 `failed`。
   - 错误原因：“视频生成完成但未返回视频文件”。

4. 无封面图时发布自媒体
   - 阻止创建发布任务。
   - 提示“视频发布需要封面图，请先补充封面”。

5. Agent 下载视频或封面失败
   - 发布任务失败。
   - 写入 `failure_reason` 和 job item message。

## 测试方案

Feature 测试：

1. 用户可以创建视频生成任务。
2. 创建任务成功后扣 `video_generations`。
3. 额度不足时无法创建任务。
4. 普通用户看不到其他用户视频任务。
5. 代理可查看当前站点下用户任务。
6. 任务轮询成功后写入视频地址并状态成功。
7. 任务轮询失败后写入失败原因。
8. 视频无封面时不能发布自媒体。
9. 视频发布按选择平台数量扣 `crebee_publishes`。
10. 视频发布记录出现在现有自媒体发布记录页。

Unit 测试：

1. `VideoGenerationClient` 正确组装请求。
2. `SelfMediaVideoPublishService` 正确生成不同平台视频参数。
3. Agent 资产下载和 payload 本地化逻辑可单测。

手工验证：

1. 配置 MoneyPrinterTurbo base URL。
2. 创建 1 条视频任务。
3. 等待任务完成。
4. 在线预览和下载。
5. 选择已绑定 B站或抖音账号发布。
6. 检查 agent 日志、CreBee 日志、发布记录页。

## 生产发布注意事项

1. 云端 `.env` 配置 MoneyPrinterTurbo 地址和 API Key。
2. 确认队列 worker/Horizon 运行 `geoflow` 队列。
3. 运维电脑启动 CreBee 客户端。
4. 运维电脑启动 Bridge Agent。
5. Agent `.env` 的 `GEOFLOW_BASE_URL` 生产环境使用 `https://geo.xinzhidi.cn`。
6. Agent 本地磁盘预留视频缓存空间。
7. MoneyPrinterTurbo 对外 endpoint 应配置为公网可访问地址，否则云端页面无法预览，Agent 也无法下载。

## 实施顺序

1. 新增数据库表、模型、配置。
2. 新增 MoneyPrinterTurbo client 和视频生成服务。
3. 新增创建任务、列表、详情、预览下载页面。
4. 新增轮询 Job。
5. 新增视频自媒体发布服务和路由。
6. 扩展 CreBee 平台 catalog，增加视频平台列表。
7. 改造 Agent：资产下载、本地路径替换、缓存清理。
8. 调整发布记录页显示内容类型。
9. 补充测试。
