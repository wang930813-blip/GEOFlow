# CreBee 本地桥接发布技术方案

## 背景

项目需要接入 CreBee 批量发布能力。CreBee 客户端提供本地 HTTP API：

- Base URL：`http://127.0.0.1:3456`
- API 前缀：`/galic/v1`
- 认证：`POST /galic/v1/auth/token`
- 账号列表：`POST /galic/v1/account/getAll`
- 批量发布：`POST /galic/v1/platform/publish/batch`
- 发布进度：`GET /galic/v1/sse` 或 `ws://127.0.0.1:3456/galic/v1/ws`

限制点是：用户账号信息只存储在 CreBee 本地客户端，没有云端同步。生产服务器无法直接访问运维电脑上的 `127.0.0.1:3456`，也不应该把本地 CreBee API 直接暴露到公网。

## 目标

1. 用户在系统中发起账号绑定，例如绑定抖音账号。
2. 平台运维在本地电脑登录 CreBee 客户端，向用户提供平台登录二维码。
3. 用户扫码登录成功后，云端系统能收到绑定成功状态，并把该 CreBee 本地账号映射到系统用户。
4. 用户生成文章或视频后，在云端点击发布，云端能通过运维电脑上的 CreBee 客户端完成实际发布。
5. 发布过程和结果能回传云端，系统页面能展示排队、发布中、成功、失败等状态。

## 推荐架构

采用“云端 GEOFlow + 本地 CreBee Bridge Agent”的结构。

```text
用户浏览器
  |
  | 绑定账号、提交发布
  v
GEOFlow 云端服务器
  |
  | HTTPS 长轮询 / WebSocket / 队列拉取
  v
CreBee Bridge Agent（运行在运维电脑）
  |
  | HTTP 127.0.0.1:3456
  v
CreBee 本地客户端
  |
  v
抖音 / B站 / 小红书 / 公众号等平台
```

核心原则：

- 云端不直接请求用户或运维电脑的 `127.0.0.1`。
- 运维电脑上的 Bridge Agent 主动连接云端，拉取绑定同步任务和发布任务。
- Bridge Agent 在本机调用 CreBee API，再把账号列表、发布进度、发布结果回传云端。
- 云端只保存系统业务映射，不保存第三方平台 Cookie、登录态、敏感本地账号凭证。

## 为什么不建议直接内网穿透

可以用 frp、Tailscale、Cloudflare Tunnel 等方式让云端访问运维电脑本地端口，但不推荐作为默认方案：

- 容易把 CreBee 本地管理接口暴露到外部网络。
- 多运维电脑、多站点、多用户时，路由和权限边界复杂。
- 本地电脑离线、换 IP、重启后更容易出现不可控故障。
- 云端主动访问本地端口，安全审计和任务归属较弱。

Bridge Agent 主动出站连接云端，更适合当前业务：

- 防火墙友好，不需要开放运维电脑入站端口。
- Agent 可以携带机器身份、站点权限和心跳状态。
- 云端可以统一调度、重试、记录任务日志。
- 后续可以扩展多台运维电脑并行发布。

## 账号绑定流程

### 状态定义

绑定申请状态建议：

- `pending`：用户已申请绑定，等待运维处理。
- `waiting_scan`：运维已打开登录二维码，等待用户扫码。
- `syncing`：用户扫码成功后，Agent 正在同步 CreBee 本地账号。
- `bound`：绑定成功。
- `failed`：绑定失败。
- `expired`：长时间未处理，绑定申请过期。
- `revoked`：用户或平台解除绑定。

### 绑定步骤

1. 用户在系统选择平台，例如抖音，点击“申请绑定”。
2. 云端创建 `crebee_bind_requests` 记录，状态为 `pending`。
3. 运维后台看到待处理申请。
4. 运维在安装 CreBee 的电脑上打开对应平台登录入口，将二维码提供给用户。
5. 运维后台把申请状态改为 `waiting_scan`，用户页面显示“请扫码登录”。
6. 用户扫码完成后，Bridge Agent 调用本地 CreBee：

