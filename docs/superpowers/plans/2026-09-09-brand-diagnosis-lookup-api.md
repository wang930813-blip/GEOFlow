# Brand Diagnosis Lookup API Implementation Plan

> **For agentic workers:** Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a separately authenticated, cross-site lookup endpoint that returns the newest matching brand diagnosis or generates a non-persistent profile/question preview without changing the existing diagnosis OpenAPI.

**Architecture:** Keep the existing diagnosis create/detail routes and presenter untouched. Add a dedicated middleware, request, controller, lookup service, and presenter; the service performs scoped-free matching and uses the existing profile/question providers only for non-stock previews. `include` controls eager loading and serialization.

**Tech Stack:** Laravel 12, Eloquent, Form Requests, PHPUnit feature/unit tests, Docker Compose.

---

### Task 1: Define failing API and service tests

**Files:**
- Create: `tests/Feature/BrandDiagnosisLookupApiTest.php`
- Create: `tests/Unit/BrandDiagnosisLookupServiceTest.php`

- [x] **Step 1: Write tests for independent lookup authentication, validation, stored matching, include filtering, and generated preview behavior.**
- [x] **Step 2: Run the focused tests and confirm they fail because the route/service do not exist.**

### Task 2: Add configuration, middleware, request, route, and key command

**Files:**
- Modify: `config/brand_diagnosis.php`
- Create: `app/Http/Middleware/AuthenticateBrandDiagnosisLookupApiKey.php`
- Create: `app/Http/Requests/Api/V1/BrandDiagnosisLookupRequest.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/api.php`
- Create: `app/Console/Commands/GenerateBrandDiagnosisLookupApiKeyCommand.php`

- [x] **Step 1: Add lookup enabled/key/cache/rate-limit configuration without sharing old OpenAPI settings.**
- [x] **Step 2: Implement constant-time `X-Api-Key` validation and disabled/invalid error contracts.**
- [x] **Step 3: Validate `brand_word` and comma-separated allowed `include` values.**
- [x] **Step 4: Register middleware alias, rate limiter, static `search` route before `{taskKey}`, and Artisan key generation.**
- [x] **Step 5: Run focused authentication and validation tests.**

### Task 3: Implement stored lookup and generated preview service

**Files:**
- Create: `app/Services/BrandDiagnosis/BrandDiagnosisLookupService.php`
- Modify: `app/Services/BrandDiagnosis/BrandProfileResolver.php`

- [x] **Step 1: Implement exact/canonical/prefix/contains ranking using `BrandEntityResolver::canonicalKey()`, newest `created_at/id`, and both global scopes removed.**
- [x] **Step 2: Eager-load only requested stored relations and expose module status for unavailable/not-run data.**
- [x] **Step 3: Implement non-persistent profile verification and question generation with cache lock; never dispatch jobs, create records, or consume quota.**
- [x] **Step 4: Map provider/profile/question failures to the documented `ApiException` codes.**
- [x] **Step 5: Run unit and feature tests for stored and generated branches.**

### Task 4: Implement public lookup presenter and controller

**Files:**
- Create: `app/Services/BrandDiagnosis/BrandDiagnosisLookupPresenter.php`
- Create: `app/Http/Controllers/Api/V1/BrandDiagnosisLookupController.php`

- [x] **Step 1: Serialize the public fields, requested module metadata, performance, questions, model results, sources, and sanitized snapshots.**
- [x] **Step 2: Reuse `BrandDiagnosisSnapshotPayload::displayAnswer()` in memory when a stored result lacks `snapshot_payload`; do not expose raw/provider/meta fields.**
- [x] **Step 3: Return the shared `ApiResponse` envelope and request ID.**
- [x] **Step 4: Run all lookup tests and existing brand diagnosis API tests.**

### Task 5: Documentation and verification

**Files:**
- Create or modify: `docs/api/brand-diagnosis-lookup.md`
- Modify: `.env.example` if present

- [x] **Step 1: Document endpoint, key generation, include modules, data source semantics, errors, and server-side usage.**
- [x] **Step 2: Run PHP lint, focused PHPUnit, full relevant PHPUnit suite, and route/config checks inside Docker.**
- [x] **Step 3: Review the diff to ensure the existing OpenAPI route, middleware, and presenter behavior are unchanged.**
