# GEO MCP Server

## 1. 定位与边界

GEO MCP Server 是独立 Node 服务，用于把 GEOFlow 已有用户侧业务能力提供给支持 MCP 的客户端。

- 不使用 `laravel/mcp`。
- 不直接连接 PostgreSQL、Redis 或 Laravel 内部模型。
- 只通过 GEOFlow `/api/v1` 调用现有业务服务。
- 使用专用 MCP Key 鉴权，Key 由 GEOFlow 用户侧创建并哈希保存。
- 所有数据请求受 Key 绑定站点、创建账号和 scope 约束。
- 首版不开放删除、审核、发布、媒体投放等高风险操作。

## 2. 首版工具

| 工具 | Scope | 作用 | 费用 |
|------|-------|------|------|
| `geo_get_catalog` | `catalog:read` | 获取模型、提示词、标题库、知识库、作者和分类 | 不扣费 |
| `geo_list_tasks` | `tasks:read` | 分页查询任务 | 不扣费 |
| `geo_get_task` | `tasks:read` | 查询任务详情和运行概况 | 不扣费 |
| `geo_run_task` | `tasks:write` | 向现有任务投递一次文章生成执行 | 成功生成后扣文章生成额度 |
| `geo_list_task_runs` | `tasks:read` | 查询任务执行记录 | 不扣费 |
| `geo_get_task_run` | `jobs:read` | 查询单次执行详情 | 不扣费 |
| `geo_list_articles` | `articles:read` | 分页查询文章 | 不扣费 |
| `geo_get_article` | `articles:read` | 查询文章完整内容 | 不扣费 |

工具根据 MCP Key scope 动态注册。客户端不会看到未授权工具。

## 3. Key 鉴权

1. 登录 GEOFlow 后台。
2. 切换到需要接入的站点。
3. 进入“账号与权益 > MCP Server”。
4. 输入 Key 名称和过期时间。
5. 按最小权限原则选择业务 scope。
6. 创建后立即复制 Key，明文只显示一次。
7. 客户端使用请求头：`Authorization: Bearer <MCP_KEY>`。

创建 Key 即启用当前站点 MCP；撤销全部 Key或全部 Key 过期即停用。

Key 使用 Sanctum 标准格式和哈希存储。MCP 服务每次请求都会调用 `/api/v1/auth/me` 校验 Key、账号状态、站点状态和 scope，不缓存明文 Key。

## 4. 客户端配置

支持 Streamable HTTP 的客户端：

```json
{
  "mcpServers": {
    "geoflow": {
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
    "geoflow": {
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

Key 不得放入 URL、提示词、公开仓库或日志。生产环境建议由客户端安全存储或环境变量注入。

## 5. 费用结算

MCP 不建立新的费用体系，全部复用 GEOFlow 现有账号级套餐和资源流水。

- 创建一个有效 MCP Key，会占用当前账号一个 API Token 数量名额。
- 查询目录、任务、执行记录和文章不扣减业务额度。
- `geo_run_task` 只负责入队，不在 MCP 层预扣费用。
- Worker 成功创建文章后，扣减一次 `article_generations`。
- 任务启用 AI 配图时，按实际成功生成图片数量扣减 `ai_image_generations`。
- 生成失败且未创建文章时，不扣文章生成额度。
- 首版没有媒体投放工具，因此不会触发媒体积分或官媒发布费用。

费用记录仍写入项目现有账号资源使用表和流水表，可在“规格使用情况”中查看。

## 6. 本机开发

`.env` 至少包含：

```dotenv
MCP_SERVER_PUBLIC_URL=http://localhost:18082/mcp
MCP_EXPOSE_PORT=18082
MCP_API_TIMEOUT_MS=30000
MCP_ALLOWED_HOSTS=localhost,127.0.0.1
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

## 7. 生产部署

`.env.prod` 增加：

```dotenv
MCP_SERVER_PUBLIC_URL=https://geo.example.com/mcp
MCP_EXPOSE_PORT=18082
MCP_API_TIMEOUT_MS=30000
MCP_ALLOWED_HOSTS=geo.example.com
```

启动：

```bash
docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build mcp
```

生产环境必须满足：

- 使用 HTTPS。
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
    proxy_buffering off;
    proxy_read_timeout 300s;
}
```

## 8. 错误处理

| 状态 | 原因 | 处理 |
|------|------|------|
| `401` | Key 缺失、格式错误、已过期或已撤销 | 检查 Bearer 请求头，必要时重新创建 Key |
| `403` | 不是专用 MCP Key、缺少 scope、站点停用 | 检查 Key 权限和绑定站点 |
| `404` | 任务、执行记录或文章不属于当前站点 | 确认资源编号和 Key 绑定站点 |
| `422` | 参数校验失败或套餐额度不足 | 按错误信息修正参数或升级规格 |
| `502` | MCP 服务无法访问 GEO API | 检查 `GEOFLOW_API_BASE_URL` 和应用容器状态 |
| `504` | GEO API 请求超时 | 检查应用负载并调整 `MCP_API_TIMEOUT_MS` |

执行任务后应保存返回的执行记录编号，并通过 `geo_get_task_run` 查询最终状态。不要在未确认状态前重复投递相同任务。
