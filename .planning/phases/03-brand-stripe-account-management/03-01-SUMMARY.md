---
phase: 03-brand-stripe-account-management
plan: "01"
subsystem: brand-backend
tags: [wave-1, brand-crud, controller, form-requests, logo-upload, routes]
dependency_graph:
  requires: [03-00]
  provides: [brand-backend-routes, brand-crud-api, brand-form-validation]
  affects: [03-02-PLAN.md, 03-03-PLAN.md, 03-04-PLAN.md]
tech_stack:
  added: []
  patterns:
    - resource-route-except-show-destroy
    - unique-slug-generation
    - public-disk-logo-upload
    - old-file-deletion-on-update
    - form-request-authorize-role-check
    - hex-color-regex-validation
    - file-type-and-size-validation
key_files:
  created:
    - routes/web.php (modified — placeholder removed, Brand + StripeAccount routes added)
    - app/Http/Controllers/Admin/BrandController.php
    - app/Http/Controllers/Admin/StripeAccountController.php (stub — full impl in 03-02)
    - app/Http/Requests/Admin/StoreBrandRequest.php
    - app/Http/Requests/Admin/UpdateBrandRequest.php
    - tests/Feature/Admin/BrandManagementTest.php (Wave 0 stubs replaced with real tests)
  modified:
    - phpunit.xml (APP_BASE_PATH added for worktree vendor junction resolution)
decisions:
  - "StripeAccountController stub created alongside BrandController — Laravel inferBasePath() via vendor junction required all referenced route controllers to exist at test bootstrap time, not just request time"
  - "APP_BASE_PATH added to phpunit.xml so Laravel inferBasePath() resolves worktree root correctly via vendor junction"
  - "generateUniqueSlug uses suffix counter (acme → acme-1 → acme-2) to handle duplicate names"
  - "Logo stored using UploadedFile::store() with random filename — prevents path traversal"
metrics:
  duration: "~30 minutes"
  completed_date: "2026-05-04"
  tasks_completed: 2
  files_created: 5
  files_modified: 2
---

# Phase 3 Plan 01: Brand Backend (Routes + Controller + Form Requests) Summary

Brand CRUD backend complete: resource routes registered in role:admin group, BrandController with full index/create/store/edit/update cycle, StoreBrandRequest + UpdateBrandRequest with hex color regex and 2MB file type validation, logo upload to public disk with old-file deletion on update. All 9 BrandManagementTest tests pass.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Fix routes/web.php — remove placeholder, add Brand resource route | 3b621b1 | routes/web.php |
| 2 | Create BrandController + StoreBrandRequest + UpdateBrandRequest | d8e41f0 | app/Http/Controllers/Admin/BrandController.php, app/Http/Requests/Admin/StoreBrandRequest.php, app/Http/Requests/Admin/UpdateBrandRequest.php, app/Http/Controllers/Admin/StripeAccountController.php (stub), tests/Feature/Admin/BrandManagementTest.php, phpunit.xml |

## What Was Built

**routes/web.php** — Two changes:
- Removed `Route::inertia('/admin/brands', 'placeholders/ComingSoon')` placeholder
- Added `Route::resource('brands', BrandController::class)->except(['show', 'destroy'])` inside role:admin group
- Added `Route::resource('brands.stripe-accounts', StripeAccountController::class)` nested resource (forward-compat with 03-02)
- Added `PATCH brands/{brand}/stripe-accounts/{stripe_account}/deactivate` route

**BrandController.php** — Full CRUD:
- `index()`: Returns Inertia response with brand list + stripe_accounts_count, logo_url via Storage::disk('public')
- `create()`: Returns Inertia create form page
- `store()`: Validates via StoreBrandRequest, auto-generates slug, stores logo on public disk
- `edit()`: Returns Inertia edit form with current brand data
- `update()`: Validates via UpdateBrandRequest, deletes old logo before storing new one
- `generateUniqueSlug()`: Suffix counter for duplicate names (acme → acme-1 → acme-2)

**StoreBrandRequest.php + UpdateBrandRequest.php** — Identical validation:
- `authorize()`: `$this->user()->hasRole('admin')` — dual-layer access control (middleware + FormRequest)
- `name`: required string max:255
- `logo`: nullable, File::types(['jpg','jpeg','png','webp','svg']), max 2MB
- `primary_color` + `secondary_color`: required, regex `/^#[0-9a-fA-F]{6}$/` — named colors like "red" rejected

**StripeAccountController.php (stub)** — Empty stub with all method signatures. Created because Laravel's `Application::inferBasePath()` uses `ClassLoader::getRegisteredLoaders()` to find basePath via the vendor junction, causing it to resolve to the MAIN project. This in turn means all referenced route controller classes must exist at route-registration time during tests — even those scheduled for 03-02.

