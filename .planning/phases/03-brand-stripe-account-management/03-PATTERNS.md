# Phase 3: Brand + Stripe Account Management - Pattern Map

**Mapped:** 2026-05-03
**Files analyzed:** 14 new/modified files
**Analogs found:** 14 / 14

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `app/Http/Controllers/Admin/BrandController.php` | controller | CRUD + file-I/O | `app/Http/Controllers/Admin/UserController.php` | role-match (adds file upload) |
| `app/Http/Controllers/Admin/StripeAccountController.php` | controller | CRUD + request-response | `app/Http/Controllers/Admin/UserController.php` | role-match (nested resource) |
| `app/Http/Requests/Admin/StoreBrandRequest.php` | middleware/validation | request-response | `app/Http/Requests/Admin/StoreUserRequest.php` | exact |
| `app/Http/Requests/Admin/UpdateBrandRequest.php` | middleware/validation | request-response | `app/Http/Requests/Admin/UpdateUserRequest.php` | exact |
| `app/Http/Requests/Admin/StoreStripeAccountRequest.php` | middleware/validation | request-response | `app/Http/Requests/Admin/StoreUserRequest.php` | role-match (adds env check) |
| `app/Http/Requests/Admin/UpdateStripeAccountRequest.php` | middleware/validation | request-response | `app/Http/Requests/Admin/UpdateUserRequest.php` | role-match (adds env check) |
| `resources/js/pages/admin/brands/Index.vue` | component | request-response | `resources/js/pages/admin/users/Index.vue` | exact |
| `resources/js/pages/admin/brands/Create.vue` | component | request-response | `resources/js/pages/admin/users/Create.vue` | role-match (adds file + color picker) |
| `resources/js/pages/admin/brands/Edit.vue` | component | request-response | `resources/js/pages/admin/users/Edit.vue` | role-match (adds file + method spoof) |
| `resources/js/pages/admin/brands/stripe-accounts/Index.vue` | component | request-response | `resources/js/pages/admin/users/Index.vue` | role-match (deactivate instead of delete) |
| `resources/js/pages/admin/brands/stripe-accounts/Create.vue` | component | request-response | `resources/js/pages/admin/users/Create.vue` | exact |
| `resources/js/pages/admin/brands/stripe-accounts/Edit.vue` | component | request-response | `resources/js/pages/admin/users/Edit.vue` | role-match (secret key masking) |
| `tests/Feature/Admin/BrandManagementTest.php` | test | CRUD | `tests/Feature/Auth/AdminUserManagementTest.php` | exact |
| `tests/Feature/Admin/StripeAccountManagementTest.php` | test | CRUD | `tests/Feature/Auth/AdminUserManagementTest.php` | role-match (adds factory + mock) |
| `routes/web.php` (modify) | config | request-response | `routes/web.php` lines 17–23 | exact (extend existing group) |

---

## Pattern Assignments

### `app/Http/Controllers/Admin/BrandController.php` (controller, CRUD + file-I/O)

**Analog:** `app/Http/Controllers/Admin/UserController.php`

