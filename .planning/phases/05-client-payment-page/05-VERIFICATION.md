---
phase: 05-client-payment-page
verified: 2026-05-09T00:00:00Z
status: gaps_found
score: 7/9 must-haves verified
overrides_applied: 0
gaps:
  - truth: "Pending payments get a PaymentIntent created via new StripeClient (never global setApiKey) — with idempotency: no duplicate PI on refresh"
    status: failed
    reason: "show() calls paymentIntents->create() unconditionally on every page load with no check of stripe_payment_intent_id. On refresh, a second PaymentIntent is created and the first is orphaned. This is CR-01 from the code review."
    artifacts:
      - path: "app/Http/Controllers/ClientPaymentController.php"
        issue: "Lines 29-37: create() called unconditionally. No if ($payment->stripe_payment_intent_id) retrieve-and-reuse branch exists."
    missing:
      - "Before calling paymentIntents->create(), check if stripe_payment_intent_id is already set on the Payment record"
      - "If it exists, call paymentIntents->retrieve() and verify the PI is still in a confirmable state (requires_payment_method, requires_confirmation, requires_action)"
      - "Only create a new PI when none exists or the existing one is in a terminal state"

  - truth: "success() controller renders ClientPayment/Success only for payments that are not in a known failure state"
    status: failed
    reason: "success() trusts the client-controlled redirect_status query parameter as the sole gate. It renders the Success page for any payment (including failed or cancelled ones) as long as the URL contains redirect_status=succeeded. CR-02 from the code review: a user can craft the URL manually and receive a misleading 'Payment received' page for a payment where no money changed hands."
    artifacts:
      - path: "app/Http/Controllers/ClientPaymentController.php"
        issue: "Lines 48-65: success() only checks request('redirect_status') !== 'succeeded'. No guard against $payment->status being 'failed' or 'cancelled'."
    missing:
      - "After the redirect_status check, add a guard: if (in_array($payment->status, ['failed', 'cancelled'])) render ClientPayment/Unavailable"
      - "This ensures that even if redirect_status=succeeded is crafted manually, a known-failed or cancelled payment does not display a success confirmation"
---

# Phase 5: Client Payment Page — Verification Report

**Phase Goal:** Clients can pay via a branded, mobile-optimised Stripe Elements form at /pay/{uuid} — inline card capture, 3DS handling, success/failure routing. No admin login required.
**Verified:** 2026-05-09
**Status:** gaps_found
**Re-verification:** No — initial verification

