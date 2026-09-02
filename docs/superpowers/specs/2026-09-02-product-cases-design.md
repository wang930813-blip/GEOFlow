# 产品案例模块设计

## 背景

系统需要新增一个全站通用的 `产品案例` 模块。案例列表和详情页对所有访客公开，不要求登录；登录后台顶部导航也要提供 `产品案例` 入口，方便所有角色查看案例。案例数据由超管统一维护，管理入口放在后台右上角管理菜单中，不放在顶部主导航。

参考页面是 `https://t.marketingforce.com/tyuncase.html` 和 `https://t.marketingforce.com/tyuncasedetail/2329748.html`。参考站的详情页结构是“客户概述、锚点导航、指标数据、关键词排名、分享区”。本系统不照搬它的业务字段和视觉样式，只借鉴信息层级，把内容改成 GEO 场景下的产品案例。

## 目标

- 新增公开案例列表页和案例详情页，未登录用户也能访问。
- 后台顶部导航新增 `产品案例`，所有登录角色可见，点击进入公开案例列表页。
- 后台右上角管理菜单新增 `产品案例管理`，仅超管可见。
- 案例数据使用独立数据表维护，方便后续增删改查、筛选、排序和上下架。
- 一个产品案例只关联一个站点/品牌，通过 `site_id` 和 `owner_admin_id` 读取监测中心数据。
- 详情页以后台手工维护的品牌/公司案例内容为主，监测中心数据作为辅助数据块展示。
- 复用现有监测中心数据服务的字段，不复用监测中心报表 HTML 样式。

## 非目标

- 不改现有文章、分类、SEO、前台模板的数据结构。
- 不把产品案例做成站点私有内容。
- 不开放普通用户或代理编辑案例。
- 不复用监测中心报表整页 UI。
- 不在第一版做自动生成案例正文。

## 数据模型

新增 `product_cases` 表。

建议字段：

- `id`
- `site_id`：关联站点，可为空。为空时案例仍可展示，但没有监测数据块。
- `owner_admin_id`：关联品牌所属账号，可为空。绑定站点时同步写入站点负责人。
- `title`：案例标题。
- `slug`：公开访问标识，唯一。
- `company_name`：公司/品牌名称。
- `logo_url`：品牌 Logo。
- `cover_url`：案例封面图。
- `industry`：行业。
- `region`：地区。
- `business_mode`：业务模式。
- `module_tags`：功能标签，JSON 数组。
- `summary`：案例摘要。
- `content`：案例正文，支持 Markdown 或纯文本。
- `customer_level`：客户等级或展示标签。
- `started_at`：案例开始时间，用于展示合作天数。
- `status`：`draft`、`published`、`hidden`。
- `sort_order`：排序值，越大越靠前。
- `view_count`：公开详情页浏览次数。
- `published_at`
- `created_by_admin_id`
- `updated_by_admin_id`
- `created_at`
- `updated_at`
- `deleted_at`

模型使用软删除。公开页只读取 `status = published` 且 `published_at <= now()` 的案例。

## 后台管理

新增 `Admin\ProductCaseController`。

路由放在后台受保护路由下，并加 `admin.super`：

- `GET /geo_admin/product-cases`：案例管理列表。
- `GET /geo_admin/product-cases/create`：新增案例。
- `POST /geo_admin/product-cases`：保存案例。
- `GET /geo_admin/product-cases/{case}/edit`：编辑案例。
- `PUT /geo_admin/product-cases/{case}`：更新案例。
- `DELETE /geo_admin/product-cases/{case}`：软删除案例。
- `POST /geo_admin/product-cases/{case}/toggle-status`：上下架。

管理列表支持：

- 标题/公司名搜索。
- 状态筛选。
- 行业、地区筛选。
- 关联站点筛选。
- 创建时间倒序。
- 分页。

新增/编辑表单支持：

- 选择关联站点/品牌。选择站点后自动确定 `site_id` 和 `owner_admin_id`。
- 填写案例标题、slug、公司名、Logo、封面、行业、地区、业务模式、功能标签、摘要、正文、客户等级、开始时间、排序、状态。
- `slug` 可自动根据标题生成，也允许超管手动修改。
- 状态为发布时自动补 `published_at`。

## 导航设计

后台顶部主导航新增一个普通链接：

- 名称：`产品案例`
- 可见角色：所有登录后台角色。
- 跳转地址：公开列表页 `/product-cases`。
- 不作为管理页入口。

后台右上角管理菜单新增：

- 名称：`产品案例管理`
- 可见角色：仅超管。
- 跳转地址：`/geo_admin/product-cases`。

这样查看入口和维护入口分开，普通用户不会看到维护能力，顶部导航也不会混入超管专属功能。

## 公开列表页

