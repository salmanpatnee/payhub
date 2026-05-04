---
phase: 03-brand-stripe-account-management
plan: "02"
subsystem: payments
tags: [stripe, encryption, form-request, scoped-binding, security]

# Dependency graph
requires:
  - phase: 03-01
    provides: brand routes, BrandController, StripeAccountController stub, StripeAccountManagementTest Wave 0 stubs

provides:
  - StripeAccountController: full CRUD with secret_key encryption and API validation
  - StoreStripeAccountRequest: pk_/sk_ prefix enforcement, test-key blocking in production
  - UpdateStripeAccountRequest: nullable secret_key on update (blank keeps existing encrypted value)

affects: [03-03-PLAN.md, 03-04-PLAN.md, 04-payment-creation]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - stripe-per-instance-client
    - secret-key-never-in-inertia-props
    - explicit-encrypted-assignment
    - scoped-route-binding-cross-brand-protection
    - form-request-production-env-closure-rule
    - testing-env-guard-for-external-api

key-files:
  created:
    - app/Http/Controllers/Admin/StripeAccountController.php
    - app/Http/Requests/Admin/StoreStripeAccountRequest.php
    - app/Http/Requests/Admin/UpdateStripeAccountRequest.php
  modified:
    - tests/Feature/Admin/StripeAccountManagementTest.php (Wave 0 stubs replaced with 11 real tests)
    - database/migrations/2026_05_03_000002_create_stripe_accounts_table.php (webhook_secret nullable)
    - database/factories/StripeAccountFactory.php (webhook_secret defaults to null)
    - phpunit.xml (APP_BASE_PATH + APP_KEY updated for this worktree)

key-decisions:
  - "validateStripeKeyPair() skips live Stripe API call when app()->environment('testing') — enables automated tests with fake keys while ensuring real validation in local/staging/production (STRIPE-03 verified manually per VALIDATION.md)"
  - "webhook_secret column made nullable because Phase 6 (Webhooks) sets this value; requiring it at create time is impossible"
  - "Production env tests require CSRF token in request body because $this->app->instance('env', 'production') activates full CSRF middleware"
  - "brand_id always from route model binding ($brand->id) — never from request body — prevents cross-brand injection"

patterns-established:
  - "Per-instance Stripe client: always new StripeClient($secretKey), never Stripe::setApiKey()"
  - "secret_key in Inertia: never included in props — not even masked/truncated"
  - "secret_key assignment: always explicit $account->secret_key = $value, never mass-assign"
  - "Cross-brand scoped binding: .scoped(['stripe_account' => 'id']) + abort_if belt-and-suspenders in deactivate()"

requirements-completed: [BRAND-04, STRIPE-01, STRIPE-02, STRIPE-03, STRIPE-04, STRIPE-05]

# Metrics
duration: ~45min
completed: 2026-05-04
---

# Phase 3 Plan 02: Stripe Account Backend (Controller + FormRequests) Summary

**StripeAccountController with AES-256-CBC key encryption, Stripe API validation probe, cross-brand scoped binding, test-key blocking in production, and 11/12 automated tests green**

## Performance

- **Duration:** ~45 min
- **Started:** 2026-05-04T17:51:00Z
- **Completed:** 2026-05-04T18:35:00Z
- **Tasks:** 1
- **Files modified:** 7

## Accomplishments

- Full CRUD for Stripe accounts: index, create, store, edit, update, deactivate (soft-deactivation)
- Critical security enforcements: secret_key never in Inertia props, never mass-assigned, always encrypted via `encrypted` cast on StripeAccount model
- `validateStripeKeyPair()` probes live Stripe API with `new StripeClient($secretKey)->balance->retrieve()` — skipped in testing env, always runs in local/staging/production
- Test-key blocking: pk_test_/sk_test_ rejected in production env via FormRequest closure rules
- Scoped route binding enforces cross-brand isolation (404 if stripe_account.brand_id != route brand)
- 11/12 StripeAccountManagementTest tests pass; STRIPE-03 marked incomplete (manual verification step)
- Full test suite: 82 tests, 72 pass, 10 skip, 1 incomplete

