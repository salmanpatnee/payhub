---
phase: 03-brand-stripe-account-management
reviewed: 2026-05-04T00:00:00Z
depth: standard
files_reviewed: 16
files_reviewed_list:
  - tests/Feature/Admin/BrandManagementTest.php
  - tests/Feature/Admin/StripeAccountManagementTest.php
  - routes/web.php
  - app/Http/Controllers/Admin/BrandController.php
  - app/Http/Controllers/Admin/StripeAccountController.php
  - app/Http/Requests/Admin/StoreBrandRequest.php
  - app/Http/Requests/Admin/UpdateBrandRequest.php
  - app/Http/Requests/Admin/StoreStripeAccountRequest.php
  - app/Http/Requests/Admin/UpdateStripeAccountRequest.php
  - database/migrations/2026_05_03_000002_create_stripe_accounts_table.php
  - database/factories/StripeAccountFactory.php
  - resources/js/pages/admin/brands/Index.vue
  - resources/js/pages/admin/brands/Create.vue
  - resources/js/pages/admin/brands/Edit.vue
  - resources/js/pages/admin/brands/stripe-accounts/Index.vue
  - resources/js/pages/admin/brands/stripe-accounts/Create.vue
  - resources/js/pages/admin/brands/stripe-accounts/Edit.vue
findings:
  critical: 4
  warning: 5
  info: 2
  total: 11
status: issues_found
---

# Phase 3: Code Review Report

**Reviewed:** 2026-05-04
**Depth:** standard
**Files Reviewed:** 16
**Status:** issues_found

## Summary

Reviewed the Brand and Stripe Account Management phase. The implementation correctly omits `secret_key` from Inertia props, uses `new StripeClient($key)` (never the global setter), and reads `brand_id` from route binding rather than request body. The encrypted cast is properly defined on the model.

Four blockers were found: (1) the `deactivate` route is not scoped, allowing cross-brand account deactivation; (2) the slug uniqueness check has a TOCTOU race condition that can produce duplicate-slug DB errors under concurrent requests; (3) the `UpdateStripeAccountRequest` closure accepts a `string` type hint that causes a PHP fatal error when `secret_key` is `null`; and (4) SVG logo uploads are accepted without sanitisation, creating a stored-XSS path when the SVG is served directly. Five warnings follow.

---

## Critical Issues

### CR-01: Deactivate route missing scoped binding — cross-brand deactivation possible

**File:** `routes/web.php:32-35`

**Issue:** The `Route::resource(...)` call at line 29 applies `.scoped(['stripe_account' => 'id'])`, which constrains the `stripe_account` parameter to belong to `{brand}`. However, the manually registered `deactivate` PATCH route at line 33 is declared outside that resource group and carries **no scoped binding**. Laravel will resolve `stripe_account` by its primary key alone, ignoring the `brand` segment. An admin can therefore deactivate any account from any brand by sending:

```
PATCH /admin/brands/1/stripe-accounts/99/deactivate
```

where account 99 belongs to a different brand. The `abort_if` guard on line 98 of `StripeAccountController` is supposed to catch this, but it only fires after the model has already been resolved — which succeeds because binding is unscoped.

**Fix:** Add the route inside the same scoped group, or apply `->scopeBindings()` explicitly to the manual route:

```php
Route::patch(
    'brands/{brand}/stripe-accounts/{stripe_account}/deactivate',
    [StripeAccountController::class, 'deactivate']
)->name('brands.stripe-accounts.deactivate')
 ->scopeBindings();
```

The `abort_if` guard in the controller is still good defence-in-depth, but the route binding must also be scoped.

---

### CR-02: TOCTOU race in `generateUniqueSlug` — unique constraint violation under concurrency

**File:** `app/Http/Controllers/Admin/BrandController.php:89-101`

**Issue:** `generateUniqueSlug` checks for slug existence in a `while` loop using a plain `SELECT` (`Brand::where('slug', $slug)->exists()`), then inserts the slug in a separate statement. Between the check and the insert, a concurrent request can claim the same slug, causing the `UNIQUE` constraint on `brands.slug` to throw an unhandled `QueryException` (HTTP 500).

```php
while (Brand::where('slug', $slug)->exists()) { // <-- window opens here
    $slug = "{$base}-{$i}";
    $i++;
}
// concurrent request can insert $slug here
Brand::create($data);  // <-- UNIQUE violation, unhandled exception
```

**Fix:** Wrap the slug generation + insert in a DB transaction with a unique-slug retry, or use `DB::transaction` + catch `QueryException` with duplicate-key code (`23000`) and retry with an incremented suffix. The simplest reliable approach:

```php
// In store(), wrap Brand::create in a try/catch:
try {
    Brand::create($data);
} catch (\Illuminate\Database\QueryException $e) {
    if ($e->getCode() === '23000') {
        // Re-generate slug and retry once
        $data['slug'] = $this->generateUniqueSlug($data['name']);
        Brand::create($data);
    } else {
        throw $e;
    }
}
```

