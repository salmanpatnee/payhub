---
phase: 07-notifications-dashboard
plan: "01"
subsystem: notifications
tags: [mail, queue, jobs, notifications, webhook]

# Dependency graph
requires:
  - phase: 07-00
    provides: "NotificationTest.php RED stubs (4 test cases)"
  - phase: 06-webhooks-status-sync
    provides: "HandleStripeWebhookJob with $updated guard"
provides:
  - "SendPaymentNotification queued job — resolves all admin users and queues PaymentSucceeded to each"
  - "PaymentSucceeded markdown mailable — full D-03 payment details in subject and body"
  - "emails.payment-succeeded Blade template — all D-03 fields rendered"
  - "HandleStripeWebhookJob dispatch hook — SendPaymentNotification dispatched after $updated > 0 AND payment_intent.succeeded"
affects: [07-02, 07-03]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Two-job chain: HandleStripeWebhookJob → SendPaymentNotification (D-08)"
    - "Mail::to($admin)->queue(new PaymentSucceeded($payment)) loop for admin recipients"
    - "Markdown mailable with private formatAmount() helper for currency symbol + 2dp formatting"
    - "Payment re-fetched with eager-loaded brand + stripeAccount after atomic update (model not returned from ->update())"
    - "Same-namespace class reference: SendPaymentNotification::dispatch() in App\\Jobs without use import (Pint-enforced)"

key-files:
  created:
    - app/Jobs/SendPaymentNotification.php
    - app/Mail/PaymentSucceeded.php
    - resources/views/emails/payment-succeeded.blade.php
  modified:
    - app/Jobs/HandleStripeWebhookJob.php
    - tests/Feature/NotificationTest.php

key-decisions:
  - "Payment must be re-fetched after atomic ->update() because ->update() returns row count, not model instances"
  - "Pint correctly removed use App\\Jobs\\SendPaymentNotification import — both classes are in App\\Jobs namespace, no import needed"
  - "NotificationTest test 1 restructured to call HandleStripeWebhookJob::handle() directly — Queue::fake() intercepts the HTTP-level dispatch preventing the job from running, making the original test unrunnable (Rule 1 fix)"
  - "No ShouldQueue on PaymentSucceeded mailable itself — Mail::to($admin)->queue() handles queueing at dispatch site"

# Metrics
duration: 14min
completed: 2026-05-14
---

# Phase 7 Plan 01: Email Notification Chain Summary

**Queued email notification chain: SendPaymentNotification job resolves all admin users and queues PaymentSucceeded markdown mailable for each; HandleStripeWebhookJob dispatches notification after $updated > 0 AND payment_intent.succeeded; all 4 NotificationTest cases GREEN**

## Performance

- **Duration:** ~14 min
- **Started:** 2026-05-14T10:49:48Z
- **Completed:** 2026-05-14T11:03:48Z
- **Tasks:** 2
- **Files modified:** 5 (3 created, 2 modified)

## Accomplishments

- Created `app/Jobs/SendPaymentNotification.php` — ShouldQueue job with $tries=3, $backoff=[1,5,10], failed() logging only payment_uuid (never stripe_payment_intent_id per T-07-01-01)
- Created `app/Mail/PaymentSucceeded.php` — Markdown mailable with subject "Payment received — {client_name} ({symbol}{amount_2dp} {CURRENCY})" and private formatAmount() helper
- Created `resources/views/emails/payment-succeeded.blade.php` — all D-03 fields: client_name, client_email, amount, brand name, stripe account name, service, package, note (conditional), View Payment button
- Modified `app/Jobs/HandleStripeWebhookJob.php` — dispatch hook after $updated > 0 AND payment_intent.succeeded, with Payment re-fetched eager-loading brand + stripeAccount
- Modified `tests/Feature/NotificationTest.php` — Rule 1 fix to test 1 (restructured to directly invoke handle() for correct queue-chain testing)

## Task Commits

Each task was committed atomically:

1. **Task 1: Create SendPaymentNotification job and PaymentSucceeded mailable** - `662bfd4` (feat)
2. **Task 2: Add dispatch hook to HandleStripeWebhookJob** - `ff39c21` (feat)

