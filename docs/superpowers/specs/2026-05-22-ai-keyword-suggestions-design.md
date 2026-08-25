# AI Keyword Suggestions Design

## Goal

Add an AI-assisted keyword expansion workflow to the keyword library detail page. An admin can enter a seed keyword, generate GEO-oriented related keywords, review the suggestions, select the useful ones, and then add the selected keywords to the current keyword library.

## Scope

This feature is limited to keyword library detail pages. It does not create title suggestions, tasks, articles, or multi-client segmentation.

## User Flow

1. The admin opens a keyword library detail page.
2. The admin opens the add keyword modal and chooses AI generation.
3. The admin enters a seed keyword and a requested count.
4. The system calls the configured active chat AI model and returns suggested keywords.
5. The modal shows the suggestions as a selectable list, default selected.
6. The admin unselects unwanted suggestions.
7. The admin confirms, and the selected keywords are added to the current library.
8. Existing keywords in the same library are skipped and reported in the success message.

## Keyword Strategy

The AI prompt should ask for keywords suitable for GEO content discovery and generative search coverage. Suggestions should include:

- Core topic keywords
- Long-tail question keywords
- Comparison or review keywords
- Scenario and use-case keywords
- Brand, industry, and audience combinations
- User intent keywords

The AI should return a simple JSON array of strings when possible. The backend should tolerate plain text or line-separated output by parsing it defensively.

## Backend Design

Add a service such as `GeoKeywordSuggestionService` with these responsibilities:

- Resolve an active chat AI model.
- Build the GEO keyword expansion prompt.
- Call the existing OpenAI-compatible runtime path.
- Parse JSON or plain text AI output into keyword strings.
- Normalize whitespace, trim punctuation, remove empty values, cap keyword length, and de-duplicate suggestions.
- Return a stable list of suggestions.

Add two admin routes:

- `POST /keyword-libraries/{libraryId}/keywords/suggest`
  - Input: `seed_keyword`, `count`
  - Output: JSON with `suggestions`.

- `POST /keyword-libraries/{libraryId}/keywords/bulk-store`
  - Input: `keywords[]`
  - Output: redirect with message showing inserted and skipped counts.

The bulk-store path must preserve current duplicate protection by checking the current library before insert.

## UI Design

Extend the existing add keyword modal using the current Tailwind/admin style. The modal should include:

- A manual single-keyword add section, kept as-is.
- An AI generation section with seed keyword input, count select/input, and generate button.
- Loading and error states for generation.
- A selectable suggestion list with checkboxes.
- A confirm button that submits selected suggestions to the bulk-store route.

Use existing Lucide icons and button patterns. Avoid adding a new page or unrelated navigation.

## Error Handling

- If no active chat AI model exists, show a clear message telling the admin to configure an AI model first.
- If the AI request fails, show the returned error and keep the modal open.
- If the AI output cannot be parsed into any keywords, show a retry-friendly message.
- If the selected list is empty, block submit and show a local validation message.
- Duplicate keywords are skipped during bulk-store and included in the result message.

## Tests

Add focused tests for:

- Suggestion parsing and normalization from JSON output.
- Suggestion parsing from line-separated text output.
- Duplicate suggestions are removed.
- Bulk keyword insert adds new values and skips existing values.
- Suggest endpoint returns a validation error when no model is available.

## Out Of Scope

- Multi-customer or multi-brand data isolation.
- Automatic article generation from suggestions.
- Keyword ranking, search volume, SERP data, or external SEO APIs.
- Background jobs for keyword suggestion generation.
