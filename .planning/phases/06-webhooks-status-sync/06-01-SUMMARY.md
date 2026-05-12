---
phase: "06-webhooks-status-sync"
plan: "01"
subsystem: "webhooks"
tags: [tdd, webhooks, stripe, controller, route, csrf]
dependency_graph:
  requires: ["06-00"]
  provides:
    - "app/Http/Controllers/StripeWebhookController.php"
    - "app/Jobs/HandleStripeWebhookJob.php (stub)"
    - "POST /webhook/stripe/{stripeAccount} route"
    - "CSRF exclusion for webhook routes"
  affects: ["06-02", "06-03"]
tech_stack:
  added: []
  patterns:
    - "preventRequestForgery(except: [...]) — Laravel 13 CSRF exclusion API"
    - "Webhook::constructEvent() — Stripe HMAC-SHA256 signature verification"
    - "HTTP_STRIPE_SIGNATURE server var pattern for raw body preservation in tests"
    - "Dual catch (SignatureVerificationException|\\UnexpectedValueException) → 400"
    - "HANDLED_EVENTS constant with early return for unknown event types"
    - "Idempotency gate 1 in controller (terminal status check before dispatch)"
key_files:
  created:
    - app/Http/Controllers/StripeWebhookController.php
    - app/Jobs/HandleStripeWebhookJob.php
  modified:
    - bootstrap/app.php
    - routes/web.php
    - tests/Feature/StripeWebhookTest.php
decisions:
  - "HTTP_STRIPE_SIGNATURE server var used in tests (not withHeaders()) to preserve raw body for Webhook::constructEvent()"
  - "HandleStripeWebhookJob stub created with typed constructor args — Wave 2 (06-02) implements handle()"
  - "PublicPaymentRouteTest pre-existing failures (pay.show route param mismatch) documented as deferred — out of scope"
metrics:
  duration: "~9 minutes"
  completed: "2026-05-12"
  tasks_completed: 2
  files_modified: 5
---

# Phase 6 Plan 01: Webhook Endpoint + CSRF Exclusion Summary

**One-liner:** Per-account webhook controller with HMAC-SHA256 signature verification, CSRF exclusion via preventRequestForgery(), and 7 GREEN tests covering WEBHOOK-01, WEBHOOK-02, WEBHOOK-06, SEC-03.

## What Was Built

### Task 1: CSRF Exclusion (bootstrap/app.php)

Added `$middleware->preventRequestForgery(except: ['webhook/stripe/*'])` to the `withMiddleware` closure in `bootstrap/app.php`. This is the Laravel 13 API — no `VerifyCsrfToken.php` class needed. Without this, Stripe POSTs would receive 419 TOKEN_MISMATCH responses.

### Task 2: StripeWebhookController + HandleStripeWebhookJob stub + route + tests

**Controller** (`app/Http/Controllers/StripeWebhookController.php`):
- Accepts `Request $request` and `StripeAccount $stripeAccount` (implicit model binding by integer ID)
- Uses `$request->getContent()` for raw body — never `$request->all()` (preserves HMAC validity)
- `Webhook::constructEvent()` with auto-decrypted `$stripeAccount->webhook_secret` from Eloquent `encrypted` cast
- Catches both `SignatureVerificationException` and `\UnexpectedValueException` → returns 400
- Early return 200 for unknown event types (only `payment_intent.succeeded` and `payment_intent.payment_failed` handled)
- Idempotency gate 1: skip if payment already in `completed` or `failed` state
- Dispatches `HandleStripeWebhookJob` with account ID, event type, and event data array

**Job stub** (`app/Jobs/HandleStripeWebhookJob.php`):
- Typed constructor promotion: `int $stripeAccountId`, `string $eventType`, `array $eventData`
- `handle()` is a stub — Wave 2 (06-02) implements the actual DB writes

**Route** (`routes/web.php`):
- `Route::post('/webhook/stripe/{stripeAccount}', [StripeWebhookController::class, 'handle'])->name('webhook.stripe')`
- Placed outside ALL middleware groups (after `/pay/{payment}` routes, before `require settings.php`)

**Tests** (`tests/Feature/StripeWebhookTest.php`):
- Introduced `stripePost()` helper using `call()` with `HTTP_STRIPE_SIGNATURE` server var for correct raw body preservation
- 7 tests GREEN, 4 intentionally incomplete (Wave 2/3)

## Test Results

```
php artisan test --compact --filter=StripeWebhookTest
# Result: 11 tests, 7 GREEN, 4 incomplete
```