**Plan metadata:** (committed with SUMMARY)

## Files Created/Modified

- `app/Jobs/SendPaymentNotification.php` — NEW: queued job, resolves User::role('admin')->get(), queues PaymentSucceeded per admin
- `app/Mail/PaymentSucceeded.php` — NEW: markdown mailable with formatted subject and content() returning markdown view
- `resources/views/emails/payment-succeeded.blade.php` — NEW: Blade markdown template with all D-03 fields and conditional note
- `app/Jobs/HandleStripeWebhookJob.php` — MODIFIED: dispatch hook in handle() after $updated > 0 AND event type guard
- `tests/Feature/NotificationTest.php` — MODIFIED: test 1 restructured for correct dispatch-chain testing (Rule 1 fix)

## Decisions Made

- Re-fetching the payment after `->update()` is necessary because `->update()` returns affected row count, not a model instance. The re-fetch also eager-loads brand and stripeAccount relations needed by the mailable.
- Pint removed the `use App\Jobs\SendPaymentNotification;` import since both `HandleStripeWebhookJob` and `SendPaymentNotification` are in `App\Jobs` namespace — no import needed and tests pass.
- `PaymentSucceeded` mailable does not implement `ShouldQueue` itself — queueing is handled at the dispatch site via `Mail::to($admin)->queue(...)`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed dispatch-chain test (NotificationTest test 1)**
- **Found during:** Task 2 verification
- **Issue:** The original RED stub in NotificationTest test 1 used `Queue::fake()` then called the webhook HTTP endpoint, expecting `Queue::assertPushed(SendPaymentNotification::class)`. With `Queue::fake()` active, `HandleStripeWebhookJob` is intercepted and never runs — so `SendPaymentNotification` is never dispatched. The test was structurally impossible to pass as written.
- **Fix:** Restructured test 1 to instantiate `HandleStripeWebhookJob` directly and call `handle()` (bypassing the HTTP/queue layer), which correctly tests that the job dispatches `SendPaymentNotification` when conditions are met. `Queue::fake()` still intercepts `SendPaymentNotification` dispatch for assertion.
- **Files modified:** `tests/Feature/NotificationTest.php`
- **Commit:** ff39c21

## Issues Encountered

- **Worktree has no vendor directory** — vendor is .gitignored; Pint and artisan were run from the main repo, and implementation files were copied to the main repo for test execution. This matches the approach documented in 07-00-SUMMARY.md.
- **Pint removed use import** — Pint's `no_unused_imports` fixer removed the `use App\Jobs\SendPaymentNotification;` import from `HandleStripeWebhookJob.php` since both classes share the same namespace. The dispatch call still works correctly — confirmed by all tests passing.

## Known Stubs

None — all D-03 fields are wired from the live `Payment` model. The email template renders real data from the database record.

## Threat Flags

None — no new network endpoints, auth paths, or trust boundaries introduced. The `failed()` method in `SendPaymentNotification` logs only `payment_uuid` and error message as required by T-07-01-01. The dispatch guard (T-07-01-02) is correctly implemented with both `$updated > 0` AND `payment_intent.succeeded` conditions.

## Self-Check: PASSED

- FOUND: app/Jobs/SendPaymentNotification.php
- FOUND: app/Mail/PaymentSucceeded.php
- FOUND: resources/views/emails/payment-succeeded.blade.php
- FOUND: app/Jobs/HandleStripeWebhookJob.php (modified)
- FOUND: tests/Feature/NotificationTest.php (modified)
- FOUND commit 662bfd4 (feat(07-01): add SendPaymentNotification job, PaymentSucceeded mailable, and email template)
- FOUND commit ff39c21 (feat(07-01): add dispatch hook to HandleStripeWebhookJob; fix dispatch chain test)
- NotificationTest: 4/4 cases GREEN
- StripeWebhookTest: 14/14 cases GREEN (no regressions)

---
*Phase: 07-notifications-dashboard*
*Completed: 2026-05-14*
