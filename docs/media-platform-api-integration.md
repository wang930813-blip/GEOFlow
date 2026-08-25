# 媒体平台接口文档

版本：2026-06-29

本文档只整理上游媒体供应商接口，分为“超级媒介”和“小青蛙开放平台”两部分。文档用于研发对接时查阅供应商原始接口能力，不包含本系统内部接口、内部鉴权或内部状态设计。

## 一、超级媒介接口文档

资料来源：`public/超级媒介代理商接入API文档.html`，线上文档地址：`https://vip.chaojimeijie.com/agent/document#common`

### 1.1 通用说明

请求地址：

```text
https://vip.chaojimeijie.com/api
```

公共请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `appid` | string | 是 | 代理商 AppID |
| `timestamp` | int | 是 | 10 位时间戳，有效期 5 分钟 |
| `algorithm` | string | 否 | 签名算法，默认 `sha256` |
| `signature` | string | 是 | 请求签名 |

签名规则：

1. 移除 `signature` 字段。
2. 参数按键名升序排序；列表按元素升序排序；字典按键名升序排序。
3. 将排序后的参数展平并拼接为 `key=value` 字符串。
4. 使用 `hash_hmac(algorithm, flatten(payload), secret)` 生成签名。

响应格式：

```json
{
  "code": 200,
  "message": "success",
  "data": {}
}
```

`code = 200` 表示成功，其他值表示失败，错误原因查看 `message`。

### 1.2 新闻媒体资源列表

```http
GET /media/resource
```

用途：分页获取新闻媒体资源列表。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `page` | int | 否 | 页码，默认 1 |
| `size` | int | 否 | 每页数量，默认 20，最大 200 |

关键返回：

| 字段 | 说明 |
|---|---|
| `data.total` | 总数量 |
| `data.items[]` | 媒体资源列表 |
| `items[].id` | 资源 ID |
| `items[].name` | 媒体名称 |
| `items[].entrance_link` | 入口链接 |
| `items[].case_link` | 案例链接 |
| `items[].homepage_focus_image_url` | 首页焦点图 |
| `items[].homepage_recommend_time` | 首页推荐时间 |
| `items[].remark` | 备注 |
| `items[].price` | 成本价 |
| `items[].published_avg` | 平均发稿时间，单位分钟 |
| `items[].published_rate` | 发稿率 |
| `items[].area` | 所属地区 |
| `items[].link_type` | 链接类型 |
| `items[].news_source` | 新闻源 |
| `items[].channel_type` | 频道类型 |
| `items[].publish_speed` | 发布速度 |
| `items[].entrance_level` | 入口级别 |
| `items[].special_industry` | 特殊行业 |
| `items[].record_situation` | 备案情况 |
| `items[].comprehensive_portal` | 综合门户 |
| `items[].pc_weight` | PC 权重 |
| `items[].mobile_weight` | 移动权重 |
| `items[].can_weekend` | 是否支持周末 |
| `items[].status` | 资源状态，见 1.18 |

### 1.3 新闻媒体资源查询

```http
GET /media/resource/query
```

用途：按资源 ID 批量查询新闻媒体详情。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `id` | array | 是 | 资源 ID 列表，最多 200 个 |

返回说明：`data` 为资源详情列表，字段与 1.2 的 `data.items[]` 一致。

### 1.4 创建新闻媒体订单

```http
POST /media/order
```

用途：向指定新闻媒体资源提交投稿订单。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `sn` | string | 是 | 代理商订单号，唯一，最大 64 字符 |
| `resource_id` | int | 是 | 新闻媒体资源 ID |
| `title` | string | 是 | 稿件标题，最大 200 字符，需 urlencode |
| `content` | string | 是 | 稿件内容预览 URL，需 urlencode |
| `publish_limited` | datetime | 否 | 限时发布时间，需晚于提交时间 2 小时 |
| `remark` | string | 否 | 备注，最大 500 字符，需 urlencode |
| `owner` | string | 否 | 稿件所属客户，最大 100 字符，需 urlencode |

关键返回：

