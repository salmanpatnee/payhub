---
phase: 06-webhooks-status-sync
fixed_at: 2026-05-12T00:00:00Z
review_path: .planning/phases/06-webhooks-status-sync/06-REVIEW.md
iteration: 1
findings_in_scope: 7
fixed: 7
skipped: 0
status: all_fixed
---

# Phase 06: Code Review Fix Report

**Fixed at:** 2026-05-12T00:00:00Z
**Source review:** `.planning/phases/06-webhooks-status-sync/06-REVIEW.md`
**Iteration:** 1

**Summary:**
- Findings in scope: 7
- Fixed: 7
- Skipped: 0

## Fixed Issues

### CR-01 + CR-02: Atomic idempotency guard + Stripe account scoping in webhook job

**Files modified:** `app/Jobs/HandleStripeWebhookJob.php`
**Commit:** b0027d0
**Applied fix:** Replaced the read-check-write TOCTOU pattern with a single atomic `Payment::where(...)->where('stripe_account_id', ...)->where('status', 'pending')->update(match (...))` call. This collapses the idempotency guard and the cross-account isolation into one SQL statement — if `$updated === 0`, the payment was already in a terminal state or not found (no-op). The `$updated` variable is assigned but intentionally not used beyond the atomic nature of the query.

---

### WR-01: Skip webhook processing for deactivated Stripe accounts

**Files modified:** `app/Http/Controllers/StripeWebhookController.php`
**Commit:** 29a294f
**Applied fix:** Added early return `return response('', 200)` when `!$stripeAccount->is_active`, placed immediately after model binding at the top of `handle()`, before signature verification. Stripe is acknowledged with 200 but no job is dispatched.

---

### WR-02 + WR-03: Use route() for webhook URL; add ApiErrorException catch fallback

**Files modified:** `app/Http/Controllers/Admin/StripeAccountController.php`
**Commit:** e79e663
**Applied fix (WR-02):** Replaced `config('app.url').'/webhook/stripe/'.$stripeAccount->id` with `route('webhook.stripe', $stripeAccount)` to avoid double-slash when APP_URL has a trailing slash.
**Applied fix (WR-03):** Added `catch (\Stripe\Exception\ApiErrorException $e)` as a third catch clause in `validateStripeSecretKey()` after the existing `AuthenticationException` and `ApiConnectionException` catches. Pint promoted the exception to a top-level `use Stripe\Exception\ApiErrorException` import.

---

### WR-04: Optional timestamp parameter in fakeStripeSignature()

**Files modified:** `tests/Feature/StripeWebhookTest.php`
**Commit:** ae8b255
**Applied fix:** Added `int $timestamp = 0` as an optional third parameter to `fakeStripeSignature()`, with `$timestamp = $timestamp ?: time()` inside the function. Existing call sites continue to work without changes. Tests can now pin a specific timestamp to prevent Stripe's 300-second tolerance window causing flaky failures in slow or clock-drifted CI environments.

---

### CR-03: Test connection includes both keys when either has changed

**Files modified:** `resources/js/pages/admin/stripe-accounts/Edit.vue`
**Commit:** bea967e
**Applied fix:** Updated the `data` expression in `testConnection()` from `form.secret_key ? {...} : {}` to `(form.secret_key || form.publishable_key !== props.stripeAccount.publishable_key) ? { secret_key: form.secret_key || undefined, publishable_key: form.publishable_key } : {}`. This ensures both keys are sent to the server for validation whenever either has been modified in the form, preventing a scenario where a new secret key is tested against a stale publishable key.

---

## Test Results

`php artisan test --compact --filter=StripeWebhookTest` — **14 tests, 14 passed, 39 assertions** after all fixes applied.

---

_Fixed: 2026-05-12T00:00:00Z_
_Fixer: Claude (gsd-code-fixer)_
_Iteration: 1_
