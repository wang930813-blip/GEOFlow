# GEO Capability Integration Blueprint

## Goal

Bring the useful GEO capabilities from `aAAaqwq/auto_geo_old` into this Laravel GEOFlow system without importing its Electron/FastAPI/n8n stack.

## Architecture Direction

GEOFlow remains the source of truth. The old repository is used as a product and workflow reference only. AI generation should use GEOFlow's existing AI model management and Laravel services. Long-running checks should use Laravel queue workers and database-backed task records.

## Phase 1: Projects, Brands, Keywords, Question Variants

Use existing keyword libraries as the project/brand container to avoid building a parallel keyword system. Add brand metadata to `keyword_libraries`:

- `company_name`
- `domain_keyword`
- `industry`
- `brand_description`
- `status`

Add `keyword_question_variants` linked to `keywords`. These variants are the prompts used later for AI search engine inclusion checks.

Admin users can:

- maintain brand/project metadata on a keyword library
- add/import keywords as they do now
- generate GEO-style related keywords from a seed keyword
- generate question variants for one keyword or all keywords

## Phase 2: Inclusion Checks

Add detection runs and result records for AI search platforms:

- Doubao
- Qianwen
- DeepSeek

Run checks through queue jobs. Start with a stable abstraction:

- `AiSearchPlatformChecker` interface
- `PlaywrightAiSearchChecker` implementation
- per-platform config for URL, selectors, login profile/storage state, timeout

Each result stores question, answer, keyword hit, brand hit, raw status, error message, and checked time.

## Phase 3: GEO Article Generation

Use the Phase 1 project/brand context and Phase 2 check gaps to enrich the existing article generation flow:

- title/keyword from existing task material
- brand facts from keyword library metadata
- question variants as audience intent
- inclusion gaps as topics to reinforce

Generated articles should continue to use existing `articles`, task runs, knowledge base, image generation, and review/publish workflow.

## Phase 4: GEO Reports

Add admin report views and query services:

- overview cards: projects, active keywords, checks, hit rate
- trend charts: keyword hit and brand hit over time
- platform distribution: Doubao/Qianwen/DeepSeek performance
- keyword ranking: top/bottom keywords by hit rate and recent trend

Reports read from inclusion result tables and existing article/task tables.

## Risks

- AI platform web UI selectors are fragile.
- Browser-based checks may require login state, cookies, or manual account maintenance.
- Platforms may throttle automated traffic.
- n8n workflows in the old repo are reference material only; importing n8n would add operational complexity.

## Implementation Order

1. Implement Phase 1 as a complete vertical slice.
2. Add Phase 2 tables and queue-backed checker abstraction.
3. Feed Phase 1 and Phase 2 context into article generation.
4. Build reports after enough result records exist.
