# Phase 2: Auth + User Management — Research

**Researched:** 2026-05-03
**Domain:** Laravel Fortify, spatie/laravel-permission v7, Inertia.js v3 shared data, admin CRUD
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Remove `Features::registration()` from `config/fortify.php` features array. Public registration is permanently disabled for v1. Register.vue page deletion vs 404 is Claude's discretion.
- **D-02:** Admin creates new team accounts via a dedicated `/admin/users` UI form. No Tinker/Artisan-only approach.
- **D-03:** `/admin/users` section supports **full CRUD**: list all users, create new user (name, email, password, role), edit existing user (including role change), delete/deactivate user.
- **D-04:** When creating a new user, Admin sets the password directly in the form. No auto-generated passwords, no email invite in Phase 2.
- **D-05:** `/admin/users` lives under the `/admin/` prefix — establishing the pattern for all future admin pages.
- **D-06:** Both layers of enforcement required:
  1. Route middleware: Spatie `role:admin` on all `/admin/*` routes.
  2. UI gates: Shared Inertia data passes `auth.user` with roles; Vue components use `v-if` checks.
- **D-07:** `/pay/{uuid}` route must NOT be in the `auth` middleware group. Stub or note in routes for Phase 5.
- **D-08:** Build a real nav shell after login — `AppSidebarLayout.vue` is the chosen variant.
- **D-09:** Sidebar nav links: Brands, Payments, Users (Admin-only), Settings. Non-backing pages show a "coming soon" placeholder.
- **D-10:** Nav establishes structural pattern all downstream phases build on.
- **D-11:** 2FA UI **deferred entirely**. `TwoFactorAuthenticatable` stays on the User model but no 2FA settings page built.

### Claude's Discretion

- Whether `Register.vue` is deleted or returns 403/404
- Exact shadcn-vue components used in the `/admin/users` form and table
- Pagination vs. full list on the users table (full list acceptable for small team)
- Route naming conventions under `/admin/`
- Fortify's `home` path (already set to `/dashboard` — keep as-is)

### Deferred Ideas (OUT OF SCOPE)

- Invite-only registration with signed URLs (v2)
- 2FA settings UI
- User deactivation mechanism details (soft-delete vs. `is_active` flag) — full CRUD in scope, mechanism is discretion
- Admin-assigned password reset forced-reset-on-next-login
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| AUTH-01 | User can log in with email and password via Laravel Fortify | Fortify already installed and wired; login route active; `FortifyServiceProvider` binds Login.vue |
| AUTH-02 | User session persists across page loads until explicit logout | Laravel session cookie + `remember_token`; `remember` checkbox already in Login.vue |
| AUTH-03 | User can log out from any authenticated page | `/logout` POST route registered by Fortify; `NavUser.vue` needs logout link |
| AUTH-04 | Admin can assign roles (Admin / User) to team members | spatie/laravel-permission v7 `assignRole()`; Admin user CRUD form with role select |
| AUTH-05 | Access to admin features is restricted to Admin role only | `role:admin` middleware on `/admin/*` group; Vue `v-if` for UI gates |
| AUTH-06 | Unauthenticated access to client payment page (/pay/{uuid}) is allowed | Route stub outside `auth` group; confirmed pattern for Phase 5 |
</phase_requirements>

---

## Summary

Phase 1 delivered a fully wired stack: Fortify installed with all view bindings pointing to Inertia pages, spatie/laravel-permission v7 with `HasRoles` on User, `TwoFactorAuthenticatable` on User, roles seeded (`admin`, `user`), one admin user seeded. Phase 2 activates auth flows and adds admin CRUD — it is almost entirely configuration, routing, and UI work, with minimal new PHP infrastructure required.

The three concrete gaps Phase 2 must close are: (1) disable Fortify registration via config change, (2) register spatie role middleware alias in `bootstrap/app.php` and apply it to an `/admin/*` route group, and (3) build `AdminUserController` with standard CRUD backed by Fortify-compatible validation. The Inertia shared data in `HandleInertiaRequests` already passes `auth.user` — it needs `roles` added so Vue components can render conditional nav items.

The key gotcha unique to this phase is the **Wayfinder route file regeneration**: adding new named routes for `/admin/users/*` requires running `php artisan wayfinder:generate` to emit TypeScript helpers so Vue components can use type-safe route references. The project uses `@laravel/vite-plugin-wayfinder` for automatic regeneration in dev mode, but it must also run after each plan for tests.

