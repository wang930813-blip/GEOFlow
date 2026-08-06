# ceying-geo MCP Server

## 1. 定位与边界

ceying-geo MCP Server 是独立 Node 服务，用于把 ceying-geo 已有用户侧业务能力提供给支持 MCP 的客户端。

- 不使用 `laravel/mcp`。
- 不直接连接 PostgreSQL、Redis 或 Laravel 内部模型。
- 只通过 ceying-geo `/api/v1` 调用现有业务服务。
- 使用专用 MCP Key 鉴权，Key 由 ceying-geo 用户侧创建并哈希保存。
- 所有数据请求受 Key 绑定站点、创建账号和 scope 约束。
- 不开放文章删除和审核能力；素材写入、素材删除、文章写入、文章站内发布、媒体投稿、视频生成与品牌诊断执行必须由 Key 显式授权。
- 正式品牌名称和推荐客户端配置名均为 `ceying-geo`。
- `GEO`、`geo`、`GEOFlow`、`geoflow` 继续作为兼容触发名称，现有 `geo_*` 工具名保持不变。
- 配套 Skill 定位为“策影GEO品牌增长智能体”，自然识别品牌或产品推广、竞争分析、品牌定位、AI 搜索可见性、内容资产、媒体传播和效果评估需求，不依赖用户显式输入 GEO 或 MCP。

## 2. 工具清单

| 工具 | Scope | 作用 | 费用 |
|------|-------|------|------|
| `geo_get_catalog` | `catalog:read` | 获取模型、提示词、标题库、知识库、作者和分类 | 不扣费 |
| `geo_list_tasks` | `tasks:read` | 分页查询任务 | 不扣费 |
| `geo_get_task` | `tasks:read` | 查询任务详情和运行概况 | 不扣费 |
| `geo_run_task` | `tasks:write` | 向现有任务投递一次文章生成执行 | 成功生成后扣文章生成额度 |
| `geo_list_task_runs` | `tasks:read` | 查询任务执行记录 | 不扣费 |
| `geo_get_task_run` | `jobs:read` | 查询单次执行详情 | 不扣费 |
| `geo_get_material_summary` | `materials:read` | 查询六类素材数量摘要 | 不扣费 |
| `geo_list_materials` | `materials:read` | 分页查询指定类型素材 | 不扣费 |
| `geo_get_material` | `materials:read` | 查询单个素材详情 | 不扣费 |
| `geo_list_material_items` | `materials:read` | 查询关键词、标题、图片条目或知识库切块 | 不扣费 |
| `geo_create_material` | `materials:write` | 创建分类、作者或素材库 | 不扣费 |
| `geo_update_material` | `materials:write` | 更新指定素材 | 不扣费 |
| `geo_delete_material` | `materials:write` | 删除未被业务数据占用的素材 | 不扣费 |
| `geo_create_material_item` | `materials:write` | 新增关键词、标题或图片条目 | 不扣费 |
| `geo_delete_material_items` | `materials:write` | 批量删除关键词、标题或图片条目 | 不扣费 |
| `geo_list_articles` | `articles:read` | 分页查询文章 | 不扣费 |
| `geo_get_article` | `articles:read` | 查询文章完整内容 | 不扣费 |
| `geo_create_article` | `articles:write` | 保存外部 AI 已完成编写的文章，自动通过审核但不公开发布 | 不扣费 |
| `geo_publish_article_to_site` | `articles:site-publish` | 将未被拒绝的文章自动通过并发布到当前 GEO 用户站点 | 不扣费 |
| `geo_list_media_channels` | `media:read` | 查询当前站点媒体渠道和实时售价 | 不扣费 |
| `geo_get_media_channel` | `media:read` | 查询单个媒体渠道详情和实时售价 | 不扣费 |
| `geo_list_media_submissions` | `media:read` | 查询当前账号投稿记录 | 不扣费 |
| `geo_get_media_submission` | `media:read` | 查询投稿状态和发布链接 | 不扣费 |
| `geo_submit_article_to_media` | `media:submit` | 将当前账号已有文章投递到指定渠道 | 按渠道实际售价扣费 |
| `geo_publish_article_to_media` | `articles:write`、`media:submit` | 保存文章并投递指定渠道 | 按渠道实际售价扣费 |
| `geo_list_videos` | `videos:read` | 分页查询当前账号的视频生成任务 | 不扣费 |
| `geo_get_video` | `videos:read` | 查询视频生成状态和结果地址 | 不扣费 |
| `geo_create_video` | `videos:write` | 创建视频生成任务 | 按生成数量扣减视频生成额度 |
| `geo_list_brand_diagnoses` | `brand-diagnoses:read` | 分页查询当前账号的品牌诊断任务 | 不扣费 |
| `geo_get_brand_diagnosis` | `brand-diagnoses:read` | 查询诊断进度、问题、回答、来源和排名 | 不扣费 |
| `geo_create_brand_diagnosis` | `brand-diagnoses:write` | 创建品牌诊断问题生成任务 | 不扣费 |
| `geo_confirm_brand_diagnosis` | `brand-diagnoses:write` | 确认问题并启动正式品牌诊断 | 扣减一次品牌诊断额度 |

