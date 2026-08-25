# Video Generation CreBee Publish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a standalone video generation module backed by MoneyPrinterTurbo, enforce account plan quotas, and publish generated videos through the existing CreBee Bridge Agent workflow.

**Architecture:** GEOFlow owns video generation jobs, UI, quota, and publish job creation. MoneyPrinterTurbo runs async video generation and is polled by Laravel queue jobs. CreBee Bridge Agent localizes video/cover URLs into local paths before calling CreBee.

**Tech Stack:** Laravel 12, Blade, Eloquent, Laravel Queue, Laravel HTTP Client, Node.js Bridge Agent.

---

### Task 1: Video generation schema and model

**Files:**
- Create: `database/migrations/2026_06_18_100000_create_video_generation_jobs_table.php`
- Create: `app/Models/VideoGenerationJob.php`
- Test: `tests/Feature/AdminVideoGenerationTest.php`

- [ ] Write failing tests for creating and storing video jobs.
- [ ] Create migration with indexes for `site_id`, `owner_admin_id`, `status`, and `api_task_id`.
- [ ] Create `VideoGenerationJob` model with fillable fields, defaults, casts, and relationships.
- [ ] Run targeted tests.

### Task 2: MoneyPrinterTurbo client and generation service

**Files:**
- Create: `config/video-generation.php`
- Create: `app/Services/VideoGeneration/VideoGenerationClient.php`
- Create: `app/Services/VideoGeneration/VideoGenerationService.php`
- Test: `tests/Feature/AdminVideoGenerationTest.php`

- [ ] Write failing tests for task creation, quota deduction, and API failure without quota deduction.
- [ ] Implement HTTP client with base URL, timeout, optional `x-api-key`, create task, and get task methods.
- [ ] Implement service that validates subscription, calls the API, consumes `video_generations`, and dispatches polling.
- [ ] Run targeted tests.

### Task 3: Polling job

**Files:**
- Create: `app/Jobs/PollVideoGenerationJob.php`
- Modify: `app/Services/VideoGeneration/VideoGenerationClient.php`
- Test: `tests/Feature/AdminVideoGenerationTest.php`

- [ ] Write failing tests for successful polling and failed polling.
- [ ] Implement one-shot polling job that updates progress and redispatches itself with delay while processing.
- [ ] Mark jobs success when videos are returned, failed when task fails or returns no video files.
- [ ] Run targeted tests.

### Task 4: Admin UI and routes

**Files:**
- Modify: `routes/web.php`
- Create: `app/Http/Controllers/Admin/VideoGenerationController.php`
- Create: `resources/views/admin/video-generations/index.blade.php`
- Create: `resources/views/admin/video-generations/create.blade.php`
- Create: `resources/views/admin/video-generations/show.blade.php`
- Modify: `resources/views/admin/partials/header.blade.php`
- Test: `tests/Feature/AdminVideoGenerationTest.php`

- [ ] Write failing tests for list, create, show, ownership isolation, preview and download links.
- [ ] Add routes under admin auth/site middleware.
- [ ] Add controller methods for index, create, store, show, update cover.
- [ ] Add views with stable, simple UI matching the admin style.
- [ ] Add menu entry.
- [ ] Run targeted tests.

### Task 5: Self-media video publish service

**Files:**
- Modify: `app/Support/Crebee/SelfMediaPlatformCatalog.php`
- Create: `app/Services/Crebee/SelfMediaVideoPublishService.php`
- Create: `app/Http/Controllers/Admin/VideoSelfMediaPublishController.php`
- Modify: `routes/web.php`
- Modify: `resources/views/admin/video-generations/index.blade.php`
- Modify: `resources/views/admin/video-generations/show.blade.php`
- Modify: `resources/views/admin/crebee-publish-records/index.blade.php`
- Test: `tests/Feature/AdminVideoGenerationTest.php`

- [ ] Write failing tests for video publishing, cover requirement, platform account filtering, and publish quota consumption.
- [ ] Add video platform catalog methods.
- [ ] Create video publish jobs with `content_type=video` and `content_source_type=video_generation`.
- [ ] Reuse `crebee_publish_jobs/items/events` and publish record page.
- [ ] Run targeted tests.

### Task 6: Bridge Agent asset localization

**Files:**
- Modify: `tools/crebee-bridge-agent/src/config.mjs`
- Modify: `tools/crebee-bridge-agent/src/job-runner.mjs`
- Modify: `tools/crebee-bridge-agent/.env.example`
- Modify: `tools/crebee-bridge-agent/README.md`
- Test: agent smoke test commands/manual log verification.

- [ ] Add asset cache config.
- [ ] Download job assets before video publish.
- [ ] Replace `videoPath`, `coverPath`, and `verticalCoverPath` with local paths.
- [ ] Clean expired cache files.
- [ ] Run agent syntax checks.

### Task 7: Verification

**Files:**
- All touched files.

- [ ] Run `php artisan test --filter=AdminVideoGenerationTest`.
- [ ] Run `php artisan test --filter=AdminArticleSelfMediaPublishTest`.
- [ ] Run `php artisan test --filter=CrebeeAgentApiTest`.
- [ ] Run Node syntax checks for Bridge Agent files.
- [ ] Summarize remaining manual checks for MoneyPrinterTurbo and CreBee desktop.
