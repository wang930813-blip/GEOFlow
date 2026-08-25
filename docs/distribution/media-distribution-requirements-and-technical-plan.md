# 分发媒体模块功能需求与技术实现方案

## 1. 背景

当前系统已有“分发管理”模块，主要面向 GEOFlow 目标站点包、远端站点同步和文章分发。新的业务需求是接入第三方媒体投稿平台，将系统内已有文章投稿到“网站媒体”和“第三方自媒体”，并围绕媒体成本价、对客户展示价、站点积分和投稿订单形成独立业务闭环。

本方案要求：

- 隐藏现有“分发管理”模块入口，但保留原有路由、代码和数据，避免影响历史能力和回滚。
- 新增“分发媒体”模块，承接媒体列表、价格配置、文章投稿、订单同步、积分消耗等功能。
- 媒体接口成本价只对超级管理员可见；普通管理员和用户看到超级管理员配置后的销售价。
- 每个站点拥有独立积分账户，投稿按销售价 1:1 消耗积分。

接口文档：

- 小青蛙开放平台 API：https://s.apifox.cn/ecc95f5a-3182-406a-aeb5-8930e3a06e65

## 2. 接口范围

### 2.1 网站媒体

基础地址以接口文档为准，文档示例为：

```text
http://8.138.187.158:8082
```

接口：

```text
POST /api/media/media_list
POST /api/media/send
POST /api/media/order_info
POST /api/media/rejection
POST /api/media/get_field
POST /api/media/cancel_order
```

### 2.2 第三方自媒体

接口：

```text
POST /api/zi_media_api/media_list
POST /api/zi_media_api/send
POST /api/zi_media_api/order_info
POST /api/zi_media_api/rejection
POST /api/zi_media_api/get_field
POST /api/zi_media_api/cancel_order
```

### 2.3 关键字段

媒体资源列表返回字段包含：

```text
resource_id
title
remarks
case_link
status
price
```

其中：

- `resource_id`：第三方媒体资源 ID。
- `title`：媒体名称。
- `remarks`：投稿注意事项。
- `case_link`：案例链接。
- `status`：媒体状态。
- `price`：平台成本价，仅超级管理员可见。

投稿接口参数：

```text
api_key
resource_id
title
content
remark
third_id
```

投稿成功返回字段包含：

```text
order_nid
```

## 3. 角色与权限

### 3.1 超级管理员

超级管理员可以：

- 配置开放平台 API 地址和 `api_key`。
- 同步网站媒体和第三方自媒体资源。
- 查看媒体成本价。
- 为每个媒体配置销售价/积分价。
- 查看全部站点的积分账户、投稿订单和积分流水。
- 给任意站点充值、扣减、调整积分。
- 查看成本、销售价、利润等运营数据。

### 3.2 普通管理员和站点用户

普通管理员和站点用户可以：

- 仅查看当前站点可用数据。
- 查看媒体列表，但只能看到销售价，不能看到成本价。
- 从当前站点文章中选择文章投稿。
- 查看当前站点积分余额和积分流水。
- 查看当前站点投稿订单。
- 对当前站点投稿订单执行同步、取消、申诉等允许操作。

## 4. 功能需求

### 4.1 隐藏旧分发管理

旧模块处理方式：

- 从顶部导航、首页入口、菜单入口隐藏“分发管理”。
- 保留 `/geo_admin/distribution` 路由和控制器，不删除。
- 保留 `distribution_channels`、`article_distributions` 等旧表。
- 不迁移旧分发模块数据到新模块。

### 4.2 新增分发媒体模块

建议后台路由：

```text
/geo_admin/media-distribution/resources
/geo_admin/media-distribution/submissions
/geo_admin/media-distribution/credits
/geo_admin/media-distribution/settings
```

导航名称：

```text
分发媒体
```

模块页面：

- 媒体资源
- 投稿订单
- 积分管理
- 接口配置

### 4.3 媒体资源列表

媒体资源页支持：

- Tab：网站媒体、第三方自媒体。
- 搜索媒体名称。
- 筛选分类。
- 筛选状态。
- 筛选价格区间。
- 查看案例链接。
- 查看投稿备注。
- 发起投稿。

超级管理员额外支持：

- 手动同步媒体资源。
- 查看成本价。
- 编辑销售价。
- 查看毛利：销售价 - 成本价。

普通管理员和用户展示：

- 媒体名称。
- 媒体类型。
- 分类。
- 案例链接。
- 投稿备注。
- 状态。
- 销售价/积分价。
- 投稿按钮。

### 4.4 价格配置

价格分为两类：

- 成本价：第三方接口返回的 `price`。
- 销售价：系统内配置给站点管理员和用户看到的价格。

规则：

