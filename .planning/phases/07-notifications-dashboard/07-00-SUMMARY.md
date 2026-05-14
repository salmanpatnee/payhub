---
phase: 07-notifications-dashboard
plan: "00"
subsystem: testing
tags: [pest, tdd, notifications, dashboard, filters, mail, queue]

# Dependency graph
requires:
  - phase: 06-webhooks-status-sync
    provides: HandleStripeWebhookJob, StripeWebhookTest patterns (fakeStripeSignature, stripePost)
  - phase: 04-payment-creation-link-generation
    provides: PaymentController.index(), PaymentCreationTest beforeEach pattern
provides:
  - "RED test baseline for NOTIFY-01, NOTIFY-02 (4 test cases in NotificationTest.php)"
  - "RED test baseline for DASH-01, DASH-02, DASH-03, DASH-04 (9 test cases in PaymentDashboardTest.php)"
  - "Nyquist compliance: every wave 1 implementation task has a failing test to make green"
affects: [07-01, 07-02, 07-03]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "RefreshDatabase + forgetCachedPermissions() + Role::firstOrCreate() in beforeEach (inherited from phase 6)"
    - "fakeStripeSignature()/stripePost() global helpers reused from StripeWebhookTest (no redeclaration)"
    - "Queue::fake() for assertPushed/assertNotPushed job dispatch verification"
    - "Mail::fake() for assertQueued mailable testing with hasTo() closure assertions"
    - "Inertia assertInertia with AssertableInertia for prop existence and value checks"

key-files:
  created:
    - tests/Feature/NotificationTest.php
    - tests/Feature/PaymentDashboardTest.php
  modified: []

key-decisions:
  - "fakeStripeSignature() and stripePost() NOT redeclared in NotificationTest — they are already global functions in StripeWebhookTest.php; Pest loads all test files sharing the same Pest.php context"
  - "Mail::assertQueued() used (not assertSent()) for queued mailables per laravel skill guidelines"
  - "SendPaymentNotification::dispatchSync() used in test 3 to bypass queue and test mail dispatch synchronously"

patterns-established:
  - "Wave 0 RED-first pattern: test stubs created before any production code, suite must exit non-zero"
  - "Dashboard filter test pattern: create two payments, filter to one, assert count=1"
  - "Role-conditional props pattern: isAdmin/brands/accounts only sent to admin role"

requirements-completed:
  - NOTIFY-01
  - NOTIFY-02
  - DASH-01
  - DASH-02
  - DASH-03
  - DASH-04

# Metrics
duration: 20min
completed: 2026-05-14
---

# Phase 7 Plan 00: RED Test Stubs for Notifications + Dashboard Summary

**13 failing RED test stubs establishing Nyquist compliance baseline: 4 for notification job/mail dispatch (NOTIFY-01/02) and 9 for payment dashboard filter scopes and role-conditional Inertia props (DASH-01 through DASH-04)**

## Performance

- **Duration:** ~20 min
- **Started:** 2026-05-14T10:25:00Z
- **Completed:** 2026-05-14T10:45:26Z
- **Tasks:** 2
- **Files modified:** 2 created

## Accomplishments

- Created NotificationTest.php with 4 RED test cases covering: job dispatch on succeeded event, no dispatch on failed event, mail sent to all admins only (not users), and mailable HTML content assertions
- Created PaymentDashboardTest.php with 9 RED test cases covering: admin sees all payments, required columns present, status/brand/date range filters, brands/accounts/isAdmin props, user scoping, and filters prop echo
- Confirmed RED state: suite exits non-zero with 7 failures + 2 class-not-found errors across both files

## Task Commits

Each task was committed atomically:

1. **Task 1: Create NotificationTest.php with RED stubs** - `def222c` (test)
2. **Task 2: Create PaymentDashboardTest.php with RED stubs** - `edf2e96` (test)

**Plan metadata:** (committed with SUMMARY)

## Files Created/Modified

- `tests/Feature/NotificationTest.php` - 4 RED test cases for NOTIFY-01/02; references SendPaymentNotification job and PaymentSucceeded mailable (both non-existent)
- `tests/Feature/PaymentDashboardTest.php` - 9 RED test cases for DASH-01 through DASH-04; references filter query params and Inertia props (brands, accounts, isAdmin, filters) not yet added to PaymentController

## Decisions Made

- `fakeStripeSignature()` and `stripePost()` are NOT redeclared in NotificationTest.php — they are already declared as global functions in `StripeWebhookTest.php`. Pest's global function scope means redeclaration would cause a fatal error.
- `Mail::assertQueued()` used instead of `assertSent()` — per mail skill guidelines, queued mailables must use `assertQueued`. The `PaymentSucceeded` mailable will use `Mail::to($admin)->queue()`.
- `SendPaymentNotification::dispatchSync()` used in test 3 — this bypasses the queue to directly test the mail dispatch logic inside `handle()`, which is the correct pattern for testing ShouldQueue jobs synchronously.

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

- **Worktree environment** — vendor directory not present in git worktree (expected behavior; vendor is .gitignored). A Windows directory junction was created pointing the worktree's `vendor/` to the main repo's `vendor/`. Test RED verification confirmed via main repo artisan run using temporary file copies.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- Both RED test files are committed and ready for wave 1 (07-01) to turn GREEN
- Wave 1 (plan 07-01) will implement: `App\Jobs\SendPaymentNotification`, `App\Mail\PaymentSucceeded`, markdown email template, and dispatch hook in `HandleStripeWebhookJob`
- Wave 1 (plan 07-02) will implement: PaymentController filter scopes, new Inertia props (brands, accounts, isAdmin, filters)
- All 13 tests must pass after wave 1 completes

## Known Stubs

None — this plan creates test files only; no production code with stubs.

## Self-Check: PASSED

- FOUND: tests/Feature/NotificationTest.php
- FOUND: tests/Feature/PaymentDashboardTest.php
- FOUND: .planning/phases/07-notifications-dashboard/07-00-SUMMARY.md
- FOUND commit: def222c (test(07-00): add failing RED stubs for notification tests)
- FOUND commit: edf2e96 (test(07-00): add failing RED stubs for dashboard filter tests)

---
*Phase: 07-notifications-dashboard*
*Completed: 2026-05-14*
