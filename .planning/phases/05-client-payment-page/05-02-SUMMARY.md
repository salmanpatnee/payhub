---
phase: 05-client-payment-page
plan: "02"
subsystem: ui
tags: [inertia, vue3, tailwind, layout, brand-theming, css-variables, lucide]

dependency_graph:
  requires:
    - phase: 05-01
      provides: ClientPaymentController with show/success/failed, Vue stubs for ClientPayment/* pages
    - phase: 05-00
      provides: ClientPaymentTest.php with 12 RED Pest tests
  provides:
    - resources/js/app.ts with ClientPayment/ resolver case returning null
    - resources/js/layouts/PaymentLayout.vue — standalone public layout with brand CSS var injection, logo/fallback, slot, footer
  affects:
    - 05-03 (Success/Failed page full implementation — uses PaymentLayout)
    - 05-04 (Unavailable page full implementation — uses PaymentLayout)

tech-stack:
  added: []
  patterns:
    - "Inertia layout resolver returns null for ClientPayment/ prefix — each page imports PaymentLayout directly so brand props flow via normal Vue prop binding"
    - "CSS variable injection pattern: :data-brand + :style binding on root element; brand-theme.css [data-brand] rule forwards --brand-primary to --color-brand-primary for Tailwind arbitrary value access"
    - "PaymentLayout.vue is fully standalone — no inheritance from AppLayout/AuthLayout, no admin nav, no dark mode toggle"

key-files:
  created:
    - resources/js/layouts/PaymentLayout.vue
  modified:
    - resources/js/app.ts

key-decisions:
  - "return null in layout resolver for ClientPayment/ prefix: resolver receives only component name string, not per-request props. Brand colors must vary per payment — returning null and importing PaymentLayout directly in each page allows brand props to flow via Vue prop binding. Same pattern as Welcome page."

patterns-established:
  - "PaymentLayout brand prop shape: { name, slug, logo_url, primary_color, secondary_color } — matches brandProps() output from ClientPaymentController"
  - "logo_url null-safe: v-if/v-else renders img when logo_url present, falls back to span with brand name text"
  - "max-w-[180px] constraint on logo img prevents oversized brand logos breaking mobile layout"

requirements-completed:
  - CLIENT-02
  - CLIENT-08

duration: 2min
completed: "2026-05-09"
---

# Phase 5 Plan 02: PaymentLayout + Inertia Resolver Summary

**Inertia layout resolver wired to return null for ClientPayment/ pages + standalone PaymentLayout.vue with brand CSS variable injection, logo fallback, max-w-md slot, and Secured by Stripe footer**

## Performance

- **Duration:** ~2 min
- **Started:** 2026-05-09T18:02:00Z
- **Completed:** 2026-05-09T18:04:16Z
- **Tasks:** 2
- **Files created:** 1, **Files modified:** 1

## Accomplishments

- `app.ts` layout resolver now returns `null` for all `ClientPayment/` pages, preventing the admin AppLayout (sidebar, auth nav) from wrapping public payment pages
- `PaymentLayout.vue` created as a fully standalone public layout: brand CSS variables injected via `:style` binding on `data-brand` root element, logo with `max-w-[180px]` constraint and brand name text fallback, `max-w-md` card slot, and "Secured by Stripe" footer with `LockIcon`
- CSS variable chain confirmed working: `--brand-primary` set via `:style` → forwarded to `--color-brand-primary` by `brand-theme.css [data-brand]` rule → accessible via Tailwind `bg-[--color-brand-primary]` in child pages

## Task Commits

1. **Task 1: Add ClientPayment/ case to Inertia layout resolver** — `4ad53f18` (feat)
2. **Task 2: Create PaymentLayout.vue** — `0c0dbcd6` (feat)

## Files Created/Modified

- `resources/js/app.ts` — Added `case name.startsWith('ClientPayment/'): return null` before the default case in the layout resolver switch block
- `resources/js/layouts/PaymentLayout.vue` — Standalone public layout: brand CSS vars, logo with fallback, max-w-md slot, Secured by Stripe footer (43 lines)

## Decisions Made

- **`return null` in resolver for ClientPayment/ prefix**: The layout resolver receives only the component name string, not Inertia page props. Brand colors/logo must vary per payment request. Returning `null` and importing `PaymentLayout` directly in each `ClientPayment/*` page allows brand props to flow via normal Vue prop binding. Established precedent: `name === 'Welcome'` already uses this pattern.

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

- Pre-existing TypeScript error in `resources/js/composables/useTwoFactorAuth.ts` (Cannot find module '@/routes/two-factor'). This is out-of-scope for this plan and was present before execution. Not touched.

## Known Stubs

None — this plan delivers final implementation for both deliverables.

## Threat Surface Scan

No new network endpoints or auth paths introduced. The layout resolver change is client-side only (Inertia frontend routing). `PaymentLayout.vue` does not make network calls. The CSS variable injection from `brand.primary_color` is low-risk (T-05-07 in plan threat model — brand color set by Admin via authenticated form, not client-supplied).

## Next Phase Readiness

- `PaymentLayout.vue` is ready for import in all four `ClientPayment/*` pages (Wave 2: 05-03, 05-04)
- Brand prop shape `{ name, slug, logo_url, primary_color, secondary_color }` matches `brandProps()` output from `ClientPaymentController` (Phase 05-01)
- Layout resolver fix is the most consequential wiring task — without it, browser would render admin sidebar on all public payment URLs

## Self-Check: PASSED

- [x] `resources/js/app.ts` contains `case name.startsWith('ClientPayment/'):`
- [x] `resources/js/app.ts` does NOT contain `import PaymentLayout`
- [x] `resources/js/layouts/PaymentLayout.vue` exists (43 lines, >= 40 minimum)
- [x] `PaymentLayout.vue` contains `:data-brand="brand.slug"`
- [x] `PaymentLayout.vue` contains `--brand-primary` and `--brand-secondary` in `:style` binding
- [x] `PaymentLayout.vue` contains `class="min-h-svh flex flex-col items-center justify-center bg-muted/40 px-4 py-10"` on root div
- [x] `PaymentLayout.vue` contains `class="h-10 max-w-[180px] w-auto object-contain"` on img
- [x] `PaymentLayout.vue` contains `v-else class="font-semibold text-lg"` fallback
- [x] `PaymentLayout.vue` contains `class="w-full max-w-md"` on slot wrapper
- [x] `PaymentLayout.vue` contains `Secured by Stripe` footer
- [x] `PaymentLayout.vue` contains `<slot />`
- [x] `PaymentLayout.vue` does NOT reference AppLayout, AuthLayout, or sidebar
- [x] Commit `4ad53f18` exists (Task 1: app.ts resolver)
- [x] Commit `0c0dbcd6` exists (Task 2: PaymentLayout.vue)
- [x] TypeScript: only pre-existing unrelated error in useTwoFactorAuth.ts

---
*Phase: 05-client-payment-page*
*Completed: 2026-05-09*
