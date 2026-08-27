# 发布参数

## 接口

创建发布流程：

```text
POST /api/v2/channels/publish/flows
请求头: X-Api-Key: <api-key>
请求头: Content-Type: application/json
```

国内发布默认使用中国站 Base URL，除非用户配置了其他地址：

```text
https://aitoearn.cn
```

## 最小视频参数

```json
{
  "content": {
    "title": "视频标题",
    "body": "视频描述",
    "media": [
      { "url": "https://example.com/video.mp4" }
    ]
  },
  "publishAt": "2030-01-01T00:00:00.000Z",
  "items": [
    {
      "platform": "douyin",
      "accountId": "account-id-from-current-user-binding"
    }
  ]
}
```

## 多平台发布

同一个视频同时发布到抖音和快手时，使用一份共享 `content`，并在 `items` 中放两个发布项：

```json
{
  "content": {
    "title": "视频标题",
    "body": "视频描述",
    "media": [
      { "url": "https://example.com/video.mp4" }
    ]
  },
  "publishAt": "2030-01-01T00:00:00.000Z",
  "items": [
    { "platform": "douyin", "accountId": "douyin-account-id" },
    { "platform": "KWAT", "accountId": "kwat-account-id" }
  ]
}
```

## 平台选项

不要硬编码平台专属 `option` 值，除非这些值来自三方平台的平台结构，或已经被生产请求验证可用。

如果平台要求 option：

1. 获取平台发布选项。
2. 如果 option 是动态值，获取账号维度的可选值。
3. 只把平台支持的字段写入 `items[n].option`。

## 发布时间

`publishAt` 必须是未来时间。如果用户要求立即发布，除非宿主应用配置了其他延迟，默认设置为当前时间加 60 秒这类较短未来时间。

## 必须持久化的数据

创建 flow 后保存：

- flow id
- 各平台 task 或 record id
- 平台
- 账号 ID
- 已提交参数
- 原始响应
- 当前状态
- 创建/提交时间
