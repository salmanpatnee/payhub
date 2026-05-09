---
phase: 05-client-payment-page
plan: "05"
subsystem: client-payment
tags: [gap-closure, security, stripe, idempotency, tdd]
dependency_graph:
  requires: ["05-04"]
  provides: ["CR-01-closed", "CR-02-closed", "WR-01-fixed", "WR-02-fixed"]
  affects: ["ClientPaymentController", "Pay.vue", "ClientPaymentTest"]
tech_stack:
  added: []
  patterns:
    - "Retrieve-and-reuse PaymentIntent pattern (idempotent PI creation)"
    - "Server-side status guard in success() (spoofing prevention)"
    - "Null-safe loadStripe() result check (CDN failure resilience)"
    - "Defensive null guard in submit() (runtime error prevention)"
key_files:
  created: []
  modified:
    - app/Http/Controllers/ClientPaymentController.php
    - tests/Feature/ClientPaymentTest.php
    - resources/js/pages/ClientPayment/Pay.vue
decisions:
  - "CR-01: confirmable states defined as requires_payment_method, requires_confirmation, requires_action — terminal states (succeeded, canceled) trigger new PI creation"
  - "CR-02: status guard placed AFTER redirect_status check so legitimate non-succeeded redirects still hit pay.failed route first"
  - "WR-01: null return from loadStripe() leaves stripeLoaded=false — loading skeleton stays and StripeElements never mounts"
  - "WR-02: null guard returns early with user-visible error rather than throwing unhandled exception"
metrics:
  duration_seconds: 463
  completed_date: "2026-05-09"
  tasks_completed: 3
  files_modified: 3
---

# Phase 5 Plan 05: Gap Closure (CR-01, CR-02, WR-01, WR-02) Summary

**One-liner:** Closed two critical gaps — duplicate PaymentIntent creation on refresh (CR-01) and success-page spoofing for failed/cancelled payments (CR-02) — plus two warnings in Pay.vue for Stripe.js CDN failure resilience.

## Tasks Completed

| # | Name | Commit | Type |
|---|------|--------|------|
| 1 | Add CR-01 and CR-02 tests (RED) | f36e49c5 | test |
| 2 | Fix CR-01 retrieve-and-reuse + CR-02 status guard | 3b669ed4 | feat |
| 3 | Fix WR-01 loadStripe null check + WR-02 submit null guard | e1eece1f | fix |

## What Was Built

### CR-01: Retrieve-and-Reuse PaymentIntent (ClientPaymentController::show())

`show()` now checks `stripe_payment_intent_id` before calling Stripe:

- If set: calls `paymentIntents->retrieve($existingId)` and checks `$pi->status`
  - Confirmable states (`requires_payment_method`, `requires_confirmation`, `requires_action`): reuse the existing PI — return its `client_secret`, do NOT update the DB column
  - Terminal states (`succeeded`, `canceled`): create a fresh PI and update `stripe_payment_intent_id`
- If not set: create a new PI as before

This prevents PI proliferation on page refresh and preserves the client's partially-entered payment form state.

### CR-02: Server-Side Status Guard (ClientPaymentController::success())

`success()` now adds a guard after the `redirect_status` check:

```php
if (in_array($payment->status, ['failed', 'cancelled'])) {
    return Inertia::render('ClientPayment/Unavailable', [...]);
}
```

A crafted URL `/pay/{uuid}/success?redirect_status=succeeded` for a `failed` or `cancelled` payment now renders `ClientPayment/Unavailable` rather than falsely confirming a payment that never succeeded. The `$payment->status` is read from the DB via Eloquent model binding and cannot be spoofed via query parameters.

### WR-01: Null-Safe loadStripe() (Pay.vue::onMounted())

`loadStripe()` returns `null` when Stripe.js CDN fails to load (network error, ad-blocker). The fix captures the return value and only sets `stripeLoaded = true` when non-null. When null, `stripeLoaded` stays `false`, the loading skeleton remains visible, and `StripeElements` never mounts — preventing unhandled runtime errors.

### WR-02: Null Guard in submit() (Pay.vue)

`submit()` now guards against `null` instance or elements at its entry point:

```typescript
if (!instance || !elements) {
    errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'
    return
}
```

This is defensive programming — the `stripeLoaded` gate should prevent this scenario, but the guard ensures no unhandled exception if the gate is somehow bypassed.

## Test Results

**16 tests, 16 passed, 0 failures**

| Test | Status | Gap |
|------|--------|-----|
| reuses existing PaymentIntent when PI is confirmable | GREEN | CR-01 |
| creates new PaymentIntent when existing PI is terminal | GREEN | CR-01 |
| success page renders Unavailable for failed payment with redirect_status=succeeded | GREEN | CR-02 |
| success page renders Unavailable for cancelled payment with redirect_status=succeeded | GREEN | CR-02 |
| (original 12 tests) | GREEN | — |

## Deviations from Plan

None — plan executed exactly as written.

The CR-01b terminal test (creates new PI when existing PI is terminal) was GREEN during the RED phase because the old code always called `create()`. This is acceptable — the test verifies correct behavior that the old code happened to produce by coincidence. After CR-01 fix, the test remains GREEN because the retrieve-and-reuse path correctly creates a new PI when `status === 'succeeded'`.

## Threat Surface Scan

No new network endpoints, auth paths, file access patterns, or schema changes introduced. All fixes are within existing controller methods and Vue component. Threats T-05-05-01 through T-05-05-04 addressed as planned.

## Self-Check: PASSED

| Item | Status |
|------|--------|
| ClientPaymentController.php exists | FOUND |
| ClientPaymentTest.php exists | FOUND |
| Pay.vue exists | FOUND |
| 05-05-SUMMARY.md exists | FOUND |
| Commit f36e49c5 (test RED) | FOUND |
| Commit 3b669ed4 (feat GREEN) | FOUND |
| Commit e1eece1f (fix WR-01/WR-02) | FOUND |
| 16 tests pass GREEN | VERIFIED |
