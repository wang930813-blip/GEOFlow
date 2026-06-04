# 策影媒体平台扩展技术方案

## 1. 背景

当前“分发媒体”模块已经支持一个第三方媒体投稿平台，负责同步媒体资源、配置积分价、创建投稿订单、同步订单状态、扣减和退回站点积分。

现在需要在不破坏现有业务的前提下，新增第二个媒体平台：

- 策影媒体1：现有第三方媒体接口。
- 策影媒体2：超级媒介代理商 API。

重要原则：

- 现有 `source_type` 不改含义，继续表示媒体类型。
- 新增 `platform_id` 表示媒体平台。
- 用户可以看到媒体来自“策影媒体1”还是“策影媒体2”。
- 成本价仍然只对超级管理员可见。
- 积分价仍然对所有可投稿用户可见。

## 2. 已确认产品决策

### 2.1 平台命名

```text
platform_id = 1  策影媒体1
platform_id = 2  策影媒体2
```

### 2.2 媒体类型保持不变

```text
source_type = website_media  网站媒体 / 新闻媒体
source_type = zi_media       自媒体
```

目标结构：

```text
媒体平台 platform_id
  ├─ 策影媒体1
  │   ├─ website_media
  │   └─ zi_media
  └─ 策影媒体2
      ├─ website_media
      └─ zi_media
```

### 2.3 策影媒体2自媒体价格

第一期只支持图文投稿，成本价取超级媒介自媒体资源字段：

```text
price
```

以下字段只保存到 `raw_payload`，暂不参与展示价和扣费：

```text
video_price
trend_price
```

### 2.4 策影媒体2申诉

当前系统里的“申诉 / 售后”操作，对接超级媒介“申请退款”接口：

```text
新闻媒体：POST /media/order/apply-refund
自媒体：  POST /we-media/order/apply-refund
```

取消操作继续对应超级媒介取消订单接口：

```text
新闻媒体：POST /media/order/cancel
自媒体：  POST /we-media/order/cancel
```

新闻媒体“申请补发”第一期不做独立按钮。

## 3. 策影媒体2接口摘要

接口文档本地文件：

```text
public/超级媒介代理商接入API文档.html
```

长期建议移到 `docs/third-party/`，避免生产环境 `public/` 下可直接访问。

### 3.1 基础地址

```text
https://vip.chaojimeijie.com/api
```

### 3.2 公共请求参数

每次请求都需要携带：

```text
appid       代理商标识
timestamp   10位时间戳，5分钟内有效
algorithm   签名算法，默认 sha256
signature   HMAC 签名
```

### 3.3 签名规则

规则来自超级媒介文档：

1. 排除 `signature`。
2. 参数按键名升序排序。
3. 如果值是列表，按元素升序排序。
4. 如果值是字典，按键名升序排序。
5. 展平成字符串。
6. 使用 `hash_hmac($algorithm, $flattened, $secret)` 生成签名。

实现时需要独立封装为：

```text
ChaoJiMeiJieSigner
```

并为签名排序和嵌套数组展平写单元测试。

### 3.4 新闻媒体接口

```text
GET  /media/resource
GET  /media/resource/query
POST /media/order
POST /media/order/urge
POST /media/order/cancel
POST /media/order/apply-refund
POST /media/order/apply-republish
GET  /media/order/query
```

### 3.5 自媒体接口

```text
GET  /we-media/resource
GET  /we-media/resource/query
POST /we-media/order
POST /we-media/order/urge
POST /we-media/order/cancel
POST /we-media/order/apply-refund
GET  /we-media/order/query
```

### 3.6 资源状态映射

超级媒介资源状态：

```text
1 审核中
2 已通过
3 未通过
4 已暂停
5 已取消
```

系统状态映射：

```text
2 -> active
其他 -> inactive
```

### 3.7 订单状态映射

超级媒介订单状态：

```text
1  待处理
2  已拒稿
3  发布中
4  已发布
5  已取消
6  退款中
7  已退款
8  退款被拒
9  已关闭
10 补发中
11 已补发
12 已收录
```

系统状态建议映射：

```text
1  -> submitted
2  -> rejected
3  -> publishing
4  -> published
5  -> cancelled
6  -> appealing
7  -> rejected
8  -> rejected
9  -> failed
10 -> publishing
11 -> published
12 -> published
```