| 字段 | 说明 |
|---|---|
| `data.partner_sn` | 超级媒介订单号 |

### 1.5 新闻媒体订单催稿

```http
POST /media/order/urge
```

用途：对新闻媒体订单发起催稿。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `sn` | string | 是 | 代理商订单号 |

### 1.6 新闻媒体订单取消

```http
POST /media/order/cancel
```

用途：取消新闻媒体订单。供应商文档说明仅“待处理”订单可取消，最终以接口返回为准。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `sn` | string | 是 | 代理商订单号 |
| `reason` | string | 是 | 取消原因 |

### 1.7 新闻媒体订单申请退款

```http
POST /media/order/apply-refund
```

用途：对新闻媒体订单发起退款申请。供应商文档说明发布中的订单申请退款不保证成功。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `sn` | string | 是 | 代理商订单号 |
| `reason` | string | 是 | 退款原因 |

### 1.8 新闻媒体订单申请补发

```http
POST /media/order/apply-republish
```

用途：对新闻媒体订单申请补发。仅适用于套餐包含的资源，并且订单未收录、发布时间或补发时间在 12 小时至 7 天范围内。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `sn` | string | 是 | 代理商订单号 |

### 1.9 新闻媒体订单查询

```http
GET /media/order/query
```

用途：批量查询新闻媒体订单结果。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `sn` | array | 是 | 代理商订单号列表，最多 20 个 |

关键返回：

| 字段 | 说明 |
|---|---|
| `data[].sn` | 代理商订单号 |
| `data[].url` | 发布链接 |
| `data[].screenshot` | 发布截图或收录截图 |
| `data[].published_at` | 发布时间 |
| `data[].status` | 订单状态，见 1.19 |
| `data[].feedback` | 订单反馈，见 1.20 |

### 1.10 自媒体资源列表

```http
GET /we-media/resource
```

用途：分页获取自媒体资源列表。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `page` | int | 否 | 页码，默认 1 |
| `size` | int | 否 | 每页数量，默认 20，最大 200 |

关键返回：

| 字段 | 说明 |
|---|---|
| `data.total` | 总数量 |
| `data.items[]` | 自媒体资源列表 |
| `items[].id` | 资源 ID |
| `items[].name` | 账号名称 |
| `items[].entrance_link` | 入口链接 |
| `items[].case_link` | 案例链接 |
| `items[].remark` | 备注 |
| `items[].price` | 图文成本价 |
| `items[].video_price` | 视频成本价 |
| `items[].trend_price` | 动态成本价 |
| `items[].published_avg` | 平均发稿时间，单位分钟 |
| `items[].published_rate` | 发稿率 |
| `items[].platform` | 所属平台 |
| `items[].area` | 所属地区 |
| `items[].industry_category` | 行业分类 |
| `items[].fans_number` | 粉丝数 |
| `items[].read_number` | 阅读数 |
| `items[].like_number` | 点赞数 |
| `items[].publish_daily` | 日发文量 |
| `items[].is_authenticated` | 是否认证 |
| `items[].is_official` | 是否官方账号 |
| `items[].can_video` | 是否支持视频 |
| `items[].can_trend` | 是否支持动态 |
| `items[].can_weekend` | 是否支持周末 |
| `items[].status` | 资源状态，见 1.18 |

### 1.11 自媒体资源查询

```http
GET /we-media/resource/query
```

用途：按资源 ID 批量查询自媒体详情。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `id` | array | 是 | 资源 ID 列表，最多 200 个 |

返回说明：`data` 为资源详情列表，字段与 1.10 的 `data.items[]` 一致。

### 1.12 创建自媒体订单

```http
POST /we-media/order
```

用途：向指定自媒体资源提交投稿订单。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `sn` | string | 是 | 代理商订单号，唯一，最大 64 字符 |
| `resource_id` | int | 是 | 自媒体资源 ID |
| `title` | string | 是 | 稿件标题，最大 200 字符，需 urlencode |
| `content` | string | 是 | 稿件内容预览 URL，需 urlencode |
| `remark` | string | 否 | 备注，最大 500 字符，需 urlencode |
| `owner` | string | 否 | 稿件所属客户，最大 100 字符，需 urlencode |
| `publish_form` | int | 是 | 1 图文发布；2 优先图文，未通过则截图发布 |
| `publish_type` | int | 是 | 1 图文；2 视频；3 动态 |
| `account_rule` | int | 是 | 2 只允许更换同类型账号；3 不允许换号发布 |

