# 状态与错误

## 轮询顺序

优先使用宿主应用已经保存的发布记录。只有当本地状态过期、缺失或需要外部刷新时，才查询三方平台。

推荐轮询输入：

- 外部 flow id
- 各平台 task 或 record id
- 平台
- 账号 ID

## 推荐本地状态

宿主应用可使用等价状态：

- `queued`：本地请求尚未提交。
- `submitted` 或 `publishing`：外部 flow 已存在，需要轮询。
- `awaiting_confirmation`：用户必须打开确认链接，通常是抖音。
- `success`：发布成功。
- `failed`：发布失败，需要查看错误信息和原始响应。

## 三方平台响应处理

三方平台返回错误时，面向用户的诊断里尽量包含以下字段：

- HTTP status
- 业务 `code`
- `requestId`
- `message`
- 嵌套的 `errors`、`details` 或 `error`
- flow id、record id、平台、账号 ID

不要只返回 `Validation failed`。如果响应中包含具体失败字段，要展开真实原因。

## 常见问题

- `15051 media_unreachable`：媒体 URL 不是公网可访问，或三方平台无法解析。通过三方平台资源上传，或替换为已确认可访问的公网媒体 URL。
- 缺少或错误的 `accountId`：账号必须来自宿主应用中当前用户/租户当前 `groupId` 下授权后同步得到的绑定关系，不能来自用户初始化任务时手动填写。
- 中文标题或描述显示为 `??????`：请求 JSON 或 HTTP 请求体没有按 UTF-8 编码发送。使用结构化 JSON 序列化，并以 `Content-Type: application/json; charset=utf-8` 提交。
- `publishAt` 无效：使用未来的 ISO 8601 时间。
- 平台 option 校验失败：移除猜测字段，并获取平台 option 结构。
- 视频上传超时：增加上传超时时间、换更快网络，或提供稳定公网 URL。

## 报错展示格式

发布失败时返回简洁结构化文本：

```text
平台：抖音
状态：发布失败
原因：<message>
code：<code>
requestId：<requestId>
外部任务：<flow id / record id>
建议：<next action>
```