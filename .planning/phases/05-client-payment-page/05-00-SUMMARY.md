---
phase: 05-client-payment-page
plan: "00"
subsystem: testing
tags: [tdd, stripe, mockery, pest, feature-tests]
dependency_graph:
  requires: []
  provides: [tests/Feature/ClientPaymentTest.php]
  affects: [ClientPaymentController, routes/web.php]
tech_stack:
  added: []
  patterns: [Mockery StripeClient binding via app()->bind(), PaymentIntent::constructFrom() for mock objects]
key_files:
  created:
    - tests/Feature/ClientPaymentTest.php
  modified:
    - phpunit.xml
decisions:
  - "Used PaymentIntent::constructFrom(['id'=>..., 'client_secret'=>...]) instead of direct property assignment — stripe-php v20 StripeObject.__set() restricts setting protected fields like 'id' directly"
  - "Fixed APP_BASE_PATH in phpunit.xml from old developer path (salmanabdul.ghani) to current environment (Salman) — Rule 3 blocker auto-fixed"
  - "mockStripeClient() defined as a global Pest helper function (not in beforeEach) so tests that don't touch the show() route (completed/cancelled guard, success, failed) don't incur unnecessary mock setup"
metrics:
  duration: "~4 minutes"
  completed_date: "2026-05-09"
  tasks_completed: 1
  tasks_total: 1
  files_created: 1
  files_modified: 1
requirements:
  - CLIENT-01
  - CLIENT-02
  - CLIENT-03
  - CLIENT-04
  - CLIENT-06
  - CLIENT-07
  - SEC-04
---

# Phase 5 Plan 00: ClientPaymentTest RED Stubs Summary

**One-liner:** 12 RED Pest feature tests for the client payment page using Mockery-bound StripeClient with `PaymentIntent::constructFrom()` mock pattern.

## What Was Built

`tests/Feature/ClientPaymentTest.php` — Wave 0 test contract for all Phase 5 requirements. All 12 tests fail in RED state because `ClientPaymentController` and public `/pay/*` routes do not yet exist.

The file establishes the `mockStripeClient()` helper that binds a Mockery mock of `StripeClient` into the Laravel container for the duration of each test that exercises the `show()` route. The mock returns a `PaymentIntent` stub with `id = 'pi_test_mock123'` and `client_secret = 'pi_test_mock123_secret_xyz'`.

## Test Coverage

| Test | Requirement | Expected Result |
|------|-------------|-----------------|
| guest can access pay route without authentication | CLIENT-01 | 200 OK, no auth redirect |
| unknown uuid returns 404 | CLIENT-01 | 404 (passes on stub route — correct) |
| brand props are passed to ClientPayment/Pay component | CLIENT-02 | Inertia component with brand.* props |
| clientSecret and publishable_key in props; secret_key absent | CLIENT-03 + CLIENT-04 | clientSecret + stripeAccount.publishable_key present; secret_key missing |
| stripe_payment_intent_id stored after page load | D-02 | DB record updated with pi_test_mock123 |
| completed payment renders Unavailable not Pay | D-03 | ClientPayment/Unavailable component |
| cancelled payment renders Unavailable | D-03 | ClientPayment/Unavailable component |
| success page renders when redirect_status=succeeded | CLIENT-06 | ClientPayment/Success component |
| success redirects to failed when redirect_status != succeeded | CLIENT-06 | Redirect to /pay/{uuid}/failed |
| failed page renders with brand props | CLIENT-07 | ClientPayment/Failed with brand.name |
| success controller does not re-expose client_secret | SEC-04 | Success page missing clientSecret prop |
| non-pending payment does not call StripeClient | D-03 | Unavailable, stripe_payment_intent_id stays null |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed PaymentIntent mock construction**
- **Found during:** Task 1 — running initial tests
- **Issue:** Plan's example used `$mockPi->id = 'pi_test_mock123'` which throws "Cannot set id on this object. HINT: you can't set: id" because `stripe-php` v20 `StripeObject.__set()` restricts setting certain protected fields via direct property assignment.
- **Fix:** Replaced with `PaymentIntent::constructFrom(['id' => 'pi_test_mock123', 'client_secret' => '...'])` which is the correct stripe-php API for constructing mock objects from data arrays.
- **Files modified:** `tests/Feature/ClientPaymentTest.php`
- **Commit:** 1f6b847d

**2. [Rule 3 - Blocking Issue] Fixed APP_BASE_PATH in phpunit.xml**
- **Found during:** Task 1 — first test run
- **Issue:** `phpunit.xml` had `APP_BASE_PATH` hardcoded to `C:\Users\salmanabdul.ghani\Herd\payhub` (old developer's machine path). Tests could not boot Laravel application, getting "Failed opening required bootstrap/app.php".
- **Fix:** Updated `APP_BASE_PATH` to `C:\Users\Salman\Herd\payhub` (current environment path).
- **Files modified:** `phpunit.xml`
- **Commit:** 1f6b847d

## Test Run Results (RED State Confirmed)

```
Tests: 12, Passed: 1, Failed: 11
```

The 1 passing test ("unknown uuid returns 404") correctly passes because the existing stub route `Route::get('/pay/{uuid}', fn () => abort(404))` returns 404 for all UUIDs — including non-existent ones. This test will remain passing after the real controller is wired.

All 11 remaining tests fail for implementation-absent reasons:
- 404 responses where 200 expected (routes don't exist yet)
- "Not a valid Inertia response" (controller not returning Inertia responses)

No syntax errors, no PHP parse failures.

## Known Stubs

None — this plan creates test infrastructure only, no application code with stub values.

## Threat Flags

None — test file only; no new network endpoints, auth paths, or schema changes introduced.

## Self-Check: PASSED

- [x] `tests/Feature/ClientPaymentTest.php` exists
- [x] `phpunit.xml` modified
- [x] Commit `1f6b847d` exists: `git log --oneline | grep 1f6b847d` → confirmed
- [x] 12 `it(` blocks present
- [x] `uses(RefreshDatabase::class)` present
- [x] `function mockStripeClient()` present
- [x] `Mockery::mock(StripeClient::class)` present
- [x] `php -l tests/Feature/ClientPaymentTest.php` exits clean
- [x] All 12 tests run (no parse errors), 11 fail RED as expected
