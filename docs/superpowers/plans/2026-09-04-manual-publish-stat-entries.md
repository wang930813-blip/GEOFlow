# 发布数据台账 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 新增一个仅超管可操作的运营手动录入台账，用于按当前站点维护媒体、自媒体、B2B、官网、视频五类发布条数。

**Architecture:** 新增独立数据表、模型和后台页面，所有统计只读取手动台账表，不读取或回写监测中心、真实发布任务、套餐额度。页面沿用后台当前站点上下文，超管切到哪个站点就查看和录入哪个站点的数据。

**Tech Stack:** Laravel、Blade、Tailwind utility classes、Eloquent、PHPUnit Feature Tests。

---

### Task 1: 功能测试

**Files:**
- Create: `tests/Feature/AdminManualPublishStatTest.php`

- [ ] 写测试覆盖：超管能访问页面，普通用户不能访问。
- [ ] 写测试覆盖：创建记录时使用当前站点，列表不会显示其他站点记录。
- [ ] 写测试覆盖：统计图表数据按当前站点汇总。
- [ ] 写测试覆盖：删除为软删除，软删除后不再进入汇总。

### Task 2: 数据层

**Files:**
- Create: `database/migrations/2026_09_04_000000_create_manual_publish_stat_entries_table.php`
- Create: `app/Models/ManualPublishStatEntry.php`
- Create: `app/Services/Admin/ManualPublishStatService.php`

- [ ] 新增表 `manual_publish_stat_entries`，字段包含 `site_id`、`owner_admin_id`、`created_by_admin_id`、`metric_type`、`quantity`、`stat_date`、`remark`、软删除和时间戳。
- [ ] 模型声明五类类型常量、label 映射、关联关系和 casts。
- [ ] 服务提供当前站点的总计、按类型汇总、最近 30 天柱状图数据、饼图数据和分页列表。

### Task 3: 后台入口

**Files:**
- Create: `app/Http/Controllers/Admin/ManualPublishStatController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/admin/partials/header.blade.php`

- [ ] 路由放入 `admin.super` 中，提供 `index`、`store`、`destroy`。
- [ ] 菜单放在右上角账号菜单的“运营配置”分组，仅超管可见。
- [ ] 控制器所有写入都使用 `CurrentSite`，没有当前站点时返回 403。

### Task 4: 页面

**Files:**
- Create: `resources/views/admin/manual-publish-stats/index.blade.php`

- [ ] 上方显示五个新增按钮，点击打开对应类型的弹窗。
- [ ] 中间左侧显示最近 30 天柱状图，右侧用 `conic-gradient` 显示类型占比饼图，避免引入新的前端依赖。
- [ ] 下方分页列表显示日期、类型、增加数量、备注、操作人、创建时间、删除操作。
- [ ] 空数据时显示清晰空状态。

### Task 5: 验证

- [ ] 运行 `php artisan test --filter=AdminManualPublishStatTest`。
- [ ] 运行必要的相关后台导航测试。
- [ ] 检查路由和视图无语法错误。