退款成功 `7` 建议映射为 `rejected`，因为业务上需要触发积分退回。实现时必须保证同一订单只退款一次。

## 4. 数据库改造

### 4.1 media_api_settings

当前只支持一套 API 配置。需要改成每个平台一套配置。

新增字段：

```text
platform_id tinyint unsigned default 1
app_id string nullable
api_secret_ciphertext text nullable
```

保留现有字段：

```text
api_base_url
api_key_ciphertext
status
price_multiplier
last_checked_at
last_error_message
```

说明：

- 策影媒体1继续使用 `api_key_ciphertext`。
- 策影媒体2使用 `app_id` + `api_secret_ciphertext`。
- `price_multiplier` 按平台独立。

索引：

```text
unique(platform_id)
```

迁移策略：

- 现有记录回填 `platform_id = 1`。
- 如无策影媒体2配置，创建空配置由后台填写。

### 4.2 media_resources

新增字段：

```text
platform_id tinyint unsigned default 1
```

调整唯一键：

旧：

```text
unique(source_type, external_resource_id)
```

新：

```text
unique(platform_id, source_type, external_resource_id)
```

原因：

不同媒体平台可能返回相同的资源 ID。如果不加 `platform_id`，策影媒体2同步时可能覆盖策影媒体1资源。

### 4.3 media_submissions

新增字段：

```text
platform_id tinyint unsigned default 1
agent_order_sn string nullable
preview_token string nullable
```

字段用途：

- `platform_id`：订单所属平台快照，后续查单、取消、退款必须按订单平台选择客户端。
- `agent_order_sn`：我方传给超级媒介的 `sn`，用于查询、取消、退款。
- `preview_token`：生成投稿内容预览 URL 时使用。

保留现有：

```text
external_order_nid
```

对策影媒体2：

- `agent_order_sn` 存我方订单号，如 `geoflow-1-123`。
- `external_order_nid` 存超级媒介返回的 `partner_sn`。

### 4.4 media_resource_sync_runs

新增字段：

```text
platform_id tinyint unsigned default 1
```

后续同步进度按平台隔离：

```text
同步策影媒体1
同步策影媒体2
```

互不阻塞。

## 5. 字段映射

### 5.1 策影媒体1

沿用现有映射逻辑。

当前关键映射：

```text
resource_id / id / nid -> external_resource_id
title / media_name / name / site_name / account_name -> title
category / field / type_name / class_name -> category
remarks / remark / description / desc -> remarks
case_link / case_url / url / link -> case_link
price -> cost_price
status=1 -> active
其他 -> inactive
```

### 5.2 策影媒体2新闻媒体

```text
id -> external_resource_id
name -> title
case_link -> case_link
remark -> remarks
price -> cost_price
published_rate -> raw_payload.published_rate
published_avg -> raw_payload.published_avg
pc_weight -> raw_payload.pc_weight
mobile_weight -> raw_payload.mobile_weight
status=2 -> active
其他 -> inactive
```

为了兼容现有页面列，可以额外标准化：

```text
raw_payload.publish_rate = published_rate
raw_payload.pc_weigh = pc_weight
raw_payload.wap_weight = mobile_weight
raw_payload.status_label = status
```

### 5.3 策影媒体2自媒体

```text
id -> external_resource_id
name -> title
case_link -> case_link
remark -> remarks
price -> cost_price
video_price -> raw_payload.video_price
trend_price -> raw_payload.trend_price
published_rate -> raw_payload.published_rate
published_avg -> raw_payload.published_avg
platform -> raw_payload.platform
area -> raw_payload.area
industry_category -> raw_payload.industry_category
fans_number -> raw_payload.fans_number
read_number -> raw_payload.read_number
like_number -> raw_payload.like_number
status=2 -> active
其他 -> inactive
```

第一期不支持视频和动态投稿，扣费和积分价都按 `price` 计算。

## 6. 价格与积分规则

现有价格规则保持不变。

### 6.1 成本价

```text
media_resources.cost_price
```

来源：

- 策影媒体1：接口成本价。
- 策影媒体2新闻媒体：`price`。
- 策影媒体2自媒体：`price` 图文成本价。

