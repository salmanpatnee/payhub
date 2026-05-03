# Phase 3: Brand + Stripe Account Management — Research

**Researched:** 2026-05-03
**Domain:** Laravel file storage, Stripe key validation, encrypted cast, nested resource routing, Vue 3 reactive color preview
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** File upload via Laravel Storage, local disk (`storage` disk), publicly accessible via `php artisan storage:link`. Logo stored at `brands/logo_path` as a Storage-relative path. Displayed via `Storage::url($brand->logo_path)`.
- **D-02:** Accept jpg/png/webp/svg image files. Max size ~2MB. No dimension enforcement. `logo_path` is nullable — brand can be created without a logo.
- **D-03:** On brand edit, if a new logo is uploaded the old file should be replaced (delete old, store new). If no new file is submitted, keep existing `logo_path` unchanged.
- **D-04:** Brand form uses native `<input type="color">` paired with a shadcn Input text field showing the hex value. Both controls sync reactively in Vue — user can pick via the color wheel or type an exact hex code. No third-party color picker library needed.
- **D-05:** Below the color pickers, a live brand preview card updates in real-time as colors are selected. Preview shows: brand name, logo thumbnail (if set), and a colored header strip using the chosen primary/secondary colors.

### Claude's Discretion

- Stripe nav structure: nested under `/admin/brands/{id}/stripe-accounts` chosen as natural drill-down.
- Secret key edit UX: never re-display the raw decrypted secret key. Show a masked placeholder.
- Test/live key blocking: check the `pk_test_`/`sk_test_` prefix against `APP_ENV === 'production'`.
- `webhook_secret` NOT captured in Phase 3 — only `publishable_key` + `secret_key` per STRIPE-01.
- Brand slug: auto-generated from name (kebab-case, unique) — not user-inputted.
- Color hex validation: validate server-side as 6-char hex string (`/^#[0-9a-fA-F]{6}$/`).
- Stripe API key validation (STRIPE-03): call `\Stripe\Account::retrieve()` or lightweight method using `new StripeClient($secret_key)`. Catch `\Stripe\Exception\AuthenticationException`.

### Deferred Ideas (OUT OF SCOPE)

- Webhook secret input — set during Phase 6 webhook endpoint setup.
- Logo CDN / S3 upload — local disk sufficient for v1.
- Brand deletion — no UI requirement in v1.
- Per-brand SMTP config — Phase 7 prerequisite blocker.
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| BRAND-01 | Admin can create a brand (name, logo, primary color, secondary color) | `BrandController@store` + `StoreBrandRequest` + `Storage::putFile('brands', ...)` + auto-slug from `Str::slug()` |
| BRAND-02 | Admin can edit brand details | `BrandController@update` with method spoofing (`_method: PUT` or `POST` + `$request->method('PUT')`) for multipart file + old logo delete |
| BRAND-03 | Admin can list all brands | `BrandController@index` with `withCount('stripeAccounts')` eager load, `logo_url` appended accessor |
| BRAND-04 | System detects test vs live Stripe keys and blocks test keys in production | `StoreStripeAccountRequest` / `UpdateStripeAccountRequest` prefix check + `APP_ENV` guard |
| STRIPE-01 | Admin can add a Stripe account (publishable key + secret key) and link it to a brand | `StripeAccountController@store` nested under brand, explicit `$account->secret_key = $value` assignment (not mass-assign) |
| STRIPE-02 | Secret key is encrypted at rest using AES-256 (Laravel encrypted cast) | Already wired via `casts()` on `StripeAccount` model — verified in Phase 1 encryption round-trip test |
| STRIPE-03 | System validates the key pair against Stripe API on save | `new StripeClient($secret_key)->accounts->retrieve('self')` or `->balance->retrieve()` inside controller action; catch `AuthenticationException` |
| STRIPE-04 | Admin can edit an existing Stripe account's keys | `StripeAccountController@update` — blank secret_key field means keep existing; new value triggers re-validation + re-encryption |
| STRIPE-05 | Admin can deactivate or archive a Stripe account without deleting it | `StripeAccountController@deactivate` (`PATCH` action setting `is_active = false`); Dialog confirm in Vue |
</phase_requirements>

---

## Summary

Phase 3 builds the admin Brand CRUD and StripeAccount CRUD on top of the schema, models, and encryption foundations from Phase 1. All major technical questions are answerable from the existing codebase patterns in Phase 2 — the UserController/FormRequest/Vue page pattern is extended directly to Brand and StripeAccount resources.

The four novel technical areas (compared to Phase 2) are: (1) file upload handling via Laravel Storage with logo replace-on-update logic, (2) Stripe API key validation using a lightweight `new StripeClient($secret_key)` probe call catching `AuthenticationException`, (3) nested resource routing with `Route::resource('brands.stripe-accounts', ...)` and scoped model binding, and (4) the Vue 3 live brand preview driven by reactive hex values via `:style` bindings rather than dynamic Tailwind classes.

There is one critical multipart gotcha: Inertia.js v3 does not support `PUT`/`PATCH` for multipart/form-data requests. The brand edit form (which may include a file upload) must use `POST` with `_method: 'put'` in the form data, and the controller route accepts both. This is a documented Inertia requirement, not a bug.