| Test | Status | Requirement |
|------|--------|-------------|
| GET /webhook/stripe/{id} returns 405 | GREEN | WEBHOOK-01 |
| POST to unknown account id returns 404 | GREEN | WEBHOOK-01 |
| returns 400 for missing Stripe-Signature header | GREEN | WEBHOOK-02 |
| returns 400 for tampered signature | GREEN | WEBHOOK-02 |
| valid signature with handled event returns 200 | GREEN | WEBHOOK-02 |
| payment_intent.succeeded dispatches job and returns 200 | GREEN | WEBHOOK-06 |
| webhook route has no csrf protection | GREEN | SEC-03 |
| payment_intent.succeeded sets status to completed and paid_at | INCOMPLETE (stub) | WEBHOOK-03 |
| payment_intent.payment_failed sets status to failed | INCOMPLETE (stub) | WEBHOOK-04 |
| already completed payment is not re-processed | INCOMPLETE (stub) | WEBHOOK-05 |
| blank webhook_secret on stripe account update preserves existing | INCOMPLETE (stub) | D-04 |

## Commits

| Task | Commit | Description |
|------|--------|-------------|
| Task 1: CSRF exclusion | 0d922c4 | feat(06-01): add CSRF exclusion for webhook/stripe/* routes |
| Task 2: Controller + route + tests | 90f4fb2 | feat(06-01): add StripeWebhookController, HandleStripeWebhookJob stub, webhook route, and GREEN tests |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] HTTP_STRIPE_SIGNATURE server var required for raw body preservation**
- **Found during:** Task 2 — all tests using `->withHeaders(['Stripe-Signature' => $sig])->call(...)` returned 400
- **Issue:** Laravel's `call()` method uses `serverVariables` array directly, NOT `defaultHeaders` set by `withHeaders()`. The `withHeaders()` method only populates `defaultHeaders`, which is used by `postJson()/get()/post()` methods but NOT by the raw `call()` method. The `Stripe-Signature` header was never reaching the controller.
- **Fix:** Introduced `stripePost()` test helper that passes `HTTP_STRIPE_SIGNATURE` directly in the `$server` array parameter of `call()`. This ensures the header is present when `$request->header('Stripe-Signature')` is called in the controller.
- **Files modified:** `tests/Feature/StripeWebhookTest.php`
- **Commit:** 90f4fb2

### Out-of-Scope Pre-existing Failures

**1. `Tests\\Feature\\Auth\\PublicPaymentRouteTest` — pre-existing failures**
- `test_pay_route_is_reachable_without_authentication` and `test_pay_route_does_not_redirect_guest_to_login` fail with "Missing required parameter for [Route: pay.show] [URI: pay/{payment}]"
- The tests use `route('pay.show', ['uuid' => 'test-uuid'])` but the route parameter name is `payment`, not `uuid`
- Confirmed pre-existing: failures present before any 06-01 changes (verified via `git stash`)
- Logged to deferred items — not introduced by this plan

## Known Stubs

- `app/Jobs/HandleStripeWebhookJob.php::handle()` — empty stub body; Wave 2 (06-02) implements `payment_intent.succeeded` → `completed` and `payment_intent.payment_failed` → `failed` status writes
- 4 test cases remain `markTestIncomplete()` — Wave 2 (06-02) and Wave 3 (06-03) will turn them GREEN

## Threat Surface Scan

No new network endpoints or auth paths beyond what is documented in the plan's threat model. The webhook route adds a public POST endpoint, but it is fully covered by the STRIDE register:
- T-06-01: Spoofing/Tampering → HMAC-SHA256 check implemented
- T-06-02: Raw body tampering → `getContent()` used, not `all()`
- T-06-04: webhook_secret exposure → Eloquent encrypted cast, never `getRawOriginal()`
- T-06-05: Route in middleware group → route placed outside all auth groups
- T-06-06: CSRF on webhook → `preventRequestForgery(except: ['webhook/stripe/*'])` applied

## Self-Check: PASSED

- [x] `bootstrap/app.php` contains `preventRequestForgery(except: ['webhook/stripe/*'])`
- [x] `app/Http/Controllers/StripeWebhookController.php` exists with `handle()`, `getContent()`, `constructEvent()`, dual exception catch, `HANDLED_EVENTS`, idempotency gate 1, `HandleStripeWebhookJob::dispatch()`
- [x] `app/Jobs/HandleStripeWebhookJob.php` exists with typed constructor
- [x] `routes/web.php` contains `Route::post('/webhook/stripe/{stripeAccount}', ...)` outside middleware groups
- [x] `tests/Feature/StripeWebhookTest.php` has 7 GREEN tests + 4 incomplete stubs
- [x] Commit 0d922c4 exists: CSRF exclusion
- [x] Commit 90f4fb2 exists: controller, job, route, tests