可见性：

- 仅超级管理员可见。

### 6.2 积分价

```text
media_resources.sale_price
```

生成规则：

```text
sale_price = cost_price * 当前平台 price_multiplier
```

用户可见：

- 所有可投稿用户都能看到积分价。

### 6.3 站点专属价

```text
media_resource_site_prices.sale_price
```

优先级：

```text
站点专属价 > 媒体全局 sale_price
```

### 6.4 投稿快照

投稿时必须保存：

```text
cost_price_snapshot
sale_price_snapshot
points_amount
platform_id
```

确保媒体后续改价不影响历史订单和利润报表。

## 7. 服务层设计

### 7.1 平台定义

建议新增支持类：

```text
App\Support\MediaDistribution\MediaPlatform
```

职责：

- 定义平台 ID。
- 定义平台展示名。
- 判断平台是否可用。

示例：

```php
public const CEYING_MEDIA_ONE = 1;
public const CEYING_MEDIA_TWO = 2;
```

### 7.2 平台客户端接口

新增接口：

```text
App\Services\MediaDistribution\Contracts\MediaPlatformClient
```

方法：

```text
resourcePages(string $sourceType): Generator
send(MediaSubmission $submission, MediaResource $resource): array
orderInfo(MediaSubmission $submission): array
cancelOrder(MediaSubmission $submission, string $reason): array
applyRefund(MediaSubmission $submission, string $reason): array
```

### 7.3 客户端实现

```text
App\Services\MediaDistribution\Platforms\CeyingMediaOneClient
App\Services\MediaDistribution\Platforms\CeyingMediaTwoClient
```

其中：

- `CeyingMediaOneClient` 承接现有 `MediaDistributionClient` 逻辑。
- `CeyingMediaTwoClient` 实现超级媒介 HMAC 签名、GET/POST请求、字段映射。

### 7.4 客户端选择器

新增：

```text
App\Services\MediaDistribution\MediaPlatformClientManager
```

职责：

```text
forPlatform(int $platformId): MediaPlatformClient
```

业务服务不直接判断接口细节，只根据 `platform_id` 取客户端。

### 7.5 资源同步服务

当前：

```text
MediaResourceSyncService::syncAll()
```

建议调整：

```text
syncPlatform(int $platformId, ?MediaResourceSyncRun $run = null)
syncAllPlatforms()
```

后台按钮分别调用：

```text
同步策影媒体1 -> syncPlatform(1)
同步策影媒体2 -> syncPlatform(2)
```

同步流程：

1. 选择平台客户端。
2. 遍历 `website_media` 和 `zi_media`。
3. 分页拉取资源。
4. 标准化字段。
5. `firstOrNew(platform_id, source_type, external_resource_id)`。
6. 写入成本价、积分价、状态和 `raw_payload`。
7. 更新同步进度。

### 7.6 投稿服务

当前投稿服务需要从资源读取平台：

```text
platform_id = media_resources.platform_id
```

投稿流程：

1. 校验文章、媒体、站点积分。
2. 计算当前站点积分价。
3. 创建 `media_submissions`，保存 `platform_id`、价格快照、`preview_token`。
4. 扣积分。
5. 选择平台客户端。
6. 调用对应平台投稿接口。
7. 保存第三方返回。
8. 失败时退积分。

## 8. 策影媒体2投稿内容预览

### 8.1 原因

超级媒介创建订单接口要求：

```text
content = 稿件内容预览地址，必须是 URL
```

现有策影媒体1是直接提交文章内容 HTML，不能直接复用。

### 8.2 方案

新增一个投稿预览路由：

```text
GET /media-submission-preview/{submission}/{token}
```

建议不要进入后台鉴权，因为第三方需要直接访问。

安全要求：

- `token` 必须足够随机。
- 只展示投稿文章内容，不展示后台操作入口。
- 不暴露成本价、站点信息、API配置等敏感数据。
- 只允许访问对应 `submission_id + token`。

生成 URL：

```text
url('/media-submission-preview/'.$submission->id.'/'.$submission->preview_token)
```

策影媒体2投稿时：

```text
content = urlencode(预览URL)
```

### 8.3 预览内容

预览页显示：

- 文章标题。
- 正文 HTML。
- 基础样式，保证第三方编辑可读。