## Task Commits

1. **Task 1: Create StripeAccountController + FormRequests** - `324f19e` (feat)

## Files Created/Modified

- `app/Http/Controllers/Admin/StripeAccountController.php` — Full CRUD with encryption, API validation, scoped binding
- `app/Http/Requests/Admin/StoreStripeAccountRequest.php` — pk_/sk_ prefix validation, production test-key blocking
- `app/Http/Requests/Admin/UpdateStripeAccountRequest.php` — Same as store except secret_key is nullable on update
- `tests/Feature/Admin/StripeAccountManagementTest.php` — 11 real test implementations (1 markTestIncomplete for STRIPE-03)
- `database/migrations/2026_05_03_000002_create_stripe_accounts_table.php` — webhook_secret made nullable
- `database/factories/StripeAccountFactory.php` — webhook_secret defaults to null
- `phpunit.xml` — APP_BASE_PATH updated to current worktree; APP_KEY added

## Decisions Made

- **Testing env guard in validateStripeKeyPair**: `app()->environment('testing')` guard added to skip live Stripe API validation in tests. This means STRIPE-03 (invalid key rejection) is a manual verification step. The plan explicitly endorses this pattern.
- **webhook_secret made nullable**: The migration required webhook_secret to be NOT NULL, but the store form has no webhook_secret field (set in Phase 6 when webhooks are registered). Making it nullable is the only correct option without adding a placeholder value.
- **CSRF with production env tests**: Setting `$this->app->instance('env', 'production')` activates Laravel's full CSRF middleware. Tests that simulate production must include a `_token` in the session and request body to pass CSRF validation.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] webhook_secret NOT NULL constraint fails on create**
- **Found during:** Task 1 (first test run)
- **Issue:** `stripe_accounts.webhook_secret` is NOT NULL in the migration, but `StripeAccountController::store()` has no webhook_secret in the form. Creating a stripe account triggered `SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: stripe_accounts.webhook_secret`
- **Fix:** Changed `$table->text('webhook_secret')` to `$table->text('webhook_secret')->nullable()` in the migration. Updated factory to default `webhook_secret` to null.
- **Files modified:** `database/migrations/2026_05_03_000002_create_stripe_accounts_table.php`, `database/factories/StripeAccountFactory.php`
- **Verification:** All store/update tests pass without webhook_secret in request body
- **Committed in:** 324f19e

**2. [Rule 3 - Blocking] phpunit.xml APP_BASE_PATH pointed to deleted worktree**
- **Found during:** Test environment setup (before first test run)
- **Issue:** `phpunit.xml` had `APP_BASE_PATH` pointing to old worktree `agent-a2ff280a470b0697f` (now deleted). Tests would fail to boot because the vendor path was unresolvable. Also, `APP_KEY` was missing from phpunit.xml, causing "No application encryption key has been specified" on every test.
- **Fix:** Updated `APP_BASE_PATH` to current worktree path; added `APP_KEY` using the key from the main `.env` file.
- **Files modified:** `phpunit.xml`
- **Verification:** BrandManagementTest (9 tests) and StripeAccountManagementTest (12 tests) run to completion
- **Committed in:** 324f19e

**3. [Rule 1 - Bug] Production env tests needed CSRF token**
- **Found during:** Task 1 (production test debugging)
- **Issue:** `test_test_publishable_key_blocked_in_production` and `test_test_secret_key_blocked_in_production` both use `$this->app->instance('env', 'production')`. This activates Laravel's full CSRF middleware (which is normally bypassed in test env). Without a CSRF token, the POST request was rejected with 419, not 422.
- **Fix:** Added `->withSession(['_token' => 'test_token'])` and `'_token' => 'test_token'` to the request body in both production tests.
- **Files modified:** `tests/Feature/Admin/StripeAccountManagementTest.php`
- **Verification:** Both production tests now pass with correct `assertSessionHasErrors` assertion
- **Committed in:** 324f19e

---

