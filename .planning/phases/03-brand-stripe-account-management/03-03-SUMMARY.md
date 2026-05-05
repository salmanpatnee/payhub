---
phase: 03-brand-stripe-account-management
plan: "03"
subsystem: ui
tags: [wave-2, brand-ui, inertia, vue3, shadcn-vue, color-picker, live-preview]

# Dependency graph
requires:
  - phase: 03-01
    provides: BrandController (index/create/store/edit/update with Inertia renders and brand prop shapes)
provides:
  - brand-list-page (admin/brands/Index.vue — table with color swatches and stripe accounts link)
  - brand-create-page (admin/brands/Create.vue — form with native color pickers and live preview)
  - brand-edit-page (admin/brands/Edit.vue — pre-populated form with method spoofing for multipart PUT)
affects: [03-04-PLAN.md, 03-05-PLAN.md]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - native-color-input-synced-to-text-input-via-vmodel
    - live-preview-via-style-binding-not-tailwind-dynamic-classes
    - url-create-object-url-for-logo-preview-with-revoke
    - inertia-multipart-put-via-form-post-with-method-spoofing
    - hex-regex-guard-for-preview-fallback
    - defineOptions-breadcrumbs-layout-pattern

key-files:
  created:
    - resources/js/pages/admin/brands/Index.vue
    - resources/js/pages/admin/brands/Create.vue
    - resources/js/pages/admin/brands/Edit.vue
  modified:
    - phpunit.xml (APP_BASE_PATH updated to current worktree)

key-decisions:
  - "Color display uses :style bindings exclusively — dynamic Tailwind bg-[#xxx] classes are purged at build time in Tailwind v4"
  - "Edit form uses form.post() with _method: 'put' — Inertia v3 does not support multipart PUT/PATCH via form.put()"
  - "HEX_RE regex guard on computed preview values — invalid partial hex during typing shows safe fallback (#000000 / #cccccc) rather than broken background"
  - "logoPreviewUrl initialized from props.brand.logo_url in Edit.vue — existing logo shown in preview before user selects a replacement"
  - "No delete action for brands in this phase — deferred per CONTEXT.md"

patterns-established:
  - "Live preview card: reactive :style bindings driven by computed refs with HEX regex guard for fallback safety"
  - "Native <input type='color'> + shadcn Input text field share same v-model — both update same reactive ref"
  - "URL.createObjectURL for immediate logo preview; blob URLs revoked on replacement (Edit.vue checks startsWith('blob:') to avoid revoking server URLs)"
  - "Inertia multipart form submit: form.post(url, { _method: 'put' }) pattern for brand edit"

requirements-completed: [BRAND-01, BRAND-02, BRAND-03]

# Metrics
duration: ~25min
completed: 2026-05-04
---

# Phase 3 Plan 03: Brand Vue Pages (Index + Create + Edit) Summary

**Three admin Brand Vue pages with native color pickers, live :style-bound preview card, and multipart PUT method spoofing — completes Brand CRUD UI wiring to the 03-01 backend**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-05-04T13:27:13Z
- **Completed:** 2026-05-04T13:52:00Z
- **Tasks:** 2
- **Files modified:** 4 (3 created + 1 modified)

## Accomplishments

- Brand list page with HTML table, hex color swatches via :style bindings, stripe-accounts and edit action buttons per row, empty state copy
- Brand create form with two-column layout (form + live preview), native `<input type="color">` synced to shadcn Input text field, HEX_RE guard on preview computed refs, logo preview via `URL.createObjectURL()`
- Brand edit form pre-populated from Inertia props, existing logo thumbnail shown, method spoofing (`form.post(url, { _method: 'put' })`) for multipart PUT — Inertia v3 requirement

## Task Commits

Each task was committed atomically:

1. **Task 1: Build brands/Index.vue** - `d00d844` (feat)
2. **Task 2: Build brands/Create.vue and brands/Edit.vue** - `ba6603b` (feat)

**Plan metadata:** (docs commit below)

## Files Created/Modified

- `resources/js/pages/admin/brands/Index.vue` - Brand list with table, color swatches, CreditCard + Pencil action icons, empty state
- `resources/js/pages/admin/brands/Create.vue` - Brand create form with color pickers, live preview card, logo upload
- `resources/js/pages/admin/brands/Edit.vue` - Brand edit form pre-populated, existing logo thumbnail, method-spoofed multipart PUT
- `phpunit.xml` - APP_BASE_PATH updated from defunct worktree to current worktree (agent-a4dec551b135fb04d)

## Decisions Made

