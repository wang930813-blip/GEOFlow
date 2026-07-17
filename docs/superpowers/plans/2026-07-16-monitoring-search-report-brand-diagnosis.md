# Monitoring Search Report Brand Diagnosis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 让企业舆情报表的搜索列表默认展示当前账号、当前站点的品牌诊断动态结果，并接入官方链接与公开快照。

**Architecture:** 继续由 `MonitoringReportDataService` 负责站点和账号作用域查询，在 `search_rows` 中输出官方链接、随机 token 快照链接和平台链接。企业报表 JavaScript 只消费结构化动态数据；静态数组保留为显式演示模式。

**Tech Stack:** Laravel 12、Eloquent、Blade/JavaScript、PHPUnit

---

### Task 1: 动态数据契约

**Files:**
- Modify: `tests/Unit/MonitoringReportDataServiceTest.php`
- Modify: `app/Services/MonitoringCenter/MonitoringReportDataService.php`

- [x] 在现有站点隔离测试中为品牌诊断结果写入 `snapshot_token` 和 `official_share_url`。
- [x] 断言 `search_rows` 返回 `official_url`、`snapshot_url`、平台链接和 `checked_at` 查询时间。
- [x] 运行目标测试并确认因字段映射缺失而失败。
- [x] 修改 `searchRows()`，只读取成功且有回答的结果，输出经过校验的官方链接和公开快照链接。
- [x] 重新运行数据服务测试并确认通过。

### Task 2: 搜索报表操作链接

**Files:**
- Modify: `tests/Feature/AdminMonitoringCenterPageTest.php`
- Modify: `resources/views/admin/monitoring-center/reports/enterprise.blade.php`

- [x] 增加页面数据映射断言，要求读取 `snapshot_url` 且官方链接不回退到相关文章。
- [x] 运行目标测试并确认失败。
- [x] 将动态行映射增加 `snapshotUrl`，快照操作优先打开公开 token 链接。
- [x] 将动态官方链接限定为 `official_url`，空值显示不可用提示。
- [x] 重新运行监测中心页面测试。

### Task 3: 默认启用动态模式

**Files:**
- Modify: `.env`
- Modify: `config/geoflow.php`

- [x] 将本地虚拟数据开关设为 `false`。
- [x] 让搜索报表只读取专用开关；环境示例中的专用开关已是 `false`。
- [x] 清理配置缓存并确认运行时配置为 `false`。

### Task 4: 回归验证

- [x] 运行 `MonitoringReportDataServiceTest`。
- [x] 运行 `AdminMonitoringCenterPageTest`。
- [x] 运行品牌诊断快照专项测试。
- [x] 编译 Blade 模板并执行 `git diff --check`。
- [x] 从本地真实站点生成报表数据，确认列表使用品牌诊断动态数据。
