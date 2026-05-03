# Phase 2: Auth + User Management - Pattern Map

**Mapped:** 2026-05-03
**Files analyzed:** 17 (new/modified)
**Analogs found:** 17 / 17

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `bootstrap/app.php` | config | request-response | `bootstrap/app.php` (existing) | exact |
| `config/fortify.php` | config | request-response | `config/fortify.php` (existing) | exact |
| `app/Http/Controllers/Admin/UserController.php` | controller | CRUD | `app/Http/Controllers/Settings/ProfileController.php` | role-match |
| `app/Http/Requests/Admin/StoreUserRequest.php` | middleware | request-response | `app/Http/Requests/Settings/PasswordUpdateRequest.php` | role-match |
| `app/Http/Requests/Admin/UpdateUserRequest.php` | middleware | request-response | `app/Http/Requests/Settings/ProfileUpdateRequest.php` | role-match |
| `app/Http/Middleware/HandleInertiaRequests.php` | middleware | request-response | `app/Http/Middleware/HandleInertiaRequests.php` (existing) | exact |
| `routes/web.php` | config | request-response | `routes/settings.php` | role-match |
| `resources/js/components/AppSidebar.vue` | component | request-response | `resources/js/components/AppSidebar.vue` (existing) | exact |
| `resources/js/pages/admin/users/Index.vue` | component | CRUD | `resources/js/pages/settings/Profile.vue` | role-match |
| `resources/js/pages/admin/users/Create.vue` | component | CRUD | `resources/js/pages/settings/Profile.vue` | role-match |
| `resources/js/pages/admin/users/Edit.vue` | component | CRUD | `resources/js/pages/settings/Security.vue` | role-match |
| `resources/js/pages/placeholders/ComingSoon.vue` | component | request-response | `resources/js/pages/Dashboard.vue` | role-match |
| `resources/js/types/auth.ts` | utility | transform | `resources/js/types/auth.ts` (existing) | exact |
| `resources/js/types/navigation.ts` | utility | transform | `resources/js/types/navigation.ts` (existing) | exact |
| `tests/Feature/Auth/AdminUserManagementTest.php` | test | CRUD | `tests/Feature/Settings/ProfileUpdateTest.php` | role-match |
| `tests/Feature/Auth/AdminAccessControlTest.php` | test | request-response | `tests/Feature/Auth/AuthenticationTest.php` | role-match |
| `tests/Feature/Auth/SessionPersistenceTest.php` | test | request-response | `tests/Feature/Auth/AuthenticationTest.php` | role-match |
| `tests/Feature/Auth/PublicPaymentRouteTest.php` | test | request-response | `tests/Feature/DashboardTest.php` | role-match |

---

## Pattern Assignments

### `bootstrap/app.php` (config, request-response)

**Analog:** `bootstrap/app.php` (existing — modify in place)

**Existing withMiddleware block** (lines 16-24):
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

    $middleware->web(append: [
        HandleAppearance::class,
        HandleInertiaRequests::class,
        AddLinkHeadersForPreloadedAssets::class,
    ]);
})
```

**Insert alias registration inside the same closure** — add after the `$middleware->web(append: [...])` block:
```php
$middleware->alias([
    'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
    'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
    'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
]);
```

**Critical:** Do NOT add a second `->withMiddleware()` call. The alias block merges into the existing closure.

---

### `config/fortify.php` (config, request-response)

**Analog:** `config/fortify.php` (existing — modify in place)

**Existing features array** (lines 146-155):
```php
'features' => [
    Features::registration(),
    Features::resetPasswords(),
    Features::emailVerification(),
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]),
],
```

**Change to** (remove both `registration()` and `emailVerification()`):
```php
'features' => [
    // Features::registration(),   // REMOVED — invite-only; no public sign-up
    Features::resetPasswords(),
    // Features::emailVerification(), // REMOVED — internal tool; simplifies test setup (Pitfall 6)
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]),
],
```

Removing `emailVerification()` also eliminates Pitfall 6 (tests redirected to `/email/verify`). The `canRegister` prop in `FortifyServiceProvider::loginView()` resolves automatically to `false` via `Features::enabled(Features::registration())` — no change needed to `Login.vue`.

---

### `app/Http/Controllers/Admin/UserController.php` (controller, CRUD)

**Analog:** `app/Http/Controllers/Settings/ProfileController.php`

**Imports pattern** (from analog, lines 1-13):
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
```