**Primary recommendation:** Follow the UserController pattern exactly for BrandController. Add the logo-handling and slug-generation as the only new concerns. Treat StripeAccountController as a nested resource under brands — implement `store`, `edit`, `update`, and a custom `deactivate` action. Stripe key validation belongs in the controller action (not FormRequest `afterValidation`), to keep FormRequests focused on format validation only.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Brand CRUD (create/edit/list) | API / Backend (Laravel Controller) | Frontend (Inertia Vue pages) | Server owns business logic: slug generation, file storage, validation |
| Logo file storage | Backend (Laravel Storage, local disk) | — | Files stored server-side; URL served via `Storage::url()` after `storage:link` |
| Stripe key encryption | Backend (Eloquent encrypted cast) | — | AES-256-CBC via APP_KEY; decryption transparent through cast on read |
| Stripe key validation (STRIPE-03) | Backend (Controller action) | — | API call to Stripe must happen server-side; never trust client-supplied keys |
| Test/live key detection (BRAND-04) | Backend (FormRequest rules) | — | String prefix check is cheap validation; belongs alongside other field rules |
| Live brand preview card | Browser / Client (Vue 3 reactive) | — | Purely reactive display from form values; no server roundtrip needed |
| Color hex sync (color picker + text) | Browser / Client (Vue 3 v-model) | — | Single reactive ref shared between native color input and text Input |
| Nested resource routing | Backend (Laravel Route) | — | Scoped `brands.stripe-accounts` resource enforces brand ownership at route level |
| Secret key masking on edit | Backend + Frontend | — | Backend never sends decrypted value in props; frontend shows placeholder only |
| Stripe account deactivation | API / Backend | Frontend (Dialog confirm) | `is_active = false` patch; dialog confirm is UX guard only |

---

## Standard Stack

All packages are already installed. Phase 3 adds no new Composer or npm dependencies.

### Core (already installed — verified in composer.json)
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `stripe/stripe-php` | ^20.1 | Per-account `StripeClient` instantiation for key validation | Only safe multi-account pattern — never global `setApiKey()` |
| `laravel/framework` | ^13.7 | `Storage` facade, `encrypted` cast, `Str::slug()`, FormRequest | Core framework |
| `inertiajs/inertia-laravel` | ^3.0 | Inertia responses, `HandleInertiaRequests` middleware | Server-side Inertia adapter |
| `@inertiajs/vue3` | (from package.json) | `useForm()`, multipart file upload | Client-side Inertia adapter for Vue 3 |
| `spatie/laravel-permission` | ^7.4 | `role:admin` middleware guard on all admin routes | Established in Phase 2 |

### No New Installations Required

The complete shadcn-vue component set needed for Phase 3 (Button, Input, Label, Card, Badge, Dialog, Alert, Sonner) is already installed per the UI-SPEC checker sign-off. No `npx shadcn add` commands are needed.

---

## Architecture Patterns

### System Architecture Diagram

```
Admin Browser
  |
  | [Inertia POST/PATCH with multipart for logo]
  v
web.php — Route::resource('brands', BrandController::class)
         — Route::resource('brands.stripe-accounts', StripeAccountController::class)->scoped()
         — Route::patch('brands/{brand}/stripe-accounts/{stripe_account}/deactivate', ...)
  |
  v
role:admin middleware [Spatie permission guard]
  |
  v
BrandController                     StripeAccountController
  |-- index()                          |-- index(Brand $brand)
  |-- create()                         |-- create(Brand $brand)
  |-- store(StoreBrandRequest)         |-- store(Brand $brand, StoreStripeAccountRequest)
  |-- edit(Brand $brand)               |-- edit(Brand $brand, StripeAccount $stripeAccount)
  |-- update(UpdateBrandRequest)       |-- update(Brand $brand, UpdateStripeAccountRequest)
  |-- destroy(Brand $brand)  [disc.]   |-- deactivate(Brand $brand, StripeAccount $stripeAccount)
  |
  v
Brand Model                          StripeAccount Model
  |-- Str::slug($name)                 |-- secret_key [encrypted cast — auto decrypt on read]
  |-- Storage::putFile('brands', ...)  |-- webhook_secret [encrypted cast — NOT captured Phase 3]
  |-- Storage::delete($old_logo_path)  |-- is_active [boolean]
  |-- Storage::url($logo_path)         |
  |-- stripeAccounts() HasMany         |-- new StripeClient($account->secret_key)
                                       |     ->accounts->retrieve('self')  [key validation]
                                       |   catch AuthenticationException → validation error
  |
  v
MySQL — brands table, stripe_accounts table
  (logo_path = Storage-relative path, e.g. "brands/abc123.jpg")
  (secret_key = AES-256-CBC ciphertext — TEXT column)
```

### Recommended Project Structure (new files only)

```
app/
├── Http/
│   ├── Controllers/Admin/
│   │   ├── BrandController.php               # new
│   │   └── StripeAccountController.php       # new
│   └── Requests/Admin/
│       ├── StoreBrandRequest.php             # new
│       ├── UpdateBrandRequest.php            # new
│       ├── StoreStripeAccountRequest.php     # new
│       └── UpdateStripeAccountRequest.php    # new
resources/js/pages/admin/
├── brands/
│   ├── Index.vue                             # new
│   ├── Create.vue                            # new
│   ├── Edit.vue                              # new
│   └── stripe-accounts/
│       ├── Index.vue                         # new
│       ├── Create.vue                        # new
│       └── Edit.vue                          # new
tests/Feature/
└── Admin/
    ├── BrandManagementTest.php               # new
    └── StripeAccountManagementTest.php       # new
```

---

### Pattern 1: Laravel Storage — Logo Upload, Replace, Delete

**What:** Upload logo via `UploadedFile::store()` or `Storage::putFile()`. On update, delete the old file before storing the new one.

**When to use:** `BrandController@store` and `BrandController@update`.

