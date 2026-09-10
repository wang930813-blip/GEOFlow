# Video Topic Script Auto Generation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add automatic Douyin/GEO-style video topic and script drafting before the existing video generation task is submitted.

**Architecture:** Keep `VideoGenerationService` and the MoneyPrinter video API unchanged. Add a focused drafting service that reads a selected keyword library, randomly selects one keyword, optionally reads a selected knowledge base, calls the same active GPT-5.5/article-generation model configuration, and returns topic candidates or a script draft to the admin create page.

**Tech Stack:** Laravel 12, Blade, Laravel AI SDK anonymous agents, existing `AiModel`, `KeywordLibrary`, `Keyword`, `KnowledgeBase`, and `OpenAiRuntimeProvider` utilities.

---

### Task 1: Drafting Service Tests

**Files:**
- Create: `tests/Unit/VideoContentDraftServiceTest.php`

- [x] **Step 1: Write failing tests**

Create tests for parsing topic JSON, filtering six style candidates, and building a 40-60 second script payload from model output.

- [x] **Step 2: Run tests and verify RED**

Run: `docker compose exec -T app php artisan test tests/Unit/VideoContentDraftServiceTest.php`

Expected: fail because `VideoContentDraftService` does not exist.

### Task 2: Drafting Service

**Files:**
- Create: `app/Services/VideoGeneration/VideoContentDraftService.php`

- [x] **Step 1: Implement service**

Implement:
- `topicCandidates(Admin $admin, Site $site, int $keywordLibraryId, ?int $knowledgeBaseId): array`
- `scriptDraft(Admin $admin, Site $site, array $payload): array`

The service should:
- Resolve the same active chat model used by article generation, preferring model name/model id containing `GPT-5.5` or `gpt-5.5`.
- Apply existing AI owner scope through `AiConfigurationScope`.
- Randomly select one keyword from the selected keyword library.
- Use keyword library brand fields and optional knowledge base content as context.
- Return six topic candidates for: `question`, `avoid_pitfall`, `how_to_choose`, `comparison`, `scenario`, `trend`.
- Return a script using the document rhythm: problem hook, pain/misconception, 2-3 knowledge points, brand connection, conclusion.
- Parse JSON first, then fall back to line parsing where needed.

- [x] **Step 2: Run tests and verify GREEN**

Run: `docker compose exec -T app php artisan test tests/Unit/VideoContentDraftServiceTest.php`

Expected: pass.

### Task 3: Admin JSON Endpoints

**Files:**
- Modify: `app/Http/Controllers/Admin/VideoGenerationController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AdminVideoGenerationTest.php`

- [x] **Step 1: Add failing feature tests**

Cover:
- Create page shows keyword library and knowledge base selectors.
- User can request topic candidates and receives JSON candidates.
- User can request a script draft from a selected candidate.
- Agent admin is forbidden from drafting videos.

- [x] **Step 2: Implement endpoints**

Add:
- `POST /geo_admin/video-generations/topic-candidates`
- `POST /geo_admin/video-generations/script-draft`

Both endpoints should validate input, require an active site context, block agent admins, and return JSON errors with readable messages.

- [x] **Step 3: Run feature tests**

Run: `docker compose exec -T app php artisan test tests/Feature/AdminVideoGenerationTest.php`

Expected: pass.

### Task 4: Create Page Interaction

**Files:**
- Modify: `resources/views/admin/video-generations/create.blade.php`

- [x] **Step 1: Add automatic drafting UI**

Add a compact panel above the existing form:
- Keyword library select
- Optional knowledge base select
- Generate topic candidates button
- Six candidate topic cards
- Generate script button on each topic

Do not add model selection. Generated subject and script fill the existing `subject` and `script` fields.

- [x] **Step 2: Keep manual editing**

Users can still edit subject and script before submitting. Existing submit button and create flow remain unchanged.

### Task 5: Verification

**Files:**
- All touched files

- [x] **Step 1: Run focused tests**

Run:
- `docker compose exec -T app php artisan test tests/Unit/VideoContentDraftServiceTest.php`
- `docker compose exec -T app php artisan test tests/Feature/AdminVideoGenerationTest.php --filter=video`

- [x] **Step 2: Run syntax checks**

Run:
- `docker compose exec -T app php -l app/Services/VideoGeneration/VideoContentDraftService.php`
- `docker compose exec -T app php -l app/Http/Controllers/Admin/VideoGenerationController.php`