**Primary recommendation:** Register `role` middleware alias in `bootstrap/app.php`, add an `auth + role:admin` route group for `/admin/users`, build `AdminUserController` with `StoreUserRequest`/`UpdateUserRequest` form requests, extend `HandleInertiaRequests::share()` with roles, update `AppSidebar.vue` to conditionally show Users nav item.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Login / logout session | Backend (Fortify) | Frontend (Inertia page) | Session is server-side; UI is Inertia render |
| Registration blocking | Backend (config) | — | `Features::registration()` removal prevents routes from registering |
| Admin role enforcement | Backend (middleware) | Frontend (v-if) | Middleware is authoritative; UI gate is UX-only |
| Admin user CRUD | Backend (controller) | Frontend (Inertia pages) | Controller owns validation and DB writes |
| Roles in shared data | Backend (HandleInertiaRequests) | Frontend (usePage) | Server pushes role info; frontend reads via `page.props.auth` |
| Sidebar nav with admin-only links | Frontend (Vue) | — | `v-if` on role name from shared props |
| `/pay/{uuid}` route isolation | Backend (routes/web.php) | — | Must be outside `auth` group; stub only in Phase 2 |

---

## Standard Stack

All packages are already installed. No `composer require` or `npm install` needed for Phase 2.

### Core (already installed)

| Library | Installed Version | Purpose | Phase 2 Usage |
|---------|-------------------|---------|---------------|
| `laravel/fortify` | ^1.37 [VERIFIED: composer.json] | Headless auth backend | Login/logout/session; disable registration |
| `spatie/laravel-permission` | ^7.4 [VERIFIED: composer.json] | Role-based access control | `role:admin` middleware; `assignRole()` in UserController |
| `inertiajs/inertia-laravel` | ^3.0 [VERIFIED: composer.json] | Server-side Inertia adapter | Shared data in HandleInertiaRequests |
| `@inertiajs/vue3` | ^3.0.0 [VERIFIED: package.json] | Client-side Inertia adapter | `usePage()` for reading `auth.user.roles` |
| `pestphp/pest` | ^4.6 [VERIFIED: composer.json] | Test framework | Feature tests for all AUTH-* requirements |
| `pestphp/pest-plugin-laravel` | ^4.1 [VERIFIED: composer.json] | Laravel Pest integration | `actingAs()`, `RefreshDatabase` |

### Supporting (already installed)

| Library | Installed Version | Purpose | Phase 2 Usage |
|---------|-------------------|---------|---------------|
| `laravel/wayfinder` | ^0.1.14 [VERIFIED: composer.json] | Type-safe route helpers | Must regenerate after adding `/admin/users` routes |
| `reka-ui` | ^2.6.1 [VERIFIED: package.json] | Headless UI primitives (shadcn-vue) | Form components for admin user form |
| `lucide-vue-next` | ^0.468.0 [VERIFIED: package.json] | Icon library | Nav icons for Brands, Payments, Users, Settings |

**No new packages required for Phase 2.**

---

## Architecture Patterns

### System Architecture Diagram

```
Browser (authenticated user)
    |
    | [Inertia request with session cookie]
    v
Laravel Router (routes/web.php)
    |
    |-- Route::post('/logout') --> Fortify AuthenticatedSessionController
    |
    |-- Route::middleware(['auth', 'verified']) -->
    |       Route::inertia('/dashboard') --> Dashboard.vue
    |
    |-- Route::middleware(['auth', 'role:admin'])->prefix('admin') -->
    |       Route::resource('/users', AdminUserController)
    |           index  --> admin/users/Index.vue  (user list)
    |           create --> admin/users/Create.vue (create form)
    |           store  --> AdminUserController@store
    |           edit   --> admin/users/Edit.vue   (edit form)
    |           update --> AdminUserController@update
    |           destroy--> AdminUserController@destroy
    |
    |-- Route stub: Route::get('/pay/{uuid}', ...) [NO auth middleware]
    |
    v
HandleInertiaRequests middleware
    |  shares: auth.user (with roles loaded)
    |  shares: name, sidebarOpen (already present)
    v
Inertia page component
    |  AppSidebarLayout.vue wraps authenticated pages
    |  AppSidebar.vue reads page.props.auth.user.roles
    |  v-if="isAdmin" gates the "Users" nav link
```

