---
phase: 06-webhooks-status-sync
reviewed: 2026-05-12T00:00:00Z
depth: standard
files_reviewed: 9
files_reviewed_list:
  - app/Http/Controllers/StripeWebhookController.php
  - app/Jobs/HandleStripeWebhookJob.php
  - bootstrap/app.php
  - routes/web.php
  - app/Http/Controllers/Admin/StripeAccountController.php
  - app/Http/Requests/Admin/UpdateStripeAccountRequest.php
  - resources/js/pages/admin/stripe-accounts/Edit.vue
  - tests/Feature/StripeWebhookTest.php
  - phpunit.xml
findings:
  critical: 3
  warning: 4
  info: 3
  total: 10
status: issues_found
---

# Phase 06: Code Review Report

**Reviewed:** 2026-05-12T00:00:00Z
**Depth:** standard
**Files Reviewed:** 9
**Status:** issues_found

## Summary

Reviewed the Phase 6 webhook + status sync implementation: `StripeWebhookController`, `HandleStripeWebhookJob`, route/middleware config, the StripeAccount edit flow (controller, form request, Vue component), the feature test suite, and `phpunit.xml`.

The overall structure is sound — CSRF exclusion is correctly scoped, raw body is preserved, `encrypted` casts are in place, and the `client_secret` is never logged. However, three critical defects were found: a TOCTOU race condition in the job's idempotency gate that allows double-writes under concurrent queue workers; the job accepting and updating any payment that carries the matching `stripe_payment_intent_id` regardless of which Stripe account the webhook came from; and a logic error in the Edit UI where testing a new secret key is validated against the old publishable key. Four warnings and three info items follow.

---

## Critical Issues

### CR-01: Job idempotency gate is not race-condition safe — double-write possible

**File:** `app/Jobs/HandleStripeWebhookJob.php:30`

**Issue:** The idempotency check reads `payment->status` and then performs `payment->update()` as two separate, non-atomic operations. If Stripe delivers the same event twice in rapid succession (a documented Stripe behavior), two job instances can both pass `in_array($payment->status, ['completed', 'failed'])` before either has written the new status. Both then execute `$payment->update(...)`, producing a double-write of `paid_at` (among other fields) and potentially corrupting audit data. With `QUEUE_CONNECTION=sync` in tests this is invisible, but with any real queue worker (database/Redis) it is a real risk.

**Fix:** Use a database-level atomic update with a `WHERE status = 'pending'` guard so only one concurrent worker can win:

```php
$updated = Payment::where('stripe_payment_intent_id', $piId)
    ->where('status', 'pending')   // atomic guard
    ->update(match ($this->eventType) {
        'payment_intent.succeeded'      => ['status' => 'completed', 'paid_at' => now()],
        'payment_intent.payment_failed' => ['status' => 'failed'],
        default                         => [],
    });

// $updated === 0 means already in terminal state or not found — idempotent no-op
```

This collapses the read-check-write into a single SQL statement, making it safe under concurrent workers.

---

### CR-02: Job does not verify the payment belongs to the incoming Stripe account — cross-account mutation possible

**File:** `app/Jobs/HandleStripeWebhookJob.php:28`

**Issue:** The job receives `$stripeAccountId` but never uses it. The query at line 28 finds a payment by `stripe_payment_intent_id` alone:

```php
$payment = Payment::where('stripe_payment_intent_id', $piId)->first();
```

Stripe PaymentIntent IDs (e.g., `pi_xxx`) are globally unique within one Stripe *account* but there is nothing preventing two distinct StripeAccount records in this application from theoretically sharing a PI ID string — or, more realistically, a maliciously crafted webhook targeting the wrong account could trigger a status change on an unrelated payment if a PI ID collision ever occurs. More concretely: the stored `$stripeAccountId` is silently ignored, which means the field is dead code and the isolation invariant is not enforced.

**Fix:** Add `stripe_account_id` to the query:

```php
$payment = Payment::where('stripe_payment_intent_id', $piId)
    ->where('stripe_account_id', $this->stripeAccountId)
    ->first();
```

---

### CR-03: Edit UI tests a new secret key against the currently-saved publishable key, not the new one

**File:** `resources/js/pages/admin/stripe-accounts/Edit.vue:69-73`

**Issue:** In `testConnection()`, when `form.secret_key` is filled (the user is replacing the key), the request sends:

```js
{ secret_key: form.secret_key, publishable_key: form.publishable_key }
```

`form.publishable_key` is bound to the `account_name`/`publishable_key` input, which may or may not have been changed. If the user is simultaneously replacing both keys (e.g., migrating from test to live), the test validates the **new** secret key against whatever publishable key currently sits in the form field. If the publishable key field has not yet been updated to the new live key, the server-side `validatePublishableKeyFormat()` will return a "key mode mismatch" error even though the secret key itself is valid. This is incorrect feedback and will confuse users performing a full key rotation.

However, the deeper flaw is that `testStoredConnection` (line 71) is called with an empty `data` object — the server uses the stored keys — but `testKeyConnection` (line 70) uses `form.publishable_key` which reflects the *form's current value*, not necessarily the database value. A user who edits the publishable key field but has not saved yet will get a test against an unsaved value, making the test result meaningless relative to what is actually in the database.

**Fix:** Either:
1. When testing a new secret key, also require the publishable key field to be filled and make both explicit, or
2. Clearly document in the UI that "Test Connection" tests the current form values (not the saved values), so users know they need to ensure both keys are correct before testing.

The more impactful code fix is to ensure the test always sends both keys when either has been changed:

```ts
const data = (form.secret_key || form.publishable_key !== props.stripeAccount.publishable_key)
    ? { secret_key: form.secret_key || undefined, publishable_key: form.publishable_key }
    : {};
```

---

## Warnings

### WR-01: Webhook is processed for deactivated Stripe accounts

**File:** `app/Http/Controllers/StripeWebhookController.php:20-50`

**Issue:** The controller performs no `is_active` check before processing the webhook. A deactivated Stripe account will still have its webhooks verified and jobs dispatched. This means payments linked to deactivated accounts can have their status mutated post-deactivation, which may be unintended.

**Fix:** Add an early return after model binding:

```php
if (! $stripeAccount->is_active) {
    return response('', 200); // Acknowledge to Stripe; skip processing
}
```

---

### WR-02: `webhook_endpoint_url` construction may produce double-slash if `APP_URL` has a trailing slash

**File:** `app/Http/Controllers/Admin/StripeAccountController.php:57`

**Issue:** The URL is assembled as:

```php
'webhook_endpoint_url' => config('app.url').'/webhook/stripe/'.$stripeAccount->id,
```

If `APP_URL` is set to `https://example.com/` (trailing slash), the result is `https://example.com//webhook/stripe/1`. While many HTTP servers normalize this, it will produce a wrong URL in the UI that the user copies into the Stripe dashboard, causing webhook delivery failures.

**Fix:** Use Laravel's `url()` helper which handles trailing slashes correctly:

```php
'webhook_endpoint_url' => url('/webhook/stripe/'.$stripeAccount->id),
```

Or use the named route:

```php
'webhook_endpoint_url' => route('webhook.stripe', $stripeAccount),
```

---

### WR-03: Unhandled Stripe exception types in `validateStripeSecretKey` cause 500 errors

**File:** `app/Http/Controllers/Admin/StripeAccountController.php:156-165`

**Issue:** Only `AuthenticationException` and `ApiConnectionException` are caught. Any other Stripe SDK exception — such as `\Stripe\Exception\RateLimitException`, `\Stripe\Exception\PermissionException`, or an unexpected API response — will bubble up as an unhandled 500, surfacing a stack trace rather than a user-friendly error. The `balance->retrieve()` call can legitimately throw `\Stripe\Exception\ApiErrorException` (the parent class for all Stripe API errors).