- 第一次同步资源时，默认 `sale_price = cost_price`。
- 后续同步成本价时，不覆盖已手动配置的 `sale_price`。
- 如果媒体下架或状态不可用，保留价格配置，但前台不可投稿。
- 投稿订单必须保存成本价和销售价快照，后续媒体价格变化不影响历史订单。

第一期建议只做全局销售价。

后续可扩展：

- 站点专属媒体价格。
- 批量加价规则。
- 按媒体分类设置利润率。

### 4.5 站点积分

每个站点有独立积分账户。

积分口径：

```text
1 积分 = 1 元
```

投稿消耗积分：

```text
消耗积分 = 媒体销售价
```

超级管理员可以：

- 给站点充值积分。
- 扣减站点积分。
- 调整站点积分。
- 查看全部站点积分流水。

普通管理员和用户可以：

- 查看当前站点余额。
- 查看当前站点积分流水。
- 投稿时消耗当前站点积分。

### 4.6 投稿流程

投稿文章来自当前系统的文章管理模块。

投稿前校验：

- 当前文章属于当前站点。
- 文章未删除。
- 文章内容不为空。
- 媒体资源状态可用。
- 当前站点积分余额足够。

投稿参数映射：

```text
api_key     => 系统配置的开放平台 API Key
resource_id => media_resources.external_resource_id
title       => articles.title
content     => 文章正文 HTML
remark      => 用户填写的投稿备注
third_id    => 本地投稿订单标识
```

`third_id` 建议格式：

```text
geoflow-{site_id}-{submission_id}
```

投稿成功后保存：

- 第三方订单号 `order_nid`。
- 投稿文章快照。
- 媒体价格快照。
- 消耗积分。
- 投稿人。
- 投稿时间。

### 4.7 投稿订单

投稿订单页展示：

- 订单 ID。
- 站点。
- 文章标题。
- 媒体名称。
- 媒体类型。
- 第三方订单号。
- 消耗积分。
- 成本价。
- 销售价。
- 状态。
- 发布链接。
- 失败原因。
- 投稿人。
- 投稿时间。
- 最后同步时间。

普通管理员和用户不显示成本价。

订单状态建议：

```text
draft
queued
submitting
submitted
publishing
published
rejected
cancelled
failed
```

操作：

- 查看详情。
- 手动同步状态。
- 取消订单。
- 订单申诉。

### 4.8 订单状态同步

第一期支持手动同步：

- 用户点击“同步状态”。
- 系统调用 `order_info` 接口。
- 更新本地订单状态、发布链接、失败原因等信息。

第二期支持自动同步：

- 定时任务每 10 到 30 分钟同步未终态订单。
- 只同步 `submitted`、`publishing` 等未完成状态。
- 对失败请求记录错误，避免无限重试。

### 4.9 取消订单和申诉

取消订单：

- 调用 `cancel_order`。
- 如果第三方确认取消成功，本地状态更新为 `cancelled`。
- 是否退积分需要根据第三方返回结果和业务规则确认。

申诉：

- 调用 `rejection`。
- 记录申诉内容和接口返回。
- 订单保持原状态或进入 `appealing` 状态，取决于接口返回能力。

## 5. 技术实现方案

### 5.1 配置

新增配置文件：

```text
config/media_distribution.php
```

建议配置项：

```php
return [
    'base_url' => env('MEDIA_DISTRIBUTION_API_BASE_URL', 'http://8.138.187.158:8082'),
    'timeout' => env('MEDIA_DISTRIBUTION_HTTP_TIMEOUT', 30),
    'connect_timeout' => env('MEDIA_DISTRIBUTION_HTTP_CONNECT_TIMEOUT', 10),
];
```

API Key 不建议放配置文件明文读取后直接使用，建议通过后台页面保存到数据库并加密。

`.env` 可提供默认值：

```env
MEDIA_DISTRIBUTION_API_BASE_URL=http://8.138.187.158:8082
MEDIA_DISTRIBUTION_HTTP_TIMEOUT=30
MEDIA_DISTRIBUTION_HTTP_CONNECT_TIMEOUT=10
```

### 5.2 数据表

#### media_api_settings

保存第三方接口配置。

字段建议：

```text
id
api_base_url
api_key_ciphertext
status
last_checked_at
last_error_message
created_at
updated_at
```

#### media_resources

保存媒体资源。

字段建议：

```text
id
source_type
external_resource_id
title
category
remarks
case_link
status
cost_price
sale_price
raw_payload
last_synced_at
created_at
updated_at
```

索引：

```text
unique(source_type, external_resource_id)
index(source_type, status)
index(title)
```

#### media_submissions

保存投稿订单。

字段建议：

