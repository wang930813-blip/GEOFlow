# 发布参数

## 接口

创建发布流程：

```text
POST /api/v2/channels/publish/flows
请求头: X-Api-Key: <api-key>
请求头: Content-Type: application/json; charset=utf-8
```

接口 Base URL 由宿主应用配置，内部默认值：

```text
https://aitoearn.cn
```

普通说明、错误解释和流程分析里不要暴露服务商名称；可写成“三方平台接口地址”。只有用户明确要求可直接执行的请求命令时，才在命令中使用真实 Base URL。

`accountId` 不是用户初始化发布任务时需要提供的字段。生成发布参数前，必须先完成当前用户/租户当前 `groupId` 下的平台授权和账号同步，再由宿主应用把已绑定账号 ID 写入 `items[n].accountId`。

## 字符编码

发布内容包含中文时，所有文本字段必须按 UTF-8 JSON 请求体提交，包括 `content.title`、`content.body`、封面说明、平台选项中的标题/描述类字段等。

- 使用结构化 JSON 序列化，不要手工拼接 JSON 字符串。
- HTTP 请求体按 UTF-8 字节发送，请求头使用 `Content-Type: application/json; charset=utf-8`。
- 不要把中文文本转换为 ANSI、GBK、本地系统编码、URL query string 或表单参数后再提交发布。
- 可用时保留中文原文输出，例如使用不转义中文的 JSON 序列化选项；如果序列化为 `\u4e2d` 这类 JSON Unicode 转义，也必须保证最终请求体是有效 UTF-8 JSON。
- 如果中文在预览、日志或平台结果中显示为 `??????` 或乱码，先修正编码再重新提交。

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
      "accountId": "account-id-from-authorized-current-group-binding"
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
    { "platform": "douyin", "accountId": "authorized-douyin-account-id" },
    { "platform": "KWAI", "accountId": "authorized-kwai-account-id" }
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
- 授权后同步得到的账号 ID
- 已提交参数
- 原始响应
- 当前状态
- 创建/提交时间
