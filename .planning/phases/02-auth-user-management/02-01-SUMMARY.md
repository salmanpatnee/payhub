---
phase: 02-auth-user-management
plan: "01"
subsystem: auth-config
tags: [fortify, spatie-permission, inertia, middleware]
dependency_graph:
  requires:
    - 02-00 (Wave 0 test stubs)
    - 01-02 (Foundation schema + spatie/laravel-permission installed)
  provides:
    - spatie role/permission/role_or_permission middleware aliases registered
    - Fortify registration + emailVerification disabled
    - auth.user shared as plain array with roles to all Inertia pages
  affects:
    - routes/web.php (role:admin middleware now usable)
    - All Vue components reading page.props.auth.user
tech_stack:
  added: []
  patterns:
    - spatie middleware alias registration via $middleware->alias() in bootstrap/app.php
    - Explicit Inertia prop shape (plain array instead of raw Eloquent model)
    - Ternary null-guard on $request->user() before array destructure
key_files:
  created: []
  modified:
    - bootstrap/app.php
    - config/fortify.php
    - app/Http/Middleware/HandleInertiaRequests.php
decisions:
  - "Used FQCN strings in $middleware->alias() — no new use statements needed"
  - "getRoleNames() returns Collection; Inertia serializes it as a JSON array automatically"
  - "Commented-out features lines kept with explanation comments per plan spec"
metrics:
  duration: "~3 minutes"
  completed: "2026-05-03"
  tasks_completed: 2
  tasks_total: 2
  files_modified: 3
  files_created: 0
  tests_passing: 6
  deviations: 0
---

# Phase 2 Plan 01: Auth Config Foundation Summary

**One-liner:** Spatie middleware aliases registered, Fortify registration disabled, and Inertia auth.user narrowed to plain array with getRoleNames() — three pure config changes that unlock all Phase 2 auth infrastructure.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Register spatie aliases + disable Fortify registration | 106396c2 | bootstrap/app.php, config/fortify.php |
| 2 | Share auth.user as plain array with roles | a5a181c7 | app/Http/Middleware/HandleInertiaRequests.php |

## What Was Built

**Task 1 — bootstrap/app.php + config/fortify.php:**

Added spatie permission middleware aliases inside the single existing `->withMiddleware()` closure using `$middleware->alias([...])`. Three aliases registered: `role`, `permission`, `role_or_permission` pointing to their respective `\Spatie\Permission\Middleware\*` classes via FQCN — no new `use` imports needed.

In `config/fortify.php`, commented out `Features::registration()` (D-01: invite-only, no public sign-up) and `Features::emailVerification()` (internal tool, simplifies test setup). `Features::resetPasswords()` and `Features::twoFactorAuthentication()` remain active. The `/register` route is now absent from `php artisan route:list`.

**Task 2 — HandleInertiaRequests.php:**

Replaced the raw `$request->user()` Eloquent model share with an explicit plain array:
```php
'user' => $request->user() ? [
    'id'    => $request->user()->id,
    'name'  => $request->user()->name,
    'email' => $request->user()->email,
    'roles' => $request->user()->getRoleNames(),
] : null,
```

This satisfies threat T-02-01-02: fields like `two_factor_secret`, `remember_token`, and `password` are never serialized to page props. The null-guard handles unauthenticated pages correctly. `getRoleNames()` returns a Spatie Collection that Inertia serializes as a JSON array.

## Verification Results

- `php artisan test --filter=AuthenticationTest`: 6/6 passed
- `php artisan route:list | grep register`: no output (correct — registration disabled)
- `grep -c "withMiddleware" bootstrap/app.php`: 1 (single closure, no duplicates)
- `grep -n "Features::registration" config/fortify.php`: line is commented out
- `grep -n "Features::emailVerification" config/fortify.php`: line is commented out
- `grep -c "getRoleNames" app/Http/Middleware/HandleInertiaRequests.php`: 1

Other Auth filter tests (AdminUserManagementTest, AdminAccessControlTest, PublicPaymentRouteTest) fail with "Route not defined" — expected at this stage per plan spec; routes/controllers are built in subsequent plans.

## Deviations from Plan

None — plan executed exactly as written.

## Known Stubs

None — this plan makes no data stubs. Auth.user roles array is live data from the database via spatie getRoleNames().

## Threat Surface Scan

All four threats in the plan's threat register are mitigated:
- T-02-01-01: Registration endpoint — `Features::registration()` removed; no GET/POST /register route
- T-02-01-02: Inertia auth.user serialization — raw model replaced with explicit array
- T-02-01-03: spatie middleware not registered — aliases now registered before any route groups
- T-02-01-04: emailVerification bypass — `Features::emailVerification()` removed

No new threat surface introduced.

## Self-Check: PASSED

- `bootstrap/app.php`: exists, contains RoleMiddleware alias
- `config/fortify.php`: exists, registration/emailVerification commented out
- `app/Http/Middleware/HandleInertiaRequests.php`: exists, contains getRoleNames()
- Commit 106396c2: verified in git log
- Commit a5a181c7: verified in git log