```text
id
site_id
article_id
media_resource_id
source_type
external_order_nid
title_snapshot
content_snapshot
cost_price_snapshot
sale_price_snapshot
points_amount
status
remark
published_url
last_error_message
submitted_by_admin_id
submitted_at
last_synced_at
raw_submit_response
raw_status_response
created_at
updated_at
```

索引：

```text
index(site_id, status)
index(article_id)
index(media_resource_id)
index(external_order_nid)
```

#### site_credit_accounts

保存站点积分账户。

字段建议：

```text
id
site_id
balance
frozen_balance
total_recharged
total_consumed
created_at
updated_at
```

约束：

```text
unique(site_id)
```

#### site_credit_ledger

保存积分流水。

字段建议：

```text
id
site_id
submission_id
type
amount
balance_after
frozen_after
remark
operator_admin_id
created_at
```

流水类型：

```text
recharge
deduct
freeze
unfreeze
refund
adjust
```

### 5.3 模型

建议新增模型：

```text
App\Models\MediaApiSetting
App\Models\MediaResource
App\Models\MediaSubmission
App\Models\SiteCreditAccount
App\Models\SiteCreditLedger
```

关系：

- `MediaSubmission belongsTo Site`
- `MediaSubmission belongsTo Article`
- `MediaSubmission belongsTo MediaResource`
- `MediaSubmission belongsTo Admin as submittedBy`
- `SiteCreditAccount belongsTo Site`
- `SiteCreditLedger belongsTo Site`
- `SiteCreditLedger belongsTo MediaSubmission`

### 5.4 服务类

#### MediaDistributionClient

职责：

- 封装第三方 API 请求。
- 支持网站媒体和第三方自媒体路径切换。
- 注入 API Key。
- 设置 `timeout` 和 `connectTimeout`。
- 统一解析响应。
- 对错误响应抛出业务异常。

建议方法：

```text
listResources(sourceType, filters)
send(sourceType, payload)
orderInfo(sourceType, orderNid)
cancelOrder(sourceType, orderNid)
appeal(sourceType, orderNid, content)
categories(sourceType)
```

#### MediaResourceSyncService

职责：

- 同步媒体资源列表。
- 写入或更新 `media_resources`。
- 保存成本价。
- 保留手动销售价。
- 标记资源最后同步时间。

#### MediaSubmissionService

职责：

- 校验文章、媒体和积分。
- 创建投稿订单。
- 冻结积分。
- 分发队列任务。
- 保存投稿快照。

#### SiteCreditService

职责：

- 创建站点积分账户。
- 充值。
- 扣减。
- 冻结。
- 解冻。
- 退款。
- 写积分流水。

并发要求：

- 所有余额变更必须在数据库事务内完成。
- 查询账户时使用行锁。
- 禁止余额扣成负数。

### 5.5 队列任务

建议新增：

```text
SubmitMediaArticleJob
SyncMediaSubmissionStatusJob
SyncMediaResourcesJob
```

队列：

```text
media-distribution
```

投稿流程：

1. Web 请求创建本地投稿订单。
2. 冻结站点积分。
3. 投递 `SubmitMediaArticleJob`。
4. Job 调用第三方投稿接口。
5. 成功：保存 `order_nid`，冻结积分转正式扣除。
6. 失败：订单标记失败，解冻积分。

### 5.6 控制器与路由

建议新增控制器：

```text
App\Http\Controllers\Admin\MediaDistribution\ResourceController
App\Http\Controllers\Admin\MediaDistribution\SubmissionController
App\Http\Controllers\Admin\MediaDistribution\CreditController
App\Http\Controllers\Admin\MediaDistribution\SettingController
```

路由建议：

```text
GET  /geo_admin/media-distribution/resources
POST /geo_admin/media-distribution/resources/sync
POST /geo_admin/media-distribution/resources/{resource}/price

GET  /geo_admin/media-distribution/submissions
POST /geo_admin/media-distribution/submissions
GET  /geo_admin/media-distribution/submissions/{submission}
POST /geo_admin/media-distribution/submissions/{submission}/sync
POST /geo_admin/media-distribution/submissions/{submission}/cancel
POST /geo_admin/media-distribution/submissions/{submission}/appeal

GET  /geo_admin/media-distribution/credits
POST /geo_admin/media-distribution/credits/{site}/recharge
POST /geo_admin/media-distribution/credits/{site}/adjust

GET  /geo_admin/media-distribution/settings
POST /geo_admin/media-distribution/settings
```

### 5.7 权限和站点隔离

沿用当前系统 `CurrentSite` 和 `site_id` 机制。

规则：

- `media_resources` 是全局资源，不带 `site_id`。
- `media_submissions` 必须带 `site_id`。
- `site_credit_accounts` 必须带 `site_id`。
- `site_credit_ledger` 必须带 `site_id`。
- 普通管理员查询时强制限定当前站点。
- 超级管理员可以跨站点筛选。
- 普通管理员不能看到成本价和利润字段。

