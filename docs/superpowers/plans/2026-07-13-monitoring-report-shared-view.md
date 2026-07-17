# Monitoring Report Shared View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make each public monitoring report share page display only its captured report, with a fixed non-interactive report label instead of the report switcher.

**Architecture:** Add an explicit shared-view option to `MonitoringReportRenderer` and pass it only from the public share controller. Both Blade reports conditionally render either the existing authenticated switcher or a fixed label, so unrelated report links never enter the public DOM.

**Tech Stack:** Laravel 12, Blade, PHPUnit, HTML/CSS

---

### Task 1: Lock Public Share Rendering Behavior

**Files:**
- Modify: `tests/Feature/AdminMonitoringCenterPageTest.php`

- [x] **Step 1: Write the failing test**

Add a renderer test for both report types with `is_shared_view` enabled. Assert the output contains `data-monitoring-fixed-report`, excludes `<details class="report-menu">`, and excludes `<div class="report-menu-list">`.

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
docker compose exec app php artisan test --filter=shared_monitoring_report_renders_fixed_report_label
```

Expected: FAIL because the current shared rendering still outputs `<details class="report-menu">`.

### Task 2: Pass Explicit Shared-View State

**Files:**
- Modify: `app/Services/MonitoringCenter/MonitoringReportRenderer.php`
- Modify: `app/Http/Controllers/MonitoringReportShareController.php`

- [x] **Step 1: Extend renderer options**

Add `is_shared_view?: bool` to the renderer option contract and pass this value to Blade as `isSharedView`:

```php
'isSharedView' => (bool) ($options['is_shared_view'] ?? false),
```

- [x] **Step 2: Mark the public controller render**

Pass the option only from `MonitoringReportShareController`:

```php
'is_shared_view' => true,
```

The authenticated monitoring controller omits the option and therefore retains the current switcher.

### Task 3: Replace Public Switcher With Fixed Label

**Files:**
- Modify: `resources/views/admin/monitoring-center/reports/enterprise.blade.php`
- Modify: `resources/views/admin/monitoring-center/reports/industry.blade.php`

- [x] **Step 1: Add fixed-label styles**

Add final, page-local styles for `.monitoring-fixed-report` that match the current summary height, border, gradient, typography, and responsive width without hover or dropdown-arrow behavior.

- [x] **Step 2: Conditionally render report navigation**

Use server-side Blade branching in the enterprise view:

```blade
@if($isSharedView ?? false)
  <div class="report-menu monitoring-fixed-report" data-monitoring-fixed-report>
    <span>企业舆情分析报表</span>
  </div>
@else
  <details class="report-menu">
    <summary>企业舆情分析报表</summary>
    <div class="report-menu-list">
      <span>企业舆情分析报表</span>
      <a href="ai-search-competition-report.html">行业竞争力分析报表</a>
    </div>
  </details>
@endif
```

Use the corresponding branch in the industry view:

```blade
@if($isSharedView ?? false)
  <div class="report-menu monitoring-fixed-report" data-monitoring-fixed-report>
    <span>行业竞争力分析报表</span>
  </div>
@else
  <details class="report-menu">
    <summary>行业竞争力分析报表</summary>
    <div class="report-menu-list">
      <span>行业竞争力分析报表</span>
      <a href="geo-dashboard-replica.html">企业舆情分析报表</a>
    </div>
  </details>
@endif
```

- [x] **Step 3: Run focused tests**

Run:

```bash
docker compose exec app php artisan test --filter="shared_monitoring_report_renders_fixed_report_label|monitoring_center_share|monitoring_report_share"
```

Expected: all focused tests pass, authenticated pages retain their menu, and public pages contain no switcher markup.

- [x] **Step 4: Check formatting**

Run:

```bash
git diff --check -- app/Services/MonitoringCenter/MonitoringReportRenderer.php app/Http/Controllers/MonitoringReportShareController.php resources/views/admin/monitoring-center/reports/enterprise.blade.php resources/views/admin/monitoring-center/reports/industry.blade.php tests/Feature/AdminMonitoringCenterPageTest.php
```

Expected: exit code 0 with no whitespace errors.