**Imports pattern** (UserController.php lines 1–14):
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBrandRequest;
use App\Http\Requests\Admin\UpdateBrandRequest;
use App\Models\Brand;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
```

**index() pattern** (UserController.php lines 17–30 — adapt with `withCount` and logo URL):
```php
public function index(): Response
{
    return Inertia::render('admin/brands/Index', [
        'brands' => Brand::withCount('stripeAccounts')
            ->orderBy('name')
            ->get()
            ->map(fn (Brand $brand) => [
                'id'                    => $brand->id,
                'name'                  => $brand->name,
                'slug'                  => $brand->slug,
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

**store() pattern** (UserController.php lines 39–46 — adapt with logo upload + slug):
```php
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

**update() pattern** (UserController.php lines 59–72 — adapt with logo replace):
```php
public function update(UpdateBrandRequest $request, Brand $brand): RedirectResponse
{
    $data = $request->safe()->except('logo');

    if ($request->hasFile('logo')) {
        if ($brand->logo_path) {
            Storage::disk('public')->delete($brand->logo_path);
        }
        $data['logo_path'] = $request->file('logo')->store('brands', 'public');
    }

    $brand->update($data);

    return redirect()->route('admin.brands.index')
        ->with('success', 'Brand updated.');
}
```

**Private helper** (no analog — new for Phase 3):
```php
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

**Error handling pattern** (UserController.php lines 74–84 — adapt for brand):
```php
// No centralized handler needed — FormRequest handles validation errors automatically.
// Custom errors use back()->withErrors([]) as shown in UserController::destroy().
// Example for destroy (if implemented):
if ($brand->stripeAccounts()->exists()) {
    return back()->withErrors(['brand' => 'Cannot delete a brand with Stripe accounts attached.']);
}
```

---

### `app/Http/Controllers/Admin/StripeAccountController.php` (controller, CRUD)

**Analog:** `app/Http/Controllers/Admin/UserController.php`

**Imports pattern**:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStripeAccountRequest;
use App\Http\Requests\Admin\UpdateStripeAccountRequest;
use App\Models\Brand;
use App\Models\StripeAccount;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\StripeClient;
```

**index() pattern** (nested resource — Brand param added):
```php
public function index(Brand $brand): Response
{
    return Inertia::render('admin/brands/stripe-accounts/Index', [
        'brand'          => $brand->only('id', 'name'),
        'stripeAccounts' => $brand->stripeAccounts()
            ->orderBy('account_name')
            ->get()
            ->map(fn (StripeAccount $account) => [
                'id'              => $account->id,
                'account_name'    => $account->account_name,
                'publishable_key' => $account->publishable_key,
                'is_active'       => $account->is_active,
            ]),
    ]);
}
```

**edit() pattern** (secret key masking — NEVER include secret_key in props):
```php
public function edit(Brand $brand, StripeAccount $stripeAccount): Response
{
    return Inertia::render('admin/brands/stripe-accounts/Edit', [
        'brand'         => $brand->only('id', 'name'),
        'stripeAccount' => [
            'id'              => $stripeAccount->id,
            'account_name'    => $stripeAccount->account_name,
            'publishable_key' => $stripeAccount->publishable_key,
            // secret_key: NEVER included in props
            'is_active'       => $stripeAccount->is_active,
        ],
    ]);
}
```

**store() pattern** (Stripe key validation before save):
```php
public function store(StoreStripeAccountRequest $request, Brand $brand): RedirectResponse
{
    $validationError = $this->validateStripeKeyPair($request->validated('secret_key'));
    if ($validationError) {
        return back()->withErrors(['stripe_api' => $validationError]);
    }

    $account             = new StripeAccount($request->safe()->except('secret_key'));
    $account->brand_id   = $brand->id;
    $account->secret_key = $request->validated('secret_key'); // explicit — not mass-assignable
    $account->save();

    return redirect()->route('admin.brands.stripe-accounts.index', $brand)
        ->with('success', 'Stripe account saved.');
}
```

**update() pattern** (blank secret_key = keep existing):
```php
public function update(UpdateStripeAccountRequest $request, Brand $brand, StripeAccount $stripeAccount): RedirectResponse
{
    if ($request->filled('secret_key')) {
        $validationError = $this->validateStripeKeyPair($request->validated('secret_key'));
        if ($validationError) {
            return back()->withErrors(['stripe_api' => $validationError]);
        }
        $stripeAccount->secret_key = $request->validated('secret_key');
    }

    $stripeAccount->fill($request->safe()->except('secret_key'));
    $stripeAccount->save();

    return redirect()->route('admin.brands.stripe-accounts.index', $brand)
        ->with('success', 'Stripe account updated.');
}
```

**deactivate() pattern** (custom PATCH action):
```php
public function deactivate(Brand $brand, StripeAccount $stripeAccount): RedirectResponse
{
    abort_if($stripeAccount->brand_id !== $brand->id, 403);

    $stripeAccount->update(['is_active' => false]);

    return redirect()->route('admin.brands.stripe-accounts.index', $brand)
        ->with('success', 'Account deactivated.');
}
```

**Private helper** (Stripe key validation probe):
```php
private function validateStripeKeyPair(string $secretKey): ?string
{
    try {
        $stripe = new StripeClient($secretKey);
        $stripe->balance->retrieve();
        return null;
    } catch (AuthenticationException $e) {
        return 'The secret key could not be verified with Stripe. Check that it is correct and try again.';
    } catch (ApiConnectionException $e) {
        return 'Could not connect to Stripe to validate the key. Check your network and try again.';
    }
}
```

---

### `app/Http/Requests/Admin/StoreBrandRequest.php` (validation, request-response)

**Analog:** `app/Http/Requests/Admin/StoreUserRequest.php`

**Full structure** (StoreUserRequest.php lines 1–24):
```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin'); // copied exactly from StoreUserRequest line 12
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:255'],
            'logo'            => [
                'nullable',
                File::types(['jpg', 'jpeg', 'png', 'webp', 'svg'])
                    ->max(2 * 1024), // 2MB in kilobytes
            ],
            'primary_color'   => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
```

---

### `app/Http/Requests/Admin/UpdateBrandRequest.php` (validation, request-response)

**Analog:** `app/Http/Requests/Admin/UpdateUserRequest.php`

**Full structure** (UpdateUserRequest.php lines 1–26 — note route() call pattern for unique ignore):
```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class UpdateBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:255'],
            'logo'            => [
                'nullable',
                File::types(['jpg', 'jpeg', 'png', 'webp', 'svg'])
                    ->max(2 * 1024),
            ],
            'primary_color'   => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }
}
```

---

### `app/Http/Requests/Admin/StoreStripeAccountRequest.php` (validation, request-response)

**Analog:** `app/Http/Requests/Admin/StoreUserRequest.php`

**authorize() pattern** (StoreUserRequest.php line 12):
```php
public function authorize(): bool
{
    return $this->user()->hasRole('admin');
}
```

**rules() pattern** (adds test/live key blocking via closure):
```php
public function rules(): array
{
    $isProduction = app()->environment('production');

    return [
        'account_name'    => ['required', 'string', 'max:255'],
        'publishable_key' => [
            'required', 'string', 'starts_with:pk_',
            function (string $attribute, mixed $value, \Closure $fail) use ($isProduction) {
                if ($isProduction && str_starts_with($value, 'pk_test_')) {
                    $fail('Test keys are not allowed in production. Use a live-mode publishable key.');
                }
            },
        ],
        'secret_key' => [
            'required', 'string', 'starts_with:sk_',
            function (string $attribute, mixed $value, \Closure $fail) use ($isProduction) {
                if ($isProduction && str_starts_with($value, 'sk_test_')) {
                    $fail('Test keys are not allowed in production. Use a live-mode secret key.');
                }
            },
        ],
    ];
}
```

---

### `app/Http/Requests/Admin/UpdateStripeAccountRequest.php` (validation, request-response)

**Analog:** `app/Http/Requests/Admin/UpdateUserRequest.php`

**Route param pattern** (UpdateUserRequest.php line 17 — adapt for stripe_account):
```php
// UpdateUserRequest uses: $userId = $this->route('user')->id;
// UpdateStripeAccountRequest uses:
$stripeAccountId = $this->route('stripe_account')->id;
```

**rules() pattern** (secret_key nullable — blank = keep existing):
```php
public function rules(): array
{
    $isProduction = app()->environment('production');

    return [
        'account_name'    => ['required', 'string', 'max:255'],
        'publishable_key' => [
            'required', 'string', 'starts_with:pk_',
            function (string $attribute, mixed $value, \Closure $fail) use ($isProduction) {
                if ($isProduction && str_starts_with($value, 'pk_test_')) {
                    $fail('Test keys are not allowed in production. Use a live-mode publishable key.');
                }
            },
        ],
        'secret_key' => [
            'nullable', 'string',
            function (string $attribute, mixed $value, \Closure $fail) use ($isProduction) {
                if ($value && str_starts_with($value, 'sk_')) {
                    // valid prefix — continue
                } elseif ($value) {
                    $fail('The secret key must begin with sk_.');
                }
                if ($value && $isProduction && str_starts_with($value, 'sk_test_')) {
                    $fail('Test keys are not allowed in production. Use a live-mode secret key.');
                }
            },
        ],
    ];
}
```

---

### `resources/js/pages/admin/brands/Index.vue` (component, request-response)

**Analog:** `resources/js/pages/admin/users/Index.vue`

**Script imports pattern** (Index.vue lines 1–14):
```typescript
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Pencil, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog, DialogContent, DialogDescription,
    DialogFooter, DialogHeader, DialogTitle,
} from '@/components/ui/dialog';
```

**Type + defineProps pattern** (Index.vue lines 16–31):
```typescript
type BrandRow = {
    id: number;
    name: string;
    slug: string;
    logo_url: string | null;
    primary_color: string;
    secondary_color: string;
    stripe_accounts_count: number;
};

defineProps<{ brands: BrandRow[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Brands', href: '/admin/brands' },
        ],
    },
});
```

**Delete dialog pattern** (Index.vue lines 33–53 — copy exactly, replace User refs with Brand):
```typescript
const deleteTarget = ref<BrandRow | null>(null);
const deleteOpen   = ref(false);
const deleteForm   = useForm({});