工具根据 MCP Key scope 动态注册。客户端不会看到未授权工具。

## 3. Key 鉴权

1. 登录 ceying-geo 后台。
2. 切换到需要接入的站点。
3. 进入“账号与权益 > MCP Server”。
4. 输入 Key 名称和过期时间。
5. 按最小权限原则选择业务 scope；素材、媒体和品牌诊断的读取与写入权限分别授权。
6. 创建后立即复制 Key，明文只显示一次。
7. 客户端使用请求头：`Authorization: Bearer <MCP_KEY>`。

创建 Key 即启用当前站点 MCP；撤销全部 Key或全部 Key 过期即停用。

Key 使用 Sanctum 标准格式和哈希存储。MCP 服务每次请求都会调用 `/api/v1/auth/me` 校验 Key、账号状态、站点状态和 scope，不缓存明文 Key。

## 4. 客户端配置

支持 Streamable HTTP 的客户端：

```json
{
  "mcpServers": {
      "ceying-geo": {
      "type": "streamable-http",
      "url": "https://geo.example.com/mcp",
      "headers": {
        "Authorization": "Bearer <MCP_KEY>"
      }
    }
  }
}
```

仅支持 stdio 的客户端可使用 `mcp-remote`：

```json
{
  "mcpServers": {
      "ceying-geo": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://geo.example.com/mcp",
        "--header",
        "Authorization: Bearer <MCP_KEY>"
      ]
    }
  }
}
```

Key 不得放入 URL、提示词、公开仓库或日志。生产环境必须使用 HTTPS，并由客户端安全存储或环境变量注入。

## 5. 配套 Skill

用户可在“账号与权益 > MCP Server”下载 `ceying-geo-content-operations` Skill。源码位于 `resources/skills/ceying-geo-content-operations`，下载时由应用生成版本化 ZIP，不包含 MCP Key、站点域名或用户数据。技术包名保持不变，用户侧角色名称为“策影GEO品牌增长智能体”。

- Skill 正式名称：`ceying-geo-content-operations`。
- 当前 Skill 版本：`2.4.0`。
- 适配 ceying-geo MCP Server：`1.5.0` 及以上。
- 兼容触发名称：策影 GEO、GEO、geo、GEOFlow、geoflow。
- 自然触发场景：品牌或产品推广、视频生成、文章站内发布、竞争对手分析、品牌定位评估、AI 搜索可见性、内容与媒体增长、推广效果评估。
- MCP 自动安装：支持 MCP 连接管理或 Skill 依赖声明的客户端会由 Skill 主动检查、创建、保存和重载 `ceying-geo` Streamable HTTP 连接；未发现 `geo_*` 工具时引导用户获取当前站点地址、创建最小权限 Key，并在客户端安全区域填写。
- 强制连接门禁：Skill 每次触发后首先检查实际工具。完全未发现 `geo_*` 时暂停品牌分析、路线图和业务资料追问，立即进入安装状态机；只有连接验证成功，或用户明确选择暂不安装、只做纯策略时，才能继续品牌咨询。
- Skill 继续调用稳定的 `geo_*` 工具名，已有客户端无需迁移工具调用。

安装时保留压缩包中的完整根目录，将其放入客户端声明的 Agent Skills 目录。安装后可直接询问“我应该如何推广品牌”“我的竞争对手是谁”“品牌定位是否有竞争力”等自然业务问题，不需要在输入中加入 GEO、ceying-geo 或 MCP。Skill 在首次需要平台数据时检查 `geo_*` 工具：支持依赖安装的客户端优先打开 `ceying-geo` 连接流程，其他客户端按实际产品界面提供手动配置引导。客户端不支持 Agent Skills 时，可复制管理页提供的兼容指令继续使用 MCP 工具。

