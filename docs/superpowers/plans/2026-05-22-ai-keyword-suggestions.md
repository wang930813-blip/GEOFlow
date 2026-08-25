# AI Keyword Suggestions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an AI-assisted preview-and-select keyword suggestion workflow to keyword library detail pages.

**Architecture:** Add a focused backend suggestion service, two admin endpoints, and an extension of the existing keyword detail modal. The service owns prompt construction, AI invocation, and output parsing; the controller owns request validation and persistence.

**Tech Stack:** Laravel 12, Blade, Tailwind CSS, PHPUnit/Pest-style Laravel tests, existing OpenAI-compatible runtime helpers.

---

### Task 1: Suggestion Service Parsing

**Files:**
- Create: `app/Services/GeoFlow/GeoKeywordSuggestionService.php`
- Test: `tests/Unit/GeoKeywordSuggestionServiceTest.php`

- [ ] **Step 1: Write failing tests for parser behavior**

Create tests that instantiate the service with no dependencies needed for parsing and assert JSON, line text, and duplicates normalize correctly.

- [ ] **Step 2: Run parser tests and verify they fail**

Run: `php artisan test tests/Unit/GeoKeywordSuggestionServiceTest.php`
Expected: fail because the class does not exist.

- [ ] **Step 3: Implement parser and normalizer**

Add public methods `parseSuggestionsForTesting(string $output, int $limit): array` and internal parsing helpers. The testing method exposes only parsing behavior.

- [ ] **Step 4: Run parser tests and verify they pass**

Run: `php artisan test tests/Unit/GeoKeywordSuggestionServiceTest.php`
Expected: pass.

### Task 2: Bulk Keyword Store Endpoint

**Files:**
- Modify: `app/Http/Controllers/Admin/KeywordLibraryController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AdminKeywordSuggestionTest.php`

- [ ] **Step 1: Write failing feature test for bulk insert**

Test that posting selected keywords to a library inserts new keywords, skips an existing duplicate, refreshes the library count, and redirects with a success message.

- [ ] **Step 2: Run the feature test and verify it fails**

Run: `php artisan test tests/Feature/AdminKeywordSuggestionTest.php --filter=bulk`
Expected: fail because the route does not exist.

- [ ] **Step 3: Implement route and controller method**

Add `bulkStoreKeywords(Request $request, int $libraryId): RedirectResponse`, validate `keywords`, normalize and de-duplicate selected values, insert missing values, refresh count, and redirect back with inserted/skipped counts.

- [ ] **Step 4: Run the feature test and verify it passes**

Run: `php artisan test tests/Feature/AdminKeywordSuggestionTest.php --filter=bulk`
Expected: pass.

### Task 3: AI Suggest Endpoint

**Files:**
- Modify: `app/Services/GeoFlow/GeoKeywordSuggestionService.php`
- Modify: `app/Http/Controllers/Admin/KeywordLibraryController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AdminKeywordSuggestionTest.php`

- [ ] **Step 1: Write failing test for no model error**

Test that posting a seed keyword when no active chat model exists returns JSON status 422 with a clear message.

- [ ] **Step 2: Run the endpoint test and verify it fails**

Run: `php artisan test tests/Feature/AdminKeywordSuggestionTest.php --filter=suggest`
Expected: fail because the suggest route does not exist.

- [ ] **Step 3: Implement suggest route and controller method**

Add `suggestKeywords(Request $request, int $libraryId): JsonResponse`. Validate `seed_keyword` and `count`, call the service, return suggestions as JSON, and convert runtime exceptions to 422 JSON errors.

- [ ] **Step 4: Implement AI model resolution and generation**

In the service, resolve the first active chat model ordered by failover priority, build the GEO prompt, decrypt the API key, register the OpenAI-compatible provider, call the markdown content agent, parse suggestions, and throw a runtime exception when no usable model exists.

- [ ] **Step 5: Run endpoint tests and verify they pass**

Run: `php artisan test tests/Feature/AdminKeywordSuggestionTest.php --filter=suggest`
Expected: pass.

### Task 4: Keyword Detail UI

**Files:**
- Modify: `resources/views/admin/keyword-libraries/detail.blade.php`

- [ ] **Step 1: Extend add modal markup**

Keep the manual single-keyword form, then add an AI generation section with seed input, count input, generate button, status message area, suggestion checklist form, and confirm button.

- [ ] **Step 2: Add modal JavaScript**

Add fetch logic for the suggest endpoint, loading state, error state, checkbox rendering, select-all handling, empty-selection validation, and hidden input creation for the bulk-store form.

- [ ] **Step 3: Run keyword page feature tests**

Run: `php artisan test tests/Feature/AdminMaterialsPagesTest.php tests/Feature/AdminKeywordSuggestionTest.php`
Expected: pass.

### Task 5: Final Verification

**Files:**
- No additional files.

- [ ] **Step 1: Run focused tests**

Run: `php artisan test tests/Unit/GeoKeywordSuggestionServiceTest.php tests/Feature/AdminKeywordSuggestionTest.php`
Expected: pass.

- [ ] **Step 2: Run existing related tests**

Run: `php artisan test tests/Feature/AdminMaterialsPagesTest.php tests/Unit/OpenAiRuntimeProviderTest.php`
Expected: pass.

- [ ] **Step 3: Check working tree**

Run: `git status --short`
Expected: only intended files changed.
