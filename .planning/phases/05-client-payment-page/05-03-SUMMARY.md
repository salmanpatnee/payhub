---
phase: 05-client-payment-page
plan: "03"
subsystem: ui
tags: [vue3, stripe-elements, brand-theming, payment-form, inertia, tailwind]

dependency_graph:
  requires:
    - phase: 05-02
      provides: PaymentLayout.vue with brand CSS variable injection
    - phase: 05-01
      provides: ClientPaymentController with clientSecret prop, Vue page stubs
  provides:
    - resources/js/pages/ClientPayment/Pay.vue — fully functional Stripe Elements payment form
  affects:
    - 05-04 (Success/Failed/Unavailable pages — same PaymentLayout pattern)
    - Phase 6 webhooks — confirmPayment() flow confirmed to NOT write DB; webhooks handle status

tech-stack:
  added: []
  patterns:
    - "loadStripe() called in onMounted before StripeElements mounts — prevents window.Stripe undefined error (Pitfall 1 from RESEARCH.md)"
    - "StripeElements gated by v-if=stripeLoaded — loading skeleton shown until Stripe.js CDN resolves"
    - "elementsOptions is a computed — reads --brand-primary via getComputedStyle after mount when CSS vars are available"
    - "confirmPayment() return_url points to /pay/{uuid}/success — client_secret never in URL (SEC-04)"
    - "processing stays true on Stripe redirect — only set false on error (browser is leaving page on success)"
    - "bg-[--color-brand-primary] Tailwind arbitrary value accesses --color-brand-primary forwarded by brand-theme.css [data-brand] rule"

key-files:
  created: []
  modified:
    - resources/js/pages/ClientPayment/Pay.vue

key-decisions:
  - "loadStripe() called in onMounted not setup(): prevents SSR and premature window.Stripe access — stripeLoaded ref gates the StripeElements render"
  - "elementsOptions as computed (not ref): guarantees CSS variables are resolved after PaymentLayout mounts; getComputedStyle reads live computed values"
  - "processing stays true after confirmPayment on success: Stripe redirects browser — there is no success branch to handle in Vue"
  - "error.message used verbatim from Stripe for card declines — do not rewrite UI copy for payment errors"

metrics:
  duration: "~2min"
  completed: "2026-05-09"
  tasks: 1
  files_created: 0
  files_modified: 1
---

# Phase 5 Plan 03: Pay.vue — Stripe Elements Payment Form Summary

**Inline Stripe Elements payment form with brand-matched appearance, loadStripe() gate, confirmPayment() with SEC-04-compliant return_url, and all four button/form states (loading/idle/processing/error)**

## Performance

- **Duration:** ~2 min
- **Started:** 2026-05-09T18:07:53Z
- **Completed:** 2026-05-09T18:10:00Z
- **Tasks:** 1
- **Files created:** 0, **Files modified:** 1

## Accomplishments

- `Pay.vue` implemented as the main client-facing payment form — 189 lines, fully functional
- `loadStripe()` called in `onMounted` with the brand's Stripe account publishable key; `StripeElements` rendered only after `stripeLoaded.value = true` — prevents `window.Stripe undefined` error
- `elementsOptions` computed property reads `--brand-primary` via `getComputedStyle(document.documentElement)` after mount, with fallback to `props.brand.primary_color` then `#000000`
- Stripe Elements appearance object configured with brand-matched `colorPrimary`, shadcn-vue design token values for `colorBackground`/`colorText`/`colorDanger`, and custom `.Input`/`.Input:focus`/`.Label`/`.Error` rules
- `submit()` handler calls `instance.confirmPayment()` with `return_url: ${window.location.origin}/pay/${props.payment.uuid}/success` — client_secret never in URL (SEC-04 enforced)
- Error branch: `errorMessage` set from `error.message ?? 'An unexpected error occurred. Please try again.'`, `processing` reset to false, button re-enabled
- Success branch: `processing` stays true — Stripe redirects browser; no DB write, no Inertia POST (CLAUDE.md rule enforced)
- Payment summary block uses `bg-[--color-brand-secondary]/10` tint on summary card, submit button uses `bg-[--color-brand-primary]` arbitrary Tailwind value
- Loading skeleton: `h-32 flex items-center justify-center` with `Spinner class="size-5 text-muted-foreground"` shown before `stripeLoaded`
- All UI-SPEC.md copy strings exact: "Complete your payment", "Review your order and enter payment details below.", "Pay $X.XX" (idle), "Processing..." (processing)
- TypeScript clean (only pre-existing unrelated error in `useTwoFactorAuth.ts`)