新增 `Public\ProductCaseController` 或同等公开控制器。

公开路由：

- `GET /product-cases`
- `GET /product-cases/{slug}`

列表页信息架构：

- 顶部 Banner：标题 `产品案例`，副文案强调 GEO/AI 搜索优化案例。
- 筛选区：地区、行业、业务模式、功能模块、关键词。
- 案例卡片：Logo、公司/品牌名、标题、摘要、行业/地区/标签、核心指标。
- 分页。

列表页核心指标使用轻量摘要，不加载完整报表。建议展示：

- AI 平台覆盖数量。
- 搜索报表数量。
- 品牌提及结果数。
- 关键词/问题词数量。

如果案例没有绑定站点或没有监测数据，卡片只展示手工内容，不展示空指标。

## 公开详情页

详情页以案例内容为主，监测数据为辅助。

页面结构：

1. 顶部案例概述
   - 公司/品牌名、Logo、客户等级、行业、地区、业务模式、合作天数、浏览量、分享按钮。

2. 品牌介绍
   - 展示后台维护的 `summary` 和 `content`。
   - 内容支持 Markdown 渲染，避免长文本堆成一坨。

3. GEO 成效总览
   - 从监测中心企业报表和行业报表中抽取关键指标。
   - 使用案例页自己的卡片样式展示。

4. AI 平台表现
   - 展示平台覆盖、平台分析、品牌排名或提及情况。
   - 只展示有数据的平台。

5. 搜索报表样例
   - 展示若干条问题、平台、终端、收录词、官方链接、快照凭证。
   - 快照和官方链接遵循现有公开访问规则。

6. 竞品与情感
   - 展示竞品提及和情感倾向摘要。
   - 数据为空时隐藏该区块。

7. 分享区
   - 复制当前案例链接。
   - 二维码可先不做，第一版用复制链接满足传播。

详情页不使用监测中心大报表的深色大屏样式，而使用更像客户案例的白底、分区、卡片、表格和轻量图表。

## 监测数据整合

新增 `ProductCaseReportSummaryService`，负责把监测中心两个报表压缩成案例页可用的数据结构。

输入：

- `ProductCase`

处理：

- 如果案例有关联站点和 owner，则根据对应 `Admin` 和 `Site` 调用现有 `MonitoringReportDataService`。
- 调用 `enterpriseReport()` 获取：总览指标、AI 收录、问题词、趋势、搜索报表。
- 调用 `industryReport()` 获取：品牌画像、排名表现、平台分析、竞品、情感。
- 抽取列表页轻量指标和详情页完整数据块。

输出：

- `summary_metrics`
- `platforms`
- `search_rows`
- `trend`
- `brand_profile`
- `overall`
- `competitors`
- `sentiment`

空数据处理：

- 未绑定站点：公开页正常展示案例内容，隐藏监测数据块。
- 绑定站点但无数据：展示“暂无监测数据”提示，只出现在详情数据区域。
- 单个区块为空：直接隐藏该区块。

## SEO

列表页：

- `title`：产品案例。
- `description`：使用平台统一描述。
- JSON-LD 使用 `CollectionPage`。

详情页：

- `title`：案例标题。
- `description`：优先使用案例摘要。
- JSON-LD 使用 `Article` 或 `CaseStudy` 的近似结构，兼容 schema.org 可识别字段。
- `canonical` 使用 `/product-cases/{slug}`。

## 权限和安全

- 公开页只读，不要求登录。
- 管理页仅超管访问。
- 公开页不展示草稿、下架、未来发布时间的案例。
- 外链图片 URL 只允许 `http`、`https` 或站内绝对路径。
- Markdown 内容渲染沿用现有安全处理方式，避免直接输出未过滤 HTML。
- 软删除案例不可公开访问。

## 测试重点

- 超管可以创建、编辑、上下架、删除案例。
- 普通用户和代理无法访问案例管理页。
- 所有登录用户都能看到顶部 `产品案例` 查看入口。
- 未登录用户可以访问已发布案例列表和详情。
- 草稿、下架、软删除案例公开访问返回 404。
- 绑定站点的案例详情能展示监测数据块。
- 未绑定站点或无监测数据时页面不报错。
- 筛选、搜索、分页正常。
- slug 唯一性和自动生成正常。

## 实施顺序

1. 新增迁移和 `ProductCase` 模型。
2. 新增后台案例管理控制器、路由和 Blade 页面。
3. 新增顶部查看入口和右侧超管管理入口。
4. 新增公开案例列表和详情控制器、路由和 Blade 页面。
5. 新增 `ProductCaseReportSummaryService` 整合监测中心数据。
6. 补充 SEO、空数据状态、权限校验和软删除逻辑。
7. 本地执行迁移和基础回归测试。