function confirmDelete(brand: BrandRow) {
    deleteTarget.value = brand;
    deleteOpen.value   = true;
}

function executeDelete() {
    if (!deleteTarget.value) return;
    deleteForm.delete(`/admin/brands/${deleteTarget.value.id}`, {
        onSuccess: () => {
            deleteOpen.value   = false;
            deleteTarget.value = null;
        },
    });
}
```

**Table template pattern** (Index.vue lines 56–122 — adapt columns):
- Table headers: Name, Colors (swatches), Stripe Accounts, Actions
- Color swatch: `<span class="inline-block size-4 rounded border" :style="{ backgroundColor: brand.primary_color }" />`
- Edit link: `/admin/brands/${brand.id}/edit`
- Stripe accounts link: `/admin/brands/${brand.id}/stripe-accounts`
- Empty state: `colspan="4"` message

**Note:** No `authUserId` check needed for brands (no self-brand concept). Remove `usePage()` import and `authUserId` computed from the user analog.

---

### `resources/js/pages/admin/brands/Create.vue` (component, request-response)

**Analog:** `resources/js/pages/admin/users/Create.vue`

**Imports pattern** (Create.vue lines 1–16 — add `computed`, `ref` for color/logo):
```typescript
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, PlusCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
```

**defineOptions breadcrumb pattern** (Create.vue lines 19–26):
```typescript
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Brands', href: '/admin/brands' },
            { title: 'New brand', href: '/admin/brands/create' },
        ],
    },
});
```

**useForm pattern** (Create.vue lines 28–33 — adapt fields):
```typescript
const form = useForm({
    name:            '',
    primary_color:   '#000000',
    secondary_color: '#cccccc',
    logo:            null as File | null,
});
```

**Logo + color reactive state**:
```typescript
const logoPreviewUrl = ref<string | null>(null);