A more robust solution generates a UUID-suffixed slug on collision rather than retrying the same SELECT loop.

---

### CR-03: `UpdateStripeAccountRequest` closure type-hint fatal on null `secret_key`

**File:** `app/Http/Requests/Admin/UpdateStripeAccountRequest.php:32-46`

**Issue:** The `secret_key` rule is declared `'nullable'`, meaning Laravel will pass `null` to the closure when the field is absent or empty. The closure signature is:

```php
function (string $attribute, mixed $value, \Closure $fail) use ($isProduction): void {
```

The `$value` parameter is typed `mixed` (correct), but the internal check `if ($value === null || $value === '')` can only be reached after PHP has already accepted `$value` — which is fine. However, `'nullable'` combined with `'string'` in the rule array means Laravel's built-in `string` rule runs **before** the closure and will itself reject a `null` value, triggering a validation error ("The secret key must be a string") even when the intent is "blank = keep existing."

More critically: if the nullable rule stops Laravel short-circuiting, and `$value` actually arrives as `null` inside the closure (possible on some Laravel versions depending on rule evaluation order), the `str_starts_with($value, 'sk_')` call on line 38 will receive `null` for argument 1, which causes a PHP `TypeError` (fatal) in PHP 8.x strict mode since `str_starts_with` requires `string`.

**Fix:** Remove `'string'` from the rule array — the closure already validates format — and guard against null explicitly:

```php
'secret_key' => [
    'nullable',
    function (string $attribute, mixed $value, \Closure $fail) use ($isProduction): void {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_string($value) || ! str_starts_with($value, 'sk_')) {
            $fail('The secret key must begin with sk_.');
            return;
        }
        if ($isProduction && str_starts_with($value, 'sk_test_')) {
            $fail('Test keys are not allowed in production. Use a live-mode secret key.');
        }
    },
],
```

---

### CR-04: SVG logo accepted without sanitisation — stored XSS via direct file URL

**File:** `app/Http/Requests/Admin/StoreBrandRequest.php:20-23` / `app/Http/Requests/Admin/UpdateBrandRequest.php:20-23`

**Issue:** The logo validation allows `svg` as an accepted MIME type:

```php
File::types(['jpg', 'jpeg', 'png', 'webp', 'svg'])->max(2 * 1024),
```

SVG files are XML and can embed `<script>` tags, event handlers (`onload`, `onerror`), and external resource references. When the stored file is served directly from the `public` disk (e.g., `storage/brands/logo.svg`) with `Content-Type: image/svg+xml`, any browser that opens the URL directly will execute embedded JavaScript in the origin of your app — a stored XSS vulnerability.

Laravel's `File::types()` validates by extension/MIME, not by content. A malicious admin (or a future path where non-admins can upload) could exploit this.

**Fix option A** (simplest): Remove `svg` from allowed types:

```php
File::types(['jpg', 'jpeg', 'png', 'webp'])->max(2 * 1024),
```

**Fix option B** (if SVG is required): Parse and sanitise the SVG on upload using a library such as `enshrined/svg-sanitize` before storing, and ensure the file is served with `Content-Disposition: attachment` or proxied through a PHP endpoint that forces `Content-Type: image/svg+xml` with `X-Content-Type-Options: nosniff` and CSP that disallows scripts.

---

## Warnings

### WR-01: Slug not regenerated on brand name update — can become stale

**File:** `app/Http/Controllers/Admin/BrandController.php:72-87`

**Issue:** `update()` passes `$request->safe()->except('logo')` directly to `$brand->update($data)`. The validated data includes `name` but not `slug` (slug is not a form field, so it passes through `safe()` only if it appeared in the request). The slug is never recalculated when the brand name changes. A brand renamed from "Acme Corp" to "Apex Industries" keeps the slug `acme-corp` forever. Payment page URLs (Phase 5) that embed the slug will be incorrect.

**Fix:** Either recalculate the slug on update (with unique-deduplication), or explicitly document and enforce that slugs are immutable after creation (and remove the slug from any URL-visible surface). If slugs are immutable, add a note in the `UpdateBrandRequest` that `name` changes do not affect slug.

---

### WR-02: `publishable_key` stored in plaintext — full key exposed in index props

**File:** `app/Http/Controllers/Admin/StripeAccountController.php:27-31`

**Issue:** The `index` action sends both `publishable_key` (full value) and `publishable_key_preview` to the frontend. The full key is included in the Inertia page props and therefore in the HTML source (the Inertia bootstrap `<script>` tag serialises all props as JSON). While publishable keys are less sensitive than secret keys, exposing the raw full key in an HTML page payload — visible to anyone who can view source — is unnecessary given a masked preview is already sent.

```php
'publishable_key'         => $account->publishable_key,  // full value, in page HTML
'publishable_key_preview' => substr($account->publishable_key, 0, 12) . '••••••••',
```