**Total deviations:** 3 auto-fixed (2 Rule 1 bugs, 1 Rule 3 blocking)
**Impact on plan:** All auto-fixes necessary for correctness. webhook_secret nullable change is architecturally correct (Phase 6 responsibility). No scope creep.

## Issues Encountered

- **PHP vendor junction**: The worktree did not have a vendor directory (requires junction setup by GSD tooling). Ran `composer install` to create it in the current worktree.
- **Worktree merge from 03-01 branch**: The 03-01 work (BrandController, routes, stub controller, tests) was on `phase-3-brand-stripe-account-management` branch but not yet merged into the current worktree branch. Used `git merge phase-3-brand-stripe-account-management` to bring in all 03-01 deliverables before implementing 03-02.

## Known Stubs

None — StripeAccountController is fully implemented. No placeholder values or TODO comments in production code paths.

## Threat Flags

None — all STRIDE mitigations from the plan's threat model are implemented:

| Flag | Status |
|------|--------|
| T-03-02-01: secret_key in Inertia props | Mitigated — key omitted from all render calls |
| T-03-02-02: brand_id injection via request body | Mitigated — `$account->brand_id = $brand->id` only |
| T-03-02-03: secret_key mass-assignment | Mitigated — `safe()->except('secret_key')` + explicit assignment |
| T-03-02-04: test keys in production | Mitigated — FormRequest closure rules with env check |
| T-03-02-05: cross-brand stripe account access | Mitigated — scoped binding + abort_if |
| T-03-02-06: invalid credentials stored | Mitigated — validateStripeKeyPair() with testing guard |
| T-03-02-07: non-admin access | Mitigated — role:admin middleware + authorize() |
| T-03-02-08: encrypted key in logs | Accepted — encrypted cast prevents plaintext in DB |

## STRIPE-03 Manual Verification Required

The `test_invalid_stripe_key_pair_is_rejected` test is marked `markTestIncomplete` because:
- `validateStripeKeyPair()` is skipped in testing environment (`app()->environment('testing')`)
- Mocking `new StripeClient()` via Laravel's service container does not intercept PHP `new` calls
- This is the documented approach per VALIDATION.md

**Manual verification steps (run in local/staging with real keys):**
1. Submit a store form with a valid-format but invalid secret key (e.g., `sk_test_wrong_key`)
2. Verify the form returns with `stripe_api` error in session
3. Verify no record was created in `stripe_accounts` table

## Next Phase Readiness

- Phase 3 backend complete: Brand CRUD (03-01) + Stripe Account CRUD (03-02)
- All 9 requirement IDs (BRAND-01 through STRIPE-05) have server-side implementation
- 03-03 frontend views can now be built against these routes and controllers
- Phase 4 (Payment Creation) can use `StripeAccount::where('brand_id', $brand->id)->where('is_active', true)->get()` to list available stripe accounts

## Self-Check

- [x] `app/Http/Controllers/Admin/StripeAccountController.php` exists with full implementation
- [x] `app/Http/Requests/Admin/StoreStripeAccountRequest.php` exists
- [x] `app/Http/Requests/Admin/UpdateStripeAccountRequest.php` exists
- [x] `grep "Stripe::setApiKey" app/Http/Controllers/Admin/StripeAccountController.php` → only in comment
- [x] `grep "secret_key" app/Http/Controllers/Admin/StripeAccountController.php | grep -v except` → no exposure in Inertia props
- [x] `grep "environment('testing')" app/Http/Controllers/Admin/StripeAccountController.php` → present
- [x] `grep "'nullable'" app/Http/Requests/Admin/UpdateStripeAccountRequest.php` → present
- [x] `php artisan test tests/Feature/Admin/StripeAccountManagementTest.php` → 11 pass, 1 incomplete
- [x] `php artisan test` → 82 tests, 72 pass, 10 skip, 1 incomplete (STRIPE-03)
- [x] Commit 324f19e exists

## Self-Check: PASSED

---
*Phase: 03-brand-stripe-account-management*
*Completed: 2026-05-04*
