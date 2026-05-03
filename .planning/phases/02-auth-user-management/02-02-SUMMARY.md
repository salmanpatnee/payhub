---
phase: 02-auth-user-management
plan: "02"
subsystem: backend
tags: [routes, controller, form-requests, rbac, spatie-permissions, admin]
dependency_graph:
  requires:
    - "02-01: spatie role middleware aliases registered, registration disabled"
    - "02-00: Wave 0 test stubs for AdminUserManagementTest, AdminAccessControlTest, PublicPaymentRouteTest"
  provides:
    - "Admin resource routes under auth+verified+role:admin middleware"
    - "AdminUserController with full CRUD (index/create/store/edit/update/destroy)"
    - "StoreUserRequest with required name/email/password/role validation"
    - "UpdateUserRequest with nullable password and self-exclusion email unique rule"
    - "/pay/{uuid} stub outside all auth groups (D-07 Phase 5 prerequisite)"
  affects:
    - "routes/web.php"
    - "app/Http/Controllers/Admin/UserController.php"
    - "app/Http/Requests/Admin/StoreUserRequest.php"
    - "app/Http/Requests/Admin/UpdateUserRequest.php"
    - "tests/TestCase.php (withoutVite global fix)"
    - "tests/Feature/Auth/PublicPaymentRouteTest.php (assertNotRedirect fix)"
tech_stack:
  added: []
  patterns:
    - "Route::resource() with ->except(['show']) for admin CRUD"
    - "FormRequest authorize() using $this->user()->hasRole('admin') via spatie HasRoles"
    - "Self-delete guard in destroy(): $user->id === $request->user()->id"
    - "syncRoles([$role]) called after User::create() — role is NOT fillable"
    - "UpdateUserRequest unique:users,email,{$userId} excludes current user from uniqueness check"
    - "withoutVite() in TestCase::setUp() — required for Inertia page rendering in tests"
key_files:
  created:
    - app/Http/Controllers/Admin/UserController.php
    - app/Http/Requests/Admin/StoreUserRequest.php
    - app/Http/Requests/Admin/UpdateUserRequest.php
  modified:
    - routes/web.php
    - tests/TestCase.php
    - tests/Feature/Auth/PublicPaymentRouteTest.php
decisions:
  - "role is not in User Fillable — syncRoles() called explicitly after User::create() (T-02-02-03 mitigation)"
  - "withoutVite() added globally to TestCase::setUp() — all test classes benefit, no per-class setup needed"
  - "assertNotRedirect replaced with assertStatus(404) — assertNotRedirect does not exist in Laravel 13.7.0"
metrics:
  duration: "15 minutes"
  completed: "2026-05-03"
  tasks_completed: 2
  tasks_total: 2
  files_created: 3
  files_modified: 3
---

# Phase 2 Plan 02: Admin Routes + UserController + FormRequests Summary

**One-liner:** Admin resource routes under auth+verified+role:admin middleware, full CRUD AdminUserController with self-delete guard, and StoreUserRequest/UpdateUserRequest with role and unique-email validation.

## Tasks Completed

| # | Task | Commit | Files |
|---|------|--------|-------|
| 1 | Add admin route group and /pay/{uuid} stub to routes/web.php | 75855b14 | routes/web.php |
| 2 | Create AdminUserController, StoreUserRequest, and UpdateUserRequest | fd678136 | app/Http/Controllers/Admin/UserController.php, app/Http/Requests/Admin/StoreUserRequest.php, app/Http/Requests/Admin/UpdateUserRequest.php, tests/TestCase.php, tests/Feature/Auth/PublicPaymentRouteTest.php |

## What Was Built

**routes/web.php** — Two new route definitions added:
- Admin resource group: `Route::middleware(['auth', 'verified', 'role:admin'])->prefix('admin')->name('admin.')` with `Route::resource('users', AdminUserController::class)->except(['show'])` — generates 6 named routes (admin.users.index, .create, .store, .edit, .update, .destroy)
- Phase 5 stub: `Route::get('/pay/{uuid}', fn () => abort(404))->name('pay.show')` — outside all auth groups per D-07