```http
POST /galic/v1/auth/token
POST /galic/v1/account/getAll
Authorization: Bearer <token>
Body: {}
```

7. Bridge Agent 把本地账号列表回传云端。
8. 云端根据本次绑定申请和运维选择，把某个 CreBee 本地账号绑定到用户、站点和平台。
9. 云端写入 `crebee_accounts`，绑定状态为 `bound`。
10. 用户页面显示“绑定成功”。

### 绑定确认策略

CreBee 文档中目前没有看到“生成登录二维码”或“登录成功回调”接口。因此绑定过程建议使用人工确认 + 自动账号同步：

- 二维码仍由运维通过 CreBee 客户端提供。
- Bridge Agent 只负责同步 `account/getAll` 的账号列表。
- 运维在后台确认“本次新增或选中的账号属于哪个用户”。
- 云端建立账号映射后，用户侧才显示绑定成功。

如果后续 CreBee 提供登录二维码接口或账号变更事件，可以把人工确认替换成自动识别。

## 发布流程

### 发布状态定义

云端发布主任务状态：

- `queued`：云端已创建任务，等待 Agent 拉取。
- `dispatching`：Agent 已领取任务。
- `preparing_assets`：Agent 正在准备本地素材。
- `submitted`：已调用 CreBee 批量发布接口。
- `publishing`：CreBee 正在发布。
- `success`：全部平台发布成功。
- `partial_success`：部分平台成功、部分失败。
- `failed`：全部失败或任务无法提交。
- `cancelled`：已取消。

子任务状态对应 CreBee 进度事件：

- `taskQueued`
- `publishing`
- `taskRetrying`
- `taskCancelled`
- `success`
- `error`

### 发布步骤

1. 用户在云端选择已绑定账号，提交文章、图文或视频发布。
2. 云端校验：
   - 用户账号已绑定目标平台。
   - 账号属于当前用户或当前站点权限范围。
   - 用户规格中 `CreBee 发布次数` 额度可用。
   - 内容类型被目标平台支持。
3. 云端创建发布主任务和平台子任务。
4. 云端生成全局唯一 `taskId`，每个平台子任务一个。
5. 云端扣减 `crebee_publishes` 额度。建议使用幂等键，避免重复点击重复扣减。
6. Bridge Agent 通过轮询或长连接领取任务。
7. Agent 下载云端素材到本地临时目录。
8. Agent 组装 CreBee 发布请求。
9. Agent 调用本地接口：

```http
POST /galic/v1/platform/publish/batch
Authorization: Bearer <token>
Content-Type: application/json
```

10. Agent 连接本地 SSE 或 WebSocket，监听发布进度：

```http
GET /galic/v1/sse
Authorization: Bearer <token>
Accept: text/event-stream
```

或：

```text
ws://127.0.0.1:3456/galic/v1/ws
```

11. Agent 收到 `platform/publish/progress` 事件后，按 `taskId` 回传云端。
12. 云端更新发布状态，并在用户页面展示。
13. 发布完成后，Agent 可调用发布记录接口校正结果：

```http
POST /galic/v1/publish-record/get-global-publish-record
```

## CreBee 发布请求要点

CreBee 批量发布请求结构：

```json
{
  "contentType": "video | image | article",
  "commonForm": {},
  "tasks": [
    {
      "taskId": "由云端生成的唯一任务ID",
      "accountId": "CreBee本地账号ID",
      "platform": "douyin",
      "contentType": "video",
      "params": {
        "taskId": "同一个任务ID",
        "title": "标题",
        "desc": "描述",
        "videoPath": "Agent本地视频绝对路径",
        "coverPath": "Agent本地封面绝对路径",
        "timing": 0
      }
    }
  ]
}
```

注意事项：

- `taskId` 必须由云端生成，并同时写入 `tasks[].taskId` 和 `tasks[].params.taskId`。
- `videoPath`、`coverPath`、`images`、`covers` 必须是运行 CreBee 客户端那台电脑上的本地路径。
- 云端的素材 URL 不能直接传给 CreBee，Agent 必须先下载到本地。
- CreBee `publish/batch` 的同步响应只代表任务提交结果，不代表平台最终发布成功。
- 最终状态以 SSE/WebSocket 进度事件为主，以发布记录查询作为校正。