### Recommended Project Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   └── Admin/
│   │       └── UserController.php     # Admin CRUD for team accounts
│   ├── Middleware/                    # No new middleware needed
│   └── Requests/
│       ├── Admin/
│       │   ├── StoreUserRequest.php   # Validation for user creation
│       │   └── UpdateUserRequest.php  # Validation for user editing
resources/js/
├── pages/
│   ├── admin/
│   │   └── users/
│   │       ├── Index.vue              # User list table
│   │       ├── Create.vue             # Create user form
│   │       └── Edit.vue               # Edit user form
│   └── placeholders/
│       └── ComingSoon.vue             # Stub for Brands, Payments, Settings
├── components/
│   └── AppSidebar.vue                 # Update with role-conditional nav
```

### Pattern 1: Registering spatie role middleware in Laravel 13

Laravel 13 uses `bootstrap/app.php` for all middleware configuration. The `withMiddleware()` closure receives a `Middleware` object.

```php
// bootstrap/app.php
// Source: https://spatie.be/docs/laravel-permission/v7/basic-usage/middleware
->withMiddleware(function (Middleware $middleware): void {
    // ... existing config ...
    $middleware->alias([
        'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
        'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
    ]);
})
```

**Critical:** This registration belongs INSIDE the existing `->withMiddleware()` call — do not add a second `->withMiddleware()`.

### Pattern 2: Admin route group with role:admin

```php
// routes/web.php
// Source: spatie docs + existing web.php structure
use App\Http\Controllers\Admin\UserController as AdminUserController;

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::resource('users', AdminUserController::class)
            ->except(['show']); // list, create, store, edit, update, destroy
    });