**Fix:** Add a catch for `\Stripe\Exception\ApiErrorException` as a fallback:

```php
} catch (AuthenticationException) {
    return 'The secret key could not be verified with Stripe. Check that it is correct and try again.';
} catch (ApiConnectionException) {
    return 'Could not connect to Stripe to validate the key. Check your network and try again.';
} catch (\Stripe\Exception\ApiErrorException $e) {
    return 'Stripe returned an unexpected error: '.$e->getMessage();
}
```

---

### WR-04: `fakeStripeSignature()` in tests uses `time()` — test can fail if clock skew exceeds Stripe's 300-second tolerance window

**File:** `tests/Feature/StripeWebhookTest.php:21-26`

**Issue:** `fakeStripeSignature()` stamps the webhook with the current Unix timestamp via `time()`. The Stripe SDK's `Webhook::constructEvent()` validates that the timestamp is within 300 seconds of `time()` on the verifying side. In CI environments with clock drift, or when a test suite takes longer than expected, individual tests in this file can fail with a `SignatureVerificationException` for reasons unrelated to the code under test. This is a flaky test risk.

**Fix:** Pass a fixed timestamp and use the Stripe SDK's tolerance override:

```php
function fakeStripeSignature(string $payload, string $secret, int $timestamp = 0): string
{
    $timestamp = $timestamp ?: time();
    $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    return "t={$timestamp},v1={$signature}";
}
```

And in `StripeWebhookController`, or in a test helper, pass `$tolerance = 0` (or a large value) to `constructEvent` in the test environment. Alternatively, pin the timestamp to a value and pass it explicitly in each test helper call so the test is deterministic.

---

## Info

### IN-01: `$stripeAccountId` job property is dead code

**File:** `app/Jobs/HandleStripeWebhookJob.php:19`

**Issue:** `$stripeAccountId` is stored on the job (line 19) and logged on failure (line 45), but is never used in `handle()` to scope the payment query (see CR-02). Until CR-02 is fixed, this field serves only as a logging aid, which should be documented, or it should be actively used.

**Fix:** Resolve by implementing CR-02 (add `stripe_account_id` to the payment query), at which point the field earns its place.

---

### IN-02: Hardcoded `APP_KEY` in `phpunit.xml`

**File:** `phpunit.xml:22`

**Issue:** A real-looking base64 application key is committed in plaintext:

```xml
<env name="APP_KEY" value="base64:v75kY5NTQ9Idxwf/TczvOyCrsLWrNy5VRYiw5lJEuGE="/>
```

While this is a test-only key and does not grant Stripe or production access, it violates the principle of never committing secrets to source control. If the same key value were accidentally reused in production (e.g., copy-paste during deployment), it would be compromised. The CLAUDE.md policy and general security hygiene both advise against committed key material.

**Fix:** Replace with a generic placeholder and generate a fresh key per CI run, or use `php artisan key:generate` in CI setup:

```xml
<env name="APP_KEY" value="base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA="/>
```

---

### IN-03: `testKeyConnection` uses `$request->input()` after `validate()` instead of `$request->validated()`

**File:** `app/Http/Controllers/Admin/StripeAccountController.php:109-110`

**Issue:** After calling `$request->validate([...])`, the controller uses `$request->input('publishable_key')` and `$request->input('secret_key')` rather than `$request->validated('publishable_key')` etc. While functionally equivalent in this case (validation has already run), it is inconsistent with the pattern used everywhere else in the codebase and is a code quality concern — future developers may add transforms or sanitizers to validation rules and expect `validated()` to reflect them.

**Fix:**

```php
$validated = $request->validate([
    'secret_key' => 'required|string',
    'publishable_key' => 'required|string',
]);

$error = $this->validatePublishableKeyFormat($validated['publishable_key'], $validated['secret_key'])
    ?? $this->validateStripeSecretKey($validated['secret_key']);
```

---

_Reviewed: 2026-05-12T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