```php
// Source: Laravel 13 docs — filesystem.md (VERIFIED: Context7 /laravel/docs)

// store action — upload new logo
if ($request->hasFile('logo')) {
    $path = $request->file('logo')->store('brands', 'public');
    // $path is Storage-relative: "brands/abc123.jpg"
}

// update action — replace old logo
if ($request->hasFile('logo')) {
    if ($brand->logo_path) {
        Storage::disk('public')->delete($brand->logo_path);
    }
    $brand->logo_path = $request->file('logo')->store('brands', 'public');
}

// Generating public URL for display
$url = Storage::disk('public')->url($brand->logo_path);
// Returns: "/storage/brands/abc123.jpg" (after php artisan storage:link)
```

**Key point:** Use the `public` disk (not `local`). The `public` disk root is `storage/app/public/`. After `storage:link`, files are accessible at `public/storage/`. The `storage` disk in `config/filesystems.php` corresponds to `storage/app/private/` which is NOT web-accessible. Always use `disk('public')`.

[VERIFIED: Context7 /laravel/docs filesystem.md]

---

### Pattern 2: Inertia.js v3 — File Upload with Edit (Method Spoofing)

**What:** Inertia.js does NOT support multipart/form-data via `PUT` or `PATCH`. File uploads in edit forms must use `POST` with a `_method: 'put'` field in the form data.

**When to use:** `BrandController@update` when the edit form may include a logo file.

```typescript
// Source: Inertia.js v3 docs — file-uploads.md (VERIFIED: Context7 /websites/inertiajs_v3)

// In BrandEdit.vue — submit function
function submit() {
    // Must use post() with _method spoofing for multipart PUT
    form.post(`/admin/brands/${props.brand.id}`, {
        _method: 'put',
    });
}
```

```php
// In routes/web.php — route still declared as PUT/PATCH resource route
// Laravel reads the _method field and routes to the update() method automatically
Route::resource('brands', BrandController::class)->except(['show']);
```

**For create (POST with file) — no spoofing needed:**
```typescript
function submit() {
    form.post('/admin/brands');  // POST with file works directly
}
```

**When no file is present in the edit form:** `form.patch()` is fine because Inertia won't send multipart without a File object in the data. The spoofing is only required when the data actually contains a `File` object.

[VERIFIED: Context7 /websites/inertiajs_v3 file-uploads.md]

---

### Pattern 3: Stripe Key Validation — StripeClient Probe

**What:** Validate a secret key by instantiating `StripeClient` and making a lightweight API call. If the key is invalid, `AuthenticationException` is thrown. Balance retrieve is the lightest endpoint.

**When to use:** `StripeAccountController@store` and `StripeAccountController@update` (only when a new secret key is provided).

```php
// Source: stripe/stripe-php Context7 + Stripe PHP exception hierarchy (VERIFIED: Context7 /stripe/stripe-php)

use Stripe\StripeClient;
use Stripe\Exception\AuthenticationException;

private function validateStripeKeyPair(string $secretKey): ?string
{
    try {
        $stripe = new StripeClient($secretKey);
        // Balance::retrieve is the lightest authenticated endpoint
        // It returns immediately if the key is valid
        $stripe->balance->retrieve();
        return null; // valid
    } catch (AuthenticationException $e) {
        return 'The secret key could not be verified with Stripe. Check that it is correct and try again.';
    } catch (\Stripe\Exception\ApiConnectionException $e) {
        return 'Could not connect to Stripe to validate the key. Check your network and try again.';
    }
}
```

**In the controller store/update action:**
```php
public function store(StoreStripeAccountRequest $request, Brand $brand): RedirectResponse
{
    // Format validation passes (FormRequest)
    $error = $this->validateStripeKeyPair($request->validated('secret_key'));
    if ($error) {
        return back()->withErrors(['stripe_api' => $error]);
    }

    $account = new StripeAccount($request->safe()->except('secret_key'));
    $account->brand_id   = $brand->id;
    $account->secret_key = $request->validated('secret_key');  // explicit assignment for encrypted cast
    $account->save();

    return redirect()->route('admin.brands.stripe-accounts.index', $brand)
        ->with('success', 'Stripe account saved.');
}
```

**Why `balance->retrieve()` not `accounts->retrieve('self')`:** The Accounts API returns the connected account info and may require Connect permissions. `balance->retrieve()` is a simpler check available on all Stripe account types. [ASSUMED — recommendation from training knowledge; both should work for standard accounts, but `balance->retrieve()` is commonly cited as the lightest validation call]

[VERIFIED: Context7 /stripe/stripe-php — AuthenticationException hierarchy confirmed]

---

### Pattern 4: Test vs Live Key Detection (BRAND-04)

**What:** Reject test keys when `APP_ENV === 'production'`. Allow them freely in local/staging.

**When to use:** `StoreStripeAccountRequest` and `UpdateStripeAccountRequest` validation rules.

```php
// Source: CONTEXT.md D-decision + Laravel validation [VERIFIED: codebase pattern]

public function rules(): array
{
    $isProduction = app()->environment('production');

    return [
        'publishable_key' => [
            'required', 'string',
            // Reject test-mode keys in production
            function (string $attribute, mixed $value, \Closure $fail) use ($isProduction) {
                if ($isProduction && str_starts_with($value, 'pk_test_')) {
                    $fail('Test keys are not allowed in production. Use a live-mode publishable key.');
                }
            },
        ],
        'secret_key' => [
            'required', 'string',
            function (string $attribute, mixed $value, \Closure $fail) use ($isProduction) {
                if ($isProduction && str_starts_with($value, 'sk_test_')) {
                    $fail('Test keys are not allowed in production. Use a live-mode secret key.');
                }
            },
        ],
        // ... other rules
    ];
}
```