function handleLogoChange(event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (file) {
        form.logo = file;
        if (logoPreviewUrl.value) URL.revokeObjectURL(logoPreviewUrl.value);
        logoPreviewUrl.value = URL.createObjectURL(file);
    }
}

const HEX_RE = /^#[0-9a-fA-F]{6}$/;
const previewPrimary   = computed(() => HEX_RE.test(form.primary_color)   ? form.primary_color   : '#000000');
const previewSecondary = computed(() => HEX_RE.test(form.secondary_color) ? form.secondary_color : '#cccccc');
```

**submit() pattern** (Create.vue line 36 — POST without method spoofing for create):
```typescript
function submit() {
    form.post('/admin/brands');
}
```

**Form layout pattern** (Create.vue lines 62–112 — single-column grid, or adapt):
- Card with `CardHeader` / `CardContent` / `CardFooter`
- `<form id="create-brand-form" @submit.prevent="submit">`
- `<Input>` + `<InputError>` for name
- Native `<input type="color">` synced to `form.primary_color` + `<Input type="text" v-model="form.primary_color">`
- Same pattern for `secondary_color`
- `<input type="file" @change="handleLogoChange">` (unstyled or wrapped in Label)
- Live preview `<div>` below color pickers — all colors via `:style`, NOT dynamic Tailwind classes:
  ```html
  <div :style="{ backgroundColor: previewPrimary }" class="h-6 w-full rounded" />
  ```
- `CardFooter` submit button: `form="create-brand-form"`, `:disabled="form.processing"`

---

### `resources/js/pages/admin/brands/Edit.vue` (component, request-response)

**Analog:** `resources/js/pages/admin/users/Edit.vue`

**Key difference from Create.vue:** Method spoofing required for file upload via PUT.

**submit() pattern** (Edit.vue line 58 — CRITICAL: use post() with _method, not patch()):
```typescript
// DO NOT use: form.patch(`/admin/brands/${props.brand.id}`)
// DO USE:
function submit() {
    form.post(`/admin/brands/${props.brand.id}`, {
        _method: 'put',
    });
}
```

**useForm pattern** (pre-fill existing values — no logo field pre-fill since it's a File):
```typescript
const form = useForm({
    name:            props.brand.name,
    primary_color:   props.brand.primary_color,
    secondary_color: props.brand.secondary_color,
    logo:            null as File | null, // never pre-filled
});
```

**Existing logo display** (show current logo if present, replace on new file selection):
```html
<div v-if="props.brand.logo_url" class="mb-2">
    <img :src="props.brand.logo_url" alt="Current logo" class="h-12 object-contain" />
    <p class="text-xs text-muted-foreground mt-1">Upload a new file to replace</p>