**Inertia render pattern** (from `ProfileController::edit`, line 22):
```php
return Inertia::render('settings/Profile', [...props]);
// New files use 'admin/users/Index', 'admin/users/Create', 'admin/users/Edit'
```

**Flash pattern** (from `ProfileController::update`, line 41):
```php
Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);
return to_route('profile.edit');
// Admin controllers use: redirect()->route('admin.users.index')->with('success', 'User created.')
// Note: to_route() and redirect()->route() are equivalent; use redirect()->route() for named resource routes
```

**Delete pattern** (from `ProfileController::destroy`, lines 49-61):
```php
public function destroy(ProfileDeleteRequest $request): RedirectResponse
{
    $user = $request->user();
    Auth::logout();
    $user->delete();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect('/');
}
// Admin destroy is simpler — no logout needed; redirect to index
```

**Self-delete guard** (new pattern, no existing analog — add to `destroy()`):
```php
public function destroy(Request $request, User $user): RedirectResponse
{
    if ($user->id === $request->user()->id) {
        return back()->withErrors(['user' => 'Cannot delete your own account.']);
    }
    $user->delete();
    return redirect()->route('admin.users.index')->with('success', 'User deleted.');
}
```

---

### `app/Http/Requests/Admin/StoreUserRequest.php` (middleware/form-request, request-response)

**Analog:** `app/Http/Requests/Settings/PasswordUpdateRequest.php`

**Class structure pattern** (lines 1-25):
```php
<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    public function rules(): array
    {
        return [
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ];
    }
}
```

**New file namespace and structure:**
```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::default()],
            'role'     => ['required', 'string', 'in:admin,user'],
        ];
    }
}
```

**Note:** No `password_confirmation` rule — admin sets password directly without a confirm field (D-04). `Password::default()` matches the project's `PasswordValidationRules` concern convention.

---

### `app/Http/Requests/Admin/UpdateUserRequest.php` (middleware/form-request, request-response)

**Analog:** `app/Http/Requests/Settings/ProfileUpdateRequest.php`

**Unique email rule with self-exclusion pattern** (from `ProfileValidationRules` concern, used in `ProfileUpdateRequest`):
```php
// ProfileUpdateRequest delegates to: $this->profileRules($this->user()->id)
// which generates: 'email' => ['unique:users,email,' . $userId]
```

**New file pattern** (password nullable on update):
```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', "unique:users,email,{$userId}"],
            'password' => ['nullable', 'string', Password::default()],
            'role'     => ['required', 'string', 'in:admin,user'],
        ];
    }
}
```

**In controller `update()` — only update password when filled:**
```php
$data = $request->safe()->only('name', 'email');
if ($request->filled('password')) {
    $data['password'] = $request->validated('password');
}
$user->update($data);
$user->syncRoles([$request->validated('role')]);
```

---

### `app/Http/Middleware/HandleInertiaRequests.php` (middleware, request-response)

**Analog:** `app/Http/Middleware/HandleInertiaRequests.php` (existing — modify in place)

**Current share() method** (lines 36-46):
```php
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'name' => config('app.name'),
        'auth' => [
            'user' => $request->user(),
        ],
        'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
    ];
}
```

**Replace `'auth'` key only** — expand from raw User model to explicit array with roles:
```php
'auth' => [
    'user' => $request->user() ? [
        'id'    => $request->user()->id,
        'name'  => $request->user()->name,
        'email' => $request->user()->email,
        'roles' => $request->user()->getRoleNames(), // Collection of strings e.g. ['admin']
    ] : null,
],
```

**Why:** The raw `$request->user()` serializes the full Eloquent model. Explicit array exposes only what Vue needs. `getRoleNames()` returns a plain Collection of strings — safe to serialize, no sensitive data.

---

### `routes/web.php` (config, request-response)

**Analog:** `routes/settings.php`

**Existing route group pattern** (lines 7-12 of `settings.php`):
```php
Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');
    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});
```

**Current web.php** (lines 1-14):
```php
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'Welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
```

**Add admin route group and pay stub** — append before the `require` line:
```php
use App\Http\Controllers\Admin\UserController as AdminUserController;

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::resource('users', AdminUserController::class)
            ->except(['show']);
    });

// Phase 5 stub — MUST be outside auth group (D-07)
Route::get('/pay/{uuid}', fn () => abort(404))->name('pay.show');
```

