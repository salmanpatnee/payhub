---
phase: 05-client-payment-page
reviewed: 2026-05-09T00:00:00Z
depth: standard
files_reviewed: 10
files_reviewed_list:
  - app/Http/Controllers/ClientPaymentController.php
  - phpunit.xml
  - resources/js/app.ts
  - resources/js/layouts/PaymentLayout.vue
  - resources/js/pages/ClientPayment/Failed.vue
  - resources/js/pages/ClientPayment/Pay.vue
  - resources/js/pages/ClientPayment/Success.vue
  - resources/js/pages/ClientPayment/Unavailable.vue
  - routes/web.php
  - tests/Feature/ClientPaymentTest.php
findings:
  critical: 2
  warning: 3
  info: 3
  total: 8
status: issues_found
---

# Phase 5: Code Review Report

**Reviewed:** 2026-05-09
**Depth:** standard
**Files Reviewed:** 10
**Status:** issues_found

## Summary

Phase 5 delivers the client-facing payment page: a public route that creates a Stripe PaymentIntent on load, renders Stripe Elements, and redirects to success/failed/unavailable pages. The overall architecture is sound. The critical CLAUDE.md security rules — no global `Stripe::setApiKey()`, no amount from client, no DB writes on `confirmPayment()`, amounts as integer cents, and `clientSecret` not placed in URLs — are all correctly observed.

Two critical issues were found: the `show()` controller creates a new PaymentIntent on every page load without checking whether one already exists (causing duplicate PaymentIntents and orphaned Stripe objects on refresh), and the `success()` controller has a logic gap where it renders the success page for any payment regardless of whether it is still in `pending` status — a user who already paid can be shown a misleading "Payment received" confirmation by visiting the URL directly. Three warnings cover: missing `expires_at` enforcement, the `stripeLoaded` promise result being discarded (making the Stripe load gate fragile), and the `failed` route being accessible without a rate-limit or any guard. Three informational items round out the report.

---

## Critical Issues

### CR-01: New PaymentIntent created on every page load — no idempotency check

**File:** `app/Http/Controllers/ClientPaymentController.php:29-37`

**Issue:** `show()` calls `$stripe->paymentIntents->create(...)` unconditionally every time the page is loaded. If the client refreshes the payment page, a second (and third, etc.) PaymentIntent is created against the Stripe account. Only the most recent one is stored in `stripe_payment_intent_id`. The earlier ones are orphaned — they can never be confirmed or cancelled by this application. This wastes Stripe objects, can confuse payment reconciliation, and means a refresh mid-form discards the Elements session the client was filling in (requiring them to re-enter card details, which is both a UX failure and a potential confusion point).

The fix is to check `stripe_payment_intent_id` before creating. If one already exists, retrieve it from Stripe and verify it is still in a confirmable state (`requires_payment_method` or `requires_confirmation`); only create a new one if it is absent or in a terminal state.

**Fix:**
```php
// In show(), replace the unconditional create() block:

$stripe = app()->make(StripeClient::class, ['apiKey' => $payment->stripeAccount->secret_key]);

if ($payment->stripe_payment_intent_id) {
    // Re-use the existing intent if it is still actionable
    $pi = $stripe->paymentIntents->retrieve($payment->stripe_payment_intent_id);
    if (!in_array($pi->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'])) {
        // Terminal or already succeeded — treat as non-pending
        return Inertia::render('ClientPayment/Unavailable', [
            'status' => $payment->status,
            'brand'  => $this->brandProps($payment->brand),
        ]);
    }
} else {
    $pi = $stripe->paymentIntents->create([
        'amount'                    => $payment->amount,
        'currency'                  => $payment->currency,
        'automatic_payment_methods' => ['enabled' => true],
    ]);
    $payment->update(['stripe_payment_intent_id' => $pi->id]);
}
```

---

### CR-02: `success()` renders "Payment received" regardless of actual payment status

**File:** `app/Http/Controllers/ClientPaymentController.php:48-66`

**Issue:** The `success()` controller only checks `request('redirect_status') === 'succeeded'` — a query parameter that can be crafted by anyone. It does not verify the `Payment` model's own `status` field. As a result:

1. A user can manually visit `/pay/{uuid}/success?redirect_status=succeeded` for any payment, including ones that are still `pending`, `failed`, or `cancelled`, and receive a "Payment received" success page with the real payment amount displayed — even though no money changed hands.
2. After Phase 6 (webhooks) marks a payment `completed`, a repeated visit to the success URL still works (benign but shows stale confirmation for already-closed payments).

The `redirect_status` query param must be treated as a hint only; the authoritative source is the server-side `Payment` record. Because webhook processing (Phase 6) runs asynchronously, the payment may still be `pending` when the redirect lands — but the controller should at minimum ensure the payment is not in a known-bad state, and should not display a success page for `failed` or `cancelled` payments.

**Fix:**
```php
public function success(Payment $payment): Response|RedirectResponse
{
    if (request('redirect_status') !== 'succeeded') {
        return redirect()->route('pay.failed', $payment->uuid);
    }

    // Guard: if the payment is in a known terminal failure state, do not show success
    if (in_array($payment->status, ['failed', 'cancelled'])) {
        return Inertia::render('ClientPayment/Unavailable', [
            'status' => $payment->status,
            'brand'  => $this->brandProps($payment->brand),
        ]);
    }

    $payment->loadMissing('brand');

    return Inertia::render('ClientPayment/Success', [
        'payment' => [
            'amount'   => $payment->amount,
            'currency' => $payment->currency,
            'service'  => $payment->service,
        ],
        'brand' => $this->brandProps($payment->brand),
    ]);
}
```