</div>
```

**breadcrumbs pattern** (Edit.vue lines 35–40 — adapt):
```typescript
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Brands', href: '/admin/brands' },
            { title: 'Edit brand', href: '#' },
        ],
    },
});
```

**BrandProp type**:
```typescript
type BrandProp = {
    id: number;
    name: string;
    logo_url: string | null;
    primary_color: string;
    secondary_color: string;
};
```

---

### `resources/js/pages/admin/brands/stripe-accounts/Index.vue` (component, request-response)

**Analog:** `resources/js/pages/admin/users/Index.vue`

**Key difference:** Deactivate action (PATCH) instead of delete (DELETE). Dialog confirms deactivation, not deletion.

**Type + defineProps pattern**:
```typescript
type StripeAccountRow = {
    id: number;
    account_name: string;
    publishable_key: string;
    is_active: boolean;
};

type BrandProp = { id: number; name: string };

const props = defineProps<{ brand: BrandProp; stripeAccounts: StripeAccountRow[] }>();
```

**breadcrumbs pattern**:
```typescript
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Brands', href: '/admin/brands' },
            { title: props.brand.name, href: `/admin/brands/${props.brand.id}/stripe-accounts` },
        ],
    },
});
```

**Deactivate form pattern** (replaces delete form in analog — uses patch not delete):
```typescript
const deactivateTarget = ref<StripeAccountRow | null>(null);
const deactivateOpen   = ref(false);
const deactivateForm   = useForm({});

function confirmDeactivate(account: StripeAccountRow) {
    deactivateTarget.value = account;
    deactivateOpen.value   = true;
}

function executeDeactivate() {
    if (!deactivateTarget.value) return;
    deactivateForm.patch(
        `/admin/brands/${props.brand.id}/stripe-accounts/${deactivateTarget.value.id}/deactivate`,
        {
            onSuccess: () => {
                deactivateOpen.value   = false;
                deactivateTarget.value = null;
            },
        }
    );
}
```

**is_active badge pattern** (replaces roles badge in analog):
```html
<Badge :variant="account.is_active ? 'default' : 'secondary'">
    {{ account.is_active ? 'Active' : 'Inactive' }}
</Badge>
```

**Table headers:** Account Name, Publishable Key (masked), Status, Actions

**Actions column:** Edit button + Deactivate button (show deactivate only if `account.is_active`).

---

### `resources/js/pages/admin/brands/stripe-accounts/Create.vue` (component, request-response)

**Analog:** `resources/js/pages/admin/users/Create.vue`

**No significant divergence from analog** — standard Card form, POST submit.

**Type + defineProps**:
```typescript
type BrandProp = { id: number; name: string };
const props = defineProps<{ brand: BrandProp }>();
```

**breadcrumbs**:
```typescript
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Brands', href: '/admin/brands' },
            { title: props.brand.name, href: `/admin/brands/${props.brand.id}/stripe-accounts` },
            { title: 'Add Stripe account', href: '#' },
        ],
    },
});
```

**useForm pattern**:
```typescript
const form = useForm({
    account_name:    '',
    publishable_key: '',
    secret_key:      '',
});
```

**submit() pattern** (Create.vue line 36 — POST, no spoofing):
```typescript
function submit() {
    form.post(`/admin/brands/${props.brand.id}/stripe-accounts`);
}
```

**Stripe API error display** (in addition to field-level InputError — show `stripe_api` error):
```html
<!-- Below the form fields, before CardFooter -->
<div v-if="form.errors.stripe_api" class="col-span-2">
    <Alert variant="destructive">
        <AlertDescription>{{ form.errors.stripe_api }}</AlertDescription>
    </Alert>