**Route names generated:** `admin.users.index`, `admin.users.create`, `admin.users.store`, `admin.users.edit`, `admin.users.update`, `admin.users.destroy`. Run `php artisan wayfinder:generate` after adding routes.

---

### `resources/js/components/AppSidebar.vue` (component, request-response)

**Analog:** `resources/js/components/AppSidebar.vue` (existing — modify in place)

**Existing script setup** (lines 1-39):
```typescript
import { Link } from '@inertiajs/vue3';
import { BookOpen, FolderGit2, LayoutGrid } from 'lucide-vue-next';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, ... } from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
];
```

**usePage pattern** (from `NavUser.vue` lines 1-21 — exact project pattern for reading auth props):
```typescript
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
```

**Updated AppSidebar script setup** — add role-computed and expanded nav:
```typescript
import { usePage } from '@inertiajs/vue3';
import { Building2, CreditCard, LayoutGrid, Settings, Users } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();
const isAdmin = computed(() =>
    page.props.auth.user?.roles?.includes('admin') ?? false
);

const mainNavItems = computed((): NavItem[] => [
    { title: 'Dashboard',  href: dashboard(),         icon: LayoutGrid },
    { title: 'Brands',     href: '/admin/brands',     icon: Building2 },
    { title: 'Payments',   href: '/payments',          icon: CreditCard },
    ...(isAdmin.value ? [{ title: 'Users', href: '/admin/users', icon: Users }] : []),
    { title: 'Settings',   href: '/settings/profile', icon: Settings },
]);
```

**Template change** — `:items` becomes dynamic:
```vue
<NavMain :items="mainNavItems" />
```

**Note:** `footerNavItems` (Repository, Documentation links) should be removed or replaced with project-relevant links.

---

### `resources/js/pages/admin/users/Index.vue` (component, CRUD)

**Analog:** `resources/js/pages/settings/Profile.vue`

**Script setup structure pattern** (lines 1-34):
```typescript
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
// Wayfinder routes (generated after routes added):
// import { index, create, edit, destroy } from '@/routes/admin/users';
```

**defineOptions layout/breadcrumbs pattern** (from `Profile.vue` lines 21-30):
```typescript
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: '/admin/users' },
        ],
    },
});
```

**Props pattern** (from `Profile.vue` lines 14-19):
```typescript
type Props = {
    users: Array<{
        id: number;
        name: string;
        email: string;
        roles: string[];
    }>;
};
defineProps<Props>();
```

**Page structure** — `Head` + list table with create button, edit link per row, delete form per row. Use `Card` + `Button` + `Badge` (all seeded in Phase 1).

---

### `resources/js/pages/admin/users/Create.vue` (component, CRUD)

**Analog:** `resources/js/pages/settings/Profile.vue`

**Form pattern using Inertia `Form` component** (from `Profile.vue` lines 48-108):
```vue
<Form
    v-bind="ProfileController.update.form()"
    class="space-y-6"
    v-slot="{ errors, processing }"
>
    <div class="grid gap-2">
        <Label for="name">Name</Label>
        <Input id="name" name="name" required />
        <InputError class="mt-2" :message="errors.name" />
    </div>
    <Button :disabled="processing">Save</Button>
</Form>
```

**For admin user create** — use `useForm` from `@inertiajs/vue3` (direct Inertia form, no Wayfinder action binding until routes are generated):
```typescript
import { useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    role: 'user',
});

function submit() {
    form.post('/admin/users');
}
```

**Props (roles list from controller):**
```typescript
type Props = {
    roles: string[];
};
defineProps<Props>();
```

**Select component** — use shadcn-vue `Select` (available in `resources/js/components/ui/select/`) for role field. All other fields use `Input` + `Label` + `InputError` (the established form field pattern from `Profile.vue`).

---

### `resources/js/pages/admin/users/Edit.vue` (component, CRUD)

**Analog:** `resources/js/pages/settings/Security.vue` (has multiple fields + conditional logic)

**Props with existing data pattern** (from `Profile.vue` lines 32-33 — user data pre-populated):
```typescript
const page = usePage();
const user = computed(() => page.props.auth.user);
// For Edit.vue: user comes as a prop, not from page.props.auth
```

**Edit.vue props:**
```typescript
type Props = {
    user: {
        id: number;
        name: string;
        email: string;
        roles: string[];
    };
    roles: string[];
};
const props = defineProps<Props>();
```