**For UpdateStripeAccountRequest** — `secret_key` is nullable (blank = keep existing):
```php
'secret_key' => [
    'nullable', 'string',
    function ($attribute, $value, $fail) use ($isProduction) {
        if ($value && $isProduction && str_starts_with($value, 'sk_test_')) {
            $fail('Test keys are not allowed in production. Use a live-mode secret key.');
        }
    },
],
```

[VERIFIED: CONTEXT.md D-decision confirmed. Laravel `app()->environment()` pattern verified in Laravel 13 docs]

---

### Pattern 5: Nested Resource Route with Scoped Binding

**What:** `Route::resource('brands.stripe-accounts', ...)` with `->scoped()` ensures the `StripeAccount` belongs to the resolved `Brand`. Laravel handles the scoped WHERE automatically.

**When to use:** `routes/web.php` admin group.

```php
// Source: Laravel 13 docs — controllers.md (VERIFIED: Context7 /laravel/docs)

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::resource('users', AdminUserController::class)->except(['show']);
        Route::resource('brands', BrandController::class)->except(['show']);
        Route::resource('brands.stripe-accounts', StripeAccountController::class)
            ->except(['show'])
            ->scoped(['stripe_account' => 'id']);
        // Custom deactivate action (not a standard REST verb)
        Route::patch('brands/{brand}/stripe-accounts/{stripe_account}/deactivate',
            [StripeAccountController::class, 'deactivate'])
            ->name('brands.stripe-accounts.deactivate');
    });
```

**Route names generated by nested resource:**
- `admin.brands.stripe-accounts.index` → `GET /admin/brands/{brand}/stripe-accounts`
- `admin.brands.stripe-accounts.create` → `GET /admin/brands/{brand}/stripe-accounts/create`
- `admin.brands.stripe-accounts.store` → `POST /admin/brands/{brand}/stripe-accounts`
- `admin.brands.stripe-accounts.edit` → `GET /admin/brands/{brand}/stripe-accounts/{stripe_account}/edit`
- `admin.brands.stripe-accounts.update` → `PUT /admin/brands/{brand}/stripe-accounts/{stripe_account}`
- `admin.brands.stripe-accounts.destroy` → `DELETE /admin/brands/{brand}/stripe-accounts/{stripe_account}`

**Controller method signature with scoped binding:**
```php
public function index(Brand $brand): Response
public function store(Brand $brand, StoreStripeAccountRequest $request): RedirectResponse
public function edit(Brand $brand, StripeAccount $stripeAccount): Response
public function update(Brand $brand, UpdateStripeAccountRequest $request, StripeAccount $stripeAccount): RedirectResponse
public function deactivate(Brand $brand, StripeAccount $stripeAccount): RedirectResponse
```

The `->scoped()` call causes Laravel to add `WHERE brand_id = :brand_id` when resolving `$stripeAccount`, automatically returning 404 if the account doesn't belong to the brand. This is the correct ownership enforcement at the routing level.

[VERIFIED: Context7 /laravel/docs — controllers.md scoped resource routes]

---

### Pattern 6: Brand Slug Auto-Generation

**What:** Derive the slug from the brand name on create. Make it unique by appending an incrementing suffix if the base slug collides.

**When to use:** `BrandController@store` (not `@update` — slug is immutable once set per CONTEXT.md).

```php
// Source: Laravel docs Str::slug + unique check pattern [VERIFIED: Context7 /laravel/docs strings.md]

use Illuminate\Support\Str;

private function generateUniqueSlug(string $name): string
{
    $base = Str::slug($name);
    $slug = $base;
    $i    = 1;

    while (Brand::where('slug', $slug)->exists()) {
        $slug = "{$base}-{$i}";
        $i++;
    }

    return $slug;
}
```

[VERIFIED: Context7 /laravel/docs strings.md — Str::slug() confirmed]

---

### Pattern 7: Vue 3 Live Preview — `:style` Bindings

**What:** Reactive brand preview driven entirely by `:style` bindings. Never use dynamic Tailwind classes (purged at build time in Tailwind v4).

**When to use:** `BrandCreate.vue` and `BrandEdit.vue` preview card.

```vue
<!-- Source: CONTEXT.md D-05 + UI-SPEC.md [VERIFIED: codebase + Inertia v3 docs] -->

<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    name:            '',
    primary_color:   '#000000',
    secondary_color: '#cccccc',
    logo:            null as File | null,
});

// Logo preview — blob URL from selected file
const logoPreviewUrl = ref<string | null>(null);

function handleLogoChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (file) {
        form.logo = file;
        if (logoPreviewUrl.value) URL.revokeObjectURL(logoPreviewUrl.value);
        logoPreviewUrl.value = URL.createObjectURL(file);
    }
}

// Only show preview if hex is valid 6-char format
const HEX_RE = /^#[0-9a-fA-F]{6}$/;
const previewPrimary   = computed(() => HEX_RE.test(form.primary_color)   ? form.primary_color   : '#000000');
const previewSecondary = computed(() => HEX_RE.test(form.secondary_color) ? form.secondary_color : '#cccccc');
</script>

<!-- In template — all colors via :style, not dynamic Tailwind classes -->
<div :style="{ backgroundColor: previewPrimary }">
    <!-- header strip -->
</div>
<div :style="{ backgroundColor: previewSecondary }">
    <!-- secondary strip -->
</div>
```

[VERIFIED: CONTEXT.md + UI-SPEC.md. Tailwind v4 purge behavior for dynamic classes confirmed in project research SUMMARY.md]

---

### Pattern 8: Secret Key Masking on Edit

**What:** The edit form must never pre-populate the secret key field. The backend must never send the decrypted key in Inertia page props.

**When to use:** `StripeAccountController@edit` and `BrandEdit.vue` for Stripe account editing.