</div>
```

Import for Alert: `import { Alert, AlertDescription } from '@/components/ui/alert';`

---

### `resources/js/pages/admin/brands/stripe-accounts/Edit.vue` (component, request-response)

**Analog:** `resources/js/pages/admin/users/Edit.vue`

**Key difference:** Secret key field starts empty, shows masked placeholder, never pre-filled.

**useForm pattern** (secret_key always blank on load):
```typescript
const form = useForm({
    account_name:    props.stripeAccount.account_name,
    publishable_key: props.stripeAccount.publishable_key,
    secret_key:      '', // always blank — never pre-filled with decrypted value
});
```

**Secret key input hint** (replaces Edit.vue line 120–124 password placeholder):
```html
<div class="grid gap-2">
    <Label for="secret_key">Secret Key</Label>
    <Input
        id="secret_key"
        v-model="form.secret_key"
        type="password"
        placeholder="Leave blank to keep current key"
        autocomplete="off"
    />
    <p class="text-xs text-muted-foreground">
        A key is saved. Paste a new key here only if you want to replace it.
    </p>
    <InputError class="mt-2" :message="form.errors.secret_key" />
</div>
```

**submit() pattern** (no file upload — regular patch is fine):
```typescript
function submit() {
    form.patch(`/admin/brands/${props.brand.id}/stripe-accounts/${props.stripeAccount.id}`);
}
```

**StripeAccountProp type**:
```typescript
type StripeAccountProp = {
    id: number;
    account_name: string;
    publishable_key: string;
    // secret_key: never in props
    is_active: boolean;
};
```

**Stripe API error display** (same as Create.vue — show `stripe_api` error):
```html
<div v-if="form.errors.stripe_api" class="col-span-2">
    <Alert variant="destructive">
        <AlertDescription>{{ form.errors.stripe_api }}</AlertDescription>
    </Alert>
</div>
```

---

### `tests/Feature/Admin/BrandManagementTest.php` (test, CRUD)

**Analog:** `tests/Feature/Auth/AdminUserManagementTest.php`

**Namespace and class structure** (AdminUserManagementTest.php lines 1–20):
```php
<?php

namespace Tests\Feature\Admin;