## Bridge Agent 设计

### 职责

Bridge Agent 是一个运行在运维电脑上的本地程序，负责：

- 检查本机 CreBee 客户端是否可用。
- 获取和缓存 CreBee token。
- 调用 `account/getAll` 同步本地已登录账号。
- 从云端领取发布任务。
- 下载云端素材到本地。
- 调用 `platform/publish/batch`。
- 监听 SSE/WebSocket 发布进度。
- 回传任务状态、错误信息和发布结果。
- 定期上报心跳和本机能力。

### Agent 与云端通信

建议采用 HTTPS 出站通信，MVP 阶段可用轮询：

- `POST /api/crebee-agent/heartbeat`
- `POST /api/crebee-agent/accounts/sync`
- `GET /api/crebee-agent/jobs/next`
- `POST /api/crebee-agent/jobs/{job}/accepted`
- `POST /api/crebee-agent/jobs/{job}/events`
- `POST /api/crebee-agent/jobs/{job}/finished`
- `POST /api/crebee-agent/jobs/{job}/failed`

后续可以升级为 WebSocket：

- 云端实时推任务。
- Agent 实时回传进度。
- 减少轮询延迟。

### Agent 鉴权

每个 Agent 分配独立密钥：

- `agent_id`
- `agent_secret`
- `site_scope` 或 `operator_scope`
- `status`
- `last_seen_at`

请求云端时使用签名或 Bearer Token。云端只允许 Agent 操作被授权站点和用户的任务。

### Agent 健康检查

Agent 定期检查：

- CreBee 本地端口是否可访问：`GET /galic/v1/health`
- token 是否有效。
- SSE/WebSocket 是否连接。
- 本地临时目录是否可写。
- 当前任务数量。

云端展示：

- 在线
- 离线
- CreBee 不可用
- 发布中
- 最近错误

## 云端数据表建议

### `crebee_agents`

记录本地桥接 Agent。

关键字段：

- `id`
- `name`
- `agent_uid`
- `secret_hash`
- `status`
- `last_seen_at`
- `crebee_base_url`
- `version`
- `site_scope`
- `meta`

### `crebee_bind_requests`

记录用户绑定申请。

关键字段：

- `id`
- `site_id`
- `admin_id`
- `platform`
- `status`
- `agent_id`
- `operator_admin_id`
- `requested_at`
- `confirmed_at`
- `expired_at`
- `failure_reason`
- `meta`

### `crebee_accounts`

记录系统用户与 CreBee 本地账号映射。

关键字段：

- `id`
- `site_id`
- `admin_id`
- `agent_id`
- `platform`
- `crebee_account_id`
- `account_name`
- `avatar`
- `status`
- `bound_at`
- `last_synced_at`
- `raw_account`

唯一约束建议：

- `agent_id + platform + crebee_account_id`
- 一个 CreBee 本地账号不能被错误绑定给多个不相关用户。

### `crebee_publish_jobs`

记录云端发布主任务。

关键字段：

- `id`
- `site_id`
- `admin_id`
- `agent_id`
- `content_type`
- `title`
- `content_source_type`
- `status`
- `scheduled_at`
- `submitted_at`
- `finished_at`
- `quota_usage_id`
- `failure_reason`
- `payload`

### `crebee_publish_job_items`

记录每个平台账号的发布子任务。

关键字段：

- `id`
- `job_id`
- `crebee_account_id`
- `platform`
- `crebee_task_id`
- `status`
- `progress`
- `message`
- `published_url`
- `published_at`
- `raw_response`
- `last_event_at`

### `crebee_publish_events`

记录进度事件。

关键字段：

- `id`
- `job_item_id`
- `crebee_task_id`
- `event_type`
- `progress`
- `message`
- `raw_event`
- `created_at`

## 权限与数据隔离

必须遵循现有账号级资源隔离逻辑：

