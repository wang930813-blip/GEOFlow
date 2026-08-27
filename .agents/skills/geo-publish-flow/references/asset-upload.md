# 资源上传

## 公网 URL 规则

三方平台发布接口中的媒体 URL 必须能被三方平台服务访问。发布前应拒绝或转换以下输入：

- 本地路径，例如 `C:\video.mp4` 或 `/tmp/video.mp4`
- `localhost`、私有 IP、内网地址或仅容器内可访问的 URL
- 需要 Cookie、请求头、登录态或临时令牌的 URL
- 从文档中复制的占位 URL
- 把非视频文件作为 `content.media[n].url`

对于公网 URL，可行时用 HEAD 或 GET 请求验证。重点检查 HTTP 2xx、有效内容长度，以及类似视频的 content type 或文件扩展名。

## 三方平台上传顺序

本地或私有媒体使用以下流程：

1. `POST /api/assets/uploadSign`
   - 请求体：`filename`、`type = publishMedia`，可用时带上 `size`。
2. `PUT data.uploadUrl`
   - 直接上传原始文件内容。
   - 对象存储 PUT 请求不要携带 `X-Api-Key`。
3. `POST /api/assets/{id}/confirm`
   - 发布参数中使用确认接口返回的 `data.url`。

## 实现建议

宿主应用可以在后端任务、浏览器直传流程或命令行诊断脚本中实现上传。大视频需要更长上传超时时间，并优先从靠近三方平台对象存储的网络环境上传。

## 封面处理

只有当宿主应用已有公网封面 URL，或用户明确提供封面时，才传 `content.cover.url`。不要生成或附加假的封面 URL。抖音和快手如不确定封面支持情况，优先使用最小有效参数，交给三方平台/目标平台默认处理。