```php
// Controller — never include secret_key in props
public function edit(Brand $brand, StripeAccount $stripeAccount): Response
{
    return Inertia::render('admin/brands/stripe-accounts/Edit', [
        'brand'         => $brand->only('id', 'name'),
        'stripeAccount' => [
            'id'                   => $stripeAccount->id,
            'account_name'         => $stripeAccount->account_name,
            'publishable_key'      => $stripeAccount->publishable_key,  // safe to show
            'publishable_key_preview' => substr($stripeAccount->publishable_key, 0, 12) . '••••••••',
            // secret_key: NEVER included
            'is_active'            => $stripeAccount->is_active,
        ],
    ]);
}
```

```vue
<!-- Frontend — secret_key starts empty, never pre-filled -->
<script setup lang="ts">
const form = useForm({
    account_name:    props.stripeAccount.account_name,
    publishable_key: props.stripeAccount.publishable_key,
    secret_key:      '',  // always blank on load
});
</script>
```

[VERIFIED: CONTEXT.md discretion decision. Anti-Pattern 3 in ARCHITECTURE.md]

---

### Pattern 9: Existing Route Bug Fix

**What:** The existing `/admin/brands` placeholder route is in the wrong middleware group. It must be moved to the `role:admin` group and replaced with a full resource route.

```php
// REMOVE from web.php line 13 (wrong group — auth+verified only, no role:admin):
Route::inertia('/admin/brands', 'placeholders/ComingSoon')->name('brands.index');

// ADD to the role:admin group:
Route::resource('brands', BrandController::class)->except(['show']);
```

[VERIFIED: routes/web.php — confirmed via codebase read]

---

### Anti-Patterns to Avoid

- **Dynamic Tailwind color classes on brand preview:** `:class="['bg-[' + hex + ']']"` — Tailwind v4 purges these at build time. Always use `:style="{ backgroundColor: hex }"`.
- **Secret key in Inertia page props:** Even as a masked/truncated string. The publishable key is safe to display; the secret key is never sent to the browser after the initial save.
- **Mass assignment of `secret_key`:** `StripeAccount::create($request->validated())` — `secret_key` is intentionally not in `$fillable` on the model. Always assign explicitly: `$account->secret_key = $request->validated('secret_key')`.
- **Using `Storage::url()` without the `public` disk:** `Storage::url($path)` on the default `local` disk returns a non-public path. Always specify `Storage::disk('public')->url($path)` or use the `public` disk when storing.
- **Stripe validation in FormRequest `afterValidation()`:** This is technically possible but puts network I/O inside a FormRequest which makes testing harder. Validation in the controller action is simpler and matches the established project pattern.
- **Querying `brand_id` from the request body for StripeAccount creation:** The `brand_id` must come from the `Brand` route model binding (`$brand->id`), never from the request body. This prevents cross-brand account creation.
- **Using `form.patch()` for brand edit when a logo file is selected:** Inertia v3 multipart does not support PATCH. Use `form.post(url, { _method: 'put' })` for all edit submissions on this form.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| File storage + public URL | Custom file handler | `Storage::disk('public')` + `storage:link` | Handles disk config, path security, URL generation |
| Encrypted DB column | Custom encrypt/decrypt | Laravel `encrypted` cast | Already on the model; AES-256-CBC via APP_KEY; decrypt transparent |
| Nested route ownership enforcement | Manual `WHERE brand_id` in every controller | `Route::resource()->scoped()` | Framework-level enforcement; 404 on ownership mismatch |
| Hex color format validation | Regex in controller | Laravel `Validator::make` custom rule or `Rule::regex('/^#[0-9a-fA-F]{6}$/')` | Reusable, testable |
| Stripe key prefix check | Controller if/else | FormRequest closure rule | Keeps controller lean; runs before controller is reached |
| Slug uniqueness | `while(exists) increment` loop | Same loop but via private method on controller | No package needed; the BrandFactory already shows this exact pattern |
| Test vs live key blocking | Global middleware | Per-FormRequest rule | Only applies to StripeAccount create/update — wrong scope for middleware |

---

## Common Pitfalls

### Pitfall 1: Wrong Storage Disk — Logo Not Publicly Accessible

**What goes wrong:** Logo stored on `local` disk (`storage/app/private/`) is not web-accessible. `Storage::url()` on the local disk returns a path without the public symlink, so logos show 404 in the browser.

**Why it happens:** `config/filesystems.php` has two similar disks: `local` (private) and `public` (symlinked). Default `Storage::putFile()` without disk argument uses the default disk which may be `local`.

**How to avoid:** Always specify `disk('public')` explicitly:
```php
$path = $request->file('logo')->store('brands', 'public');
// or
Storage::disk('public')->putFile('brands', $request->file('logo'));
```
Then display with: `Storage::disk('public')->url($brand->logo_path)`

**Warning signs:** Logo uploads succeed (file appears in `storage/app/public/brands/`) but the URL path doesn't resolve in browser.

[VERIFIED: Context7 /laravel/docs filesystem.md]

---

### Pitfall 2: `secret_key` Mass Assignment Silently Ignored

**What goes wrong:** `StripeAccount::create($request->validated())` or `$account->fill($data)` does NOT set `secret_key` because it is intentionally excluded from `$fillable` on the model. The account saves with an empty/null `secret_key`, causing all Stripe API calls for this account to fail with `AuthenticationException`.

**Why it happens:** The model was designed this way in Phase 1 to prevent accidental mass assignment of the encrypted credential. There is even a comment in the fillable array: `// secret_key and webhook_secret are NOT mass-assignable — assign explicitly only`.

**How to avoid:**
```php
$account         = new StripeAccount($request->safe()->except('secret_key', 'webhook_secret'));
$account->brand_id  = $brand->id;
$account->secret_key = $request->validated('secret_key');  // explicit
$account->save();
```