// Phase 5 stub — MUST be outside auth group (D-07)
Route::get('/pay/{uuid}', fn () => abort(404))->name('pay.show');
```

**Note:** `->name('admin.')` establishes the route name prefix. Resource routes will be `admin.users.index`, `admin.users.create`, etc. — Wayfinder will generate these as TypeScript helpers.

### Pattern 3: Fortify registration disable (D-01)

```php
// config/fortify.php — remove Features::registration() from the array
// Source: https://laravel.com/docs/13.x/fortify#disabling-views [VERIFIED]
'features' => [
    // Features::registration(),   <-- REMOVE THIS LINE
    Features::resetPasswords(),
    Features::emailVerification(),
    Features::twoFactorAuthentication([
        'confirm' => true,
        'confirmPassword' => true,
    ]),
],
```

When removed: Fortify does NOT register `GET /register` or `POST /register`. The `canRegister` prop in `FortifyServiceProvider::loginView()` will resolve to `false` via `Features::enabled(Features::registration())`, automatically hiding the "Sign up" link in `Login.vue` — no template change needed. [VERIFIED: codebase inspection of FortifyServiceProvider.php and Login.vue]

**Register.vue disposition (Claude's discretion):** Leave `Register.vue` as a dead file. The route no longer exists so the file is unreachable. Deleting it would also require removing the `register` import in `Login.vue` (line 9) to avoid a TypeScript/lint error — simpler to keep the file.

**RegistrationTest.php:** Already handles disabled registration via `skipUnlessFortifyHas(Features::registration())` — existing tests self-skip when registration is disabled. [VERIFIED: codebase inspection]

### Pattern 4: Extending HandleInertiaRequests with roles (D-06 UI gates)

The existing `share()` method passes `auth.user` as the raw User model. Roles need to be loaded as a simple array for Vue consumption.

```php
// app/Http/Middleware/HandleInertiaRequests.php
// Source: https://inertiajs.com/shared-data [CITED]
public function share(Request $request): array
{
    return [
        ...parent::share($request),
        'name'        => config('app.name'),
        'auth'        => [
            'user' => $request->user()?->only('id', 'name', 'email')
                ? array_merge(
                    $request->user()->only('id', 'name', 'email'),
                    ['roles' => $request->user()->getRoleNames()]
                )
                : null,
        ],
        'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
    ];
}
```

**Simpler equivalent (recommended for clarity):**

```php
'auth' => [
    'user' => $request->user() ? [
        'id'    => $request->user()->id,
        'name'  => $request->user()->name,
        'email' => $request->user()->email,
        'roles' => $request->user()->getRoleNames(), // returns Collection of role name strings
    ] : null,
],
```

`getRoleNames()` returns an Illuminate Collection of strings — e.g., `['admin']`. Vue can check `page.props.auth.user.roles.includes('admin')`.

**TypeScript type update required:** Add `roles: string[]` to the `User` type in `resources/js/types/auth.ts`.

### Pattern 5: AdminUserController (CRUD)

Do NOT reuse `app/Actions/Fortify/CreateNewUser.php` — that action is wired to the Fortify registration flow (now disabled). Admin user creation uses a dedicated controller. [VERIFIED: codebase inspection of CreateNewUser.php]

```php
// app/Http/Controllers/Admin/UserController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users/Index', [
            'users' => User::with('roles')->orderBy('name')->get()
                ->map(fn ($user) => [
                    'id'    => $user->id,
                    'name'  => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Create', [
            'roles' => Role::pluck('name'),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = User::create($request->safe()->only('name', 'email', 'password'));
        $user->syncRoles([$request->validated('role')]);

        return redirect()->route('admin.users.index')
            ->with('success', 'User created.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('admin/users/Edit', [
            'user'  => array_merge($user->only('id', 'name', 'email'), [
                'roles' => $user->getRoleNames(),
            ]),
            'roles' => Role::pluck('name'),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $user->update($request->safe()->only('name', 'email', 'password'));
        $user->syncRoles([$request->validated('role')]);

        return redirect()->route('admin.users.index')
            ->with('success', 'User updated.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', 'User deleted.');
    }
}
```

### Pattern 6: Form Request — StoreUserRequest

```php
// app/Http/Requests/Admin/StoreUserRequest.php
namespace App\Http\Requests\Admin;

use App\Concerns\PasswordValidationRules;
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

**Note:** `Password::default()` is the same rule used in `PasswordValidationRules` concern already in the project. Admin-set passwords do NOT use `confirmed` rule — there is no `password_confirmation` field when admin sets a password.

### Pattern 7: UpdateUserRequest (password optional on edit)

```php
// app/Http/Requests/Admin/UpdateUserRequest.php
public function rules(): array
{
    $userId = $this->route('user')->id;

    return [
        'name'     => ['required', 'string', 'max:255'],
        'email'    => ['required', 'email', 'max:255', "unique:users,email,{$userId}"],
        'password' => ['nullable', 'string', Password::default()], // blank = keep existing
        'role'     => ['required', 'string', 'in:admin,user'],
    ];
}
```

**In the controller `update()` method:** Only update password if provided:
```php
$data = $request->safe()->only('name', 'email');
if ($request->filled('password')) {
    $data['password'] = $request->validated('password');
}
$user->update($data);
```

### Pattern 8: Vue role check (D-06 UI gate)

```typescript
// In AppSidebar.vue — access auth.user.roles from shared Inertia props
// Source: existing NavUser.vue pattern [VERIFIED: codebase inspection]
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const isAdmin = computed(() =>
    page.props.auth.user?.roles?.includes('admin') ?? false
);
```

```vue
<!-- Nav item conditional rendering -->
<NavMain :items="mainNavItems.filter(item => !item.adminOnly || isAdmin)" />
```

Or inline in the nav items array:

```typescript
const mainNavItems: NavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'Brands',    href: '/admin/brands',   icon: Building2 },
    { title: 'Payments',  href: '/payments',        icon: CreditCard },
    // Admin-only items filtered by v-if or computed
    ...(isAdmin.value ? [{ title: 'Users', href: '/admin/users', icon: Users }] : []),
    { title: 'Settings',  href: '/settings/profile', icon: Settings },
];
```

**Note:** `NavItem` type does not currently have an `adminOnly` field. Extending it or using a computed filtered array are both valid — Claude's discretion.

### Anti-Patterns to Avoid

- **Using `CreateNewUser` Fortify action for admin user creation:** That action is for the registration flow (now disabled). It validates `password_confirmation` and has no `role` field. Build a separate `AdminUserController` with `StoreUserRequest`.
- **Putting `/pay/{uuid}` inside the `auth` middleware group:** D-07 is explicit — this route must be reachable without a session. Even as a stub, it must live outside auth groups.
- **Checking `$user->role` as a column:** Spatie roles are not a column on the users table. Always use `$user->hasRole('admin')` or `$user->getRoleNames()`. The only user table columns are the standard Laravel ones.
- **Manually querying the `roles` table for validation:** The `in:admin,user` rule in form requests is sufficient for v1. Using `Rule::exists('roles', 'name')` is more robust but couples validation to DB state — fine if preferred, but `in:admin,user` matches the seeded roles exactly.
- **Adding `role:admin` to the global middleware stack:** The `role:admin` middleware should only be applied to the `/admin/*` group, not globally. Applying it globally would block all non-admin users from everything.
- **Not regenerating Wayfinder routes:** After adding named routes for `/admin/users`, running `php artisan wayfinder:generate` produces TypeScript helpers. In dev mode `@laravel/vite-plugin-wayfinder` auto-regenerates, but the first run after adding routes needs a manual trigger if Vite is not running.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Role-based route protection | Custom auth middleware checking role | `role:admin` from spatie/laravel-permission | Already installed; handles guard-specific role checks |
| Password hashing | Manual bcrypt calls | Laravel `password` cast on User model (already set) | `User::create(['password' => 'plain'])` auto-hashes via `#[Hidden]` cast |
| Session management | Custom session handling | Laravel Fortify's `AuthenticatedSessionController` | Already wired; handles remember-me token, rate limiting |
| Shared auth state in Vue | Vuex/Pinia store for user | `usePage().props.auth.user` | Inertia shared data is the correct pattern; no extra store needed |
| Role list for form select | Hardcoded array | `Role::pluck('name')` passed as page prop | Reads from DB; stays in sync if roles change |

---

## Common Pitfalls

### Pitfall 1: spatie middleware not registered before route definition

**What goes wrong:** `Role middleware [role] not found` exception at runtime. The `role:admin` middleware string is meaningless until the alias is registered in `bootstrap/app.php`.
**Why it happens:** Laravel 13 no longer uses `app/Http/Kernel.php`. Aliases must be set in `bootstrap/app.php` `->withMiddleware()`.
**How to avoid:** Add the `$middleware->alias([...])` block BEFORE adding any routes that use `role:admin`. The alias registration belongs in `bootstrap/app.php`, not in a service provider.
**Warning signs:** `RuntimeException: Route middleware [role] not defined.` in tests or browser.

### Pitfall 2: HandleInertiaRequests sharing the full User model

**What goes wrong:** The full Eloquent User model serializes all attributes including `two_factor_secret`, `two_factor_recovery_codes`, `remember_token` — sensitive fields — into every Inertia page response as JSON.
**Why it happens:** `$request->user()` returns the model; JSON serialization includes all non-hidden attributes. The `#[Hidden]` attribute on User hides `password`, `two_factor_secret`, `two_factor_recovery_codes`, `remember_token` [VERIFIED: User.php inspection]. However, the roles relationship is NOT loaded by default and will serialize as `null`.
**How to avoid:** Explicitly control what is shared: build a plain array with only `id`, `name`, `email`, `roles`. Load roles via `getRoleNames()` — returns a Collection of strings, no sensitive data.
**Warning signs:** Browser DevTools shows unexpected user attributes in Inertia page props.

### Pitfall 3: Wayfinder stale route definitions

**What goes wrong:** TypeScript compilation fails or runtime route functions are undefined after adding `/admin/users` resource routes. The existing `resources/js/routes/` files (e.g., `login.ts`, `index.ts`) are auto-generated — there is no `admin/users.ts` until Wayfinder regenerates.
**Why it happens:** Wayfinder generates TypeScript route files from Laravel's route list. New routes are invisible to TypeScript until regenerated.
**How to avoid:** Run `php artisan wayfinder:generate` after adding routes. In dev mode, Vite plugin auto-regenerates on each Vite HMR cycle. For Pest tests that don't start Vite, ensure routes are named and tested via string URLs (`'/admin/users'`) not Wayfinder imports.
**Warning signs:** `Cannot find module '@/routes/admin/users'` TypeScript error.

### Pitfall 4: Admin self-delete

**What goes wrong:** Admin deletes their own account from the user management UI, destroying the only admin session and locking everyone out.
**Why it happens:** `destroy()` method has no guard against self-deletion.
**How to avoid:** In `AdminUserController@destroy`, add: `if ($user->id === $request->user()->id) { return back()->withErrors(['user' => 'Cannot delete your own account.']); }`
**Warning signs:** No guard in place; easy to reproduce accidentally during testing.

### Pitfall 5: spatie permission cache during tests

**What goes wrong:** Pest feature tests fail with `Role ... does not exist` or `Permission denied` errors when tests run in a certain order, despite `RefreshDatabase` resetting the DB.
**Why it happens:** spatie/laravel-permission caches role/permission data. After `RefreshDatabase` truncates and re-seeds the tables, the in-memory cache may still hold stale data from earlier tests.
**How to avoid:** Add to `TestCase.php` or individual test files:
```php
protected function setUp(): void
{
    parent::setUp();
    // Clear spatie permission cache after RefreshDatabase
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
}
```
Or use `afterEach(fn () => app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions())` in Pest test files.
**Warning signs:** Tests pass in isolation but fail when the full suite runs.

### Pitfall 6: Fortify's `emailVerification` feature in tests

**What goes wrong:** Tests that `actingAs($user)` are redirected to `/email/verify` instead of the expected route, failing assertions.
**Why it happens:** `Features::emailVerification()` is still enabled in `config/fortify.php`. Factories create users with `email_verified_at = null` by default.
**How to avoid:** In tests, use `User::factory()->create()` which calls `User::factory()->definition()`. Check whether the factory sets `email_verified_at`. If not, use `User::factory()->create(['email_verified_at' => now()])`. Alternatively, remove `emailVerification` from features if it's not needed for this app (small internal tool).
**Warning signs:** `assertRedirect('/dashboard')` fails; actual redirect is `/email/verify`.

---

## Code Examples

### Complete bootstrap/app.php withMiddleware block (after Phase 2 changes)

```php
// Source: spatie v7 docs + existing bootstrap/app.php [VERIFIED: codebase]
->withMiddleware(function (Middleware $middleware): void {
    $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

    $middleware->web(append: [
        HandleAppearance::class,
        HandleInertiaRequests::class,
        AddLinkHeadersForPreloadedAssets::class,
    ]);

    $middleware->alias([
        'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
        'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
    ]);
})
```

### Pest test scaffolding for role enforcement

```php
// Source: existing AuthenticationTest.php pattern [VERIFIED: codebase]
uses(RefreshDatabase::class);

beforeEach(function () {
    // Clear spatie permission cache (Pitfall 5)
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    // Seed roles (required since RefreshDatabase wipes them)
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});

it('blocks non-admin users from /admin/users', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->syncRoles(['user']);

    $this->actingAs($user)
        ->get('/admin/users')
        ->assertForbidden(); // 403 from role:admin middleware
});

it('allows admin users to access /admin/users', function () {
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->syncRoles(['admin']);

    $this->actingAs($admin)
        ->get('/admin/users')
        ->assertOk();
});

it('/pay/{uuid} is reachable without authentication', function () {
    $this->get('/pay/test-uuid')->assertNotFound(); // stub returns 404, not 302 redirect
});
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `app/Http/Kernel.php` for middleware registration | `bootstrap/app.php` `withMiddleware()` | Laravel 11 | Spatie docs may show old Kernel approach — use bootstrap/app.php |
| `Fortify::registerView()` still called even with registration disabled | `Fortify::registerView()` binding is no-op when `Features::registration()` not in features array | Always was the case | Safe to leave in FortifyServiceProvider |
| `$user->roles` (Collection of Role models) | `$user->getRoleNames()` (Collection of strings) | Spatie v7 | Use `getRoleNames()` for frontend data — avoids serializing full Role model |

**Deprecated/outdated:**
- **`app/Http/Kernel.php` `$routeMiddleware`:** The old location for registering middleware aliases. Laravel 13 removed Kernel.php. Any blog post or package guide showing `$routeMiddleware['role'] = ...` in Kernel.php is for Laravel ≤10. Use `bootstrap/app.php`.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4.6 with pest-plugin-laravel 4.1 |
| Config file | `tests/Pest.php` — `uses(Tests\TestCase::class)->in('Feature', 'Unit')` |
| Quick run command | `php artisan test --filter=Auth` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| AUTH-01 | User logs in with email + password; session created | Feature (HTTP) | `php artisan test --filter=AuthenticationTest` | ✅ `tests/Feature/Auth/AuthenticationTest.php` |
| AUTH-01 | Login with wrong password is rejected | Feature (HTTP) | `php artisan test --filter=AuthenticationTest` | ✅ existing `test_users_can_not_authenticate_with_invalid_password` |
| AUTH-02 | Session persists across page loads (remember token) | Feature (HTTP) | `php artisan test --filter=SessionPersistenceTest` | ❌ Wave 0 |
| AUTH-03 | Logout destroys session and redirects to login | Feature (HTTP) | `php artisan test --filter=AuthenticationTest` | ✅ existing `test_users_can_logout` |
| AUTH-04 | Admin can create user with role via POST /admin/users | Feature (HTTP) | `php artisan test --filter=AdminUserManagementTest` | ❌ Wave 0 |
| AUTH-04 | Admin can update user role via PATCH /admin/users/{id} | Feature (HTTP) | `php artisan test --filter=AdminUserManagementTest` | ❌ Wave 0 |
| AUTH-04 | Admin can delete user via DELETE /admin/users/{id} | Feature (HTTP) | `php artisan test --filter=AdminUserManagementTest` | ❌ Wave 0 |
| AUTH-05 | Non-admin (user role) gets 403 on GET /admin/users | Feature (HTTP) | `php artisan test --filter=AdminAccessControlTest` | ❌ Wave 0 |
| AUTH-05 | Unauthenticated request to /admin/users redirects to login | Feature (HTTP) | `php artisan test --filter=AdminAccessControlTest` | ❌ Wave 0 |
| AUTH-05 | Registration is disabled — GET /register returns 404 | Feature (HTTP) | `php artisan test --filter=RegistrationDisabledTest` | ❌ Wave 0 |
| AUTH-06 | GET /pay/{uuid} returns non-redirect when unauthenticated | Feature (HTTP) | `php artisan test --filter=PublicPaymentRouteTest` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `php artisan test --filter=Auth`
- **Per wave merge:** `php artisan test`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps

- [ ] `tests/Feature/Auth/AdminUserManagementTest.php` — covers AUTH-04 (admin CRUD)
- [ ] `tests/Feature/Auth/AdminAccessControlTest.php` — covers AUTH-05 (role enforcement, registration disabled)
- [ ] `tests/Feature/Auth/SessionPersistenceTest.php` — covers AUTH-02 (remember-me session)
- [ ] `tests/Feature/Auth/PublicPaymentRouteTest.php` — covers AUTH-06 (/pay/{uuid} no auth required)

**Note:** `tests/Feature/Auth/RegistrationTest.php` already handles the disabled registration case via `skipUnlessFortifyHas` — no changes needed, tests will self-skip after D-01 is applied. [VERIFIED: codebase inspection]

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | Yes | Laravel Fortify (email + password, rate limiting already configured) |
| V3 Session Management | Yes | Laravel session (cookie, `remember_token`); `RefreshDatabase` in tests |
| V4 Access Control | Yes | spatie `role:admin` middleware (server) + `v-if` (UI) |
| V5 Input Validation | Yes | Form Requests (`StoreUserRequest`, `UpdateUserRequest`) with `Password::default()` |
| V6 Cryptography | Partial | `password` cast auto-hashes; no new encrypted columns in this phase |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Unauthenticated access to `/admin/users` | Elevation of Privilege | `auth` middleware in route group |
| User-role account accessing admin routes | Elevation of Privilege | `role:admin` middleware; returns 403 |
| Mass assignment on User::create() | Tampering | `#[Fillable]` attribute on User model (`name`, `email`, `password` only) — role NOT fillable |
| Admin self-delete (accidental lockout) | Denial of Service | Guard in `destroy()`: reject if `$user->id === auth()->id()` |
| Registration endpoint still accessible | Unauthorized access | Removing `Features::registration()` deregisters both GET and POST routes |
| Password stored in plain text via admin form | Information Disclosure | `password` cast on User model hashes automatically; `Hash::make()` not required explicitly |

**Mass assignment note:** The `User` model has `#[Fillable(['name', 'email', 'password'])]` [VERIFIED: User.php codebase inspection]. `role` is intentionally NOT in the fillable list — `syncRoles()` must be called explicitly. This is the correct pattern.

---

## Environment Availability

All dependencies for Phase 2 are pre-installed. No external services required.

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.3 | Laravel 13 | ✓ | ^8.3 (composer.json) | — |
| spatie/laravel-permission | role:admin middleware | ✓ | ^7.4 [VERIFIED: composer.json] | — |
| laravel/fortify | login/logout/session | ✓ | ^1.37 [VERIFIED: composer.json] | — |
| Pest 4.6 | Test suite | ✓ | ^4.6 [VERIFIED: composer.json] | — |

**No missing dependencies.** Phase 2 is a pure code + config phase.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `NavItem` type extension (adding `adminOnly` field) is the right pattern for conditional nav rendering | Architecture Patterns / Pattern 8 | Low — alternatively filter by computed `isAdmin` in the items array; both work |
| A2 | `email_verified_at` defaults to `null` in User factory | Common Pitfalls (Pitfall 6) | Tests using `actingAs()` may be redirected to email verification instead of expected route — resolve by passing `email_verified_at: now()` in factory |

**All other claims verified via codebase inspection or official documentation.**

---

## Open Questions

1. **`email_verified_at` in tests**
   - What we know: `Features::emailVerification()` is active in `config/fortify.php`. Tests using `actingAs()` will hit the verified middleware.
   - What's unclear: Whether the existing `User::factory()` definition sets `email_verified_at` by default.
   - Recommendation: Inspect `database/factories/UserFactory.php` and either always pass `email_verified_at: now()` in test factories, or remove `Features::emailVerification()` from `config/fortify.php` (small internal tool; verification adds no value).

2. **User deletion strategy (soft-delete vs. hard-delete)**
   - What we know: D-03 says full CRUD including delete. Deferred items say "soft-delete vs. is_active flag is Claude's discretion."
   - What's unclear: Whether Phase 1 migration added `deleted_at` to the users table.
   - Recommendation: Use hard-delete for Phase 2 (simpler). Add `SoftDeletes` trait and `deleted_at` column in a later phase if needed.

3. **Fortify `emailVerification` — remove or keep?**
   - What we know: It's enabled. For an invite-only internal tool, email verification adds no security value.
   - What's unclear: Whether the team wants to use email verification in Phase 2.
   - Recommendation: Remove `Features::emailVerification()` from `config/fortify.php` to simplify the auth flow. This also eliminates Pitfall 6. Reversible if needed.

---

## Sources

### Primary (HIGH confidence)
- `config/fortify.php` — current feature flags, confirmed `Features::registration()` still enabled [VERIFIED: codebase inspection]
- `app/Providers/FortifyServiceProvider.php` — confirmed `canRegister` uses `Features::enabled()` [VERIFIED: codebase inspection]
- `app/Models/User.php` — confirmed `HasRoles`, `TwoFactorAuthenticatable`, `#[Fillable]` [VERIFIED: codebase inspection]
- `bootstrap/app.php` — confirmed `withMiddleware()` structure for spatie alias registration [VERIFIED: codebase inspection]
- `app/Http/Middleware/HandleInertiaRequests.php` — confirmed current `share()` method shape [VERIFIED: codebase inspection]
- `tests/TestCase.php` — confirmed `skipUnlessFortifyHas` helper exists [VERIFIED: codebase inspection]
- `tests/Feature/Auth/RegistrationTest.php` — confirmed self-skip guard [VERIFIED: codebase inspection]
- `resources/js/types/auth.ts` — confirmed `User` type needs `roles` field added [VERIFIED: codebase inspection]
- `resources/js/types/navigation.ts` — confirmed `NavItem` type shape [VERIFIED: codebase inspection]
- `resources/js/components/NavUser.vue` — confirmed `usePage().props.auth.user` pattern [VERIFIED: codebase inspection]
- spatie v7 middleware docs — https://spatie.be/docs/laravel-permission/v7/basic-usage/middleware [CITED]
- Laravel Fortify disable registration — https://laravel.com/docs/13.x/fortify [CITED]
- Inertia.js shared data — https://inertiajs.com/shared-data [CITED]

### Secondary (MEDIUM confidence)
- spatie permission cache clearing — https://spatie.be/docs/laravel-permission/v7/advanced-usage/cache [CITED]
- Laravel 13 form request validation — https://laravel.com/docs/13.x/validation [CITED]

### Tertiary (LOW confidence)
- None in this research.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all packages verified in composer.json/package.json
- Architecture: HIGH — patterns verified against codebase + official docs
- Pitfalls: HIGH — backed by codebase inspection (spatie cache, Kernel.php deprecation, Wayfinder regeneration)
- Test architecture: HIGH — existing test patterns verified against actual test files

**Research date:** 2026-05-03
**Valid until:** 2026-06-03 (stable stack; spatie v7 + Laravel 13 releases are stable)
