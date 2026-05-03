---
phase: 02-auth-user-management
plan: "00"
subsystem: testing
tags: [wave-0, tdd-stubs, auth, spatie-permissions, pest]
dependency_graph:
  requires: []
  provides:
    - "Wave 0 test contracts for AUTH-02, AUTH-04, AUTH-05, AUTH-06"
    - "Automated verify commands for all Phase 2 auth tasks"
  affects:
    - "tests/Feature/Auth/ (4 new stub files)"
tech_stack:
  added: []
  patterns:
    - "PHPUnit class-style test files with RefreshDatabase"
    - "Spatie permission cache clearing in setUp() before every test class"
    - "Role::firstOrCreate() for idempotent role seeding after RefreshDatabase wipe"
key_files:
  created:
    - tests/Feature/Auth/AdminUserManagementTest.php
    - tests/Feature/Auth/AdminAccessControlTest.php
    - tests/Feature/Auth/SessionPersistenceTest.php
    - tests/Feature/Auth/PublicPaymentRouteTest.php
  modified: []
decisions:
  - "Wave 0 stubs use PHPUnit class-style to match existing test files (not Pest function syntax)"
  - "All four stubs include forgetCachedPermissions() + Role::firstOrCreate() in setUp() per Spatie pitfall documentation"
  - "Route-missing errors are expected Wave 0 behavior — tests will turn green when Wave 1/2 deliver implementation"
metrics:
  duration: "3 minutes"
  completed: "2026-05-03"
  tasks_completed: 2
  tasks_total: 2
  files_created: 4
  files_modified: 0
---

# Phase 2 Plan 00: Wave 0 Auth Test Stubs Summary

**One-liner:** Four Pest/PHPUnit Wave 0 test stub files defining the full AUTH-02/04/05/06 test contract with spatie permission cache clearing, ready to turn green when Wave 1 implementation lands.

## Tasks Completed

| # | Task | Commit | Files |
|---|------|--------|-------|
| 1 | Create AdminUserManagementTest and AdminAccessControlTest stubs | f5c124c4 | tests/Feature/Auth/AdminUserManagementTest.php, tests/Feature/Auth/AdminAccessControlTest.php |
| 2 | Create SessionPersistenceTest and PublicPaymentRouteTest stubs | d21606c4 | tests/Feature/Auth/SessionPersistenceTest.php, tests/Feature/Auth/PublicPaymentRouteTest.php |

## What Was Built

Four Wave 0 test stub files in `tests/Feature/Auth/` that define the complete test contract for Phase 2 auth requirements:

**AdminUserManagementTest** (AUTH-04 — 5 test methods):
- `test_admin_can_view_user_list` — GET admin.users.index returns 200
- `test_admin_can_create_user` — POST admin.users.store creates user in DB
- `test_admin_can_update_user_role` — PATCH admin.users.update syncs role
- `test_admin_can_delete_user` — DELETE admin.users.destroy removes from DB
- `test_admin_cannot_delete_own_account` — DELETE own account returns session error

**AdminAccessControlTest** (AUTH-05 — 3 test methods):
- `test_non_admin_user_gets_403_on_admin_users_index` — assertForbidden
- `test_unauthenticated_request_to_admin_users_redirects_to_login` — assertRedirect(login)
- `test_registration_is_disabled_get_register_returns_not_found` — assertNotFound

**SessionPersistenceTest** (AUTH-02 — 2 test methods):
- `test_user_session_persists_with_remember_token` — remember_token set after login with remember=true
- `test_authenticated_user_can_reach_dashboard_across_requests` — actingAs + assertOk

**PublicPaymentRouteTest** (AUTH-06 — 2 test methods):
- `test_pay_route_is_reachable_without_authentication` — assertNotFound (not 302)
- `test_pay_route_does_not_redirect_guest_to_login` — assertNotRedirect(login)

## Verification Results

```
php artisan test --filter=Auth
38 tests | 28 passed | 0 PHP parse errors
```

Failures are all expected Wave 0 behavior:
- 7 errors: `Route [admin.users.*] not defined` — implementation in Wave 1
- 2 errors: `Route [pay.show] not defined` — implementation in Wave 3 (Phase 5)
- 1 failure: registration returns 200 instead of 404 — registration disable in Wave 1

SessionPersistenceTest: 2/2 passed (login + remember token already work via existing Fortify setup).

## Deviations from Plan

None — plan executed exactly as written. All four files match the exact PHP templates specified in the plan.

## Known Stubs

None — these files are themselves the stubs (Wave 0 contracts). All test methods reference real routes/behaviors that will exist post-Wave 1 implementation.

## Threat Flags

No new security surface introduced. Test files only.

## Self-Check: PASSED

- tests/Feature/Auth/AdminUserManagementTest.php: FOUND
- tests/Feature/Auth/AdminAccessControlTest.php: FOUND
- tests/Feature/Auth/SessionPersistenceTest.php: FOUND
- tests/Feature/Auth/PublicPaymentRouteTest.php: FOUND
- Commit f5c124c4: FOUND
- Commit d21606c4: FOUND