- 用户只能看到自己绑定的 CreBee 账号。
- 代理可以看到自己分站点下用户的绑定和发布任务。
- 超管可以看全站数据。
- 直客不能访问其他直客账号。
- Agent 回传账号列表后，只有被运维确认绑定的账号才展示给用户。

发布扣减规格资源：

- 使用 `PlatformPlan::RESOURCE_CREBEE_PUBLISHES`。
- 每次用户点击发布并成功创建云端发布任务，按任务或按平台子任务扣减，需要业务确认。
- 推荐 MVP 阶段按“用户一次发布操作”扣 1 次；如果一次发布到多个平台仍算 1 次。
- 如果发布任务未成功提交到 CreBee，可释放或回滚额度。
- 如果已提交到 CreBee 后平台发布失败，不自动退回额度，除非后台人工处理。

## 异常处理

### Agent 离线

- 云端任务保持 `queued`。
- 页面提示“发布客户端离线，等待运维客户端上线”。
- 超过配置时间后标记 `failed` 或允许用户取消。

### CreBee 本地不可用

- Agent 上报 `crebee_unavailable`。
- 云端暂停派发任务到该 Agent。
- 运维后台显示“CreBee 客户端未启动或端口不可用”。

### Token 失效

- Agent 收到 401 后重新调用 `auth/token`。
- 重试当前请求。

### 素材下载失败

- 子任务标记 `failed`。
- 主任务如果全部失败，标记 `failed`。
- 记录具体 URL 和错误原因。

### SSE/WebSocket 断开

- Agent 自动重连。
- 断线期间可能漏掉事件，重连后调用发布记录接口校正。

### 重复提交

- 云端发布按钮需要防重复点击。
- 发布任务使用幂等键。
- CreBee `taskId` 全局唯一，避免重复任务状态串联。

## 安全要求

- 不在云端保存第三方平台 Cookie 或登录凭证。
- Agent secret 只展示一次，服务端保存 hash。
- Agent 到云端必须使用 HTTPS。
- 云端下发给 Agent 的素材 URL 使用短时效签名链接。
- Agent 本地临时素材目录定期清理。
- 所有绑定确认和发布操作写入审计日志。
- 禁止把 `127.0.0.1:3456` 通过公网无鉴权暴露。

## MVP 实施范围

第一阶段先做最小可用：

1. 后台新增 CreBee Agent 管理。
2. 运维电脑部署一个 Bridge Agent。
3. 用户发起绑定申请。
4. 运维人工提供二维码并确认账号归属。
5. Agent 同步 `account/getAll`。
6. 用户可选择已绑定账号发布文章或视频。
7. Agent 调用 `platform/publish/batch`。
8. Agent 通过 SSE 监听发布进度并回传云端。
9. 云端展示发布状态和错误信息。
10. 接入 `CreBee 发布次数` 规格额度。

暂不做：

- 自动生成第三方平台登录二维码。
- 多 Agent 自动负载均衡。
- 全平台复杂发布参数配置。
- 发布数据分析报表。
- 用户自助重新登录。

## 后续增强

1. 多 Agent 调度：按平台、账号归属、在线状态分配任务。
2. 账号健康检测：定时检测账号是否失效。
3. 自动重新绑定提醒：账号失效后通知用户重新扫码。
4. 发布模板：按平台保存默认发布参数。
5. 数据回流：定时调用平台数据分析接口，展示播放量、点赞、评论等。
6. WebSocket 双向通道：替代轮询，降低延迟。
7. 运维端任务面板：展示当前本机待处理、发布中、失败任务。

## 结论

由于 CreBee 账号和 API 能力都在本地客户端，云端直接调用不可行。最稳妥的方案是在运行 CreBee 的运维电脑上部署 Bridge Agent，由 Agent 主动连接云端、领取任务、调用本地 CreBee API 并回传状态。

该方案能满足当前两个核心目标：

- 用户扫码绑定后，云端能获得绑定成功状态。
- 用户在云端点击发布后，能通过本地 CreBee 客户端实际执行发布，并把发布状态同步回系统。
