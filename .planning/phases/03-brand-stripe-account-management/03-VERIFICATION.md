---
phase: 03-brand-stripe-account-management
verified: 2026-05-04T00:00:00Z
status: human_needed
score: 9/10 must-haves verified
overrides_applied: 0
human_verification:
  - test: "Submit a Stripe account with a deliberately wrong secret key (correct format, wrong value) via the admin UI in a non-test environment"
    expected: "Form shows a destructive Alert with 'Stripe key validation failed' and the account is NOT saved to the database"
    why_human: "validateStripeKeyPair() is bypassed in APP_ENV=testing. The live API call to Stripe cannot run in automated tests. STRIPE-03 / SC-3 must be verified manually with real keys."
  - test: "Run 'php artisan storage:link', upload a logo via brand create form, then check the brand list"
    expected: "Logo image renders in the brand list table and the preview card on the create form"
    why_human: "File system symlink and browser image rendering cannot be verified programmatically"
  - test: "Open /admin/brands/create, move the primary color picker"
    expected: "The live preview card header strip changes color in real time without a page reload"
    why_human: "Vue reactivity and DOM painting cannot be verified without a browser"
---

# Phase 3: Brand + Stripe Account Management Verification Report

**Phase Goal:** Admin can manage brands (name, logo, colors) and their associated Stripe accounts (API keys stored encrypted, key pair validated, test-mode keys blocked in production). All CRUD routes are admin-only. Frontend pages allow admin to perform all operations from the browser.
**Verified:** 2026-05-04
**Status:** human_needed
**Re-verification:** No — initial verification

## Goal Achievement

### Observable Truths

| #  | Truth | Status | Evidence |
|----|-------|--------|----------|
| 1  | Admin can create, edit, and list brands with name, logo, primary color, and secondary color (SC-1 / BRAND-01, 02, 03) | VERIFIED | BrandController@index/create/store/edit/update all exist and are substantive. 9/9 BrandManagementTest tests pass. |
| 2  | Admin can add a Stripe account linked to a brand; secret key stored encrypted and never re-displayed (SC-2 / STRIPE-01, 02) | VERIFIED | StripeAccountController never includes secret_key in Inertia props. StripeAccount model uses `'secret_key' => 'encrypted'` cast. explicit assignment `$account->secret_key = $value` confirmed. |
| 3  | Saving a Stripe account with a mismatched key pair fails with a Stripe API validation error (SC-3 / STRIPE-03) | UNCERTAIN | `validateStripeKeyPair()` calls `new StripeClient($secretKey)->balance->retrieve()` in non-test environments. In APP_ENV=testing it returns null to allow automated tests. Live Stripe API behavior requires manual verification. |
| 4  | Saving a test-mode key (pk_test_ / sk_test_) in production is blocked (SC-4 / BRAND-04) | VERIFIED | Both StoreStripeAccountRequest and UpdateStripeAccountRequest have closure rules checking `app()->environment('production')` and blocking `pk_test_` / `sk_test_` prefixes. Tests `test_test_publishable_key_blocked_in_production` and `test_test_secret_key_blocked_in_production` pass. |
| 5  | Admin can deactivate an existing Stripe account without deleting it (SC-5 / STRIPE-05) | VERIFIED | `deactivate()` sets `is_active = false` and calls redirect — no delete. Tests `test_admin_can_deactivate_stripe_account` and `test_deactivated_account_remains_in_database` pass. |
| 6  | All brand and Stripe account routes are admin-only; non-admin receives 403 (AUTH-05 / BRAND-03 / STRIPE-01) | VERIFIED | Routes registered inside `role:admin` middleware group. FormRequests use `authorize(): bool { return $this->user()->hasRole('admin'); }`. `test_non_admin_cannot_access_brand_list` and `test_non_admin_cannot_access_stripe_accounts` pass. |
| 7  | brand_id on StripeAccount comes from route model binding, never from request body | VERIFIED | `$account->brand_id = $brand->id` uses route-bound Brand. `brand_id` not in `$fillable` on StripeAccount model. `test_brand_id_comes_from_route_not_request` passes. |
| 8  | A StripeAccount that does not belong to the route Brand returns 404 (scoped binding) | VERIFIED | Route uses `.scoped(['stripe_account' => 'id'])`. `test_cannot_access_stripe_account_from_wrong_brand` passes (assertNotFound). |
| 9  | Frontend pages (6 Vue pages) exist and are substantive with correct form wiring | VERIFIED | All 6 pages exist: brands/Index.vue, brands/Create.vue, brands/Edit.vue, stripe-accounts/Index.vue, stripe-accounts/Create.vue, stripe-accounts/Edit.vue. All contain real logic — no stubs. |
| 10 | Stripe::setApiKey() is never called globally; always new StripeClient($secretKey) per instance | VERIFIED | Grep for `Stripe::setApiKey` in app/ returns only a comment string (line 110 of StripeAccountController.php). `new StripeClient($secretKey)` used inside `validateStripeKeyPair()`. |