use App\Models\Brand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BrandManagementTest extends TestCase
{
    use RefreshDatabase;
```

**setUp() pattern** (AdminUserManagementTest.php lines 14–20 — copy exactly):
```php
protected function setUp(): void
{
    parent::setUp();
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
}
```

**adminUser() helper** (AdminUserManagementTest.php lines 22–27 — copy exactly):
```php
private function adminUser(): User
{
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->syncRoles(['admin']);
    return $admin;
}
```

**Test method pattern** (AdminUserManagementTest.php lines 29–51 — adapt for Brand):
```php
public function test_admin_can_view_brand_list(): void
{
    $this->actingAs($this->adminUser())
        ->get(route('admin.brands.index'))
        ->assertOk();
}

public function test_admin_can_create_brand(): void
{
    Storage::fake('public');
    $admin = $this->adminUser();

    $this->actingAs($admin)
        ->post(route('admin.brands.store'), [
            'name'            => 'Acme Corp',
            'primary_color'   => '#ff0000',
            'secondary_color' => '#00ff00',
            'logo'            => UploadedFile::fake()->image('logo.png', 200, 200)->size(100),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.brands.index'));

    $this->assertDatabaseHas('brands', ['name' => 'Acme Corp', 'slug' => 'acme-corp']);
    Storage::disk('public')->assertExists(Brand::latest()->first()->logo_path);
}

public function test_admin_can_update_brand(): void
{
    Storage::fake('public');
    $admin = $this->adminUser();
    $brand = Brand::factory()->create(['logo_path' => null]);

    $this->actingAs($admin)
        ->put(route('admin.brands.update', $brand), [
            'name'            => 'Updated Name',
            'primary_color'   => '#aabbcc',
            'secondary_color' => '#112233',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.brands.index'));

    $this->assertDatabaseHas('brands', ['id' => $brand->id, 'name' => 'Updated Name']);
}

public function test_non_admin_cannot_access_brands(): void
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->syncRoles(['user']);

    $this->actingAs($user)
        ->get(route('admin.brands.index'))
        ->assertForbidden();
}
```

**Note on file upload test:** `Storage::fake('public')` must be called before the request. `UploadedFile::fake()->image()` creates a synthetic image. `Storage::disk('public')->assertExists($path)` verifies the file was stored.

---

### `tests/Feature/Admin/StripeAccountManagementTest.php` (test, CRUD)

**Analog:** `tests/Feature/Auth/AdminUserManagementTest.php`

**Same setUp() + adminUser() helpers** as BrandManagementTest — copy exactly.

**Factory usage pattern** (EncryptionRoundTripTest.php lines 13–17):
```php
// Both factories available and verified:
$brand   = Brand::factory()->create();
$account = StripeAccount::factory()->create(['brand_id' => $brand->id]);
```

**Key test methods**:
```php
public function test_admin_can_view_stripe_accounts_for_brand(): void
{
    $brand   = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['brand_id' => $brand->id]);

    $this->actingAs($this->adminUser())
        ->get(route('admin.brands.stripe-accounts.index', $brand))
        ->assertOk();
}

public function test_admin_can_deactivate_stripe_account(): void
{
    $brand   = Brand::factory()->create();
    $account = StripeAccount::factory()->create(['brand_id' => $brand->id, 'is_active' => true]);

    $this->actingAs($this->adminUser())
        ->patch(route('admin.brands.stripe-accounts.deactivate', [$brand, $account]))
        ->assertRedirect(route('admin.brands.stripe-accounts.index', $brand));

    $this->assertDatabaseHas('stripe_accounts', ['id' => $account->id, 'is_active' => false]);
}

public function test_test_key_blocked_in_production(): void
{
    // Uses Laravel's withEnvironmentVariables or mock app()->environment()
    // Pattern: mock the environment check inside FormRequest
    $this->app['env'] = 'production';

    $brand = Brand::factory()->create();

    $this->actingAs($this->adminUser())
        ->post(route('admin.brands.stripe-accounts.store', $brand), [
            'account_name'    => 'Test Account',
            'publishable_key' => 'pk_test_abc123',
            'secret_key'      => 'sk_test_abc123',
        ])
        ->assertSessionHasErrors(['publishable_key']);
}
```

**STRIPE-03 testing note:** Stripe API validation must be mocked. Extract a `validateStripeKeyPair()` method in the controller and test by mocking the StripeClient. Alternatively, test only the FormRequest format validation in unit tests, and document STRIPE-03 as a manual verification step.

---

### `routes/web.php` (modify — remove placeholder, add resource routes)

**Analog:** `routes/web.php` lines 17–23 (existing admin group)

**Lines to remove** (web.php line 13 — wrong middleware group):
```php
// REMOVE this line (currently inside auth+verified group, missing role:admin):
Route::inertia('/admin/brands', 'placeholders/ComingSoon')->name('brands.index');
```

**Lines to add** (inside the `role:admin` group, after the existing `users` resource):
```php
use App\Http\Controllers\Admin\BrandController;
use App\Http\Controllers\Admin\StripeAccountController;

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::resource('users', AdminUserController::class)
            ->except(['show']);

        // Phase 3 additions:
        Route::resource('brands', BrandController::class)
            ->except(['show', 'destroy']);

        Route::resource('brands.stripe-accounts', StripeAccountController::class)
            ->except(['show'])
            ->scoped(['stripe_account' => 'id']);

        Route::patch(
            'brands/{brand}/stripe-accounts/{stripe_account}/deactivate',
            [StripeAccountController::class, 'deactivate']
        )->name('brands.stripe-accounts.deactivate');
    });
```

**Route names after change:**
- `admin.brands.index`, `admin.brands.create`, `admin.brands.store`, `admin.brands.edit`, `admin.brands.update`
- `admin.brands.stripe-accounts.index`, `.create`, `.store`, `.edit`, `.update`, `.destroy`, `.deactivate`

---

## Shared Patterns

### Authorization (apply to all FormRequests)

**Source:** `app/Http/Requests/Admin/StoreUserRequest.php` line 12
```php
public function authorize(): bool
{
    return $this->user()->hasRole('admin');
}
```

### Inertia Redirect with Flash

**Source:** `app/Http/Controllers/Admin/UserController.php` lines 44–45
```php
return redirect()->route('admin.users.index')
    ->with('success', 'User created.');
```
Apply the same `->with('success', '...')` pattern to all controller store/update/destroy/deactivate actions.

### Test setUp + adminUser Helper

**Source:** `tests/Feature/Auth/AdminUserManagementTest.php` lines 14–27

Copy to every new test class without modification:
```php
protected function setUp(): void
{
    parent::setUp();
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
}

private function adminUser(): User
{
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->syncRoles(['admin']);
    return $admin;
}
```

### Vue defineOptions Breadcrumbs

**Source:** `resources/js/pages/admin/users/Create.vue` lines 19–26
```typescript
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: '/admin/users' },
            { title: 'Add user', href: '/admin/users/create' },
        ],
    },
});
```
All new Vue pages must include this pattern. Update title and href values per page.

### Vue Card Form Layout

**Source:** `resources/js/pages/admin/users/Create.vue` lines 54–121
```html
<Card>
    <CardHeader>
        <CardTitle>...</CardTitle>
        <CardDescription>...</CardDescription>
    </CardHeader>
    <CardContent>
        <form id="create-X-form" class="grid grid-cols-2 gap-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="field">Field</Label>
                <Input id="field" v-model="form.field" ... />
                <InputError class="mt-2" :message="form.errors.field" />
            </div>
        </form>
    </CardContent>
    <CardFooter class="flex justify-end">
        <Button type="submit" form="create-X-form" :disabled="form.processing">
            Submit
        </Button>
    </CardFooter>