Skill 不内置 MCP Server 地址或 MCP Key。用户必须在当前站点“MCP Server”页面获取真实公网地址并创建 Key，再将 Key 直接填入客户端安全凭证区域；不得把 Key 发送到对话、写入 Skill、拼接到 URL 或提交到版本库。用户完成安全填写后，Skill 继续完成配置保存、连接重载、工具发现和只读验证，不把客户端能够自动完成的步骤转交给用户。仅保存配置、仅显示连接名称或仅返回健康检查成功都不算安装完成，必须实际发现目标 `geo_*` 工具并通过只读调用。

Skill 采用“识别目标、收集最少必要信息、判断是否需要诊断、区分事实与建议、输出增长路线图、获得授权、MCP 执行、跟踪结果”的流程。用户明确要求投稿、执行已有任务或管理素材时可直接进入执行模块，不强制重新诊断。

当前品牌诊断只支持豆包、DeepSeek、千问和文心一言。ChatGPT 等其他平台的问题可以触发 Skill，但 MCP Server 不能执行对应平台实测。当前也没有定时品牌监控和自动趋势对比工具，只能按相同问题及模型重复诊断后人工比较。AI 答案中的占位品牌不等同于现实市场中的完整竞争对手，市场竞品仍需可靠来源验证。

## 6. 费用结算

MCP 不建立新的费用体系，全部复用 ceying-geo 现有账号级套餐和资源流水。

- 创建一个有效 MCP Key，会占用当前账号一个 API Token 数量名额。
- 查询目录、任务、执行记录和文章不扣减业务额度。
- `geo_run_task` 只负责入队，不在 MCP 层预扣费用。
- Worker 成功创建文章后，扣减一次 `article_generations`。
- 任务启用 AI 配图时，按实际成功生成图片数量扣减 `ai_image_generations`。
- 生成失败且未创建文章时，不扣文章生成额度。
- 素材查询和素材管理不扣减业务额度。
- `geo_publish_article_to_site` 将未被拒绝的文章自动标记为 `auto_approved` 并发布到当前 GEO 用户站点，不扣媒体投稿费用。
- 创建品牌诊断和查询诊断结果不扣减业务额度。
- `geo_confirm_brand_diagnosis` 成功确认问题时扣减一次当前账号套餐中的 `brand_diagnoses`，重复调用同一幂等请求不会重复扣减。
- `geo_create_video` 按 `video_count` 扣减当前账号套餐中的 `video_generations`，重复调用同一幂等请求不会重复扣减。
- 单次 MCP 投稿最多选择 20 个渠道。
- 每篇文章按渠道实时售价逐笔扣费，提交失败按现有媒体结算规则自动退款。

费用记录仍写入项目现有账号资源使用表和流水表，可在“规格使用情况”中查看。

## 7. 本机开发

`.env` 至少包含：

```dotenv
MCP_SERVER_PUBLIC_URL=http://localhost:18082/mcp
MCP_EXPOSE_PORT=18082
MCP_API_TIMEOUT_MS=30000
MCP_ALLOWED_HOSTS=localhost,127.0.0.1
MCP_HEALTHCHECK_HOST=localhost
MCP_TRUSTED_PROXIES=
MCP_RATE_LIMIT_WINDOW_MS=60000
MCP_RATE_LIMIT_IP_MAX=120
MCP_RATE_LIMIT_TOKEN_MAX=60
MCP_REQUIRE_HTTPS=false
```

启动：

```powershell
docker compose --env-file .env -f docker-compose.dev.yml up -d --build
```

检查：

```powershell
docker compose --env-file .env -f docker-compose.dev.yml ps
Invoke-WebRequest -UseBasicParsing http://localhost:18082/health
```

开发容器将 `mcp-server` 目录挂载到 `/app`，使用 `tsx watch` 热重载。

## 8. 生产部署

`.env.prod` 增加：

```dotenv
MCP_SERVER_PUBLIC_URL=https://geo.example.com/mcp
MCP_EXPOSE_PORT=18082
MCP_API_TIMEOUT_MS=30000
MCP_ALLOWED_HOSTS=geo.example.com
MCP_HEALTHCHECK_HOST=geo.example.com
MCP_TRUSTED_PROXIES=uniquelocal
MCP_RATE_LIMIT_WINDOW_MS=60000
MCP_RATE_LIMIT_IP_MAX=120
MCP_RATE_LIMIT_TOKEN_MAX=60
MCP_REQUIRE_HTTPS=true
```

