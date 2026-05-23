# 策影GEO 多用户多站点升级计划

## 目标

把当前单后台、单站点的数据模型升级为多用户多站点系统。每个用户可以管理一个或多个站点，每个站点拥有独立的文章、任务、素材、知识库、关键词、图片、AI 配置、分发渠道和报表数据。

## 总体架构

采用单数据库、多租户字段隔离方案。系统新增 `sites` 作为租户边界，新增 `site_members` 表维护用户与站点的成员关系。后台登录账号继续复用现有 `admins` 表，避免大规模重写认证系统。

核心关系：

```text
admins
  -> site_members
  -> sites
  -> articles / tasks / materials / knowledge / images / ai models / distribution / reports
```

## 隔离原则

1. 所有站点业务数据最终都必须归属 `site_id`。
2. 后台请求必须解析当前站点，并校验当前管理员是否是站点成员。
3. 普通管理员只能访问自己所属站点的数据。
4. 超级管理员可以跨站点维护，但默认也需要有当前站点上下文。
5. 队列任务、AI 生成、图片生成、分发和收录检测必须显式携带 `site_id`，不能依赖 session。

## 第一期：租户基础能力

目标：建立多站点基础，不大面积改业务数据。

- 新增 `sites` 表。
- 新增 `site_members` 表。
- 新增 `Site` 和 `SiteMember` 模型。
- 给现有管理员创建默认站点。
- 登录后台后自动设置当前站点。
- 增加当前站点上下文服务。
- 增加当前站点中间件。
- 增加后台站点切换接口。
- 顶部或用户菜单展示当前站点。
- 增加测试覆盖用户只能切换到自己所属站点。

验收标准：

- 老系统升级后自动生成默认站点。
- 已登录管理员访问后台时有当前站点。
- 管理员不能切换到非成员站点。
- 超级管理员可以进入站点上下文。

## 第二期：核心内容数据隔离

目标：让文章生成和内容资产按站点隔离。

改造表：

- `site_settings`
- `articles`
- `categories`
- `authors`
- `tasks`
- `task_runs`
- `task_schedules`
- `title_libraries`
- `titles`
- `keyword_libraries`
- `keywords`
- `knowledge_bases`
- `knowledge_chunks`
- `image_libraries`
- `images`
- `article_images`

实现点：

- 给核心表补 `site_id`。
- 给核心模型增加站点作用域。
- 列表、详情、创建、编辑、删除全部按当前站点过滤。
- 创建数据时自动写入当前 `site_id`。
- 任务运行时携带 `site_id`。
- 文章生成只读取当前站点的标题库、关键词库、知识库、图片库和作者。

验收标准：

- 用户 A 看不到用户 B 站点的文章和素材。
- 用户 A 不能通过 ID 编辑或删除用户 B 站点的数据。
- 任务生成文章时写入正确 `site_id`。
- 报错页面不泄露其他站点数据。

## 第三期：AI、分发和报表隔离

目标：把外围能力全部站点化。

改造表：

- `ai_models`
- `prompts`
- `sensitive_words`
- `distribution_channels`
- `distribution_channel_secrets`
- `distribution_logs`
- `article_distributions`
- `geo_inclusion_check_runs`
- `geo_inclusion_check_results`
- `url_import_jobs`
- `url_import_job_logs`
- `admin_activity_logs`
- `system_logs`
- `api_idempotency_keys`

实现点：

- AI 模型配置支持站点独立配置。
- 分发渠道按站点隔离。
- 收录检测按站点隔离。
- 数据分析和报表只统计当前站点。
- API Token 绑定站点或限制站点访问范围。
- 操作日志记录 `site_id`。

验收标准：

- 当前站点只能使用自己的 AI 配置。
- 当前站点只能看到自己的分发渠道和检测结果。
- 报表数字只来自当前站点。
- 日志可以按站点追踪。

## 第四期：平台化管理能力

目标：从多站点后台升级为可运营的 SaaS 平台。

- 平台管理员后台。
- 用户邀请和成员管理。
- 站点角色权限：owner、admin、editor、viewer。
- 站点套餐和额度。
- 文章生成额度。
- 图片生成额度。
- API Token 站点授权。
- 站点域名绑定。
- 前台按域名解析站点。

验收标准：

- 平台管理员可管理全部用户和站点。
- 站点所有者可邀请成员。
- 不同角色权限不同。
- 前台域名可映射到不同站点。

## 风险控制

- 不一次性改完所有表，按阶段推进。
- 每期都补隔离测试。
- 关键查询优先走模型作用域和当前站点上下文。
- 队列 Job 必须显式传 `site_id`。
- 对外 API 必须校验 Token 可访问的站点。
- 迁移必须给历史数据绑定默认站点。

## 当前执行顺序

1. 第一期租户基础能力。
2. 第二期核心内容数据隔离。
3. 第三期 AI、分发和报表隔离。
4. 第四期平台化管理能力。
