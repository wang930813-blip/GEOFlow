# Brand Diagnosis Doubao MVP Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the first real brand diagnosis flow using Doubao web search only.

**Architecture:** A POST request creates a new diagnosis run, reserves daily free usage for normal admins, creates fixed questions, and dispatches one queued job. The job calls Volcano Ark Responses API with `web_search`, stores answers and citation sources, and recalculates run metrics for the admin page.

**Tech Stack:** Laravel 12, Blade, Eloquent, Redis queue, Laravel HTTP client, PHPUnit.

---

### Task 1: Tests

**Files:**
- Modify: `tests/Feature/AdminBrandDiagnosisPageTest.php`
- Create: `tests/Feature/BrandDiagnosisDoubaoFlowTest.php`

- [ ] Add failing tests for creating a Doubao diagnosis run.
- [ ] Add failing tests for normal admin daily limit and super admin bypass.
- [ ] Add failing tests for Doubao client request/response parsing with `Http::fake()`.
- [ ] Add failing tests for the processing job persisting answers, sources, and metrics.

### Task 2: Data Model

**Files:**
- Create: `database/migrations/2026_06_06_000000_create_brand_diagnosis_tables.php`
- Create: `app/Models/BrandDiagnosisRun.php`
- Create: `app/Models/BrandDiagnosisQuestion.php`
- Create: `app/Models/BrandDiagnosisResult.php`
- Create: `app/Models/BrandDiagnosisSource.php`
- Create: `app/Models/BrandDiagnosisUsageLimit.php`

- [ ] Add run, question, result, source, and daily usage limit tables.
- [ ] Add guarded/fillable fields, casts, and relationships.

### Task 3: Services And Job

**Files:**
- Create: `config/brand_diagnosis.php`
- Modify: `.env.example`
- Modify: `.env.prod.example`
- Create: `app/Services/BrandDiagnosis/*`
- Create: `app/Jobs/ProcessBrandDiagnosisJob.php`
- Modify: `app/Providers/AppServiceProvider.php`

- [ ] Add config-backed Doubao API settings.
- [ ] Add fixed question generator.
- [ ] Add usage policy and run creation service.
- [ ] Add Doubao Responses API client using `tools: [{type: "web_search"}]`.
- [ ] Add metrics calculation and queued processing.

### Task 4: Controller And Page

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Admin/BrandDiagnosisController.php`
- Modify: `resources/views/admin/brand-diagnosis/index.blade.php`

- [ ] Add POST route.
- [ ] Submit the brand search form with CSRF.
- [ ] List real diagnosis runs and show per-run questions, answers, and sources.
- [ ] Keep non-Doubao model cards visible but only create Doubao runs in this MVP.

### Task 5: Verification

- [ ] Run targeted brand diagnosis tests.
- [ ] Run PHP syntax checks for new/modified PHP files.
- [ ] Run existing `AdminBrandDiagnosisPageTest`.