**Form with pre-populated values:**
```typescript
const form = useForm({
    name: props.user.name,
    email: props.user.email,
    password: '',        // blank = keep existing (UpdateUserRequest handles this)
    role: props.user.roles[0] ?? 'user',
});

function submit() {
    form.patch(`/admin/users/${props.user.id}`);
}
```

---

### `resources/js/pages/placeholders/ComingSoon.vue` (component, request-response)

**Analog:** `resources/js/pages/Dashboard.vue`

**Minimal page structure pattern** (from `Dashboard.vue` lines 1-47):
```typescript
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Dashboard', href: dashboard() },
        ],
    },
});
</script>

<template>
    <Head title="Dashboard" />
    <div class="flex h-full flex-1 flex-col gap-4 ...">
        <PlaceholderPattern />
    </div>
</template>
```

**ComingSoon.vue pattern** — accept `title` prop for reuse across Brands, Payments, Settings stubs:
```typescript
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

const props = defineProps<{ title?: string }>();
</script>

<template>
    <Head :title="title ?? 'Coming Soon'" />
    <div class="flex h-full flex-1 flex-col items-center justify-center gap-4 rounded-xl p-8">
        <h2 class="text-xl font-semibold text-muted-foreground">
            {{ title ?? 'Coming Soon' }}
        </h2>
        <p class="text-sm text-muted-foreground">This section is under construction.</p>
    </div>
</template>
```

---

### `resources/js/types/auth.ts` (utility, transform)

**Analog:** `resources/js/types/auth.ts` (existing — modify in place)

**Current User type** (lines 1-10):
```typescript
export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};
```

**Add `roles` field** — insert after `email`:
```typescript
export type User = {
    id: number;
    name: string;
    email: string;
    roles: string[];           // ADD: array of role name strings e.g. ['admin']
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};
```

**Note:** `HandleInertiaRequests` now omits `created_at`, `updated_at`, `email_verified_at` from the serialized user. The `[key: string]: unknown` index signature preserves flexibility. Consider making `email_verified_at`, `created_at`, `updated_at` optional (`?`) since the new share() no longer sends them.

---

### `resources/js/types/navigation.ts` (utility, transform)

**Analog:** `resources/js/types/navigation.ts` (existing — modify in place)

**Current NavItem type** (lines 9-14):
```typescript
export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
};
```

**No change required if using computed filter in AppSidebar.** If `adminOnly` field approach is preferred instead of the computed spread pattern, add:
```typescript
export type NavItem = {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon;
    isActive?: boolean;
    adminOnly?: boolean;    // optional: gate nav item to admin role
};
```

Either approach is valid per RESEARCH.md Pattern 8. The computed spread (`...(isAdmin.value ? [...] : [])`) requires no type change.

---

### `tests/Feature/Auth/AdminUserManagementTest.php` (test, CRUD)

**Analog:** `tests/Feature/Settings/ProfileUpdateTest.php`

**Test class structure pattern** (lines 1-10):
```php
<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;
```

**actingAs + route + assertion pattern** (lines 13-22):
```php
public function test_profile_page_is_displayed()
{
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
}
```

**AdminUserManagementTest structure:**
```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

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
}
```

**CRUD test pattern** (modelled on `test_profile_information_can_be_updated` lines 23-44):
```php
public function test_admin_can_create_user()
{
    $admin = $this->adminUser();

    $this->actingAs($admin)
        ->post(route('admin.users.store'), [
            'name'     => 'New User',
            'email'    => 'new@example.com',
            'password' => 'Password1!',
            'role'     => 'user',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('admin.users.index'));

    $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
}
```

---

### `tests/Feature/Auth/AdminAccessControlTest.php` (test, request-response)

**Analog:** `tests/Feature/Auth/AuthenticationTest.php`

**Unauthenticated redirect pattern** (from `DashboardTest.php` lines 13-17):
```php
public function test_guests_are_redirected_to_the_login_page()
{
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
}
```

**assertForbidden pattern for role enforcement:**
```php
public function test_non_admin_gets_403_on_admin_users()
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->syncRoles(['user']);

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden(); // 403 from role:admin middleware
}
```

---

### `tests/Feature/Auth/SessionPersistenceTest.php` (test, request-response)

**Analog:** `tests/Feature/Auth/AuthenticationTest.php`

**actingAs pattern** (lines 22-32):
```php
public function test_users_can_authenticate_using_the_login_screen()
{
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
}
```