启动：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build mcp
```

生产环境必须满足：

- 使用 HTTPS。
- `MCP_REQUIRE_HTTPS=true`，反向代理必须正确传递 `X-Forwarded-Proto: https`。
- `MCP_ALLOWED_HOSTS` 只包含实际对外域名。
- 在 Nginx、网关或负载均衡器限制请求体和连接速率。
- 反向代理保留 `Authorization`、`Content-Type`、`Accept` 和 `MCP-Protocol-Version` 请求头。
- 反向代理关闭响应缓冲或允许流式响应。
- 不在访问日志中记录 Authorization 请求头。

Nginx 反向代理核心配置：

```nginx
location /mcp {
    proxy_pass http://127.0.0.1:18082/mcp;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header Authorization $http_authorization;
    proxy_set_header MCP-Protocol-Version $http_mcp_protocol_version;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_buffering off;
    proxy_read_timeout 300s;
}
```

## 9. 错误处理

| 状态 | 原因 | 处理 |
|------|------|------|
| `401` | Key 缺失、格式错误、已过期或已撤销 | 检查 Bearer 请求头，必要时重新创建 Key |
| `403` | 不是专用 MCP Key、缺少 scope、站点停用 | 检查 Key 权限和绑定站点 |
| `404` | 任务、执行记录、文章或品牌诊断不属于当前账号和站点 | 确认资源编号和 Key 绑定站点 |
| `409` | 相同幂等操作仍在处理中、同键载荷不一致或品牌诊断状态不可确认 | 保持原幂等键并等待当前请求完成，确认诊断问题已生成且任务尚未启动 |
| `422` | 参数校验失败、素材仍被占用或套餐额度不足 | 按错误信息修正参数、解除占用或升级规格 |
| `426` | 使用了非 HTTPS 连接 | 修正公网 TLS 和 `X-Forwarded-Proto` 配置 |
| `429` | IP 或 Token 请求超过限流 | 按响应头等待后重试，降低并发调用频率 |
| `502` | MCP 服务无法访问 ceying-geo API | 检查 `GEOFLOW_API_BASE_URL` 和应用容器状态 |
| `504` | ceying-geo API 请求超时 | 检查应用负载并调整 `MCP_API_TIMEOUT_MS` |

执行任务后应保存返回的执行记录编号，并通过 `geo_get_task_run` 查询最终状态。不要在未确认状态前重复投递相同任务。

媒体投稿前必须先读取渠道实时售价，并且只使用用户明确指定的媒体资源编号；重试同一操作时必须保持正文、渠道和 `idempotency_key` 不变。

## 10. 文章站内发布与媒体投稿边界

站内发布和媒体投稿是两条不同路径：

- `geo_publish_article_to_site` 只把未被拒绝的文章置为 `published`，需要时自动写入 `review_status=auto_approved`，使其出现在当前 GEO 用户站点文章页，不创建媒体投稿订单，也不扣媒体投稿费用。
- `geo_submit_article_to_media` 将已有文章投递到外部媒体渠道，按渠道实时售价扣费。
- `geo_publish_article_to_media` 先保存已自动通过审核的 AI 文章，再投递到外部媒体渠道，按渠道实时售价扣费。

站内发布执行顺序：

1. 使用 `geo_list_articles` 或用户提供的文章编号定位文章。
2. 使用 `geo_get_article` 核对标题、正文、分类、作者、`status` 和 `review_status`。
3. 只要 `review_status` 不是 `rejected`，即可在用户确认后调用 `geo_publish_article_to_site`；MCP 发布会自动通过审核。
4. 发布成功后读取返回的 `status`、`review_status`、`published_at` 和 `slug`，并告知用户文章已进入当前 GEO 用户站点。
5. 如果文章已被人工拒绝，不要覆盖拒绝结论，提示用户先修正文章内容。

媒体投稿前必须先读取渠道实时售价，并且只使用用户明确指定的媒体资源编号；重试同一操作时必须保持正文、渠道和 `idempotency_key` 不变。

## 11. 素材字段与操作边界

素材类型固定为：

- `categories`：分类，创建字段为 `name`，可选 `slug`、`description`、`sort_order`。
- `authors`：作者，创建字段为 `name`，可选 `email`、`bio`、`avatar`、`website`、`social_links`。
- `keyword-libraries`：关键词库，字段为 `name`、`description`；条目字段为 `keyword`。
- `title-libraries`：标题库，字段为 `name`、`description`；条目字段为 `title`，可选 `keyword`。
- `image-libraries`：图片库，字段为 `name`、`description`；图片条目必须提供 `file_path`，可选文件名、尺寸、类型和标签元数据。
- `knowledge-bases`：知识库，创建字段为 `name`、`content`，可选 `description`、`file_type`、`file_path`；正文保存后由 ceying-geo 自动切块。

推荐操作顺序：

1. 使用 `geo_get_material_summary` 确认素材类型和数量。
2. 使用 `geo_list_materials` 查找素材编号，必要时使用 `geo_get_material` 获取完整内容。
3. 使用 `geo_list_material_items` 查询库内条目；分类和作者没有条目接口。
4. 使用写工具创建或更新素材。知识库切块只能读取，不能直接新增或删除。
5. 删除素材或素材条目前先确认编号和引用关系；仍被文章、任务或其他素材引用时，ceying-geo 会拒绝删除。
6. 所有写操作重试必须保持 `idempotency_key` 和请求内容不变。

## 12. 视频生成流程

视频工具只访问 MCP Key 创建账号在绑定站点中的视频生成任务，不读取同站点其他账号的数据。MCP 当前只负责视频生成和结果查询，不发布到自媒体平台。

执行顺序：

1. 调用 `geo_create_video` 提交视频主题、脚本、比例、数量和可选封面图，保存返回的视频任务编号。
2. 调用 `geo_get_video` 查询进度；`status=queued` 表示已入队，`status=processing` 表示生成中，`status=success` 表示可读取 `first_video_url`。
3. 生成失败时读取 `failure_reason`，不要重复创建同一任务；需要重试时使用新的明确主题或用户确认后的新幂等键。

状态说明：

| 字段 | 值 | 含义 |
|------|----|------|
| `status` | `queued` | 视频生成任务已排队 |
| `status` | `processing` | 视频生成中 |
| `status` | `success` | 视频生成成功，可读取视频地址 |
| `status` | `failed` | 视频生成失败，读取 `failure_reason` |

`geo_create_video` 会按 `video_count` 扣减 `video_generations`。视频生成成功不等于已发布；如果用户需要发布视频，需要在 MCP 之外使用平台后台或其他已授权发布工具完成。

## 13. 品牌诊断流程与边界

品牌诊断工具只访问 MCP Key 创建账号在绑定站点中的用户侧诊断记录，不读取系统级 OpenAPI 任务，也不读取同站点其他账号的任务。

支持模型：

- `doubao`：豆包。
- `deepseek`：DeepSeek。
- `qianwen`：千问。
- `wenxin`：文心一言。

执行顺序：

1. 调用 `geo_create_brand_diagnosis` 提交品牌词和一至四个模型，保存返回的 `run_id`。
2. 调用 `geo_get_brand_diagnosis` 查询进度；`raw_status=questions_generating` 表示正在生成问题。
3. 当 `raw_status=questions_ready` 且 `can_confirm=true` 时检查 `questions`。需要调整时向确认工具传入问题编号和文本；省略 `questions` 表示保留全部问题。
4. 在用户确认消耗一次品牌诊断额度后，调用 `geo_confirm_brand_diagnosis`。网络重试必须保持 `idempotency_key` 和问题内容不变。
5. 继续调用 `geo_get_brand_diagnosis`，直到 `status=completed` 或 `status=failed`。完成后读取 `brand_performance`、`model_results`、`sources` 和 `rankings`。

状态说明：

| 字段 | 值 | 含义 |
|------|----|------|
| `status` | `diagnosing` | 正在生成问题、等待确认或执行诊断 |
| `status` | `completed` | 诊断完成，可读取完整结果 |
| `status` | `failed` | 诊断失败，读取 `error_message` |
| `raw_status` | `questions_ready` | 问题已生成，可以确认并消耗额度启动诊断 |
| `can_confirm` | `true` | 当前任务允许调用确认工具 |

创建任务不等于正式诊断，不扣减额度。只有确认工具成功将任务转为执行中时才消耗一次 `brand_diagnoses`；额度不足返回 `422`，任务保留在可确认状态。
