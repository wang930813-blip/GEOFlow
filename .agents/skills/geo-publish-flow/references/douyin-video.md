# 抖音视频发布

## 使用场景

目标平台为抖音（`douyin`），且内容类型为视频时读取本文档。

## 必要检查

- 当前用户/租户在宿主应用中已有已授权的抖音账号绑定。
- 视频媒体 URL 是公网可访问地址，或已通过三方平台上传并确认。
- `publishAt` 是未来的 ISO 8601 时间。

## 最小发布项

```json
{
  "platform": "douyin",
  "accountId": "current-user-douyin-account-id"
}
```

默认不要添加不支持的 options。

## 用户确认

抖音可能要求用户在手机端完成最终确认。当状态表示等待用户操作时，调用：

```text
GET /api/v2/channels/publish/records/{recordId}/user-action
```

把 `shortLink` 返回给用户，并提示用户使用已登录目标抖音账号的手机打开。宿主应用应在发布项/发布记录中保存原始 action 响应。

## 状态处理

- 待处理/发布中：继续轮询。
- 等待用户操作：展示短链并继续轮询。
- 成功：返回作品链接。
- 失败/取消：返回平台错误、code、requestId 和可用的 record id。

## 实用回复模板

需要短链时：

```text
抖音还需要手机端确认。请用已登录目标抖音账号的手机打开这个链接完成确认：<shortLink>
确认完成后继续同步发布状态。
```