不展示：

- 管理后台导航。
- 投稿订单信息。
- 成本价和积分价。

## 9. 策影媒体2接口请求细节

### 9.1 新闻媒体投稿

```text
POST /media/order
```

参数：

```text
sn          agent_order_sn，必须唯一，最大64
resource_id external_resource_id
title       urlencode(文章标题)
content     urlencode(投稿预览URL)
remark      urlencode(备注)
owner       可选
```

响应：

```text
data.partner_sn -> external_order_nid
```

### 9.2 自媒体投稿

```text
POST /we-media/order
```

参数：

```text
sn
resource_id
title
content
remark
owner
publish_form = 1
publish_type = 1
account_rule = 3
```

第一期默认：

```text
publish_form = 1  图文发布
publish_type = 1  图文
account_rule = 3  不允许换号发布
```

如后续业务需要，可在后台增加配置项。

### 9.3 查询订单

新闻媒体：

```text
GET /media/order/query
sn[] = agent_order_sn
```

自媒体：

```text
GET /we-media/order/query
sn[] = agent_order_sn
```

响应字段：

```text
sn
url
screenshot
published_at
status
feedback
```

发布链接来源优先级：

```text
url > feedback.url
```

### 9.4 取消订单

新闻媒体：

```text
POST /media/order/cancel
```

自媒体：

```text
POST /we-media/order/cancel
```

参数：

```text
sn = agent_order_sn
reason
```

### 9.5 申请退款

新闻媒体：

```text
POST /media/order/apply-refund
```

自媒体：

```text
POST /we-media/order/apply-refund
```

参数：

```text
sn = agent_order_sn
reason
```

当前系统“申诉 / 售后”按钮对应此接口。

## 10. 后台交互方案

### 10.1 媒体资源页

新增平台筛选：

```text
媒体平台：
全部
策影媒体1
策影媒体2
```

表格新增列：

```text
媒体平台
```

媒体信息展示建议：

```text
媒体名称
策影媒体2 · 网站媒体 · 资源ID
```

超级管理员顶部按钮：

```text
同步策影媒体1
同步策影媒体2
```

同步状态分开展示：

```text
策影媒体1：running / completed / failed
策影媒体2：running / completed / failed
```

### 10.2 接口配置页

改成两个配置卡片或 Tab：

```text
策影媒体1 API配置
策影媒体2 API配置
```

策影媒体1字段：

```text
API Base URL
API Key
状态
积分价倍率
```

策影媒体2字段：

```text
API Base URL
AppID
Secret
状态
积分价倍率
```

### 10.3 投稿页

媒体选择项展示：

```text
媒体名称 · 策影媒体2 · 4.50 积分
```

### 10.4 订单列表与详情

新增展示：

```text
媒体平台
```

超级管理员继续可见：

```text
成本价
```

普通用户只看：

```text
消耗积分
媒体平台
媒体类型
订单状态
```

## 11. 路由与控制器调整

### 11.1 同步资源

当前：

```text
POST /media-distribution/resources/sync
```

建议保留旧路由兼容，同时新增：

```text
POST /media-distribution/resources/sync/{platform}
```

或者使用表单参数：

```text
POST /media-distribution/resources/sync
platform_id=1|2
```

推荐第二种，路由改动更少。

### 11.2 接口配置

当前：

```text
GET  /media-distribution/settings
POST /media-distribution/settings
```

建议保留路由，POST 增加：

```text
platform_id
```

后台同页保存不同平台配置。

### 11.3 投稿预览

新增公共路由：

```text
GET /media-submission-preview/{submission}/{token}
```

控制器建议：

```text
App\Http\Controllers\Site\MediaSubmissionPreviewController
```

也可以放在 `Admin\MediaDistribution` 下，但不要套后台鉴权中间件。

## 12. 兼容与迁移

### 12.1 老数据

所有现有数据回填：

```text
platform_id = 1
```

涉及表：

```text
media_api_settings
media_resources
media_submissions
media_resource_sync_runs
```

### 12.2 旧接口行为

策影媒体1保持现有逻辑：

- 同步仍拉现有接口。
- 投稿仍传现有字段。
- 查单、取消、申诉仍走现有接口。

### 12.3 唯一键迁移