注意：超级媒介文档标注自 2026-04-10 起，自媒体不再支持限时发布，`publish_limited` 不应再用于自媒体订单。

关键返回：

| 字段 | 说明 |
|---|---|
| `data.partner_sn` | 超级媒介订单号 |

### 1.13 自媒体订单催稿

```http
POST /we-media/order/urge
```

用途：对自媒体订单发起催稿。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `sn` | string | 是 | 代理商订单号 |

### 1.14 自媒体订单取消

```http
POST /we-media/order/cancel
```

用途：取消自媒体订单。最终是否可取消以接口返回为准。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `sn` | string | 是 | 代理商订单号 |
| `reason` | string | 是 | 取消原因 |

### 1.15 自媒体订单申请退款

```http
POST /we-media/order/apply-refund
```

用途：对自媒体订单发起退款申请。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `sn` | string | 是 | 代理商订单号 |
| `reason` | string | 是 | 退款原因 |

### 1.16 自媒体订单查询

```http
GET /we-media/order/query
```

用途：批量查询自媒体订单结果。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| 公共参数 | - | 是 | 见 1.1 |
| `sn` | array | 是 | 代理商订单号列表，最多 20 个 |

关键返回：

| 字段 | 说明 |
|---|---|
| `data[].sn` | 代理商订单号 |
| `data[].url` | 发布链接 |
| `data[].screenshot` | 发布截图 |
| `data[].published_at` | 发布时间 |
| `data[].status` | 订单状态，见 1.19 |
| `data[].feedback` | 订单反馈，见 1.20 |

### 1.17 事件通知

超级媒介支持事件通知，回调地址为代理商在超级媒介后台设置的“事件通知 URL”。

通知规则：

| 项目 | 说明 |
|---|---|
| 请求方式 | `POST` |
| 签名方式 | 与请求接口时的签名方式一致 |
| `event` | 1 资源变更；2 订单变更 |
| `payload` | 事件数据 |

资源变更 `payload`：

| 字段 | 说明 |
|---|---|
| `type` | 1 新闻媒体；2 自媒体 |
| `id` | 资源 ID，可调用资源查询接口更新本地资源 |

订单变更 `payload`：

| 字段 | 说明 |
|---|---|
| `type` | 1 新闻媒体；2 自媒体 |
| `sn` | 代理商订单号，可调用订单查询接口更新订单结果 |

### 1.18 资源状态

| status | 说明 |
|---:|---|
| 1 | 审核中 |
| 2 | 已通过，可用 |
| 3 | 未通过 |
| 4 | 已暂停 |
| 5 | 已取消 |

### 1.19 订单状态

| status | 说明 | 适用范围 |
|---:|---|---|
| 1 | 待处理 | 新闻媒体、自媒体 |
| 2 | 已拒稿 | 新闻媒体、自媒体 |
| 3 | 发布中 | 新闻媒体、自媒体 |
| 4 | 已发布 | 新闻媒体、自媒体 |
| 5 | 已取消 | 新闻媒体、自媒体 |
| 6 | 退款中 | 新闻媒体、自媒体 |
| 7 | 已退款 | 新闻媒体、自媒体 |
| 8 | 退款被拒 | 新闻媒体、自媒体 |
| 9 | 已关闭 | 新闻媒体、自媒体 |
| 10 | 补发中 | 新闻媒体 |
| 11 | 已补发 | 新闻媒体 |
| 12 | 已收录 | 新闻媒体 |

### 1.20 订单反馈信息

| 订单状态 | `feedback` 说明 |
|---|---|
| 待处理、发布中、已退款 | 通常为空 |
| 已取消、退款中、补发中、已拒稿、退款被拒、已关闭 | 反馈原因 |
| 已发布、已补发 | 发布链接、截图等结果信息 |
| 已收录 | 收录截图等结果信息 |

