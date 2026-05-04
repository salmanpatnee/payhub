---
phase: 03-brand-stripe-account-management
plan: "00"
subsystem: test-infrastructure
tags: [wave-0, tdd, stubs, brand-management, stripe-account-management]
dependency_graph:
  requires: [02-auth-user-management]
  provides: [brand-management-test-contract, stripe-account-management-test-contract]
  affects: [03-01-PLAN.md, 03-02-PLAN.md, 03-03-PLAN.md, 03-04-PLAN.md]
tech_stack:
  added: []
  patterns: [wave-0-stub-pattern, markTestIncomplete, forgetCachedPermissions-setUp]
key_files:
  created:
    - tests/Feature/Admin/BrandManagementTest.php
    - tests/Feature/Admin/StripeAccountManagementTest.php
  modified: []
decisions:
  - "STRIPE-02 (encrypted cast) not duplicated in StripeAccountManagementTest — already covered by EncryptionRoundTripTest"
  - "All 21 stubs use markTestIncomplete() so PHPUnit marks them I (incomplete) not F (failed), keeping full suite green"
  - "vendor junction created via PowerShell New-Item -ItemType Junction to allow running artisan test from worktree"
metrics:
  duration: "~15 minutes"
  completed_date: "2026-05-04"
  tasks_completed: 2
  files_created: 2
  files_modified: 0
---

# Phase 3 Plan 00: Wave 0 Test Stubs for Brand + Stripe Account Management Summary

Wave 0 Nyquist contract satisfied — 21 test method stubs across 2 test files define the full test surface for Brand CRUD and Stripe Account management before any Wave 1 backend code is written.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Create BrandManagementTest stubs (BRAND-01, BRAND-02, BRAND-03) | 798f92b | tests/Feature/Admin/BrandManagementTest.php |
| 2 | Create StripeAccountManagementTest stubs (BRAND-04, STRIPE-01, STRIPE-03, STRIPE-04, STRIPE-05) | 3a358b6 | tests/Feature/Admin/StripeAccountManagementTest.php |

## What Was Built

**BrandManagementTest.php** — 9 stubs covering:
- BRAND-03: Admin can view brand list
- BRAND-01: Admin can create brand without logo, with logo, auto-generated slug
- BRAND-02: Admin can update brand, old logo deleted on new upload, logo preserved without new upload
- Access control: non-admin blocked from brand routes
- Validation: primary_color must be valid hex

**StripeAccountManagementTest.php** — 12 stubs covering:
- STRIPE-01: Admin can view, create stripe accounts; brand_id from route binding
- STRIPE-04: Admin can update account without changing key; update with new key
- STRIPE-05: Admin can deactivate account (soft, not deleted); deactivated record persists in DB
- BRAND-04: Test-mode publishable key blocked in production; test-mode secret key blocked in production
- STRIPE-03: Invalid key pair rejected (notes StripeClient mock requirement)
- Access control: non-admin blocked from stripe account routes
- Scoped binding: cannot access stripe account from wrong brand

Both files:
- Use exact `setUp()` + `adminUser()` pattern from `AdminUserManagementTest`
- Include `forgetCachedPermissions()` to prevent spatie permission cache contamination
- Use `RefreshDatabase` trait for test isolation
- All stubs use `$this->markTestIncomplete(...)` per Wave 0 contract

## Verification Results

```
php artisan test tests/Feature/Admin/BrandManagementTest.php
→ tests: 9, passed: 9, incomplete: 9 (exit 0)

php artisan test tests/Feature/Admin/StripeAccountManagementTest.php
→ tests: 12, passed: 12, incomplete: 12 (exit 0)

php artisan test tests/Feature/Admin/
→ tests: 21, passed: 21, incomplete: 21 (exit 0)
```

## Deviations from Plan

### Infrastructure Setup

**[Rule 3 - Blocking] Worktree vendor symlink required**
- **Found during:** Task 1 verification
- **Issue:** Worktree directory (`agent-aea5799c86fad24af`) has no `vendor/` directory — artisan fails with `Failed to open stream: vendor/autoload.php`
- **Fix:** Created Windows junction link via `PowerShell New-Item -ItemType Junction` pointing worktree `vendor/` to main project `vendor/`; also copied `.env` file from main project to worktree
- **Files modified:** None (infrastructure only)
- **Commit:** N/A (infrastructure setup, not tracked)

### Pre-existing Worktree Test Failures (Out of Scope)

9 pre-existing tests fail when running the full suite from the worktree:
- `EncryptionRoundTripTest` (2 tests)
- `ModelRelationshipsTest` (4 tests)
- `PaymentAmountIntegrityTest` (3 tests)

All fail with `Unknown format "company"` (faker `company()` method unavailable in Pest `it()` function-style tests within the worktree context). Root cause: the worktree path `agent-aea5799c86fad24af` appears to interfere with Pest's class namespace resolution in its function-style runner, causing faker providers to not be registered.

**These failures pre-exist my changes.** The same tests pass in the main project (`/Herd/payhub`). My new test files (PHPUnit class-style, not Pest function-style) are not affected and run cleanly. Deferred to `deferred-items.md`.

## Known Stubs

All 21 test methods are intentional stubs. They use `markTestIncomplete()` per the Wave 0 Nyquist contract — Wave 1 backend plans will implement the bodies. These stubs do NOT prevent the plan's goal (defining the test contract) from being achieved.

## Threat Flags

None — this plan creates test infrastructure only. No production code, no network endpoints, no auth paths, no schema changes.

## Self-Check: PASSED

- [x] `tests/Feature/Admin/BrandManagementTest.php` exists
- [x] `tests/Feature/Admin/StripeAccountManagementTest.php` exists
- [x] Commit 798f92b exists: `test(03-00): add BrandManagementTest Wave 0 stubs`
- [x] Commit 3a358b6 exists: `test(03-00): add StripeAccountManagementTest Wave 0 stubs`
- [x] Both test files run cleanly (exit 0) with all stubs marked incomplete