---

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | GET /pay/{uuid} is publicly accessible without auth | VERIFIED | Routes at lines 53-55 of routes/web.php are outside all middleware groups. All 12 tests pass (including "guest can access pay route without authentication"). |
| 2 | Pending payments get a PaymentIntent created via per-account StripeClient; NO global setApiKey; stripe_payment_intent_id stored | PARTIAL | app()->make(StripeClient::class, ['apiKey'=>...]) confirmed (line 29). stripe_payment_intent_id updated (line 37). setApiKey: zero occurrences. BUT: create() is unconditional — no idempotency check. Refresh creates a new PI every time (CR-01). |
| 3 | Non-pending payments render ClientPayment/Unavailable and do not call StripeClient | VERIFIED | Guard at line 19: if ($payment->status !== 'pending') returns Unavailable before StripeClient is created. Test "non-pending payment does not call StripeClient" passes GREEN. |
| 4 | clientSecret is passed only as Inertia prop — never in a URL or log | VERIFIED | clientSecret in Inertia props (line 44). No Log:: calls. return_url in Pay.vue (line 105) is /pay/{uuid}/success with no client_secret appended. Test "success controller does not re-expose client_secret" passes GREEN. |
| 5 | Stripe Elements form renders inline with brand appearance; loadStripe() gate in onMounted | VERIFIED | Pay.vue: loadStripe() in onMounted (line 44), stripeLoaded gate on v-if (line 148), StripeElements + StripeElement type="payment" present, brand CSS vars wired through PaymentLayout and elementsOptions computed. |
| 6 | 3DS handling is automatic via automatic_payment_methods + confirmPayment() API | VERIFIED | ClientPaymentController show(): automatic_payment_methods: ['enabled' => true] (line 33). Pay.vue: instance.confirmPayment() used (not confirmCardPayment). Stripe handles 3DS natively. |
| 7 | success() renders success page only after valid redirect_status; redirects to failed otherwise | PARTIAL | Redirect to failed when redirect_status != succeeded: VERIFIED (line 52, test passes). BUT: no server-side status guard — for a payment with status='failed' or 'cancelled', the URL /pay/{uuid}/success?redirect_status=succeeded still renders ClientPayment/Success (CR-02). |
| 8 | ClientPayment/* pages use PaymentLayout with brand props; no admin AppLayout | VERIFIED | app.ts resolver returns null for ClientPayment/ (line 20-21). All four pages import PaymentLayout directly and pass :brand="props.brand". PaymentLayout has no AppLayout/AuthLayout reference. |
| 9 | Payment page renders correctly on mobile screens | HUMAN NEEDED | PaymentLayout: min-h-svh, max-w-md, px-4 present. Pay.vue: max-w-md card, button size=lg. Cannot verify visual/touch behavior programmatically. |

**Score:** 7/9 truths verified (2 gaps, 1 human-needed)

---

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `tests/Feature/ClientPaymentTest.php` | 12 Pest tests, Mockery StripeClient | VERIFIED | 142 lines. 12 it() blocks. mockStripeClient() defined. PaymentIntent::constructFrom() mock pattern. All 12 tests pass GREEN. |
| `app/Http/Controllers/ClientPaymentController.php` | show(), success(), failed(), brandProps(), paymentProps() | VERIFIED (with gaps) | 104 lines. All five methods present. Per-account StripeClient. D-07 enforced (no client_name/email/note in paymentProps). Two critical logic gaps: CR-01 (no idempotency) and CR-02 (no status guard in success()). |
| `routes/web.php` | Three public /pay/{payment} routes outside auth | VERIFIED | Lines 53-55: pay.show, pay.success, pay.failed all outside middleware groups. Import of ClientPaymentController at line 6. |
| `resources/js/app.ts` | ClientPayment/ case returning null | VERIFIED | Line 20-21: case name.startsWith('ClientPayment/'): return null. All existing cases unchanged. |
| `resources/js/layouts/PaymentLayout.vue` | Standalone layout, brand CSS vars, logo, slot, footer | VERIFIED | 43 lines (>= 40). :data-brand, :style binding, min-h-svh, max-w-[180px] logo, max-w-md slot, "Secured by Stripe" footer with LockIcon, slot present. No admin nav. |
| `resources/js/pages/ClientPayment/Pay.vue` | Stripe Elements form, all states | VERIFIED | 189 lines (>= 120). loadStripe in onMounted, stripeLoaded gate, StripeElements + StripeElement type="payment", confirmPayment() with correct return_url, error/processing states, brand colors, PaymentLayout wrapper. |
| `resources/js/pages/ClientPayment/Success.vue` | Terminal success page | VERIFIED | 61 lines (>= 45). CheckCircle2, "Payment received" heading/description, text-xl font-mono amount, optional service line, no actions. |
| `resources/js/pages/ClientPayment/Failed.vue` | Failure page with retry button | VERIFIED | 54 lines (>= 40). XCircle, "Payment unsuccessful", Button as-child with /pay/{uuid} link. |
| `resources/js/pages/ClientPayment/Unavailable.vue` | Status-aware guard page | VERIFIED | 84 lines (>= 55). status-to-content computed map, v-if/v-else-if/v-else icon switching for all 3 statuses, empty CardContent, no actions. |

---

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| routes/web.php | ClientPaymentController::show() | Route::get('/pay/{payment}', ...) | WIRED | Confirmed at line 53. Route model binding resolves Payment by uuid via getRouteKeyName(). |
| routes/web.php | ClientPaymentController::success() | Route::get('/pay/{payment}/success', ...) | WIRED | Confirmed at line 54. |
| routes/web.php | ClientPaymentController::failed() | Route::get('/pay/{payment}/failed', ...) | WIRED | Confirmed at line 55. |
| ClientPaymentController::show() | stripe->paymentIntents->create() | app()->make(StripeClient::class, ['apiKey' => ...]) | WIRED | Line 29-30. Per-account key. No setApiKey. |
| ClientPaymentController::show() | Inertia::render('ClientPayment/Pay') | clientSecret in props | WIRED | Line 44. clientSecret prop is $pi->client_secret. |
| Pay.vue | PaymentLayout | <PaymentLayout :brand="props.brand"> | WIRED | Line 119. Brand prop flows correctly. |
| Pay.vue submit() | instance.confirmPayment() | return_url: /pay/{uuid}/success | WIRED | Line 102-107. SEC-04 compliant — no client_secret in URL. |
| resources/js/app.ts | ClientPayment/* pages | case name.startsWith('ClientPayment/'): return null | WIRED | Line 20-21. Pages import PaymentLayout directly. |

---

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| Pay.vue | payment, brand, stripeAccount, clientSecret | ClientPaymentController::show() Inertia props | Yes — from DB via Payment + Brand + StripeAccount models; clientSecret from live Stripe API call | FLOWING |
| Success.vue | payment, brand | ClientPaymentController::success() Inertia props | Yes — from DB via Payment model | FLOWING |
| Failed.vue | payment, brand | ClientPaymentController::failed() Inertia props | Yes — from DB via Payment model | FLOWING |
| Unavailable.vue | status, brand | ClientPaymentController::show() Inertia props | Yes — $payment->status from DB | FLOWING |

---

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| All 12 ClientPayment feature tests pass | php artisan test --filter ClientPayment | 12 tests, 12 passed, 73 assertions, 22.5s | PASS |
| No setApiKey calls in controller | grep setApiKey ClientPaymentController.php | 0 matches | PASS |
| No Log:: calls in controller | grep Log:: ClientPaymentController.php | 0 matches | PASS |
| client_name/email/note excluded from paymentProps | grep client_name/client_email ClientPaymentController.php | Only in comment (intentional exclusion note) | PASS |
| return_url has no client_secret | grep return_url Pay.vue | /pay/${props.payment.uuid}/success only | PASS |
| Three routes outside auth middleware | Inspected routes/web.php | Lines 53-55 outside all middleware groups | PASS |

---

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| CLIENT-01 | 05-00, 05-01 | Client opens /pay/{uuid} without login | SATISFIED | Route outside auth middleware. 2 tests pass (guest access, 404 for unknown). |
| CLIENT-02 | 05-00, 05-01, 05-02 | Payment page displays correct brand logo and colors | SATISFIED | brandProps() returns name/slug/logo_url/primary_color/secondary_color. PaymentLayout applies CSS vars. Test confirms brand.* props present. |
| CLIENT-03 | 05-00, 05-01, 05-03 | Stripe Elements embedded inline (no redirect to stripe.com) | SATISFIED | Pay.vue uses StripeElements + StripeElement type="payment" inline. confirmPayment() with return_url to local /success. No external redirect. |
| CLIENT-04 | 05-00, 05-01, 05-03 | Stripe Elements initialized with brand's publishable key | SATISFIED | stripeAccount.publishable_key passed as prop. loadStripe(props.stripeAccount.publishable_key) in onMounted. :stripe-key="stripeAccount.publishable_key" on StripeElements. Test asserts publishable_key present, secret_key absent. |
| CLIENT-05 | 05-01, 05-03 | System handles 3DS / SCA challenges | SATISFIED | automatic_payment_methods: ['enabled' => true] in PaymentIntent create. confirmPayment() API handles 3DS natively — no custom redirect flow needed. |
| CLIENT-06 | 05-00, 05-01, 05-04 | Client sees success page after payment confirmed | PARTIAL | Success.vue exists and renders correctly. Controller redirects to failed when redirect_status != succeeded. Gap: success() renders Success page even for failed/cancelled payments if URL is crafted (CR-02). |
| CLIENT-07 | 05-00, 05-01, 05-04 | Client sees error/failure page if payment fails | SATISFIED | Failed.vue with XCircle icon, "Payment unsuccessful", "Try again" button to /pay/{uuid}. Test passes. |
| CLIENT-08 | 05-02, 05-03, 05-04 | Payment page is mobile-responsive | NEEDS HUMAN | CSS classes present: min-h-svh, max-w-md, px-4, button size=lg. Cannot verify visual rendering on actual mobile screens programmatically. |
| SEC-04 | 05-00, 05-01, 05-03 | PaymentIntent client_secret never in URLs, logs, or beyond page load | SATISFIED | clientSecret only in Inertia props. return_url has no client_secret. success() discards payment_intent_client_secret query param. No Log:: calls. Test confirms clientSecret missing from Success page props. |

---

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| app/Http/Controllers/ClientPaymentController.php | 30 | paymentIntents->create() unconditional — no idempotency | Blocker | Refresh creates a new PaymentIntent every time. Prior PI is orphaned in Stripe. Client loses form progress on refresh. Wastes Stripe objects. |
| app/Http/Controllers/ClientPaymentController.php | 52 | success() trusts client-controlled redirect_status without validating server-side payment status | Blocker | Any user can view "Payment received" for a failed/cancelled/pending payment by crafting the URL. No money changes hands but the page falsely confirms payment. |
| resources/js/pages/ClientPayment/Pay.vue | 44 | loadStripe() return value discarded — stripeLoaded set true even if Stripe.js failed to load | Warning | If Stripe.js CDN fails (network error, ad-blocker), StripeElements mounts with null Stripe instance; submit() will throw unhandled runtime error and leave processing stuck at true. |
| resources/js/pages/ClientPayment/Pay.vue | 98 | submit(instance: any, elements: any) — no null guard | Warning | Typed as any, no null check. If instance or elements is null (Stripe load failure + WR-02), calling instance.confirmPayment() throws uncaught exception. |
| phpunit.xml | 21 | APP_BASE_PATH hardcoded to absolute Windows path C:\Users\Salman\Herd\payhub | Info | Will break on any other machine or in CI. Not a runtime blocker for phase verification but should be removed. |
| Pay.vue + Success.vue | multiple | formatAmount() duplicated in both files (and in Show.vue) | Info | Three copies of same function — maintenance hazard but not a runtime issue. |

---

### Human Verification Required

#### 1. Mobile Responsive Layout

**Test:** Open /pay/{uuid} on a physical mobile device (or browser DevTools at 375px width). Verify the card is full-width, the Stripe Elements form renders correctly without horizontal overflow, and the "Pay $X.XX" button is large and easy to tap.
**Expected:** Card fills screen width with 16px side padding. Elements form has single-column layout. Submit button spans full width at 52px+ height.
**Why human:** CSS class presence verified programmatically but actual responsive layout rendering requires a browser viewport.

#### 2. Stripe Elements Appearance Matches Brand Colors

**Test:** Create a payment for a brand with a distinctive primary_color (e.g., #E91E63). Open the payment page. Verify the Stripe Elements card input field has a focus border and the submit button background uses that brand color.
**Expected:** Focus ring on Stripe input matches brand primary color. Submit button background matches brand primary color (not default blue).
**Why human:** CSS variable injection and Stripe Elements appearance object can be verified in code, but the actual visual rendering in the Stripe iframe requires a browser.

#### 3. 3DS Challenge Flow

**Test:** Use a Stripe test card that triggers 3DS (e.g., 4000000000003220 for required authentication). Submit the payment form. Verify the 3DS modal/redirect appears and after successful authentication the client lands on the success page.
**Expected:** 3DS challenge modal or redirect appears. After completing challenge, browser lands on /pay/{uuid}/success?redirect_status=succeeded.
**Why human:** Requires live Stripe test environment, actual Stripe Elements, and user interaction with the 3DS challenge UI.

---

## Gaps Summary

Two critical issues identified in the code review (CR-01 and CR-02) remain unresolved in the codebase. Both require changes to `ClientPaymentController.php`.

**CR-01 — Duplicate PaymentIntents on refresh:** The `show()` controller calls `paymentIntents->create()` unconditionally on every page load. There is no check whether `stripe_payment_intent_id` is already set on the Payment record. A client who refreshes the payment page triggers a new PI creation, orphaning the previous one. The fix is a retrieve-and-reuse pattern: check `stripe_payment_intent_id` first; if set, retrieve and verify the PI is still confirmable; only create a new one when absent or in a terminal state.

**CR-02 — success() trusts client-controlled redirect_status without server-side status validation:** `success()` renders `ClientPayment/Success` for any payment where `redirect_status=succeeded` appears in the query string, regardless of the server-side `$payment->status`. A user can craft `/pay/{uuid}/success?redirect_status=succeeded` for a `failed` or `cancelled` payment and receive a "Payment received" confirmation page. The fix is to add a guard after the `redirect_status` check that renders `ClientPayment/Unavailable` for payments in known terminal failure states (`failed`, `cancelled`).

Both gaps are security/correctness issues that must be resolved before phase completion. No later phase addresses them — Phase 6 (webhooks) writes status but does not fix the PI idempotency or success-page spoofing issues.

All other must-haves are verified: the public route structure, the per-account StripeClient pattern, the non-pending guard before PI creation, the clientSecret prop delivery (not in URLs or logs), the Stripe Elements loadStripe gate and confirmPayment flow, the layout resolver, PaymentLayout brand theming, and all four client-facing Vue pages. The test suite (12/12 GREEN) validates the controller contract thoroughly with one exception: the tests do not exercise the CR-02 scenario (success page for a failed/cancelled payment with redirect_status=succeeded) because the test for CLIENT-06 uses a `pending` payment — the gap is not caught by the current test suite.

---

_Verified: 2026-05-09_
_Verifier: Claude (gsd-verifier)_