### 1.21 超级媒介注意事项

- 新闻媒体和自媒体的 `content` 都是稿件内容预览 URL，不是 HTML 正文。
- 标题、内容 URL、备注、客户名等字段按供应商要求需要 urlencode。
- 创建订单时应保证 `sn` 唯一，避免重复下单。
- 资源只有 `status = 2` 时才表示可用。
- 查询订单时 `sn` 单次最多传 20 个。
- `screenshot` 和 `feedback` 可能包含供应商返回内容，页面展示前要做安全处理。

## 二、小青蛙开放平台接口文档

资料来源：小青蛙开放平台 Apifox 文档目录：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65`

### 2.1 通用说明

请求格式：

| 项目 | 说明 |
|---|---|
| 请求方式 | `POST` |
| 请求体 | `multipart/form-data` |
| 鉴权字段 | `api_key` |
| 成功标识 | `code = 1` |
| 失败标识 | `code = 0`，错误原因查看 `msg` |

响应格式：

```json
{
  "code": 1,
  "msg": "获取成功",
  "time": "1768318475",
  "data": {}
}
```

### 2.2 网站媒体资源列表

```http
POST /api/media/media_list
```

用途：分页获取网站媒体资源。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `api_key` | string | 是 | 接口密钥 |
| `page` | int | 是 | 页码 |
| `page_size` | int | 是 | 每页数量 |

关键返回：

| 字段 | 说明 |
|---|---|
| `data[].resource_id` | 媒体 ID |
| `data[].title` | 媒体名称 |
| `data[].remarks` | 媒体备注 |
| `data[].case_link` | 案例链接 |
| `data[].field_1` - `field_9` | 分类筛选字段 |
| `data[].pc_weigh` | PC 权重 |
| `data[].wap_weigh` | 移动权重 |
| `data[].publish_rate` | 出稿率 |
| `data[].publish_time` | 平均发布时间，单位秒 |
| `data[].status` | 可用状态，1 可接单，0 不接单 |
| `data[].price` | 媒体价格 |

### 2.3 网站媒体投稿

```http
POST /api/media/send
```

用途：向网站媒体提交投稿。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `api_key` | string | 是 | 接口密钥 |
| `resource_id` | string | 是 | 媒体资源 ID |
| `title` | string | 是 | 标题 |
| `content` | string | 是 | 内容，HTML 格式 |
| `remark` | string | 否 | 投稿备注 |
| `third_id` | string | 否 | 第三方标识 ID，建议放对接系统的用户 ID 或业务 ID |

关键返回：

| 字段 | 说明 |
|---|---|
| `data.order_nid` | 稿件订单 ID |

### 2.4 网站媒体订单详情

```http
POST /api/media/order_info
```

用途：查询网站媒体投稿订单详情，支持批量查询。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `api_key` | string | 是 | 接口密钥 |
| `order_nids[]` | array | 是 | 投稿订单号；单条订单可传 `order_nids=xxx` |

关键返回：

| 字段 | 说明 |
|---|---|
| `data[].resource_id` | 媒体 ID |
| `data[].order_nid` | 订单号 |
| `data[].status` | 订单状态，见 2.15 |
| `data[].price` | 金额 |
| `data[].is_refund` | 1 已退款，0 未退款 |
| `data[].title` | 标题 |
| `data[].remark` | 投稿备注 |
| `data[].rejection_info` | 拒稿原因 |
| `data[].refund_info` | 退稿原因 |
| `data[].rewrite_info` | 改稿原因 |
| `data[].order_url` | 文章结果链接 |

### 2.5 网站媒体订单申诉

```http
POST /api/media/rejection
```

用途：对已完成的网站媒体订单发起申诉。供应商文档说明申诉会进入审核，如申诉成功会自动退款。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `api_key` | string | 是 | 接口密钥 |
| `order_nid` | string | 是 | 订单号 |
| `title_id` | number | 是 | 申诉原因 ID，见下表 |
| `info` | string | 否 | 具体原因说明 |

`title_id`：

| 值 | 说明 |
|---:|---|
| 1 | 未收录，申请退款，适用于包收录资源 |
| 2 | 发布或账号结果与案例不一致 |
| 3 | 时效内链接打不开 |
| 4 | 其他原因 |

### 2.6 网站媒体分类

```http
POST /api/media/get_field
```

用途：获取网站媒体分类筛选项。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `api_key` | string | 是 | 接口密钥 |
| `media_type` | string | 是 | 网站媒体传 `website`，自媒体传 `wemedia` |
| `field_type` | string | 否 | 分类字段，如 `field_1` 至 `field_8` |

关键返回：

| 字段 | 说明 |
|---|---|
| `data[].field_id` | 分类 ID |
| `data[].field_type` | 分类类型 |
| `data[].field_title` | 分类名称 |
| `data[].rsort` | 排序，越小越靠前 |

### 2.7 网站媒体取消订单

```http
POST /api/media/cancel_order
```

用途：取消网站媒体投稿订单。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `api_key` | string | 是 | 接口密钥 |
| `order_nid` | string | 是 | 订单号 |

响应说明：`code = 1` 表示取消成功，`code = 0` 表示取消失败，失败原因查看 `msg`。

### 2.8 第三方自媒体资源列表

```http
POST /api/zi_media_api/media_list
```

用途：分页获取第三方自媒体资源。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `api_key` | string | 是 | 接口密钥 |
| `page` | int | 是 | 页码 |
| `page_size` | int | 是 | 每页数量 |

关键返回：字段结构与 2.2 网站媒体资源列表一致。

### 2.9 第三方自媒体投稿

```http
POST /api/zi_media_api/send
```

用途：向第三方自媒体提交投稿。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `api_key` | string | 是 | 接口密钥 |
| `resource_id` | string | 是 | 媒体资源 ID |
| `title` | string | 是 | 标题 |
| `content` | string | 是 | 内容，HTML 格式 |
| `remark` | string | 否 | 投稿备注 |
| `third_id` | string | 否 | 第三方标识 ID，建议放对接系统的用户 ID 或业务 ID |

关键返回：

| 字段 | 说明 |
|---|---|
| `data.order_nid` | 稿件订单 ID |

### 2.10 第三方自媒体订单详情

```http
POST /api/zi_media_api/order_info
```

用途：查询第三方自媒体投稿订单详情，支持批量查询。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `api_key` | string | 是 | 接口密钥 |
| `order_nids[]` | array | 是 | 投稿订单号；单条订单可传 `order_nids=xxx` |

关键返回：字段结构与 2.4 网站媒体订单详情一致。

### 2.11 第三方自媒体订单申诉

```http
POST /api/zi_media_api/rejection
```

用途：对已完成的第三方自媒体订单发起申诉。供应商文档说明申诉会进入审核，如申诉成功会自动退款。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `api_key` | string | 是 | 接口密钥 |
| `order_nid` | string | 是 | 订单号 |
| `title_id` | number | 是 | 申诉原因 ID |
| `info` | string | 否 | 具体原因说明 |

`title_id`：

| 值 | 说明 |
|---:|---|
| 1 | 未收录，申请退款，适用于包收录资源 |
| 2 | 发布或账号结果与案例不一致 |
| 3 | 时效内链接打不开 |
| 4 | 未收录，申请退款，适用于包收录资源 |

### 2.12 第三方自媒体分类

```http
POST /api/zi_media_api/get_field
```

用途：获取第三方自媒体分类筛选项。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `api_key` | string | 是 | 接口密钥 |
| `media_type` | string | 是 | 网站媒体传 `website`，自媒体传 `wemedia` |
| `field_type` | string | 否 | 分类字段，如 `field_1` 至 `field_8` |

关键返回：字段结构与 2.6 网站媒体分类一致。

### 2.13 第三方自媒体取消订单

```http
POST /api/zi_media_api/cancel_order
```

用途：取消第三方自媒体投稿订单。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `api_key` | string | 是 | 接口密钥 |
| `order_nid` | string | 是 | 订单号 |

响应说明：`code = 1` 表示取消成功，`code = 0` 表示取消失败，失败原因查看 `msg`。

### 2.14 查询余额

```http
POST /api/geo/get_balance
```

用途：查询小青蛙开放平台接口余额。

请求参数：

| 参数 | 类型 | 必填 | 说明 |
|---|---|---:|---|
| `api_key` | string | 是 | 接口密钥 |

关键返回：

| 字段 | 说明 |
|---|---|
| `data.power_count` | 剩余算力 |
| `data.money` | 剩余余额 |

### 2.15 投稿订单状态

| status | 说明 |
|---:|---|
| 0 | 待安排 |
| 1 | 已安排 |
| 2 | 已发布 |
| 4 | 已退稿 |
| 9 | 售后中 |

### 2.16 小青蛙注意事项

- 网站媒体接口前缀为 `/api/media`。
- 第三方自媒体接口前缀为 `/api/zi_media_api`。
- 投稿内容 `content` 为 HTML 正文。
- 投稿后需保存 `data.order_nid`，后续订单查询、申诉、取消都依赖该订单号。
- 小青蛙当前提供的媒体投稿文档中未看到催单接口。
- 申诉接口要求已完成订单才可操作，申诉成功后供应商会自动退款。

## 三、接口能力对照

| 能力 | 超级媒介新闻媒体 | 超级媒介自媒体 | 小青蛙网站媒体 | 小青蛙第三方自媒体 |
|---|---|---|---|---|
| 媒体列表 | `/media/resource` | `/we-media/resource` | `/api/media/media_list` | `/api/zi_media_api/media_list` |
| 媒体详情/查询 | `/media/resource/query` | `/we-media/resource/query` | 通过列表和分类接口获取 | 通过列表和分类接口获取 |
| 媒体分类 | 附录字段 | 附录字段 | `/api/media/get_field` | `/api/zi_media_api/get_field` |
| 投稿下单 | `/media/order` | `/we-media/order` | `/api/media/send` | `/api/zi_media_api/send` |
| 订单详情 | `/media/order/query` | `/we-media/order/query` | `/api/media/order_info` | `/api/zi_media_api/order_info` |
| 取消订单 | `/media/order/cancel` | `/we-media/order/cancel` | `/api/media/cancel_order` | `/api/zi_media_api/cancel_order` |
| 退款/申诉 | `/media/order/apply-refund` | `/we-media/order/apply-refund` | `/api/media/rejection` | `/api/zi_media_api/rejection` |
| 催单/催稿 | `/media/order/urge` | `/we-media/order/urge` | 文档未提供 | 文档未提供 |
| 补发 | `/media/order/apply-republish` | 文档未提供 | 文档未提供 | 文档未提供 |
| 事件通知 | 支持 | 支持 | 文档未提供 | 文档未提供 |
| 余额查询 | 文档未提供 | 文档未提供 | `/api/geo/get_balance` | `/api/geo/get_balance` |

## 四、资料来源

- 超级媒介代理商接入 API 文档：`https://vip.chaojimeijie.com/agent/document#common`
- 小青蛙开放平台 API 文档目录：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65`
- 小青蛙网站媒体资源列表：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65/404388141e0.md`
- 小青蛙网站媒体投稿：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65/404721133e0.md`
- 小青蛙网站媒体订单详情：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65/404831215e0.md`
- 小青蛙网站媒体订单申诉：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65/404852333e0.md`
- 小青蛙网站媒体分类：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65/404856352e0.md`
- 小青蛙网站媒体取消订单：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65/406151386e0.md`
- 小青蛙第三方自媒体资源列表：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65/433008922e0.md`
- 小青蛙第三方自媒体投稿：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65/433008923e0.md`
- 小青蛙第三方自媒体订单详情：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65/433008924e0.md`
- 小青蛙第三方自媒体订单申诉：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65/433008925e0.md`
- 小青蛙第三方自媒体分类：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65/433008926e0.md`
- 小青蛙第三方自媒体取消订单：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65/433008929e0.md`
- 小青蛙查询接口算力余额：`https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65/396090533e0.md`