## Task Commits

1. **Task 1: Create Pay.vue with Stripe Elements, all states, and brand theming** — `07251209` (feat)

## Files Created/Modified

- `resources/js/pages/ClientPayment/Pay.vue` — Replaced stub (7 lines) with full implementation (189 lines):
  - Script section: `loadStripe` + `StripeElements`/`StripeElement` imports, `PaymentLayout` import, shadcn-vue component imports, `stripeLoaded`/`processing`/`errorMessage` refs, `onMounted` async loadStripe gate, `formatAmount` utility, `elementsOptions` computed with full appearance object, `submit()` async handler with `confirmPayment()`
  - Template section: `PaymentLayout` wrapper with brand prop, `Head` tag, `Card` with `CardHeader`/`CardContent`/`CardFooter`, payment summary block with amount/currency/service/package, `Separator`, conditional `StripeElements` render with `StripeElement type="payment"`, error `Alert`, submit `Button` with `Spinner`

## Decisions Made

- **`loadStripe()` in `onMounted`**: Not in `setup()` or at module level — prevents SSR issues and ensures `window` is available. The `stripeLoaded` gate prevents `StripeElements` mounting before `window.Stripe` is set.
- **`elementsOptions` as `computed`**: A `ref` initialized in `setup()` would read CSS vars before the `PaymentLayout` root element has mounted (and set `--brand-primary`). Using `computed` defers the `getComputedStyle` read until the template accesses the value — which happens after mount.
- **`processing` not reset on Stripe redirect**: When `confirmPayment()` succeeds, Stripe immediately redirects the browser. There is no callback or return value for the success case — Vue cannot act on it. The spinner stays visible during the redirect transition, which is correct UX.

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None — this plan delivers the full Pay.vue implementation. All states functional, all copy exact, all brand theming wired.

## Threat Surface Scan

No new network endpoints or auth paths introduced. Pay.vue is a client-side Vue component that:
- Calls `loadStripe()` (loads Stripe.js CDN — already in T-05-12, mitigated)
- Calls `instance.confirmPayment()` (Stripe iframe submits directly to Stripe — T-05-09 mitigated via SEC-04 return_url)
- Does NOT call any Laravel backend on submit (CLAUDE.md rule — all DB writes via Phase 6 webhooks)

All threats are within the plan's threat model. No new surface added.

## Self-Check: PASSED

- [x] `resources/js/pages/ClientPayment/Pay.vue` exists (189 lines, >= 120 minimum)
- [x] File contains `<script setup lang="ts">`
- [x] File contains `import { loadStripe } from '@stripe/stripe-js'`
- [x] File contains `import { StripeElements, StripeElement } from 'vue-stripe-js'`
- [x] File contains `import PaymentLayout from '@/layouts/PaymentLayout.vue'`
- [x] File contains `await loadStripe(props.stripeAccount.publishable_key)` inside `onMounted`
- [x] File contains `stripeLoaded.value = true` after the await
- [x] File contains `v-if="stripeLoaded"` gating StripeElements render
- [x] File contains `template #default="{ instance, elements }"` in StripeElements slot
- [x] File contains `instance.confirmPayment(` in submit handler
- [x] File contains `return_url: \`${window.location.origin}/pay/${props.payment.uuid}/success\``
- [x] File does NOT contain `confirmCardPayment`
- [x] File contains "Complete your payment" (card title)
- [x] File contains "Review your order and enter payment details below." (card description)
- [x] File contains `bg-[--color-brand-primary]` on submit button
- [x] File contains `bg-[--color-brand-secondary]/10` on payment summary block
- [x] File contains `getComputedStyle(document.documentElement)` for colorPrimary
- [x] File contains `class="h-32 flex items-center justify-center"` loading skeleton
- [x] File contains 'Processing...' button text when processing
- [x] File contains 'An unexpected error occurred. Please try again.' fallback error
- [x] TypeScript: `npx tsc --noEmit --skipLibCheck` — only pre-existing unrelated error in `useTwoFactorAuth.ts`
- [x] Commit `07251209` exists (Task 1: Pay.vue full implementation)

---
*Phase: 05-client-payment-page*
*Completed: 2026-05-09*