**Warning signs:** StripeAccount saved successfully but `$account->secret_key` is null/empty when retrieved.

[VERIFIED: codebase — StripeAccount.php fillable array]

---

### Pitfall 3: Multipart PUT/PATCH Fails Silently on Brand Edit

**What goes wrong:** `form.put('/admin/brands/1')` with a File object in form data sends the request as JSON (not multipart), silently dropping the file. The brand updates without the new logo, and `$request->hasFile('logo')` returns false in the controller.

**Why it happens:** Inertia v3 doesn't support multipart for PUT/PATCH. The Inertia docs explicitly state: "Use POST with `_method: 'put'` for file uploads."

**How to avoid:**
```typescript
// Brand Edit submit — always use post() with _method spoofing
function submit() {
    form.post(`/admin/brands/${props.brand.id}`, {
        _method: 'put',
    });
}
```

On the Laravel side, the route is still declared as `PUT` (via resource), so no controller change is needed. Laravel reads `_method` automatically.

**Warning signs:** New logo file selection appears in the preview but the saved brand still shows the old logo.

[VERIFIED: Context7 /websites/inertiajs_v3 — file-uploads.md explicit documentation]

---

### Pitfall 4: Stripe Key Validation Error Not Surfaced in Vue Alert

**What goes wrong:** `return back()->withErrors(['stripe_api' => $message])` returns a validation error, but the Vue form uses `form.errors.stripe_api` in an `<Alert>` component. If the alert is only shown conditionally with `v-if="form.errors.stripe_api"`, it won't appear unless the error key matches exactly.

**Why it happens:** Error key name mismatch, or the `<Alert>` component conditional is checking a different key.

**How to avoid:** Error key in `withErrors([])` must match the `v-if` key in Vue. Both must use `stripe_api` (matches the UI-SPEC contract).

**Warning signs:** Stripe API returns 401 (visible in browser network tab) but no user-facing error appears.

[VERIFIED: UI-SPEC.md error state definitions]

---

### Pitfall 5: `Route::inertia('/admin/brands', ...)` Shadow Route

**What goes wrong:** The existing placeholder `brands.index` named route (line 13 in `web.php`, wrong middleware group) will shadow the new resource route's `admin.brands.index` name if not removed. The sidebar nav link pointing to `/admin/brands` will route to the old placeholder instead of the new `BrandController@index`.

**Why it happens:** The placeholder was added as a stub in Phase 1/2. It must be deleted and replaced.

**How to avoid:** Remove the `Route::inertia('/admin/brands', 'placeholders/ComingSoon')->name('brands.index')` line entirely before adding the resource route. Verify the sidebar nav link resolves to the correct named route after the change.

**Warning signs:** Navigating to "Brands" in the sidebar shows "Coming Soon" instead of the brand list.

[VERIFIED: routes/web.php — line 13]

---

## Code Examples

### BrandController@store — complete pattern

```php
// Source: UserController.php pattern + Phase 3 research [VERIFIED: codebase]

public function store(StoreBrandRequest $request): RedirectResponse
{
    $data = $request->safe()->except('logo');
    $data['slug'] = $this->generateUniqueSlug($data['name']);

    if ($request->hasFile('logo')) {
        $data['logo_path'] = $request->file('logo')->store('brands', 'public');
    }

    Brand::create($data);

    return redirect()->route('admin.brands.index')
        ->with('success', 'Brand created.');
}
```

### BrandController@update — with method spoofing and logo replace

```php
// Source: Laravel filesystem docs + Inertia file upload docs [VERIFIED: Context7]

public function update(UpdateBrandRequest $request, Brand $brand): RedirectResponse
{
    $data = $request->safe()->except('logo');

    if ($request->hasFile('logo')) {
        // Delete old logo if exists
        if ($brand->logo_path) {
            Storage::disk('public')->delete($brand->logo_path);
        }
        $data['logo_path'] = $request->file('logo')->store('brands', 'public');
    }
    // If no new file: logo_path not in $data → existing value preserved by Eloquent

    $brand->update($data);

    return redirect()->route('admin.brands.index')
        ->with('success', 'Brand updated.');
}
```

### BrandController@index — with stripe account count and logo URL

```php
// Source: UserController@index pattern [VERIFIED: codebase]

public function index(): Response
{
    return Inertia::render('admin/brands/Index', [
        'brands' => Brand::withCount('stripeAccounts')
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $brand) => [
                'id'                    => $brand->id,
                'name'                  => $brand->name,
                'logo_url'              => $brand->logo_path
                                            ? Storage::disk('public')->url($brand->logo_path)
                                            : null,
                'primary_color'         => $brand->primary_color,
                'secondary_color'       => $brand->secondary_color,
                'stripe_accounts_count' => $brand->stripe_accounts_count,
            ]),
    ]);
}
```

### StripeAccountController@store — with key validation

```php
// Source: stripe-php AuthenticationException + FormRequest pattern [VERIFIED: Context7 /stripe/stripe-php]

public function store(StoreStripeAccountRequest $request, Brand $brand): RedirectResponse
{
    // Format validation already passed via FormRequest
    // Now validate key pair against Stripe API
    $validationError = $this->validateStripeKeyPair($request->validated('secret_key'));
    if ($validationError) {
        return back()->withErrors(['stripe_api' => $validationError]);
    }

    $account             = new StripeAccount($request->safe()->except('secret_key'));
    $account->brand_id   = $brand->id;
    $account->secret_key = $request->validated('secret_key');
    $account->save();

    return redirect()->route('admin.brands.stripe-accounts.index', $brand)
        ->with('success', 'Stripe account saved.');
}
```