</Card>
```

### Vue Back Button Navigation

**Source:** `resources/js/pages/admin/users/Create.vue` lines 44–50
```html
<Button variant="ghost" size="sm" as-child class="-ml-2 mb-4">
    <Link href="/admin/brands">
        <ArrowLeft class="size-4 mr-1" />
        Back to brands
    </Link>
</Button>
```

### Vue Dialog Confirm Pattern

**Source:** `resources/js/pages/admin/users/Index.vue` lines 126–146
```html
<Dialog v-model:open="deleteOpen">
    <DialogContent>
        <DialogHeader>
            <DialogTitle>Confirm action?</DialogTitle>
            <DialogDescription>...</DialogDescription>
        </DialogHeader>
        <DialogFooter>
            <Button variant="outline" @click="deleteOpen = false">Cancel</Button>
            <Button variant="destructive" :disabled="deleteForm.processing" @click="executeDelete">
                Confirm
            </Button>
        </DialogFooter>
    </DialogContent>
</Dialog>
```

---

## No Analog Found

All files have analogs. The following capabilities are new to Phase 3 with no codebase analog (use RESEARCH.md patterns):

| Capability | Where Used | Pattern Source |
|---|---|---|
| Logo file upload + Storage::disk('public') | BrandController store/update | RESEARCH.md Pattern 1 |
| Inertia `_method: 'put'` spoofing for file upload | BrandEdit.vue submit() | RESEARCH.md Pattern 2 |
| StripeClient probe for key validation | StripeAccountController private helper | RESEARCH.md Pattern 3 |
| Native `<input type="color">` + text sync | BrandCreate.vue / BrandEdit.vue | RESEARCH.md Pattern 7 |
| Live brand preview via `:style` bindings | BrandCreate.vue / BrandEdit.vue | RESEARCH.md Pattern 7 |
| Secret key masking in edit props | StripeAccountController edit() | RESEARCH.md Pattern 8 |
| Nested scoped resource routing | routes/web.php | RESEARCH.md Pattern 5 |
| `generateUniqueSlug()` private method | BrandController | RESEARCH.md Pattern 6 |

---

## Metadata

**Analog search scope:** `app/Http/Controllers/Admin/`, `app/Http/Requests/Admin/`, `resources/js/pages/admin/users/`, `tests/Feature/Auth/`, `routes/web.php`, `app/Models/`, `database/factories/`
**Files scanned:** 13 existing files read
**Pattern extraction date:** 2026-05-03