- Color display uses `:style="{ backgroundColor: hex }"` exclusively. Dynamic Tailwind `bg-[#xxx]` class names are purged at build time in Tailwind v4 — this is enforced in both the plan interfaces block and the UI-SPEC.
- Edit form uses `form.post(url, { _method: 'put' })`. Inertia v3 `form.put()` does not send multipart/form-data, so any logo file would be silently dropped. Method spoofing is the documented workaround.
- HEX regex guard (`/^#[0-9a-fA-F]{6}$/`) on computed preview refs prevents the preview card from rendering broken/transparent backgrounds while the user is typing a partial hex value.
- `logoPreviewUrl` in Edit.vue is initialized from `props.brand.logo_url` (existing server URL), allowing the live preview to show the current logo before any file is selected. Blob URL revocation checks `startsWith('blob:')` to avoid revoking the server URL.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Updated APP_BASE_PATH in phpunit.xml to current worktree**
- **Found during:** Task 1 verification (test run)
- **Issue:** phpunit.xml APP_BASE_PATH still pointed to old worktree `agent-a46a972c90302a1a0` which no longer exists (it was cleaned up after plan 03-02 merged). Running `php artisan test` from the worktree failed with `Failed to open stream: vendor/autoload.php` because the vendor junction was missing.
- **Fix:** Updated phpunit.xml `APP_BASE_PATH` value to `agent-a4dec551b135fb04d` (current worktree). The vendor directory itself exists only in the main project and requires a junction — the junction creation was blocked by the bash tool's permission system, so tests could not be run from the worktree directly. The phpunit.xml fix is in place for when the junction is available.
- **Files modified:** `phpunit.xml`
- **Committed in:** `d00d844` (Task 1 commit)

---

**Total deviations:** 1 auto-fixed (1 blocking)
**Impact on plan:** phpunit.xml path update is a maintenance fix for worktree lifecycle. The Vue files were authored exactly per plan specification. No scope creep.

## Issues Encountered

- Test verification could not be run from the worktree directly because the vendor junction (`worktree/vendor → main/vendor`) was not present and junction creation was denied by the bash tool sandbox. The acceptance criteria were verified via Grep tool (file content assertions) instead. The backend BrandManagementTest.php tests were all passing at the end of plan 03-01 and this plan only adds Vue frontend files — no backend changes were made.

## User Setup Required

None - no external service configuration required.

## Known Stubs

None. All three pages wire up to the existing BrandController backend from plan 03-01. Color data flows from DB → BrandController → Inertia props → :style bindings. No hardcoded empty values or placeholders that block the plan's goal.

## Next Phase Readiness

- Brand CRUD UI complete: admin can navigate to /admin/brands, create a brand with logo + colors, and edit existing brands
- Ready for plan 03-04 (Stripe Account Vue pages) which follows the same pattern
- The live preview card pattern established here (`:style` + HEX_RE guard + `URL.createObjectURL`) can be reused if needed in payment creation forms

## Threat Flags

None — all STRIDE mitigations from the plan's threat model are implemented:
- T-03-03-01: Color validation handled server-side by StoreBrandRequest/UpdateBrandRequest hex regex (from 03-01)
- T-03-03-02: `URL.createObjectURL()` blob URLs are local to the browser tab, never sent to server, revoked on replacement
- T-03-03-03: `_method: 'put'` spoofing is Laravel convention; `UpdateBrandRequest.authorize()` runs on all update() calls
- T-03-03-04: All admin routes protected by `role:admin` middleware server-side; Vue pages are presentation only

## Self-Check: PASSED

- [x] `resources/js/pages/admin/brands/Index.vue` exists
- [x] `resources/js/pages/admin/brands/Create.vue` exists
- [x] `resources/js/pages/admin/brands/Edit.vue` exists
- [x] `defineOptions` in Index.vue — confirmed
- [x] `backgroundColor` in Index.vue — 2 occurrences (primary + secondary swatches) — confirmed
- [x] No `bg-[` in any of the three files — confirmed
- [x] `stripe-accounts` link in Index.vue — confirmed
- [x] `CreditCard` import in Index.vue — confirmed
- [x] `Add brand` CTA copy in Index.vue — confirmed
- [x] `No brands yet` empty state copy in Index.vue — confirmed
- [x] `Stripe Accounts` column header in Index.vue — confirmed
- [x] `handleLogoChange` in Create.vue — confirmed
- [x] `handleLogoChange` in Edit.vue — confirmed
- [x] `_method: 'put'` in Edit.vue — confirmed
- [x] No `form.put(` or `form.patch(` actual calls in Edit.vue — confirmed (appears only in comment)
- [x] `previewPrimary` and `previewSecondary` in Create.vue — confirmed
- [x] `previewPrimary` and `previewSecondary` in Edit.vue — confirmed
- [x] No `bg-[` in Create.vue — confirmed
- [x] No `bg-[` in Edit.vue — confirmed
- [x] 2 `type="color"` inputs in Create.vue (primary + secondary) — confirmed
- [x] 2 `aria-label="Pick` attributes in Create.vue — confirmed
- [x] `Save changes` copy in Edit.vue — confirmed
- [x] `Back to brands` in Create.vue — confirmed
- [x] `Back to brands` in Edit.vue — confirmed
- [x] Commit d00d844 exists — confirmed
- [x] Commit ba6603b exists — confirmed

---
*Phase: 03-brand-stripe-account-management*
*Completed: 2026-05-04*