The `Index.vue` template only displays `publishable_key_preview`, so the full `publishable_key` is transmitted but unused in the view.

**Fix:** Remove `publishable_key` from the index props. Only send `publishable_key_preview`. If the edit form needs the full key pre-filled, it already receives it from the `edit` action.

---

### WR-03: Old logo file not deleted on brand deletion (cascade gap)

**File:** `app/Models/Brand.php` / `app/Http/Controllers/Admin/BrandController.php`

**Issue:** The `brands` resource excludes `destroy` (`->except(['show', 'destroy'])`), so deletion is not yet implemented. However, the `stripe_accounts` table has `cascadeOnDelete()` on `brand_id`, meaning if a brand row is ever deleted (e.g., via `php artisan tinker`, a future admin endpoint, or a test `RefreshDatabase` teardown), its associated `logo_path` file on disk is never removed — the `public` storage disk accumulates orphaned files. The model has no `deleting` observer or `boot()` hook to clean up storage.

**Fix:** Add a model observer or `boot()` static method to delete the logo file when a brand is deleted:

```php
protected static function booted(): void
{
    static::deleting(function (Brand $brand) {
        if ($brand->logo_path) {
            Storage::disk('public')->delete($brand->logo_path);
        }
    });
}
```

---

### WR-04: Test for production key blocking uses unreliable `app()->instance('env', ...)` override

**File:** `tests/Feature/Admin/StripeAccountManagementTest.php:158-171`

**Issue:** The test overrides the environment string via `$this->app->instance('env', 'production')`. However, `StoreStripeAccountRequest::rules()` reads the environment with `app()->environment('production')`, which internally calls `$this->app['env']` — the same binding. This works in some Laravel versions but is fragile: if the `Application::environment()` method is cached or reads from `$_ENV`/`$_SERVER` instead of the container binding, the override silently fails and the test passes vacuously (the validation closure never fires, no error is set, but `assertSessionHasErrors` checks for `publishable_key` which may also fail for other reasons).

A more reliable approach uses `$this->app->detectEnvironment(fn() => 'production')` or sets `APP_ENV=production` in the test's `.env.testing`.

**Fix:**

```php
// Instead of:
$this->app->instance('env', 'production');

// Use:
$this->app['config']->set('app.env', 'production');
// and update rules() to read: app()->isProduction() or config('app.env') === 'production'
```

---

### WR-05: `StripeAccountFactory` stores a real-looking but plaintext secret key

**File:** `database/factories/StripeAccountFactory.php:16`

**Issue:** The factory sets:

```php
'secret_key' => 'sk_test_placeholder_for_dev_only',
```

The `StripeAccount` model casts `secret_key` as `encrypted`. When the factory creates a record, Eloquent will encrypt this string before writing to DB — which is correct. However, if any test or seeder **directly** inserts into the `stripe_accounts` table via `DB::table()` or raw SQL (bypassing the model), the plaintext string is stored unencrypted and will later cause a `DecryptException` when the model tries to read it. This is an operational hazard and also a factory smell — the value looks intentionally fake but offers no indication to future developers that it must go through model assignment.

**Fix:** Add an inline comment clarifying that this value must only be created via the model factory (not via direct DB insert), and consider using a non-`sk_`-prefixed placeholder to make the intent more obvious:

```php
'secret_key' => 'PLACEHOLDER_encrypted_by_model_cast',
```

Or, better, use the factory's `afterCreating` callback to assert the cast is working. At minimum, document the constraint.

---

## Info

### IN-01: `name` uniqueness not validated — duplicate brand names allowed

**File:** `app/Http/Requests/Admin/StoreBrandRequest.php:17` / `app/Http/Requests/Admin/UpdateBrandRequest.php:17`

**Issue:** Brand `name` has no `unique:brands` validation rule. Two brands can be created with identical names. The slug deduplication loop will append `-1`, `-2`, etc., so slugs remain unique, but the name column itself allows duplicates. This is likely to cause user confusion in the admin UI and in payment link attribution.

**Fix:** Add `'unique:brands,name'` to `StoreBrandRequest` and `'unique:brands,name,'.$this->brand->id` to `UpdateBrandRequest`.

---

### IN-02: No `'file'` rule before `File::types()` in logo validation — misleading error on wrong input type

**File:** `app/Http/Requests/Admin/StoreBrandRequest.php:19-23`

**Issue:** The logo validation rules are `['nullable', File::types([...])->max(...)]`. If a non-file value is submitted for `logo` (e.g., a plain string in a crafted request), `File::types()` will produce a confusing error message rather than a clear "must be a file" message. Adding `'file'` before the `File::` rule ensures the error message is correct.

**Fix:**

```php
'logo' => ['nullable', 'file', File::types(['jpg', 'jpeg', 'png', 'webp'])->max(2 * 1024)],
```

(Note: this also applies `'file'` before the MIME check in `UpdateBrandRequest`.)

---

_Reviewed: 2026-05-04_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