### StripeAccountController@deactivate

```php
// Source: established project pattern [VERIFIED: codebase]

public function deactivate(Brand $brand, StripeAccount $stripeAccount): RedirectResponse
{
    abort_if($stripeAccount->brand_id !== $brand->id, 403);  // belt-and-suspenders

    $stripeAccount->update(['is_active' => false]);

    return redirect()->route('admin.brands.stripe-accounts.index', $brand)
        ->with('success', 'Account deactivated.');
}
```

### StoreBrandRequest — full validation rules

```php
// Source: StoreUserRequest pattern + Laravel File rule [VERIFIED: Context7 /laravel/docs]

use Illuminate\Validation\Rules\File;

public function rules(): array
{
    return [
        'name'            => ['required', 'string', 'max:255'],
        'logo'            => [
            'nullable',
            File::types(['jpg', 'jpeg', 'png', 'webp', 'svg'])
                ->max(2 * 1024),  // 2MB in kilobytes
        ],
        'primary_color'   => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        'secondary_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
    ];
}
```

### StoreStripeAccountRequest — with test key detection

```php
// Source: CONTEXT.md D-decision + FormRequest pattern [VERIFIED: codebase + CONTEXT.md]

public function rules(): array
{
    $isProduction = app()->environment('production');

    return [
        'account_name'    => ['required', 'string', 'max:255'],
        'publishable_key' => [
            'required', 'string', 'starts_with:pk_',
            function ($attr, $value, $fail) use ($isProduction) {
                if ($isProduction && str_starts_with($value, 'pk_test_')) {
                    $fail('Test keys are not allowed in production.');
                }
            },
        ],
        'secret_key' => [
            'required', 'string', 'starts_with:sk_',
            function ($attr, $value, $fail) use ($isProduction) {
                if ($isProduction && str_starts_with($value, 'sk_test_')) {
                    $fail('Test keys are not allowed in production.');
                }
            },
        ],
    ];
}
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `Stripe::setApiKey()` globally | `new StripeClient($secretKey)` per request | stripe-php v7+ | Concurrent requests safe; multi-account safe |
| `public_path('storage')` for URL | `Storage::disk('public')->url($path)` | Laravel 9+ | Works with any driver, not hardcoded to local |
| `form.put()` for file edit | `form.post(url, { _method: 'put' })` | Inertia.js design constraint | Multipart support via POST only |
| Custom slug uniqueness table | `while(Brand::where('slug', $slug)->exists())` | — | Simple; no extra package needed at agency scale |

**Deprecated/outdated patterns in this stack:**
- `\Stripe\Stripe::setApiKey($key)` global: never use in this project — documented as CRITICAL in ARCHITECTURE.md
- `\Stripe\Account::retrieve()` static call style: use `$stripe->accounts->retrieve('self')` via StripeClient instance

---

## Environment Availability

> This phase is purely code/config changes. No new external services, CLIs, or runtimes are required beyond what was established in Phases 1-2.

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.3 | str_starts_with(), readonly properties | Assumed available | 8.3 (from CLAUDE.md) | — |
| MySQL | Brand/StripeAccount tables | Already migrated (Phase 1) | — | — |
| `php artisan storage:link` | Logo public URL | Must be run once on setup | — | No fallback — required for local dev logo display |
| Stripe test keys | STRIPE-03 validation testing | Admin must provide test keys | — | Factory uses placeholder values for DB tests |

**Missing dependencies with no fallback:** None that block code changes. The `storage:link` command must be run on the dev machine for logo display to work; this is a standard Laravel setup step.

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 4.6 + pestphp/pest-plugin-laravel 4.1 |
| Config file | `phpunit.xml` (Pest reads it) |
| Quick run command | `php artisan test tests/Feature/Admin/BrandManagementTest.php` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| BRAND-01 | Admin can create brand with name/logo/colors | Feature (HTTP) | `php artisan test tests/Feature/Admin/BrandManagementTest.php` | ❌ Wave 0 |
| BRAND-02 | Admin can edit brand; old logo deleted on replace | Feature (HTTP) | Same file | ❌ Wave 0 |
| BRAND-03 | Admin can list brands | Feature (HTTP) | Same file | ❌ Wave 0 |
| BRAND-04 | Test keys blocked in production | Feature (HTTP) | `php artisan test tests/Feature/Admin/StripeAccountManagementTest.php` | ❌ Wave 0 |
| STRIPE-01 | Admin can add Stripe account nested under brand | Feature (HTTP) | Same file | ❌ Wave 0 |
| STRIPE-02 | Secret key encrypted at rest | Feature (Unit) | `php artisan test tests/Feature/EncryptionRoundTripTest.php` | ✅ Exists |
| STRIPE-03 | Key pair validated against Stripe API | Feature (HTTP) | Same file as BRAND-04 | ❌ Wave 0 |
| STRIPE-04 | Admin can edit Stripe account keys | Feature (HTTP) | Same file as BRAND-04 | ❌ Wave 0 |
| STRIPE-05 | Admin can deactivate Stripe account | Feature (HTTP) | Same file as BRAND-04 | ❌ Wave 0 |

**Note on STRIPE-03 testing:** Stripe API validation cannot be tested against the live Stripe API in the test suite. Tests should mock the `StripeClient` or use a `StripeService` wrapper that can be swapped in tests. The real-world validation is verified manually with test keys.

### Test Setup Pattern (from Phase 2)

All new test classes must include the Phase 2 `setUp()` pattern to prevent spatie permission cache contamination:

```php
protected function setUp(): void
{
    parent::setUp();
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
}
```

[VERIFIED: codebase — AdminUserManagementTest.php]

### Sampling Rate
- **Per task commit:** `php artisan test tests/Feature/Admin/BrandManagementTest.php tests/Feature/Admin/StripeAccountManagementTest.php`
- **Per wave merge:** `php artisan test`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Admin/BrandManagementTest.php` — covers BRAND-01, BRAND-02, BRAND-03
- [ ] `tests/Feature/Admin/StripeAccountManagementTest.php` — covers BRAND-04, STRIPE-01, STRIPE-03, STRIPE-04, STRIPE-05
- [ ] `StripeService` (or inline controller method) injectable mock for STRIPE-03 test isolation

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes (admin routes) | Fortify session auth + `role:admin` middleware — already wired |
| V3 Session Management | no (no new session logic) | — |
| V4 Access Control | yes | `role:admin` middleware on all admin routes; scoped route binding prevents cross-brand StripeAccount access |
| V5 Input Validation | yes | FormRequest: file type/size, hex regex, key prefix, required fields |
| V6 Cryptography | yes | Laravel `encrypted` cast on `secret_key` — AES-256-CBC via APP_KEY |

