---
phase: "06-webhooks-status-sync"
plan: "00"
subsystem: "webhooks"
tags: [tdd, red-stubs, webhooks, stripe]
dependency_graph:
  requires: []
  provides: ["tests/Feature/StripeWebhookTest.php", "fakeStripeSignature()"]
  affects: ["06-01", "06-02", "06-03"]
tech_stack:
  added: []
  patterns: ["Pest test stubs with markTestIncomplete", "fakeStripeSignature HMAC-SHA256 helper"]
key_files:
  created:
    - tests/Feature/StripeWebhookTest.php
  modified:
    - phpunit.xml
decisions:
  - "All 11 Wave 0 stubs use markTestIncomplete('stub') — pure RED contract with zero implementation"
  - "fakeStripeSignature() uses hash_hmac sha256 with t=,v1= format matching Stripe's constructEvent requirement"
  - "phpunit.xml APP_BASE_PATH corrected from Salman to salmanabdul.ghani (pre-existing path mismatch)"
metrics:
  duration: "~10 minutes"
  completed: "2026-05-12"
  tasks_completed: 1
  files_modified: 2
---

# Phase 6 Plan 00: Wave 0 Webhook Test Stubs Summary

**One-liner:** 11 RED Pest test stubs with HMAC-SHA256 fakeStripeSignature() helper covering WEBHOOK-01..06, SEC-03, and D-04 requirements.

## What Was Built

Created `tests/Feature/StripeWebhookTest.php` as the Wave 0 test contract for Phase 6. The file contains:

1. `fakeStripeSignature(string $payload, string $secret): string` — global helper that builds a valid Stripe-Signature header using `hash_hmac('sha256', "{timestamp}.{payload}", $secret)` in `t={timestamp},v1={hmac}` format.

2. Standard test setup: `uses(RefreshDatabase::class)`, `beforeEach()` with `forgetCachedPermissions()` and `Role::firstOrCreate()` for admin/user roles.

3. Exactly 11 `it()` stub test cases, each calling `$this->markTestIncomplete('stub')`.

## Test Coverage Map

| Test | Requirement |
|------|-------------|
| GET returns 405 | WEBHOOK-01 |
| Unknown account returns 404 | WEBHOOK-01 |
| Missing Stripe-Signature returns 400 | WEBHOOK-02 |
| Tampered signature returns 400 | WEBHOOK-02 |
| Valid signature returns 200 | WEBHOOK-02 |
| payment_intent.succeeded dispatches job + 200 | WEBHOOK-06 |
| payment_intent.succeeded sets completed + paid_at | WEBHOOK-03 |
| payment_intent.payment_failed sets failed | WEBHOOK-04 |
| Already completed not re-processed | WEBHOOK-05 |
| No CSRF on webhook route | SEC-03 |
| Blank webhook_secret preserves existing | D-04 |

## Commits

| Task | Commit | Description |
|------|--------|-------------|
| Task 1: RED stubs | 9a1edc0 | test(06-00): add 11 RED stub tests for Phase 6 webhook requirements |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Fixed phpunit.xml APP_BASE_PATH path mismatch**
- **Found during:** Task 1 verification
- **Issue:** `APP_BASE_PATH` was `C:\Users\Salman\Herd\payhub` but actual path is `C:\Users\salmanabdul.ghani\Herd\payhub` — caused `Failed opening required bootstrap/app.php` errors in ALL tests (pre-existing environment mismatch)
- **Fix:** Updated `phpunit.xml` `APP_BASE_PATH` value to match the actual user directory
- **Files modified:** phpunit.xml
- **Commit:** 9a1edc0

## Verification Results

```
php artisan test --compact --filter=StripeWebhookTest
# Result: 11 tests, 11 incomplete (RED confirmed)
grep -c "markTestIncomplete" tests/Feature/StripeWebhookTest.php
# Result: 11
```

Acceptance criteria all satisfied:
- `markTestIncomplete` count: 11
- `fakeStripeSignature` function defined
- `uses(RefreshDatabase::class)` present
- `forgetCachedPermissions()` present
- Tests exit with non-zero (incomplete = RED)

## Known Stubs

All 11 tests are intentional stubs. Wave 1 (06-01, 06-02) will turn them GREEN.

## Self-Check: PASSED

- [x] `tests/Feature/StripeWebhookTest.php` exists
- [x] Commit 9a1edc0 exists in git log
- [x] 11 `markTestIncomplete` calls verified
- [x] `fakeStripeSignature()` function verified
