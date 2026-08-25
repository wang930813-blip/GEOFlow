# Brand Diagnosis Answer Snapshot Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 为每条品牌诊断回答生成公开系统快照，并提供超管专属的官方链接批量维护入口。

**Architecture:** 在诊断结果表保存随机公开令牌、冻结快照载荷和可选官方链接。公开控制器按令牌读取冻结内容；后台专用控制器通过服务端角色校验和平台官方域名校验批量更新链接；现有报告数据格式补充两个 URL 字段。

**Tech Stack:** Laravel 12、Eloquent、Blade、Tailwind CSS、PHPUnit

---

### Task 1: Public snapshot identity

**Files:**
- Create: `database/migrations/2026_07_16_000000_add_snapshot_and_official_link_to_brand_diagnosis_results.php`
- Modify: `app/Models/BrandDiagnosisResult.php`
- Test: `tests/Feature/BrandDiagnosisAnswerSnapshotTest.php`

- [x] Write failing tests for missing-token response and automatic token generation.
- [x] Run the focused tests and confirm the expected failures.
- [x] Add the migration, model event, casts, and relationships.
- [x] Run the focused tests.

### Task 2: Public snapshot page

**Files:**
- Create: `app/Http/Controllers/BrandDiagnosisSnapshotController.php`
- Create: `resources/views/brand-diagnosis/snapshot.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/BrandDiagnosisAnswerSnapshotTest.php`

- [x] Write a failing unauthenticated snapshot rendering test.
- [x] Add the public route, controller, and standalone view.
- [x] Freeze snapshot payloads for new and historical results.
- [x] Verify valid and invalid tokens, immutability, and cross-site public access.

### Task 3: Super-admin official link batch editor

**Files:**
- Create: `app/Http/Requests/Admin/UpdateBrandDiagnosisOfficialLinksRequest.php`
- Create: `app/Http/Controllers/Admin/BrandDiagnosisOfficialLinkController.php`
- Create: `resources/views/admin/brand-diagnosis/official-links.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/BrandDiagnosisAnswerSnapshotTest.php`

- [x] Write failing authorization, validation, and multi-row update tests.
- [x] Implement the request, controller, routes, and batch form.
- [x] Validate official domains per platform and preserve unchanged audit metadata.
- [x] Verify super-admin access and 403 responses for other roles.

### Task 4: Existing diagnosis UI integration

**Files:**
- Modify: `app/Http/Controllers/Admin/BrandDiagnosisController.php`
- Modify: `resources/views/admin/brand-diagnosis/index.blade.php`
- Modify: `resources/views/admin/brand-diagnosis/report.blade.php`
- Test: `tests/Feature/AdminBrandDiagnosisPageTest.php`

- [x] Write failing tests for snapshot links, official links, and the super-admin management button.
- [x] Add URLs to conversation rows and render the actions.
- [x] Run brand diagnosis page tests.

### Task 5: Verification

- [x] Run the new feature test and existing brand diagnosis test suites.
- [x] Run Pint on touched PHP files.
- [x] Run `git diff --check` and inspect the final diff.