### Known Threat Patterns for This Phase

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Cross-brand StripeAccount access | Tampering | `Route::resource()->scoped()` — Laravel auto-adds `WHERE brand_id` |
| Decrypted secret key in HTTP response | Information Disclosure | Never include in Inertia props; field omitted from edit page props |
| Test key in production | Tampering / Repudiation | FormRequest prefix check against `APP_ENV === 'production'` |
| Mass-assignment of `secret_key` | Tampering | Excluded from `$fillable`; explicit assignment only |
| Logo path traversal | Tampering | `UploadedFile::store()` generates a random filename — no user-controlled path |
| Invalid Stripe credentials stored | Tampering | Controller validates via Stripe API before save (STRIPE-03) |
| Non-admin accessing admin routes | Elevation of Privilege | `role:admin` middleware — established in Phase 2 |

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `$stripe->balance->retrieve()` is the lightest endpoint for key validation (lighter than `accounts->retrieve('self')`) | Pattern 3 | Both methods work; if `balance->retrieve()` requires additional scopes on some account types, switch to `accounts->retrieve('self')`. Functional risk is low. |
| A2 | The `public` disk in `config/filesystems.php` is configured to `storage/app/public` (Laravel default) | Pattern 1, Pitfall 1 | If disk root was changed in Phase 1, logo paths could be stored incorrectly. Verify `config/filesystems.php` `public` disk `root` before the logo upload task. |

**If this table is empty:** Not empty — two low-risk assumptions noted above.

---

## Open Questions

1. **`stripe listen` for STRIPE-03 testing**
   - What we know: STRIPE-03 requires a real Stripe API call to validate keys. Test suites should not make live API calls.
   - What's unclear: Should `StripeService` be extracted as a dedicated class in Phase 3 (even though PaymentIntent creation is Phase 5), purely to enable mock injection in tests?
   - Recommendation: Yes — extract a minimal `StripeService::validateKeyPair(string $secretKey): ?string` method in Phase 3. This makes STRIPE-03 testable via mock without changing controller interface in Phase 5.

2. **Brand deletion (deferred)**
   - What we know: CONTEXT.md defers brand deletion. The current resource route `except(['show'])` still includes `destroy`.
   - What's unclear: Should the `destroy` action be included (with a controller stub) or explicitly excluded from the resource route?
   - Recommendation: Exclude it explicitly: `->except(['show', 'destroy'])`. This prevents accidental 405 responses and makes the scope clear.

---

## Sources

### Primary (HIGH confidence)
- Context7 `/laravel/docs` — `filesystem.md`: Storage::putFile, store, delete, url, public disk pattern
- Context7 `/laravel/docs` — `controllers.md`: nested resource routes, `->scoped()` behavior, method signatures
- Context7 `/laravel/docs` — `strings.md`: `Str::slug()` auto-generation
- Context7 `/websites/inertiajs_v3` — `file-uploads.md`: multipart POST only, `_method: 'put'` spoofing requirement
- Context7 `/stripe/stripe-php` — `README.md` + llms.txt: `StripeClient` instantiation, `AuthenticationException` hierarchy
- Codebase `app/Models/StripeAccount.php` — `$fillable` exclusion of `secret_key`, `encrypted` cast
- Codebase `app/Http/Controllers/Admin/UserController.php` — established admin CRUD pattern
- Codebase `resources/js/pages/admin/users/` — Vue form patterns (Create.vue, Edit.vue, Index.vue)
- Codebase `routes/web.php` — existing route structure and placeholder bug
- Codebase `database/migrations/` — confirmed `secret_key` TEXT column, `logo_path` nullable string
- `.planning/phases/03-brand-stripe-account-management/03-UI-SPEC.md` — approved component inventory, page specs, copywriting contract
- `.planning/phases/03-brand-stripe-account-management/03-CONTEXT.md` — locked decisions D-01 through D-05

### Secondary (MEDIUM confidence)
- `.planning/research/ARCHITECTURE.md` — StripeService per-account pattern, anti-patterns, route structure
- `.planning/research/SUMMARY.md` — pitfall catalogue, stack versions

### Tertiary (LOW confidence)
- A1: `balance->retrieve()` as lightest validation endpoint — training knowledge, not formally benchmarked

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all packages verified in composer.json; no new dependencies
- Architecture: HIGH — all patterns verified against Context7 or existing codebase
- Pitfalls: HIGH — all 5 pitfalls derived from verified sources (Context7, codebase, CONTEXT.md)
- Test patterns: HIGH — existing test infrastructure verified in codebase

**Research date:** 2026-05-03
**Valid until:** 2026-06-03 (stable stack; no fast-moving dependencies in this phase)