迁移顺序：

1. 给 `media_resources` 加 `platform_id`。
2. 回填 `platform_id = 1`。
3. 删除旧唯一键 `source_type + external_resource_id`。
4. 增加新唯一键 `platform_id + source_type + external_resource_id`。

注意兼容 MySQL / PostgreSQL / SQLite 测试环境。

## 13. 测试计划

### 13.1 单元测试

签名：

- 参数排序。
- 列表排序。
- 嵌套字典排序。
- 排除 `signature`。
- 生成 HMAC sha256。

字段映射：

- 策影媒体2新闻媒体资源映射。
- 策影媒体2自媒体资源映射。
- 状态 `2` 映射 active。
- 非 `2` 映射 inactive。
- 自媒体成本价取 `price`。

订单状态：

- 超级媒介状态映射到系统状态。
- 退款成功只退积分一次。

### 13.2 Feature 测试

- 普通用户看不到成本价。
- 超级管理员能看到成本价。
- 媒体资源列表能按平台筛选。
- 策影媒体1和策影媒体2相同 `external_resource_id` 不冲突。
- 策影媒体2投稿生成预览 URL。
- 策影媒体2查单使用 `agent_order_sn`。
- 策影媒体2取消使用 `agent_order_sn`。
- 策影媒体2申诉调用申请退款接口。

### 13.3 回归测试

- 策影媒体1同步不受影响。
- 策影媒体1投稿不受影响。
- 积分扣减、退款不受影响。
- 利润报表继续按快照计算。

## 14. 实施步骤

建议分阶段实现。

### 阶段一：数据结构与平台基础

1. 新增平台常量。
2. 新增迁移，增加 `platform_id` 等字段。
3. 回填老数据为策影媒体1。
4. 调整模型 fillable / casts / 关系。

### 阶段二：客户端抽象

1. 抽出 `MediaPlatformClient` 接口。
2. 将现有 `MediaDistributionClient` 改造成策影媒体1客户端。
3. 新增策影媒体2客户端。
4. 新增签名器和测试。

### 阶段三：资源同步

1. 同步服务支持按平台同步。
2. 后台增加两个同步按钮。
3. 媒体资源列表增加平台筛选和平台列。
4. 同步状态按平台展示。

### 阶段四：投稿与订单

1. 投稿服务按资源 `platform_id` 选择客户端。
2. 新增投稿预览 URL。
3. 策影媒体2投稿传预览 URL。
4. 查单、取消、申诉按订单 `platform_id` 选择客户端。

### 阶段五：接口配置与权限

1. 接口配置页拆成两个平台配置块。
2. 每个平台独立倍率。
3. 确认普通用户只看平台名和积分价。
4. 确认超管看成本价和配置入口。

### 阶段六：测试和验收

1. 跑新增单元测试。
2. 跑分发媒体 Feature 测试。
3. Docker 本地验证同步、投稿、查单、退积分。
4. 生产上线前备份数据库。

## 15. 验收标准

- 后台能配置策影媒体1和策影媒体2。
- 超级管理员能分别同步两个平台媒体资源。
- 用户能看到媒体属于策影媒体1或策影媒体2。
- `source_type` 仍然只表示网站媒体 / 自媒体。
- 策影媒体2新闻媒体资源能正常同步。
- 策影媒体2自媒体资源能正常同步，成本价取 `price`。
- 策影媒体2投稿能成功创建订单。
- 策影媒体2查单能更新状态和发布链接。
- 取消订单能调用对应平台接口。
- 申诉能调用策影媒体2申请退款接口。
- 成本价仅超管可见。
- 积分价所有可投稿用户可见。
- 投稿扣费和退款规则与现有系统一致。

## 16. 待确认事项

当前已确认：

- `source_type` 不动。
- 新增 `platform_id`。
- 策影媒体2自媒体第一期只用图文成本价 `price`。
- 策影媒体2自媒体第一期固定图文投稿参数：
  - `publish_form = 1`
  - `publish_type = 1`
  - `account_rule = 3`
- 当前“申诉”对应策影媒体2“申请退款”。
- 补发第一期不做独立按钮。
- 投稿预览 URL 允许被第三方公网访问。
- 策影媒体2 API Secret 就是文档中的 HMAC secret。