### 5.8 内容格式

接口要求 `content` 为 HTML 格式。

当前文章可能是 Markdown 或 HTML，投稿前需要统一处理：

- 如果文章内容已是 HTML，直接发送。
- 如果文章内容是 Markdown，转换成 HTML 后发送。
- 图片使用文章内容中的远程地址，不额外上传。
- 投稿时保存 `content_snapshot`，避免文章后续编辑影响历史投稿记录。

### 5.9 状态映射

第三方接口状态字段需在实现时根据返回样例补齐映射。

本地建议统一状态：

```text
queued       待投稿
submitting   投稿中
submitted    已提交
publishing   发布中
published    已发布
rejected     已拒稿
cancelled    已取消
failed       投稿失败
```

未知第三方状态：

- 保存原始响应到 `raw_status_response`。
- 本地状态保持不变。
- 记录同步日志。

## 6. 页面结构

### 6.1 媒体资源页

顶部信息：

- 当前站点积分余额。
- 媒体总数。
- 可投稿媒体数量。

列表字段：

- 媒体名称。
- 媒体类型。
- 分类。
- 案例链接。
- 投稿备注。
- 状态。
- 积分价。
- 操作。

超级管理员额外字段：

- 成本价。
- 利润。
- 销售价编辑。

### 6.2 投稿弹窗

字段：

- 媒体名称。
- 消耗积分。
- 当前站点余额。
- 选择文章。
- 投稿备注。

提交前提示：

```text
本次投稿将消耗 X 积分，投稿提交后会进入审核发布流程。
```

### 6.3 投稿订单页

筛选：

- 站点，超级管理员可见。
- 媒体类型。
- 媒体名称。
- 文章标题。
- 状态。
- 时间范围。

操作：

- 查看详情。
- 同步状态。
- 取消订单。
- 申诉。

### 6.4 积分管理页

超级管理员：

- 站点列表。
- 余额。
- 冻结积分。
- 累计充值。
- 累计消耗。
- 充值。
- 扣减/调整。
- 流水查看。

普通管理员：

- 当前站点余额。
- 当前站点冻结积分。
- 当前站点积分流水。

### 6.5 接口配置页

仅超级管理员可见。

配置：

- API Base URL。
- API Key。
- 启用状态。
- 测试连接。

API Key 必须加密存储，页面默认不回显完整明文。

## 7. 一期实现范围

第一期建议完成最小业务闭环：

1. 隐藏旧分发管理入口。
2. 新增“分发媒体”导航和页面。
3. 新增接口配置，保存开放平台 API Key。
4. 同步网站媒体和第三方自媒体资源列表。
5. 超级管理员配置媒体销售价。
6. 新增站点积分账户和积分流水。
7. 超级管理员给站点充值/扣减积分。
8. 普通管理员从当前站点文章选择媒体投稿。
9. 投稿消耗积分并创建本地订单。
10. 保存第三方 `order_nid`。
11. 投稿订单列表和详情。
12. 手动同步订单状态。

## 8. 二期实现范围

第二期建议扩展：

- 自动定时同步未完成订单。
- 取消订单完整闭环。
- 申诉完整闭环。
- 批量投稿。
- 站点专属媒体价格。
- 媒体分类同步和筛选优化。
- 投稿利润报表。
- 积分充值导出和订单导出。

## 9. 风险与待确认事项

需要在开发前或联调时确认：

- 第三方接口失败时是否已经扣除平台费用。
- 取消订单是否一定退款。
- 拒稿是否退款。
- `order_info` 的完整状态码和字段含义。
- `cancel_order` 的请求参数和返回语义。
- `rejection` 的请求参数和返回语义。
- 第三方接口是否有频率限制。
- 媒体资源列表是否支持分页、分类、关键词搜索。
- 投稿内容中图片是否允许远程图床地址。
- 是否需要用户侧页面，还是第一期只做后台管理员投稿。

## 10. 验收标准

第一期验收建议：

- 普通管理员看不到旧“分发管理”入口。
- 后台可以看到新“分发媒体”入口。
- 超级管理员可以配置 API Key 并同步媒体资源。
- 超级管理员可以看到成本价并配置销售价。
- 普通管理员只能看到销售价。
- 每个站点有独立积分余额。
- 超级管理员可以给站点充值积分。
- 当前站点积分不足时无法投稿。
- 当前站点积分足够时可以选择文章投稿媒体。
- 投稿成功后保存本地订单和第三方订单号。
- 投稿失败时记录失败原因，并按冻结规则退回积分。
- 投稿订单严格按站点隔离。
- 超级管理员可以查看全部站点订单。