**BrandManagementTest.php** — All 9 Wave 0 stubs replaced with real assertions:
- test_admin_can_view_brand_list: assertOk
- test_admin_can_create_brand_without_logo: assertDatabaseHas slug
- test_admin_can_create_brand_with_logo: Storage::fake, assertExists
- test_brand_slug_is_auto_generated_from_name: slug acme-corp
- test_admin_can_update_brand: assertDatabaseHas new name
- test_old_logo_deleted_when_new_logo_uploaded: assertMissing old logo
- test_logo_preserved_when_no_new_logo_submitted: assertDatabaseHas logo_path unchanged
- test_non_admin_cannot_access_brand_list: assertForbidden
- test_primary_color_must_be_valid_hex: assertSessionHasErrors

## Verification Results

```
php artisan route:list --name=admin.brands
→ 5 brand routes: admin.brands.index, admin.brands.create, admin.brands.store, admin.brands.edit, admin.brands.update
→ NO admin.brands.show or admin.brands.destroy

php artisan test tests/Feature/Admin/BrandManagementTest.php
→ tests: 9, passed: 9 (exit 0)

grep "ComingSoon" routes/web.php
→ No brands ComingSoon found

grep "Storage::disk('public')" app/Http/Controllers/Admin/BrandController.php
→ 3 occurrences (index logo_url, store logo upload, update logo delete + upload)
```

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] StripeAccountController stub required for route registration**
- **Found during:** Task 2 verification (test run)
- **Issue:** `Route::resource('brands.stripe-accounts', StripeAccountController::class)` in routes/web.php causes Laravel's test bootstrap to fail with `ReflectionException: Class "App\Http\Controllers\Admin\StripeAccountController" does not exist`. The plan states "Laravel resolves controller classes at request time, not at route registration time" — this is true for HTTP requests but NOT for `Application::inferBasePath()` which uses `ClassLoader::getRegisteredLoaders()`, causing it to use the MAIN project vendor path. The test bootstrap then tries to reflect all route controllers.
- **Fix:** Created `app/Http/Controllers/Admin/StripeAccountController.php` as an empty stub with all method signatures. Will be fully implemented in Plan 03-02.
- **Files modified:** `app/Http/Controllers/Admin/StripeAccountController.php` (new file)
- **Commit:** d8e41f0

**2. [Rule 3 - Blocking] APP_BASE_PATH required in phpunit.xml for worktree vendor junction**
- **Found during:** Task 2 verification (all 9 tests failing with "Route not defined")
- **Issue:** `Application::inferBasePath()` uses `ClassLoader::getRegisteredLoaders()` — the first registered classloader path is the vendor junction target (`C:\...\payhub\vendor`), so `dirname()` returns the MAIN project root. The test bootstrap then loads `bootstrap/app.php` from the MAIN project, which loads the MAIN project's `routes/web.php` (without Brand routes). Adding `APP_BASE_PATH` to phpunit.xml overrides this by setting `$_ENV['APP_BASE_PATH']` before `inferBasePath()` is called.
- **Fix:** Added `<env name="APP_BASE_PATH" value="C:\...\agent-a2ff280a470b0697f"/>` to `phpunit.xml`
- **Files modified:** `phpunit.xml`
- **Commit:** d8e41f0

### Pre-existing Worktree Test Failures (Out of Scope)

9 tests fail with `Unknown format "company"` in the full suite run:
- EncryptionRoundTripTest (2 tests)
- ModelRelationshipsTest (4 tests)
- PaymentAmountIntegrityTest (3 tests)

These are pre-existing failures documented in 03-00 SUMMARY. They are caused by the worktree path interfering with Pest's function-style class namespace resolution. They are NOT caused by this plan's changes.

## Known Stubs

- `app/Http/Controllers/Admin/StripeAccountController.php` — All methods return null. Full implementation in Plan 03-02.

## Threat Flags

None beyond what the threat model covers. All STRIDE mitigations in the plan's threat register are implemented:
- T-03-01-01: `role:admin` middleware + `authorize()` in FormRequest — both applied
- T-03-01-02: `UploadedFile::store()` random filename + `File::types()` restriction + 2MB max
- T-03-01-03: `regex:/^#[0-9a-fA-F]{6}$/` in both FormRequests
- T-03-01-04: `Storage::disk('public')->url($path)` — server-assigned path only
- T-03-01-05: `generateUniqueSlug()` — user cannot supply slug

## Self-Check: PASSED

- [x] `routes/web.php` has Brand + StripeAccount routes, no ComingSoon brands placeholder
- [x] `app/Http/Controllers/Admin/BrandController.php` exists
- [x] `app/Http/Controllers/Admin/StripeAccountController.php` exists (stub)
- [x] `app/Http/Requests/Admin/StoreBrandRequest.php` exists
- [x] `app/Http/Requests/Admin/UpdateBrandRequest.php` exists
- [x] `tests/Feature/Admin/BrandManagementTest.php` exists (9 real tests)
- [x] Commit 3b621b1 exists: `feat(03-01): register Brand + StripeAccount resource routes in admin group`
- [x] Commit d8e41f0 exists: `feat(03-01): add BrandController, StoreBrandRequest, UpdateBrandRequest + green tests`
- [x] `php artisan test tests/Feature/Admin/BrandManagementTest.php` → 9/9 pass
- [x] `php artisan route:list --name=admin.brands` → 5 brand routes shown
