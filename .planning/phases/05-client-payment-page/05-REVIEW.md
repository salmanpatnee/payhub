---
phase: 05-client-payment-page
reviewed: 2026-05-09T00:00:00Z
depth: standard
files_reviewed: 3
files_reviewed_list:
  - app/Http/Controllers/ClientPaymentController.php
  - tests/Feature/ClientPaymentTest.php
  - resources/js/pages/ClientPayment/Pay.vue
findings:
  critical: 0
  warning: 2
  info: 2
  total: 4
status: issues_found
---

# Phase 5: Gap-Closure Code Review Report

**Reviewed:** 2026-05-09
**Depth:** standard
**Files Reviewed:** 3
**Status:** issues_found

## Summary

This is a gap-closure re-review targeting the three files modified by plan 05-05. The prior review (same phase) identified CR-01 (duplicate PaymentIntents on refresh) and CR-02 (success-page spoofing via crafted `redirect_status` URL) as critical issues. Both fixes have been applied and are correct.

**CR-01 closed.** `show()` now implements retrieve-and-reuse: when `stripe_payment_intent_id` is already set, the existing PI is retrieved from Stripe and its status checked. Confirmable states (`requires_payment_method`, `requires_confirmation`, `requires_action`) are reused as-is; only terminal states trigger a new PI creation. The DB is not written to when reusing — correct.

**CR-02 closed.** `success()` now guards against `failed` and `cancelled` payment status after checking `redirect_status`. A crafted URL cannot produce a false "Payment received" confirmation for a known-failed payment.

**WR-01 (loadStripe null check) and WR-02 (submit null guard) from plan 05-05** are both applied correctly in Pay.vue. `onMounted` captures the `loadStripe()` return value and only sets `stripeLoaded = true` on a non-null result. `submit()` returns early with a user-visible error when `instance` or `elements` is null.

No critical issues remain. Two warnings and two informational items are noted below.

---

## Warnings

### WR-01: `expires_at` still not enforced — expired payment links remain payable

**File:** `app/Http/Controllers/ClientPaymentController.php:19-24`

**Issue:** The original review flagged this as WR-01. The status guard at line 19 correctly blocks non-pending payments, but it does not check `expires_at`. A payment whose `expires_at` timestamp is in the past but whose `status` is still `pending` (because no expiry job has run, or the job has not been implemented yet) will pass through the guard, create a PaymentIntent, and allow the client to complete payment on a semantically expired link. This is a correctness issue — the payment link has expired by contract even if the DB status has not caught up.

**Fix:**
```php
// In show(), extend the guard at line 19:
if ($payment->status !== 'pending'
    || ($payment->expires_at !== null && $payment->expires_at->isPast())
) {
    return Inertia::render('ClientPayment/Unavailable', [
        'status' => ($payment->expires_at?->isPast() && $payment->status === 'pending')
            ? 'cancelled'
            : $payment->status,
        'brand'  => $this->brandProps($payment->brand),
    ]);
}
```

---

### WR-02: `paymentIntents->retrieve()` can throw — no exception handling on public route

**File:** `app/Http/Controllers/ClientPaymentController.php:37`

**Issue:** `$stripe->paymentIntents->retrieve($payment->stripe_payment_intent_id)` is an outbound HTTP call that can throw `\Stripe\Exception\ApiErrorException` in several legitimate scenarios: the PI was deleted from Stripe, the stored `stripe_payment_intent_id` references a PI that belongs to a different Stripe account (data integrity issue), the Stripe API is temporarily unavailable, or the stored ID is malformed. There is no try/catch. When this throws on a public-facing page, the unhandled exception will produce a 500 response with a Laravel exception page (or JSON in debug mode). A client paying a legitimate invoice would see an error with no retry guidance. The API key would also appear in the stack trace in debug mode.

This code path is new — the prior implementation only called `create()`, which is less likely to fail on an already-stored value. The retrieve-and-reuse pattern brings this risk into scope.

**Fix:**
```php
if ($payment->stripe_payment_intent_id) {
    try {
        $pi = $stripe->paymentIntents->retrieve($payment->stripe_payment_intent_id);
    } catch (\Stripe\Exception\ApiErrorException $e) {
        // PI not found or Stripe error — create a fresh PI and overwrite the stale ID
        $pi = $stripe->paymentIntents->create([
            'amount'                    => $payment->amount,
            'currency'                  => $payment->currency,
            'automatic_payment_methods' => ['enabled' => true],
        ]);
        $payment->update(['stripe_payment_intent_id' => $pi->id]);
    }

    if (isset($pi) && ! in_array($pi->status, $confirmableStates)) {
        // ... terminal state branch (unchanged)
    }
} else {
    // ... no PI exists branch (unchanged)
}
```

---

## Info

### IN-01: CR-01 test does not assert that `create()` is NOT called when reusing a confirmable PI

**File:** `tests/Feature/ClientPaymentTest.php:169-185`

**Issue:** The test "reuses existing PaymentIntent when stripe_payment_intent_id is set and PI is confirmable" uses `mockStripeClientWithRetrieve()`, which registers `shouldReceive('create')->andReturn($newPi)` with no call-count constraint. This means the mock permits `create()` to be called zero or more times. The test verifies the correct `clientSecret` is returned and that `stripe_payment_intent_id` is unchanged — but if the controller were to call both `retrieve()` and `create()` (a regression), the test would still pass because the `where('clientSecret', ...)` assertion would be satisfied by the retrieved PI's secret (assuming `create()` result is discarded). Adding a `->never()` expectation on `create()` makes the reuse invariant explicit and protects against regressions.

**Fix:**
```php
// In mockStripeClientWithRetrieve(), add a conditional 'never' expectation
// OR add a separate mock helper for the reuse-only case:

function mockStripeClientRetrieveOnly(string $existingPiId, string $existingStatus, string $existingClientSecret): void
{
    $retrievedPi = PaymentIntent::constructFrom([
        'id'            => $existingPiId,
        'client_secret' => $existingClientSecret,
        'status'        => $existingStatus,
    ]);

    $mockPaymentIntents = Mockery::mock();
    $mockPaymentIntents->shouldReceive('retrieve')->with($existingPiId)->once()->andReturn($retrievedPi);
    $mockPaymentIntents->shouldNotReceive('create');

    $mockStripe = Mockery::mock(StripeClient::class);
    $mockStripe->paymentIntents = $mockPaymentIntents;

    app()->bind(StripeClient::class, fn () => $mockStripe);
}
```

Use this stricter helper in the confirmable-reuse test.

---

### IN-02: `submit()` retains `any` typing — TypeScript types not tightened as recommended

**File:** `resources/js/pages/ClientPayment/Pay.vue:104`

**Issue:** The null guard added to `submit()` is correct, but the parameter types remain `instance: any, elements: any`. The original review's WR-03 recommendation was to type them as `Stripe | null` and `StripeElements | null` from `@stripe/stripe-js`. Using `any` means TypeScript cannot catch misuse of the Stripe API (e.g., calling a non-existent method on `instance`). This is a low-risk residual issue because the null guard is now present, but proper typing would surface future mistakes at compile time.

**Fix:**
```typescript
import type { Stripe, StripeElements } from '@stripe/stripe-js'

async function submit(instance: Stripe | null, elements: StripeElements | null): Promise<void> {
    if (!instance || !elements) {
        errorMessage.value = 'Payment system is unavailable. Please refresh and try again.'
        return
    }
    // ... rest unchanged
}
```

---

_Reviewed: 2026-05-09_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