**Remember token test pattern:**
```php
public function test_user_session_persists_with_remember_token()
{
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->post(route('login.store'), [
        'email'    => $user->email,
        'password' => 'password',
        'remember' => true,
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
    $this->assertNotNull($user->fresh()->remember_token);
}
```

---

### `tests/Feature/Auth/PublicPaymentRouteTest.php` (test, request-response)

**Analog:** `tests/Feature/DashboardTest.php`

**Guest redirect pattern** (lines 13-17):
```php
public function test_guests_are_redirected_to_the_login_page()
{
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
}
```

**PublicPaymentRoute — inverse pattern (no redirect for guest):**
```php
public function test_pay_route_is_reachable_without_authentication()
{
    // Stub returns 404 (not 302 redirect to login)
    $this->get(route('pay.show', ['uuid' => 'test-uuid']))
        ->assertNotFound(); // 404, not redirect
}

public function test_pay_route_does_not_redirect_guest_to_login()
{
    $response = $this->get(route('pay.show', ['uuid' => 'test-uuid']));

    $response->assertNotRedirect(route('login'));
}
```

---

## Shared Patterns

### Spatie Permission Cache Clearing
**Source:** RESEARCH.md Pitfall 5 (backed by spatie v7 docs)
**Apply to:** All new test files that use `RefreshDatabase` + roles
```php
protected function setUp(): void
{
    parent::setUp();
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
}
```

### Inertia Auth User Access (Vue)
**Source:** `resources/js/components/NavUser.vue` (lines 19-21)
**Apply to:** `AppSidebar.vue` and any other Vue component needing auth user data
```typescript
const page = usePage();
const user = computed(() => page.props.auth.user);
// After Phase 2: user.roles is a string[] e.g. ['admin']
const isAdmin = computed(() => user.value?.roles?.includes('admin') ?? false);
```

### Inertia Page + Breadcrumbs Layout
**Source:** `resources/js/pages/settings/Profile.vue` (lines 21-30) and `Dashboard.vue` (lines 7-15)
**Apply to:** All new Vue page components (`Index.vue`, `Create.vue`, `Edit.vue`, `ComingSoon.vue`)
```typescript
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Page Title', href: '/route' },
        ],
    },
});
```

### Form Field Trio (Label + Input + InputError)
**Source:** `resources/js/pages/settings/Profile.vue` (lines 53-81)
**Apply to:** `Create.vue`, `Edit.vue` for all text/email/password fields
```vue
<div class="grid gap-2">
    <Label for="field-id">Field Label</Label>
    <Input id="field-id" name="field_name" ... />
    <InputError class="mt-2" :message="errors.field_name" />
</div>
```

### Controller Inertia Redirect with Flash
**Source:** `app/Http/Controllers/Settings/ProfileController.php` (lines 41-43)
**Apply to:** `AdminUserController` store/update/destroy methods
```php
// ProfileController uses Inertia::flash() + to_route()
Inertia::flash('toast', ['type' => 'success', 'message' => __('...')]);
return to_route('route.name');

// AdminUserController uses redirect()->route() + with()
// (with() puts message in session flash, not Inertia flash)
return redirect()->route('admin.users.index')->with('success', 'User created.');
// Use redirect()->route() for resource routes — consistent with settings.php pattern
```

### FormRequest authorize() Pattern
**Source:** All `app/Http/Requests/Settings/` files (no explicit `authorize()` — defaults to `true`)
**Apply to:** `StoreUserRequest`, `UpdateUserRequest`
```php
// Settings requests use no authorize() (implicitly return true)
// Admin requests add an explicit role check:
public function authorize(): bool
{
    return $this->user()->hasRole('admin');
    // Route middleware already enforces this; authorize() provides defence-in-depth
}
```

### Route Group Middleware Stack
**Source:** `routes/settings.php` (lines 7-12)
**Apply to:** Admin route group in `routes/web.php`
```php
// Settings pattern:
Route::middleware(['auth'])->group(function () { ... });
Route::middleware(['auth', 'verified'])->group(function () { ... });

// Admin pattern (adds role middleware):
Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () { ... });
```

---

## No Analog Found

All files have analogs in the codebase. No entries in this section.

---

## Metadata

**Analog search scope:** `app/Http/Controllers/`, `app/Http/Requests/`, `app/Http/Middleware/`, `routes/`, `resources/js/pages/`, `resources/js/components/`, `resources/js/types/`, `tests/Feature/`, `bootstrap/`, `config/`
**Files scanned:** 28
**Pattern extraction date:** 2026-05-03