**app/Http/Controllers/Admin/UserController.php** — Full CRUD controller:
- `index()` — loads users with roles, maps to {id, name, email, roles} ordered by name
- `create()` — returns Role::pluck('name') for role select
- `store()` — creates user via User::create(), calls syncRoles() explicitly (role is not fillable)
- `edit()` — returns user data + roles list
- `update()` — updates name/email always; password only if filled; syncRoles()
- `destroy()` — self-delete guard prevents admin from deleting own account

**app/Http/Requests/Admin/StoreUserRequest.php** — Validates: name (required), email (required, unique), password (required, Password::default()), role (required, in:admin,user). authorize() checks hasRole('admin').

**app/Http/Requests/Admin/UpdateUserRequest.php** — Same fields with: password (nullable), email unique rule excludes current user via `unique:users,email,{$userId}`.

## Verification Results

```
php artisan test --filter="AdminUserManagementTest|AdminAccessControlTest|PublicPaymentRouteTest"
10 tests | 10 passed
```

- AdminUserManagementTest: 5/5 (view list, create, update role, delete, cannot self-delete)
- AdminAccessControlTest: 3/3 (403 for user role, 302 for unauthenticated, 404 for /register)
- PublicPaymentRouteTest: 2/2 (returns 404, does not redirect to login)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical Functionality] Added withoutVite() to TestCase::setUp()**
- **Found during:** Task 2 verification
- **Issue:** `test_admin_can_view_user_list` returned HTTP 500 — Inertia renders full Blade template on non-Inertia requests; `@vite()` call fails because `admin/users/Index.vue` is not in the Vite manifest (page not built yet in Phase 2)
- **Fix:** Added `$this->withoutVite()` to `TestCase::setUp()` so all tests bypass Vite asset resolution
- **Files modified:** tests/TestCase.php
- **Commit:** fd678136

**2. [Rule 1 - Bug Fix] Replaced non-existent assertNotRedirect() in PublicPaymentRouteTest**
- **Found during:** Task 2 verification
- **Issue:** `Method Illuminate\Http\Response::assertNotRedirect does not exist` — Wave 0 stub used `assertNotRedirect()` which does not exist in Laravel 13.7.0's TestResponse class
- **Fix:** Replaced `$response->assertNotRedirect(route('login'))` with `$response->assertStatus(404)` — equivalent intent (stub returns 404, not a redirect to login)
- **Files modified:** tests/Feature/Auth/PublicPaymentRouteTest.php
- **Commit:** fd678136

## Known Stubs

- `app/Http/Controllers/Admin/UserController.php::index()` renders `admin/users/Index` Inertia page — Vue page component does not exist yet (created in Plan 02-03 or 02-04)
- `app/Http/Controllers/Admin/UserController.php::create()` renders `admin/users/Create` — same
- `app/Http/Controllers/Admin/UserController.php::edit()` renders `admin/users/Edit` — same
- `/pay/{uuid}` route returns abort(404) — full Phase 5 client payment page deferred to Phase 5

These stubs are intentional. The controller actions work correctly for CRUD operations (POST/PATCH/DELETE) and the Inertia page rendering stubs will be wired in the frontend plans.

## Threat Flags

All STRIDE threats from the threat model are mitigated:
- T-02-02-01/02: auth + role:admin middleware on all /admin/* routes
- T-02-02-03: role not in Fillable; syncRoles() called explicitly
- T-02-02-04: self-delete guard in destroy() returns withErrors without deleting
- T-02-02-05: UpdateUserRequest uses unique:users,email,{$userId}
- T-02-02-06: /pay/{uuid} outside all auth groups — verified by PublicPaymentRouteTest
- T-02-02-07: password cast on User model auto-hashes; no manual Hash::make() in controller

No new security surface introduced beyond the plan's threat model.

## Self-Check: PASSED

- app/Http/Controllers/Admin/UserController.php: FOUND
- app/Http/Requests/Admin/StoreUserRequest.php: FOUND
- app/Http/Requests/Admin/UpdateUserRequest.php: FOUND
- routes/web.php (admin routes): FOUND (php artisan route:list shows 6 admin routes)
- routes/web.php (pay.show route): FOUND
- Commit 75855b14: FOUND
- Commit fd678136: FOUND