Note: `brandProps()` is a private method; add `$payment->loadMissing('brand')` before calling it in the new guard block, or move `loadMissing` above the guard.

---

## Warnings

### WR-01: `expires_at` is never enforced — expired payment links still process

**File:** `app/Http/Controllers/ClientPaymentController.php:19-24`

**Issue:** The `Payment` model has an `expires_at` column (visible in the migration and factory) but `show()` only checks `status !== 'pending'` before creating the PaymentIntent. A payment where `expires_at` is in the past but `status` is still `pending` (because the expiry job has not run, or no expiry job exists yet) will successfully load the payment form and allow the client to pay. This is a correctness issue: the payment link has semantically expired.

**Fix:**
```php
if ($payment->status !== 'pending'
    || ($payment->expires_at !== null && $payment->expires_at->isPast())
) {
    return Inertia::render('ClientPayment/Unavailable', [
        'status' => $payment->expires_at?->isPast() ? 'cancelled' : $payment->status,
        'brand'  => $this->brandProps($payment->brand),
    ]);
}
```

---

### WR-02: `loadStripe()` return value discarded — `stripeLoaded` gate is unreliable on network failure

**File:** `resources/js/pages/ClientPayment/Pay.vue:43-46`

**Issue:** `loadStripe()` returns a `Promise<Stripe | null>`. The current code awaits it but discards the return value. If Stripe.js fails to load (network error, CSP block, ad-blocker), `loadStripe()` resolves to `null` rather than rejecting. `stripeLoaded` is set to `true` regardless, the `<StripeElements>` component mounts with a null Stripe instance, and the `submit()` function will call `instance.confirmPayment()` on a null reference — throwing an unhandled runtime error that leaves `processing` stuck at `true` if the error occurs after `processing.value = true`.

**Fix:**
```typescript
onMounted(async () => {
    const stripe = await loadStripe(props.stripeAccount.publishable_key)
    if (!stripe) {
        errorMessage.value = 'Payment system could not be loaded. Please refresh the page or try a different browser.'
        return
    }
    stripeLoaded.value = true
})
```

---

### WR-03: `submit()` typed as `instance: any, elements: any` — no null guard

**File:** `resources/js/pages/ClientPayment/Pay.vue:98`

**Issue:** The `submit` function accepts `instance` and `elements` as `any`. The `vue-stripe-js` scoped slot can pass `null` for either when the element has not fully initialized. Combined with WR-02 (Stripe failing to load), calling `instance.confirmPayment(...)` on a null/undefined `instance` will throw an unhandled runtime exception. Using `any` also defeats TypeScript's ability to catch misuse.

**Fix:**
```typescript
import type { Stripe, StripeElements } from '@stripe/stripe-js'

async function submit(instance: Stripe | null, elements: StripeElements | null): Promise<void> {
    if (!instance || !elements) {
        errorMessage.value = 'Payment form is not ready. Please wait and try again.'
        return
    }
    processing.value = true
    errorMessage.value = null
    // ... rest of function unchanged
}
```

---

## Info

### IN-01: `formatAmount` duplicated across Pay.vue and Success.vue — extract to composable

**Files:** `resources/js/pages/ClientPayment/Pay.vue:49-54`, `resources/js/pages/ClientPayment/Success.vue:25-30`

**Issue:** The `formatAmount` function is identical in both files (with a comment in each noting it copies the pattern from `Show.vue`). Three copies of the same function is a maintenance hazard — a locale change or rounding fix needs to be applied in three places.

**Fix:** Extract to `resources/js/composables/useFormatAmount.ts` and import it in all three files:
```typescript
// resources/js/composables/useFormatAmount.ts
export function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    ).format(cents / 100)
}
```

---

### IN-02: `phpunit.xml` hard-codes an absolute Windows path

**File:** `phpunit.xml:21`

**Issue:** `APP_BASE_PATH` is set to `C:\Users\Salman\Herd\payhub`. This file is checked into version control and will break on any other developer's machine or in CI.

**Fix:** Remove the `APP_BASE_PATH` env entry — Laravel resolves its base path automatically. If an override is genuinely needed, use `%BASE_DIR%` or a relative path, and document why.

---

### IN-03: `Unavailable.vue` status type does not include `'pending'` but controller can pass other strings

**File:** `resources/js/pages/ClientPayment/Unavailable.vue:11`

**Issue:** The `status` prop is typed as `'completed' | 'failed' | 'cancelled'`. The controller passes `$payment->status` directly (line 22 of the controller), which is a plain string from the database. If the database ever holds an unrecognised status value (e.g., `'processing'` added in a future phase), the TypeScript type will be satisfied at runtime but the `content` computed property falls back to `map['cancelled']` silently — showing a "Link no longer active" message for a payment that is actually processing. The type and the guard should both be explicit.

**Fix:** Add an exhaustive fallback message, or align the backend to only ever pass the three known terminal statuses to this component. At minimum, document the fallback intent in the computed map:
```typescript
// Make the fallback explicit and deliberate:
return map[props.status] ?? {
    title:       'Payment unavailable',
    description: 'This payment link is no longer active.',
    pageTitle:   `Payment unavailable — ${props.brand.name}`,
    icon:        'ban',
}
```

---

_Reviewed: 2026-05-09_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