**Score:** 9/10 truths verified (1 UNCERTAIN requires human verification)

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `routes/web.php` | Brand + StripeAccount resource routes inside role:admin group; ComingSoon placeholder removed | VERIFIED | All 12 admin brand routes registered. ComingSoon only appears for `/payments` (unrelated placeholder). |
| `app/Http/Controllers/Admin/BrandController.php` | Brand CRUD: index, create, store, edit, update with slug and logo handling | VERIFIED | 102 lines, generateUniqueSlug(), Storage::disk('public') in store and update, proper redirect pattern. |
| `app/Http/Controllers/Admin/StripeAccountController.php` | Stripe account CRUD: index, create, store, edit, update, deactivate | VERIFIED | 133 lines, all 6 methods present, testing env guard, validateStripeKeyPair private method. |
| `app/Http/Requests/Admin/StoreBrandRequest.php` | Brand create validation: name, logo (nullable), hex color regex | VERIFIED | authorize() + rules() with regex:/^#[0-9a-fA-F]{6}$/ for both colors. |
| `app/Http/Requests/Admin/UpdateBrandRequest.php` | Brand edit validation: same as store | VERIFIED | Identical rules to StoreBrandRequest. |
| `app/Http/Requests/Admin/StoreStripeAccountRequest.php` | Stripe account create validation with production test-key blocking | VERIFIED | starts_with:pk_ and starts_with:sk_ rules + environment('production') closure checks. |
| `app/Http/Requests/Admin/UpdateStripeAccountRequest.php` | Stripe account update validation; secret_key nullable | VERIFIED | secret_key is nullable; closure validates sk_ prefix and production env only when non-blank. |
| `resources/js/pages/admin/brands/Index.vue` | Brand list with color swatches via :style bindings, stripe-accounts link | VERIFIED | defineOptions(), :style="{backgroundColor: brand.primary_color}", CreditCard icon link to stripe-accounts. No bg-[...] dynamic classes. |
| `resources/js/pages/admin/brands/Create.vue` | Create form with native color pickers, live preview, handleLogoChange | VERIFIED | handleLogoChange(), previewPrimary/previewSecondary computed, form.post('/admin/brands'). |
| `resources/js/pages/admin/brands/Edit.vue` | Edit form with method spoofing, pre-populated fields | VERIFIED | form.post(url, { _method: 'put' }). No form.put() usage. |
| `resources/js/pages/admin/brands/stripe-accounts/Index.vue` | Stripe accounts list with deactivation dialog | VERIFIED | deactivateForm = useForm({}), deactivateForm.patch(...), v-if="account.is_active" on deactivate button. |
| `resources/js/pages/admin/brands/stripe-accounts/Create.vue` | Create form with stripe_api error Alert, secret key as password type | VERIFIED | Alert v-if="form.errors.stripe_api", type="password" on secret_key input. |
| `resources/js/pages/admin/brands/stripe-accounts/Edit.vue` | Edit form with blank secret key field, placeholder, helper text | VERIFIED | secret_key: '' in useForm init, placeholder="sk_••••••••••••••••", "Leave blank to keep" text. |
| `tests/Feature/Admin/BrandManagementTest.php` | 9 passing tests covering BRAND-01, 02, 03 | VERIFIED | 9/9 tests pass. |
| `tests/Feature/Admin/StripeAccountManagementTest.php` | 11 passing tests + 1 incomplete for STRIPE-03 manual verification | VERIFIED | 11/12 pass, 1 markTestIncomplete for STRIPE-03. |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| routes/web.php | BrandController | Route::resource('brands', BrandController::class) | WIRED | Confirmed in routes/web.php line 25-26 |
| routes/web.php | StripeAccountController | Route::resource('brands.stripe-accounts', StripeAccountController::class) | WIRED | Confirmed in routes/web.php line 28-30 |
| BrandController | Brand model | Brand::withCount(), Brand::create(), $brand->update() | WIRED | Confirmed in controller body — all Eloquent calls use Brand:: or $brand-> |
| BrandController | Storage::disk('public') | store() and update() logo handling | WIRED | Two occurrences confirmed in store() and update() methods |
| StripeAccountController | StripeAccount model | explicit $account->secret_key = $value (never mass-assigned) | WIRED | Line 56: `$account->secret_key = $request->validated('secret_key')` |
| StripeAccountController | Stripe\StripeClient | validateStripeKeyPair() private method | WIRED | new StripeClient($secretKey)->balance->retrieve() confirmed |
| StoreStripeAccountRequest | APP_ENV | app()->environment('production') in closure rule | WIRED | Confirmed in both StoreStripeAccountRequest and UpdateStripeAccountRequest |
| brands/Create.vue | POST /admin/brands | form.post('/admin/brands') | WIRED | Confirmed in submit() function |
| brands/Edit.vue | PUT /admin/brands/{id} | form.post(url, { _method: 'put' }) | WIRED | Confirmed — no form.put() usage |
| stripe-accounts/Index.vue | PATCH /deactivate | deactivateForm.patch(url) | WIRED | Confirmed in executeDeactivate() |
| stripe-accounts/Create.vue | form.errors.stripe_api | Alert v-if="form.errors.stripe_api" | WIRED | Confirmed — destructive Alert renders on stripe_api error |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|-------------------|--------|
| brands/Index.vue | brands | BrandController@index — Brand::withCount('stripeAccounts')->get()->map() | Yes — DB query with eager count | FLOWING |
| brands/Edit.vue | brand | BrandController@edit — $brand->only(...) from route model binding | Yes — Eloquent model | FLOWING |
| stripe-accounts/Index.vue | stripeAccounts | StripeAccountController@index — $brand->stripeAccounts()->get()->map() | Yes — hasMany query | FLOWING |
| stripe-accounts/Edit.vue | stripeAccount | StripeAccountController@edit — explicit array (no secret_key) | Yes — Eloquent model read | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Test suite (21 phase tests) | php artisan test BrandManagementTest + StripeAccountManagementTest | 21 tests: 20 passed, 1 incomplete | PASS |
| Full test suite | php artisan test | 82 tests: 72 passed, 10 skipped, 1 incomplete, 0 failed | PASS |
| Brand routes exist with correct names | php artisan route:list --name=admin.brands | 12 routes listed including index, create, store, edit, update, deactivate | PASS |
| No global Stripe::setApiKey() | grep in app/ for Stripe::setApiKey | Only appears in a comment string | PASS |
| secret_key not in Inertia render props | grep in StripeAccountController | secret_key only in safe/except, filled check, validated call, and explicit assignment | PASS |
| No dynamic Tailwind color classes | grep bg-\[ in resources/js/pages/admin/brands/ | No matches | PASS |

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|------------|-------------|--------|----------|
| BRAND-01 | 03-01, 03-03 | Admin can create a brand (name, logo, primary color, secondary color) | SATISFIED | BrandController@store with logo upload, hex validation. 3 passing tests. Create.vue form complete. |
| BRAND-02 | 03-01, 03-03 | Admin can edit brand details | SATISFIED | BrandController@update with old-logo deletion. 3 passing tests (update, old logo deleted, logo preserved). Edit.vue pre-populated. |
| BRAND-03 | 03-01, 03-03 | Admin can list all brands | SATISFIED | BrandController@index with withCount. Index.vue renders brand list with color swatches and stripe-accounts link. |
| BRAND-04 | 03-02 | System detects test vs live Stripe keys and blocks test keys in production | SATISFIED | StoreStripeAccountRequest + UpdateStripeAccountRequest both check environment('production') and reject pk_test_/sk_test_. 2 passing tests. |
| STRIPE-01 | 03-02, 03-04 | Admin can add a Stripe account linked to a brand | SATISFIED | StripeAccountController@store; brand_id from route binding. 3 passing tests. |
| STRIPE-02 | 03-02 | Secret key is encrypted at rest using AES-256 | SATISFIED | StripeAccount model: 'secret_key' => 'encrypted' cast. Covered by existing EncryptionRoundTripTest.php (Phase 1). |
| STRIPE-03 | 03-02 | System validates the key pair against Stripe API on save | UNCERTAIN | validateStripeKeyPair() calls new StripeClient($secretKey)->balance->retrieve() in non-test environments. Test environment bypasses this (intentional design). Manual verification required. |
| STRIPE-04 | 03-02, 03-04 | Admin can edit an existing Stripe account's keys | SATISFIED | StripeAccountController@update handles blank-secret-key (keep existing) and new-key (re-validate). Edit.vue with blank secret_key field. 2 passing tests. |
| STRIPE-05 | 03-02, 03-04 | Admin can deactivate or archive a Stripe account without deleting it | SATISFIED | deactivate() sets is_active=false, no delete. Index.vue shows PowerOff button only for active accounts. 2 passing tests. |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| routes/web.php | 29 | `->except(['show'])` on brands.stripe-accounts resource registers DELETE /admin/brands/{brand}/stripe-accounts/{id} but StripeAccountController has no destroy() method | WARNING | A DELETE request to that URL would throw a 500 error (BadMethodCallException: "Method App\Http\Controllers\Admin\StripeAccountController::destroy does not exist"). Not reachable from UI — no Vue page links to it. Not a blocker but is an exposed error-prone endpoint. |

### Human Verification Required

#### 1. STRIPE-03: Live Stripe API Key Validation (Required for SC-3)

**Test:** In a local or staging environment with APP_ENV=local (not testing), add a Stripe account via the admin UI at /admin/brands/{id}/stripe-accounts/create. First use valid matching test keys from the Stripe dashboard — confirm the account is saved. Then submit a form where the secret_key is deliberately wrong (e.g. `sk_test_wrong_key_value`) while the publishable_key is valid format.

**Expected:** The form re-displays with a destructive Alert: "Stripe key validation failed" and the message "The secret key could not be verified with Stripe. Check that it is correct and try again." The stripe_accounts table should have no new record for the wrong key attempt.

**Why human:** `validateStripeKeyPair()` returns null unconditionally when `app()->environment('testing')`. A live Stripe API call cannot be made in automated tests. This is the most critical security behavior of the phase (STRIPE-03 / SC-3) and must be confirmed with real Stripe credentials.

#### 2. Logo Upload and Display

**Test:** Run `php artisan storage:link` once, then create a brand via the admin UI with a logo file uploaded.

**Expected:** The brand list page shows the logo image in the Brand column. The preview card on the Create form shows the selected logo immediately after choosing a file (no page reload).

**Why human:** File system symlink (storage:link) and browser image rendering cannot be verified programmatically.

#### 3. Color Picker Live Preview Reactivity

**Test:** Open /admin/brands/create in a browser. Move the primary color picker or type a hex value in the text input next to it.

**Expected:** The preview card header strip (right panel) changes color in real time as the picker moves — no page reload or form submission required.

**Why human:** Vue computed property reactivity and DOM paint require a live browser. No E2E framework is set up.

### Gaps Summary

No automated gaps found. One truth (STRIPE-03 / SC-3) is UNCERTAIN and requires human verification before the phase can be considered fully complete. The missing `destroy()` method on StripeAccountController is a warning — the route is registered but the endpoint is not reachable from any UI or documented feature.

---

_Verified: 2026-05-04_
_Verifier: Claude (gsd-verifier)_
