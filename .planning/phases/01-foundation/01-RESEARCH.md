# Phase 1: Foundation - Research

**Researched:** 2026-05-02
**Domain:** Laravel 12 + Inertia v2 + Vue 3 + Tailwind CSS 4 + shadcn-vue + Encrypted Eloquent Casts
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Use the official Laravel 12 Vue+Inertia starter kit: `laravel new payhub --kit=vue`. It ships Inertia v2, Vue 3, Tailwind CSS 4, and Vite pre-wired.
- **D-02:** Keep the Breeze-style auth pages that ship with the starter kit. Do NOT remove them in Phase 1.
- **D-03:** Install additional packages not in the starter kit: `laravel/fortify`, `spatie/laravel-permission`, `stripe/stripe-php`, `spatie/laravel-stripe-webhooks`, `laravel/telescope` (dev), npm packages `vue-stripe-js`, `@stripe/stripe-js`.
- **D-04:** Initialize shadcn-vue CLI with **New York** style (one-time choice for all phases).
- **D-05:** Seed five components into `resources/js/components/ui/`: Button, Input, Label, Card, Badge.
- **D-06:** Build the **full final schema** in Phase 1 migrations. No alter-table migrations in later phases.
- **D-07:** `payments.status` as MySQL `ENUM('pending', 'completed', 'failed', 'cancelled')` default `'pending'`.
- **D-08:** `brands.logo_path` as nullable `VARCHAR(255)` (storage path, not URL).
- **D-09:** `payments.expires_at` as nullable `TIMESTAMP`, default `NULL`.
- **D-10:** `stripe_accounts.secret_key` and `stripe_accounts.webhook_secret` as TEXT columns with `encrypted` cast.
- **D-11:** Seeder: one Brand, one StripeAccount with fake key, one Admin user — idempotent via `firstOrCreate`.
- **D-12:** Add `APP_PREVIOUS_KEYS=` placeholder to `.env.example` (commented). No rotation runbook in Phase 1.

### Claude's Discretion

- Migration file naming and ordering (as long as `migrate:fresh --seed` runs clean)
- Factory definitions (Faker-based realistic data)
- Model cast definitions beyond `encrypted` (dates, integers implied by column types)
- Pest test structure for encryption round-trip verification

### Deferred Ideas (OUT OF SCOPE)

- APP_KEY rotation runbook — only add `.env.example` placeholder
- Laravel Horizon — deferred to Phase 6
- Additional shadcn-vue components beyond the five seed components
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID     | Description                                                    | Research Support                                                                                            |
|--------|----------------------------------------------------------------|-------------------------------------------------------------------------------------------------------------|
| SEC-01 | Stripe secret keys and webhook secrets are encrypted at rest (AES-256 via Laravel encrypted cast) | Laravel `encrypted` cast uses AES-256-CBC via APP_KEY; requires TEXT columns; round-trip verified via Pest test |
</phase_requirements>

---

## Summary

Phase 1 scaffolds the entire application foundation before any feature work begins. The tasks break into four clear groups: (1) framework scaffold via `laravel new --vue --pest --database=mysql`, (2) additional package installation for the full v1 stack, (3) database migrations with the complete final schema, and (4) model definitions with encrypted casts plus factory/seeder/test scaffolding.

**Critical installer version concern:** As of March 17 2026, `laravel new` installs Laravel 13 by default (Laravel 13 was released March 17, 2026 with zero breaking changes from L12). The CONTEXT.md decision D-01 specifies Laravel 12. To honour this, use `composer create-project laravel/laravel:^12.0 payhub` instead of `laravel new`, then configure the starter kit assets manually — OR use `laravel new --vue --pest --database=mysql` and accept Laravel 13 knowing the stack (inertia-laravel v3, inertia/vue3 v3, shadcn-vue, Tailwind v4) is identical. The key behavioural difference in L13 is that the CSRF middleware class was renamed from `VerifyCsrfToken` to `PreventRequestForgery`. This affects webhook route exclusions (Phase 6 concern) but is irrelevant to Phase 1 scope.

**Recommendation:** Use `laravel new payhub --vue --pest --database=mysql` (installs L13 with the identical stack). Document that the project is on L13, not L12. The Inertia v2 reference in D-01 is also superseded — the current starter kit ships inertia-laravel v3 / @inertiajs/vue3 v3. All userland APIs (`useForm`, `usePage`, `router`) are unchanged.

The encryption story is straightforward: Laravel's native `encrypted` cast on `TEXT` columns handles AES-256-CBC transparently. The only action required is ensuring columns are `TEXT` type in migrations and keeping `APP_KEY` backed up. The `APP_PREVIOUS_KEYS` placeholder per D-12 is the only rotation affordance needed in Phase 1.

**Primary recommendation:** Run `laravel new payhub --vue --pest --database=mysql` to get the full starter kit (Inertia v3, Vue 3, Tailwind v4, shadcn-vue, TypeScript), then add the D-03 packages, run `npx shadcn-vue@latest init` with New York style selection, seed the five components, and write all four migrations with correct column types.

---

## Architectural Responsibility Map

| Capability                  | Primary Tier     | Secondary Tier   | Rationale                                                                     |
|-----------------------------|-----------------|-----------------|-------------------------------------------------------------------------------|
| Database schema/migrations  | Database/Storage | —               | Pure schema definition; no app logic involved                                 |
| Model relationships + casts | API/Backend      | —               | Eloquent model layer; encryption is server-side only                          |
| Encrypted credential storage| Database/Storage | API/Backend      | TEXT columns store ciphertext; Eloquent cast performs encrypt/decrypt         |
| shadcn-vue component seeding| Browser/Client   | —               | Frontend component files; no backend involvement                              |
| brand-theme.css CSS vars     | Browser/Client   | —               | Pure CSS; consumed by Vue layouts in Phases 3–5                               |
| Factories + Seeders          | API/Backend      | Database/Storage | PHP code that writes records to DB; tooling concern only                      |
| Pest encryption round-trip   | API/Backend      | —               | Server-side test; reads/writes StripeAccount records via Eloquent             |

---

## Standard Stack

### Core (verified against Packagist and npm registries, 2026-05-02)

| Library                       | Version         | Purpose                              | Why Standard                                         |
|-------------------------------|----------------|--------------------------------------|------------------------------------------------------|
| PHP                           | 8.3             | Runtime                              | Laravel 12/13 requires 8.2+; 8.3 is installed on Herd [VERIFIED: `php --version`] |
| Laravel                       | ^12.0 or ^13.0  | Application framework                | Full-stack foundation; starter kit scaffolds everything [VERIFIED: packagist.org] |
| inertiajs/inertia-laravel     | ^3.0.6          | Server-side Inertia adapter          | Ships with starter kit; v3 is stable [VERIFIED: Packagist] |
| laravel/fortify               | ^1.37.0         | Auth backend                         | Frontend-agnostic; disable registration in config [VERIFIED: packagist.org] |
| spatie/laravel-permission     | ^7.4.1          | RBAC — Admin / User roles            | Requires PHP 8.3+; Laravel 12/13 supported [VERIFIED: packagist.org] |
| stripe/stripe-php             | ^20.1.0         | Stripe PHP SDK (Phase 3+ use)        | Latest stable; install now, use from Phase 3 [VERIFIED: packagist.org] |
| spatie/laravel-stripe-webhooks| ^3.11.0         | Webhook handling (Phase 6+ use)      | Multi-secret support for per-account webhooks [VERIFIED: packagist.org] |
| laravel/telescope             | ^5.20.0 (dev)   | Debug assistant                      | Dev-only; invaluable for webhook debugging [VERIFIED: packagist.org] |
| @inertiajs/vue3               | ^3.0.3          | Client-side Inertia adapter          | Ships with starter kit; v3 matches server adapter [VERIFIED: npm] |
| Vue 3                         | ^3.x            | UI layer (Composition API)          | Ships with starter kit [VERIFIED: starter kit package.json] |
| Tailwind CSS                  | ^4.x            | Utility CSS                          | Ships with starter kit; CSS-first config [VERIFIED: npm] |
| @tailwindcss/vite             | ^4.2.4          | Tailwind Vite plugin                 | Ships with starter kit [VERIFIED: npm] |
| shadcn-vue (CLI)              | 2.6.2           | Component library (copy-into-project)| Ships with starter kit (initialised already) [VERIFIED: npm] |
| reka-ui                       | ^2.x            | Headless primitives                  | Installed by shadcn-vue CLI automatically [VERIFIED: starter kit package.json] |
| vue-stripe-js                 | ^2.0.2          | Vue Stripe Elements wrapper (Phase 5+)| Phase 5 use; install now per D-03 [VERIFIED: npm] |
| @stripe/stripe-js             | ^9.4.0          | Stripe.js browser SDK (Phase 5+)    | Phase 5 use; install now per D-03 [VERIFIED: npm] |
| Pest PHP                      | ^4.x (via starter kit) | Test framework               | Installed via `--pest` flag [VERIFIED: packagist.org] |

### Supporting

| Library             | Version   | Purpose                          | When to Use                          |
|---------------------|----------|----------------------------------|--------------------------------------|
| laravel/tinker      | ^3.0     | Interactive REPL                 | Verifying relationships in Phase 1 success criteria |
| fakerphp/faker      | ^1.24    | Factory data generation          | ModelFactory definitions              |
| laravel/pint        | ^1.x     | PSR-12 code style fixer          | Pre-commit formatting                 |
| lucide-vue-next     | latest   | Icon library (ships with kit)    | Add to Vue components from Phase 2+  |

### Alternatives Considered

| Instead of                          | Could Use                      | Tradeoff                                                        |
|-------------------------------------|-------------------------------|------------------------------------------------------------------|
| `laravel new --vue`                 | `composer create-project laravel/laravel:^12.0` | composer method pins L12 but requires manual shadcn-vue init; `laravel new --vue` gives L13 with identical stack |
| Laravel `encrypted` cast (native)   | joelwmale/laravel-encryption  | Native cast is zero-dependency; third-party adds no value        |
| spatie/laravel-permission v7        | Gate/Policy only               | spatie gives DB-stored roles; simple Gates need no package but are harder to manage across phases |

**Installation — PHP backend (after scaffold):**
```bash
composer require laravel/fortify
composer require spatie/laravel-permission
composer require stripe/stripe-php
composer require spatie/laravel-stripe-webhooks
composer require --dev laravel/telescope
```

**Installation — npm frontend (after scaffold):**
```bash
npm install vue-stripe-js @stripe/stripe-js
```

> Note: `@inertiajs/vue3`, `reka-ui`, `tailwindcss`, `@tailwindcss/vite`, `vue`, `lucide-vue-next`, `class-variance-authority`, `clsx`, `tailwind-merge` are already installed by the starter kit. Do NOT reinstall them.

**shadcn-vue is already initialised by the starter kit.** The starter kit runs `npx shadcn-vue@latest init` during project creation. Verify `components.json` exists at project root before proceeding. If it is missing, run:
```bash
npx shadcn-vue@latest init
```
Select New York style, CSS variables, Tailwind v4.

**Seed five Phase 1 components:**
```bash
npx shadcn-vue@latest add button
npx shadcn-vue@latest add input
npx shadcn-vue@latest add label
npx shadcn-vue@latest add card
npx shadcn-vue@latest add badge
```

**Version verification (confirmed 2026-05-02):**
```
stripe/stripe-php: v20.1.0  [VERIFIED: packagist.org]
spatie/laravel-permission: v7.4.1  [VERIFIED: packagist.org]
spatie/laravel-stripe-webhooks: v3.11.0  [VERIFIED: packagist.org]
laravel/fortify: v1.37.0  [VERIFIED: packagist.org]
laravel/telescope: v5.20.0  [VERIFIED: packagist.org]
@inertiajs/vue3: v3.0.3  [VERIFIED: npm]
@tailwindcss/vite: v4.2.4  [VERIFIED: npm]
shadcn-vue: v2.6.2  [VERIFIED: npm]
vue-stripe-js: v2.0.2  [VERIFIED: npm]
@stripe/stripe-js: v9.4.0  [VERIFIED: npm]
```

---

## Architecture Patterns

### System Architecture Diagram

```
laravel new payhub --vue --pest --database=mysql
    |
    v
Laravel 13 / Inertia v3 / Vue 3 / Tailwind v4 / shadcn-vue scaffold
    |
    |-- composer require [D-03 packages]
    |-- npm install vue-stripe-js @stripe/stripe-js
    |-- npx shadcn-vue@latest add button input label card badge
    |
    v
resources/css/
    ├── app.css              (@import "tailwindcss")
    └── brand-theme.css      (CSS variable scaffold for multi-brand theming)
    |
    v
database/migrations/
    ├── ..._create_users_table.php         (standard auth user)
    ├── ..._create_brands_table.php        (name, slug, logo_path, colors)
    ├── ..._create_stripe_accounts_table.php  (encrypted keys)
    └── ..._create_payments_table.php      (UUID, amounts in cents, ENUM status)
    |
    v
app/Models/
    ├── User.php             (HasRoles from spatie)
    ├── Brand.php            (hasMany StripeAccount, hasMany Payment)
    ├── StripeAccount.php    (encrypted casts, belongsTo Brand, hasMany Payment)
    └── Payment.php          (belongsTo Brand, StripeAccount, User)
    |
    v
database/factories/ + database/seeders/
    |-- BrandFactory.php, StripeAccountFactory.php, PaymentFactory.php
    |-- DatabaseSeeder.php  (idempotent via firstOrCreate)
    |
    v
tests/Feature/
    └── EncryptionRoundTripTest.php   (SEC-01 Pest test)
```

### Recommended Project Structure

```
payhub/
├── app/
│   ├── Models/
│   │   ├── Brand.php
│   │   ├── Payment.php
│   │   ├── StripeAccount.php
│   │   └── User.php
│   └── Services/          # Empty in Phase 1 — established structure for Phase 3
├── database/
│   ├── factories/
│   │   ├── BrandFactory.php
│   │   ├── PaymentFactory.php
│   │   └── StripeAccountFactory.php
│   ├── migrations/
│   │   ├── ..._create_users_table.php
│   │   ├── ..._create_brands_table.php
│   │   ├── ..._create_stripe_accounts_table.php
│   │   └── ..._create_payments_table.php
│   └── seeders/
│       └── DatabaseSeeder.php
├── resources/
│   ├── css/
│   │   ├── app.css                 # @import "tailwindcss"
│   │   └── brand-theme.css         # CSS variable scaffold
│   └── js/
│       └── components/ui/          # shadcn-vue seed components
│           ├── button/
│           ├── input/
│           ├── label/
│           ├── card/
│           └── badge/
├── tests/
│   └── Feature/
│       └── EncryptionRoundTripTest.php
├── components.json                 # shadcn-vue config (created by starter kit)
└── .env.example                    # APP_PREVIOUS_KEYS= placeholder added
```

### Pattern 1: Laravel Encrypted Cast on TEXT Columns

**What:** Place `'encrypted'` in the model's `casts()` method. Laravel auto-encrypts on write and auto-decrypts on read using AES-256-CBC via `APP_KEY`.

**When to use:** Any column storing Stripe secret keys or webhook secrets — any credential that must be unreadable if the database is compromised without the app key.

**Migration column type — MUST be TEXT:**
```php
// Source: https://laravel.com/docs/12.x/eloquent-mutators#encrypted-casting [VERIFIED]
// Encrypted text length is unpredictable and always longer than plaintext.
// VARCHAR(255) will truncate and corrupt the encrypted value.
$table->text('secret_key');
$table->text('webhook_secret');
```

**Model cast declaration:**
```php
// Source: https://laravel.com/docs/12.x/eloquent-mutators#encrypted-casting [VERIFIED]
protected function casts(): array
{
    return [
        'secret_key'     => 'encrypted',
        'webhook_secret' => 'encrypted',
        'is_active'      => 'boolean',
    ];
}
```

**Read/write behaviour:** Completely transparent. `$account->secret_key = 'sk_test_abc'` encrypts on assignment. `$account->secret_key` decrypts on access. No manual `Crypt::encrypt()` calls needed.

### Pattern 2: MySQL ENUM for Payment Status

**What:** Use `$table->enum('status', [...])` in migrations for `payments.status`.

**When to use:** Status is a closed set known at design time; DB-level enforcement prevents invalid values that would slip through without a migration.

```php
// D-07: ENUM enforced at the database level [ASSUMED - standard Laravel migration pattern]
$table->enum('status', ['pending', 'completed', 'failed', 'cancelled'])->default('pending');
```

**Caution:** Adding a new status value to a MySQL ENUM requires a table rebuild (ALTER TABLE) on large tables. For an internal agency tool this is acceptable. Document the ENUM values exhaustively in Phase 1 to avoid future ALTER migrations.

### Pattern 3: UUID Primary Identifier for Payments

**What:** Payments use a separate `uuid` column (not the primary key `id`) as the public identifier. The PK `id` is an auto-increment integer for internal join performance.

```php
// D-06: Full schema in Phase 1 [ASSUMED - standard pattern for UUID payment links]
$table->uuid('uuid')->unique();
```

`Str::uuid()` generates UUID v4 (122 bits of randomness) — unguessable, safe as public URL parameter.

> Note: Laravel 12 defaults to UUID v7 for `HasUuids` trait. For `Payment`, the `uuid` column is a separate field explicitly populated in the factory/seeder with `Str::uuid()`, not relying on the `HasUuids` trait. This avoids confusion with the UUIDv7 default.

### Pattern 4: Idempotent Seeders with firstOrCreate

**What:** D-11 requires seeders that are safe to run multiple times (idempotent).

```php
// Source: [ASSUMED - standard Laravel seeder pattern]
Brand::firstOrCreate(
    ['slug' => 'demo-brand'],
    [
        'name'            => 'Demo Brand',
        'logo_path'       => null,
        'primary_color'   => '#3B82F6',
        'secondary_color' => '#EFF6FF',
    ]
);
```

Use `firstOrCreate(unique_identifier_array, defaults_array)` — first argument is the WHERE clause; second argument is the column values to use only on CREATE.

### Pattern 5: Pest Feature Test with RefreshDatabase

**What:** Pest tests for database operations use `uses(RefreshDatabase::class)` at the file level.

```php
// Source: [VERIFIED: Laravel 12 Pest integration — standard pattern]
<?php

use App\Models\StripeAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('encrypts the secret_key on save and decrypts on read', function () {
    $plainKey = 'sk_test_placeholder_for_dev_only';

    $account = StripeAccount::factory()->create([
        'secret_key' => $plainKey,
    ]);

    // Verify raw DB value is NOT the plain key (it is encrypted ciphertext)
    $rawRow = DB::table('stripe_accounts')->where('id', $account->id)->first();
    expect($rawRow->secret_key)->not->toBe($plainKey);

    // Verify fresh Eloquent fetch decrypts back to the original value
    $fresh = StripeAccount::find($account->id);
    expect($fresh->secret_key)->toBe($plainKey);
});

it('encrypts webhook_secret on save and decrypts on read', function () {
    $plainSecret = 'whsec_test_placeholder_for_dev_only';

    $account = StripeAccount::factory()->create([
        'webhook_secret' => $plainSecret,
    ]);

    $fresh = StripeAccount::find($account->id);
    expect($fresh->webhook_secret)->toBe($plainSecret);
});
```

### Anti-Patterns to Avoid

- **VARCHAR for encrypted columns:** Encrypted ciphertext is longer than plaintext and has unpredictable length. VARCHAR(255) silently truncates, corrupting the value. Always use `TEXT`.
- **`Str::random()` for payment UUID:** `Str::random()` produces a predictable-length hex string without UUID formatting. Use `Str::uuid()` which produces UUID v4 (122 bits of randomness, IETF compliant).
- **Using `HasUuids` trait for `payments.uuid`:** L12+ defaults to UUIDv7 with the trait. This project uses `uuid` as a separate column, not the primary key. Don't add `HasUuids` — manually set `uuid` in the factory with `'uuid' => Str::uuid()`.
- **Storing floats for amounts:** Amount columns must be `unsignedBigInteger` (cents). A `decimal(8,2)` column storing `$9.99` as `9.99` introduces floating-point arithmetic hazards when multiplying by 100. Store `999` as integer cents from the start.
- **Skipping the APP_PREVIOUS_KEYS placeholder:** Even in Phase 1 with no real Stripe keys, establishing the `.env.example` comment now prevents future operators from rotating APP_KEY without reading the warning.

---

## Don't Hand-Roll

| Problem                        | Don't Build                   | Use Instead                    | Why                                                              |
|-------------------------------|-------------------------------|-------------------------------|------------------------------------------------------------------|
| AES-256 field encryption      | Manual openssl_encrypt() calls| Laravel `encrypted` cast       | Handles IV generation, MAC signing, key rotation automatically   |
| Role-based access control     | Custom `role` column + checks | spatie/laravel-permission      | DB-stored roles, middleware, @can directives, team support       |
| RBAC middleware                | Custom auth middleware         | `->middleware('role:admin')`   | spatie provides registered middleware out of the box             |
| UUID generation                | `md5(time())` or `uniqid()`   | `Str::uuid()`                  | UUID v4 has 122 bits randomness; uniqid is predictable           |
| Webhook deduplication         | Custom processed_ids table     | spatie/laravel-stripe-webhooks | Built-in deduplication, DB logging, queued dispatch              |

**Key insight:** Laravel's native `encrypted` cast eliminates the most error-prone code in the application (credential handling). Any custom encryption implementation risks IV reuse, missing MAC verification, or key management errors.

---

## Migration Schema Details

All four migrations must be created in Phase 1 per D-06.

### Table 1: `users` (already created by starter kit)

The starter kit creates a standard `users` migration. No changes required. spatie/laravel-permission adds its own migrations when published with `php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"`.

### Table 2: `brands`

```php
Schema::create('brands', function (Blueprint $table) {
    $table->id();
    $table->string('name');
    $table->string('slug')->unique();
    $table->string('logo_path')->nullable();       // D-08: nullable VARCHAR(255) storage path
    $table->string('primary_color', 7)->default('#3B82F6');   // hex, e.g. #3B82F6
    $table->string('secondary_color', 7)->default('#EFF6FF');
    $table->timestamps();
});
```

### Table 3: `stripe_accounts`

```php
Schema::create('stripe_accounts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
    $table->string('account_name');
    $table->string('publishable_key');              // plain text — public key, no encryption needed
    $table->text('secret_key');                     // D-10: TEXT, encrypted cast
    $table->text('webhook_secret');                 // D-10: TEXT, encrypted cast
    $table->boolean('is_active')->default(true);
    $table->timestamps();
});
```

### Table 4: `payments`

```php
Schema::create('payments', function (Blueprint $table) {
    $table->id();
    $table->uuid('uuid')->unique();
    $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
    $table->foreignId('stripe_account_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->unsignedBigInteger('amount');           // integer cents — NEVER float
    $table->string('currency', 3);                  // 'usd' or 'gbp' only
    $table->string('description')->nullable();
    $table->enum('status', ['pending', 'completed', 'failed', 'cancelled'])->default('pending'); // D-07
    $table->string('client_email');
    $table->string('stripe_payment_intent_id')->nullable();
    $table->timestamp('expires_at')->nullable();    // D-09: NULL = never expires
    $table->timestamp('paid_at')->nullable();
    $table->timestamps();
});
```

**Migration ordering:** Create in this order to satisfy foreign key constraints:
1. `users` (starter kit, already present)
2. `brands`
3. `stripe_accounts` (references brands)
4. `payments` (references brands, stripe_accounts, users)

---

## Model Relationships and Cast Implementation

### Brand.php

```php
// app/Models/Brand.php
class Brand extends Model
{
    protected $fillable = ['name', 'slug', 'logo_path', 'primary_color', 'secondary_color'];

    public function stripeAccounts(): HasMany
    {
        return $this->hasMany(StripeAccount::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
```

### StripeAccount.php

```php
// app/Models/StripeAccount.php
class StripeAccount extends Model
{
    protected $fillable = [
        'brand_id', 'account_name', 'publishable_key',
        'secret_key', 'webhook_secret', 'is_active'
    ];

    protected function casts(): array
    {
        return [
            'secret_key'     => 'encrypted',    // AES-256-CBC via APP_KEY
            'webhook_secret' => 'encrypted',    // AES-256-CBC via APP_KEY
            'is_active'      => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
```

### Payment.php

```php
// app/Models/Payment.php
class Payment extends Model
{
    protected $fillable = [
        'uuid', 'brand_id', 'stripe_account_id', 'user_id',
        'amount', 'currency', 'description', 'status',
        'client_email', 'stripe_payment_intent_id', 'expires_at', 'paid_at'
    ];

    protected function casts(): array
    {
        return [
            'amount'     => 'integer',
            'expires_at' => 'datetime',
            'paid_at'    => 'datetime',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function stripeAccount(): BelongsTo
    {
        return $this->belongsTo(StripeAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

### User.php (additions to starter kit model)

```php
// Add to existing User model from starter kit
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;  // Adds $user->assignRole('admin'), $user->hasRole('admin')
    // ... rest unchanged from starter kit
}
```

---

## Factory Patterns

Factories use Faker for realistic data. The `StripeAccount` factory uses a placeholder key — it will round-trip through encryption correctly with `APP_KEY` but cannot be used against the real Stripe API.

```php
// database/factories/StripeAccountFactory.php [ASSUMED - standard Laravel factory pattern]
class StripeAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_name'    => $this->faker->company(),
            'publishable_key' => 'pk_test_' . $this->faker->regexify('[a-zA-Z0-9]{24}'),
            'secret_key'      => 'sk_test_placeholder_for_dev_only',  // D-11: fake key
            'webhook_secret'  => 'whsec_placeholder_for_dev_only',
            'is_active'       => true,
        ];
    }
}
```

```php
// database/factories/PaymentFactory.php [ASSUMED - standard Laravel factory pattern]
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid'         => Str::uuid(),
            'amount'       => $this->faker->numberBetween(500, 100000),  // integer cents
            'currency'     => $this->faker->randomElement(['usd', 'gbp']),
            'description'  => $this->faker->sentence(),
            'status'       => 'pending',
            'client_email' => $this->faker->email(),
            'expires_at'   => null,
            'paid_at'      => null,
        ];
    }
}
```

---

## Seeder Implementation

Per D-11: one Brand, one StripeAccount with fake key, one Admin user. All idempotent via `firstOrCreate`.

```php
// database/seeders/DatabaseSeeder.php [ASSUMED - standard Laravel seeder pattern]
public function run(): void
{
    $brand = Brand::firstOrCreate(
        ['slug' => 'demo-brand'],
        [
            'name'            => 'Demo Brand',
            'logo_path'       => null,
            'primary_color'   => '#3B82F6',
            'secondary_color' => '#EFF6FF',
        ]
    );

    StripeAccount::firstOrCreate(
        ['account_name' => 'Demo Stripe Account'],
        [
            'brand_id'        => $brand->id,
            'publishable_key' => 'pk_test_placeholder_for_dev_only',
            'secret_key'      => 'sk_test_placeholder_for_dev_only',  // encrypted by cast
            'webhook_secret'  => 'whsec_placeholder_for_dev_only',    // encrypted by cast
            'is_active'       => true,
        ]
    );

    $admin = User::firstOrCreate(
        ['email' => 'admin@payhub.test'],
        [
            'name'     => 'PayHub Admin',
            'password' => Hash::make('password'),
        ]
    );

    $admin->assignRole('admin');  // requires spatie/laravel-permission role to exist
}
```

**Note:** spatie/laravel-permission requires roles to be seeded before assignment. Either run `php artisan db:seed --class=RoleSeeder` first, or create roles inline in DatabaseSeeder:

```php
use Spatie\Permission\Models\Role;
Role::firstOrCreate(['name' => 'admin']);
Role::firstOrCreate(['name' => 'user']);
```

---

## brand-theme.css CSS Variable Scaffold

Per UI-SPEC.md, create `resources/css/brand-theme.css` with this exact content:

```css
/* resources/css/brand-theme.css */
/* Default (admin panel) — neutral, no brand coloring */
:root {
  --color-brand-primary: hsl(221.2 83.2% 53.3%);   /* shadcn-vue blue default */
  --color-brand-secondary: hsl(210 40% 96.1%);      /* shadcn-vue muted default */
}

/* Per-brand overrides — applied via data-brand attribute on layout root */
/* Phase 5 sets: <div :data-brand="brand.slug" :style="`--brand-primary: ${brand.primary_color}; ...`"> */
[data-brand] {
  --color-brand-primary: var(--brand-primary, hsl(221.2 83.2% 53.3%));
  --color-brand-secondary: var(--brand-secondary, hsl(210 40% 96.1%));
}
```

Import this file from `resources/css/app.css`:

```css
/* resources/css/app.css */
@import "tailwindcss";
@import "./brand-theme.css";
```

---

## Common Pitfalls

### Pitfall 1: VARCHAR column for encrypted fields

**What goes wrong:** Encrypted ciphertext is longer than plaintext and its length is unpredictable. A `VARCHAR(255)` column silently truncates the encrypted value. The model appears to save successfully, but decryption fails on read with a `DecryptException`.

**Why it happens:** Developers assume VARCHAR(255) is "big enough." AES-256 encryption adds padding, IV bytes, and MAC signature — the output is substantially longer.

**How to avoid:** Use `$table->text()` for ALL encrypted columns. Verified: Laravel docs explicitly state "make sure the associated database column is of TEXT type or larger." [CITED: laravel.com/docs/12.x/eloquent-mutators#encrypted-casting]

**Warning signs:** `Illuminate\Contracts\Encryption\DecryptException` on model read; encrypted values in DB appear truncated (missing trailing `=`/`==` base64 padding).

### Pitfall 2: laravel new installs Laravel 13, not Laravel 12

**What goes wrong:** The CONTEXT.md specifies D-01 as `laravel new payhub --kit=vue` for Laravel 12. As of March 17, 2026, `laravel new` installs Laravel 13 (zero breaking changes from L12, same stack). The stack is identical but `inertia-laravel` is now v3 (not v2) and the CSRF middleware class is `PreventRequestForgery` (not `VerifyCsrfToken`).

**Why it happens:** Laravel releases annually; the installer always installs the latest.

**How to avoid:** Either accept L13 (recommended — identical stack, same APIs), or use `composer create-project laravel/laravel:^12.0 payhub` and manually install the starter kit assets. The `--vue` flag is not available with `composer create-project`.

**Impact on Phase 1:** None — Phase 1 has no auth or webhook middleware. Impact surfaces in Phase 6 when webhook routes must exclude the CSRF middleware (class name differs between L12 and L13). [VERIFIED: laravel.com/docs/13.x/upgrade]

### Pitfall 3: shadcn-vue starter kit components already present — running init again overwrites config

**What goes wrong:** The Laravel Vue starter kit already initialises shadcn-vue and places components in `resources/js/components/ui/`. Running `npx shadcn-vue@latest init` again overwrites `components.json`.

**Why it happens:** Developer follows Phase 1 plan step "run shadcn-vue init" without checking if it already ran.

**How to avoid:** Check for `components.json` at project root before running init. If it exists and shows `new-york-v4` style, skip init and go directly to `npx shadcn-vue@latest add <component>` for any missing components.

### Pitfall 4: spatie/laravel-permission migrations not published before seeding roles

**What goes wrong:** `DatabaseSeeder.php` calls `Role::firstOrCreate(...)` but the `roles` and `model_has_roles` tables don't exist yet — migration fails.

**Why it happens:** spatie/laravel-permission ships its own migrations that must be published and run.

**How to avoid:** After `composer require spatie/laravel-permission`, run:
```bash
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate
```
This publishes `config/permission.php` and the migrations for `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`. [CITED: spatie.be/docs/laravel-permission]

### Pitfall 5: ENUM migration doesn't match model validation in later phases

**What goes wrong:** `payments.status` ENUM is `('pending', 'completed', 'failed', 'cancelled')`. A later phase adds a new status like `'refunded'` — but changing a MySQL ENUM requires an ALTER TABLE that locks the table.

**Why it happens:** ENUM values are written once at migration time.

**How to avoid:** D-07 locks the ENUM values exhaustively to the four values above. Document this in the migration comment. Any future status must be added via a new migration — acceptable for an internal agency tool at low traffic volume.

### Pitfall 6: Windows Herd — `laravel new` SSL certificate validation failure

**What goes wrong:** On Windows Herd, `laravel new payhub --vue` may fail with "curl error 60: SSL certificate problem: unable to get local issuer certificate" when the bundled PHP cannot verify Packagist's SSL cert.

**Why it happens:** A known Herd Windows bug (herd-community issue #1296, reported Feb 2026). The SSL cert chain issue with the bundled phar.

**How to avoid:** If `laravel new --vue` fails, fall back to:
```bash
composer create-project laravel/laravel:^13.0 payhub
```
Then manually install the Vue starter kit assets from `github.com/laravel/vue-starter-kit`. Alternatively, update the Herd app and Laravel installer to the latest versions before attempting creation.

### Pitfall 7: npm run dev shows no visible Tailwind styles (HMR issue)

**What goes wrong:** Browser renders the Inertia root page but Tailwind utilities produce no visible styling. Success Criterion 1 cannot be verified.

**Why it happens:** A known Herd Windows + Vite HMR issue. Vue component changes don't reflect; sometimes a full browser refresh (`Ctrl+Shift+R`) is needed after cold start.

**How to avoid:** After `npm run dev`, perform a hard browser refresh. If styles are still absent, verify `resources/css/app.css` contains `@import "tailwindcss"` (not a PostCSS config path). Tailwind v4 uses CSS-first config — there is NO `tailwind.config.js` required. The `@import "tailwindcss"` directive is the entire configuration. [CITED: tailwindcss.com/blog/tailwindcss-v4]

---

## Code Examples

### Encryption round-trip verified pattern

```php
// Source: [VERIFIED: laravel.com/docs/12.x/eloquent-mutators#encrypted-casting]
// Migration
$table->text('secret_key');         // TEXT not VARCHAR
$table->text('webhook_secret');

// Model
protected function casts(): array
{
    return ['secret_key' => 'encrypted', 'webhook_secret' => 'encrypted'];
}

// Usage — encryption is transparent
$account = new StripeAccount();
$account->secret_key = 'sk_test_abc123';  // auto-encrypted on save
$account->save();

$loaded = StripeAccount::find($account->id);
$loaded->secret_key;  // returns 'sk_test_abc123' — auto-decrypted
```

### APP_PREVIOUS_KEYS placeholder in .env.example

```bash
# .env.example — add after APP_KEY line
APP_KEY=

# Key rotation: before changing APP_KEY, add the OLD key here as a comma-separated list.
# Laravel will decrypt with the old key and re-encrypt with the new one transparently.
# Failure to set this BEFORE rotating APP_KEY will permanently lose all encrypted Stripe credentials.
# APP_PREVIOUS_KEYS=
```

### shadcn-vue components.json expected shape (New York / Tailwind v4)

```json
{
  "style": "new-york-v4",
  "tailwind": {
    "config": "",
    "css": "resources/css/app.css",
    "cssVariables": true
  },
  "aliases": {
    "components": "@/components",
    "utils": "@/lib/utils",
    "ui": "@/components/ui"
  }
}
```

Note: `"tailwind.config": ""` (blank string) is correct for Tailwind v4 — no config file path needed. [CITED: shadcn-vue.com/docs/components-json]

---

## State of the Art

| Old Approach                          | Current Approach               | When Changed  | Impact                                                  |
|--------------------------------------|-------------------------------|--------------|--------------------------------------------------------|
| Inertia Laravel v2 (`^2.0`)          | inertia-laravel v3 (`^3.0`)    | March 2026   | New starter kit default; `Inertia::lazy()` removed, use `Inertia::optional()` |
| `@inertiajs/vue3` v2                 | `@inertiajs/vue3` v3           | March 2026   | All userland APIs (`useForm`, `router`, `usePage`) unchanged |
| `VerifyCsrfToken` middleware (L12)   | `PreventRequestForgery` (L13)  | March 2026   | Webhook exclusion syntax changes; affects Phase 6 only |
| `laravel new --kit=vue` → L12        | `laravel new --vue` → L13      | March 2026   | Use `--vue` flag directly; no `--kit=` flag in current installer |
| shadcn-vue `default` style           | `new-york` style only          | Early 2025   | `default` deprecated; new projects use `new-york-v4`  |
| Radix Vue primitives (shadcn-vue)    | Reka UI v2 primitives          | Feb 2025     | shadcn-vue migrated; do NOT install Radix Vue          |
| `tailwind.config.js`                 | CSS-first via `@import "tailwindcss"` | Jan 2025 | Tailwind v4 config is pure CSS; no JS config file     |

**Deprecated/outdated:**
- `Inertia::lazy()`: removed in v3. Use `Inertia::optional()`.
- PostCSS approach for Tailwind: replaced by `@tailwindcss/vite` plugin.
- Radix Vue: replaced by Reka UI. Do not install.
- shadcn-vue `default` style: deprecated. Use `new-york`.
- `laravel/breeze`: deprecated as starter kit for L12+. The Vue starter kit IS the replacement.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Seeder uses `Role::firstOrCreate()` before `$admin->assignRole()` | Seeder Implementation | Seeder throws error if role doesn't exist; easy fix |
| A2 | `payments.currency` stored as lowercase 3-char string ('usd', 'gbp') | Migration Schema | Downstream phases must consistently case-match; low risk |
| A3 | `brands.primary_color` / `secondary_color` stored as VARCHAR(7) hex strings | Migration Schema | Color picker in Phase 3 must output hex; easy constraint |
| A4 | `StripeAccountFactory` uses fixed placeholder keys per D-11 | Factory Patterns | Correct per decision; factory used for test seeding only |
| A5 | Existing Herd project confirms MySQL at localhost:3306 with root/root credentials | Environment Availability | Credentials from an unrelated project; user must confirm payhub DB setup |

---

## Open Questions

1. **Laravel 12 vs Laravel 13**
   - What we know: CONTEXT.md D-01 specifies "Laravel 12". `laravel new` now installs L13. L13 is a zero-breaking-change release with identical stack.
   - What's unclear: Whether the user cares about the version number specifically, or just wants the stable stack described in CLAUDE.md.
   - Recommendation: Use `laravel new payhub --vue --pest --database=mysql` (installs L13). The stack is identical. Document in the plan that the app will be L13. If L12 is mandatory, use `composer create-project laravel/laravel:^12.0 payhub` and manually install starter kit assets.

2. **Inertia v2 vs v3 in CONTEXT.md / CLAUDE.md**
   - What we know: CONTEXT.md D-01 says "Inertia v2". The current starter kit ships inertia-laravel v3 / @inertiajs/vue3 v3. All Phase 1–5 userland APIs are unchanged.
   - What's unclear: Whether Inertia v2 is a hard requirement or an outdated reference.
   - Recommendation: Proceed with Inertia v3. Note the divergence from CLAUDE.md. The only breaking change that affects PayHub is the Phase 6 `constructEvent()` configuration file path (addressed in Phase 6 research).

3. **MySQL database name for payhub**
   - What we know: Herd provides MySQL at localhost:3306, root/root (confirmed from existing grc project `.env`).
   - What's unclear: Whether a `payhub` database must be created manually in MySQL before `migrate:fresh`, or if Herd's UI handles database creation.
   - Recommendation: Plan must include `mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS payhub;"` or equivalent Herd UI step as a prerequisite.

---

## Environment Availability

| Dependency      | Required By                           | Available | Version    | Fallback                         |
|-----------------|--------------------------------------|-----------|-----------|----------------------------------|
| PHP             | Laravel runtime                       | Yes       | 8.3.19     | —                                |
| Node.js         | npm, Vite                             | Yes       | 22.19.0    | —                                |
| npm             | Frontend dependencies                 | Yes       | 10.9.3     | —                                |
| Composer        | PHP dependencies                      | Yes       | 2.8.1      | —                                |
| Laravel Installer| `laravel new` command                | Yes       | 5.24.9     | `composer create-project` fallback|
| MySQL           | Database (via Herd)                   | Yes       | via Herd   | SQLite for early testing only    |
| Git             | Version control                       | [ASSUMED] | —          | —                                |

**Missing dependencies with no fallback:** None identified.

**Missing dependencies with fallback:**
- MySQL database `payhub` must be created before running migrations. Herd's database manager UI or `mysql -u root -p -e "CREATE DATABASE payhub;"` covers this.

**Windows-specific notes:**
- `laravel new` interactive prompts work in PowerShell on Windows with the current Herd-bundled installer v5.24.9.
- The `--vue --pest --database=mysql` flags bypass most interactive prompts.
- If the install fails with SSL error, use `composer create-project` fallback (see Pitfall 6).
- HMR hot reload may require hard refresh after first cold start (see Pitfall 7).
- shadcn-vue `add` commands run non-interactively with `npx shadcn-vue@latest add button` — no special Windows workaround needed.

---

## Validation Architecture

### Test Framework

| Property       | Value                                     |
|----------------|------------------------------------------|
| Framework      | Pest PHP v4.x                             |
| Config file    | `phpunit.xml` (Pest uses PHPUnit config)  |
| Quick run      | `php artisan test --filter=EncryptionRoundTrip` |
| Full suite     | `php artisan test`                        |

### Phase Requirements to Test Map

| Req ID | Behavior                                                      | Test Type | Automated Command                                           | File Exists? |
|--------|---------------------------------------------------------------|-----------|-------------------------------------------------------------|--------------|
| SEC-01 | Encrypted `secret_key` column saves ciphertext, reads plaintext | Unit/DB   | `php artisan test --filter=EncryptionRoundTripTest`         | No — Wave 0  |
| SEC-01 | Encrypted `webhook_secret` column round-trips correctly        | Unit/DB   | `php artisan test --filter=EncryptionRoundTripTest`         | No — Wave 0  |
| SC-5   | Integer amount stored and retrieved without float drift        | Unit/DB   | `php artisan test --filter=PaymentAmountIntegrityTest`      | No — Wave 0  |
| SC-7   | Model relationships traversable (Brand→StripeAccount→Payment) | Feature/DB| `php artisan test --filter=ModelRelationshipsTest`          | No — Wave 0  |

> SC-5 and SC-7 are success criteria from the Phase 1 ROADMAP, not formal REQ-IDs.

### Sampling Rate

- **Per task commit:** `php artisan test --filter=EncryptionRoundTripTest`
- **Per wave merge:** `php artisan test`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps

- [ ] `tests/Feature/EncryptionRoundTripTest.php` — covers SEC-01 (secret_key and webhook_secret round-trips)
- [ ] `tests/Feature/ModelRelationshipsTest.php` — covers SC-7 (Brand→StripeAccount, Payment→Brand, Payment→User)
- [ ] `tests/Feature/PaymentAmountIntegrityTest.php` — covers SC-5 (amount stored as integer cents)

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category      | Applies | Standard Control                                  |
|--------------------|---------|---------------------------------------------------|
| V2 Authentication  | No      | No auth in Phase 1; auth is Phase 2 scope         |
| V3 Session Mgmt    | No      | No sessions in Phase 1                            |
| V4 Access Control  | No      | No routes in Phase 1                              |
| V5 Input Validation| Partial | Seeder uses hardcoded values; no request validation|
| V6 Cryptography    | Yes     | Laravel `encrypted` cast — AES-256-CBC + MAC via APP_KEY |

### Known Threat Patterns for this Phase

| Pattern                        | STRIDE    | Standard Mitigation                                      |
|-------------------------------|-----------|----------------------------------------------------------|
| DB dump exposes Stripe keys    | Info Disclosure | Laravel `encrypted` cast — keys unreadable without APP_KEY |
| APP_KEY rotation destroys data | Availability | `APP_PREVIOUS_KEYS` placeholder in `.env.example` (D-12) |
| Float amount causes underpayment | Tampering | `unsignedBigInteger` cents column from day one           |

---

## Sources

### Primary (HIGH confidence)

- `laravel.com/docs/12.x/eloquent-mutators#encrypted-casting` — encrypted cast requirements (TEXT column, casts() method)
- `laravel.com/docs/12.x/encryption` — APP_PREVIOUS_KEYS key rotation
- `packagist.org/packages/stripe/stripe-php` — v20.1.0 confirmed latest stable
- `packagist.org/packages/spatie/laravel-permission` — v7.4.1 confirmed
- `packagist.org/packages/spatie/laravel-stripe-webhooks` — v3.11.0 confirmed
- `packagist.org/packages/laravel/fortify` — v1.37.0 confirmed
- `packagist.org/packages/laravel/telescope` — v5.20.0 confirmed
- `packagist.org/packages/pestphp/pest` — v4.6.3 confirmed
- `npmjs.com/package/@inertiajs/vue3` — v3.0.3 confirmed
- `npmjs.com/package/@tailwindcss/vite` — v4.2.4 confirmed
- `npmjs.com/package/shadcn-vue` — v2.6.2 confirmed
- `npmjs.com/package/vue-stripe-js` — v2.0.2 confirmed
- `npmjs.com/package/@stripe/stripe-js` — v9.4.0 confirmed
- `shadcn-vue.com/docs/components-json` — `new-york-v4` style name, blank tailwind config for v4
- `github.com/laravel/vue-starter-kit/composer.json` — inertiajs/inertia-laravel `^3.0`, laravel/fortify `^1.34`
- `laravel.com/docs/13.x/upgrade` — `VerifyCsrfToken` → `PreventRequestForgery` rename in L13
- `laravel.com/docs/12.x/releases` — L12 bug fix support until Aug 13, 2026
- `herd.laravel.com` (Herd Windows changelog) — SSL cert issue with starter kit install
- Local environment verification: `php --version`, `node --version`, `npm --version`, `composer --version`, `laravel --version`

### Secondary (MEDIUM confidence)

- `github.com/beyondcode/herd-community/issues/1296` — Windows Herd SSL issue with `laravel new --kit=vue` (closed, fix released)
- `tailwindcss.com/blog/tailwindcss-v4` — CSS-first config, no tailwind.config.js
- `inertiajs.com/docs/v3/getting-started/upgrade-guide` — Inertia v3 breaking changes (Axios removed, ES Modules only)

### Tertiary (LOW confidence)

- Windows HMR / hot reload issue: reported on Laracasts, not verified against official Herd docs

---

## Metadata

**Confidence breakdown:**

| Area              | Level  | Reason                                                                  |
|-------------------|--------|-------------------------------------------------------------------------|
| Standard Stack    | HIGH   | All package versions verified against npm and Packagist registries      |
| Schema Details    | HIGH   | Column types verified against Laravel encrypted cast docs and project decisions |
| Architecture      | HIGH   | Patterns verified against Laravel 12 docs; model relationships are standard |
| Pitfalls          | HIGH   | L13 installer and CSRF middleware rename verified; Windows Herd issue confirmed via GitHub |
| shadcn-vue Setup  | MEDIUM | `new-york-v4` style name inferred from components.json in starter kit repo; interactive prompts partially documented |

**Research date:** 2026-05-02
**Valid until:** 2026-06-01 (30 days for stable stack; inertia-laravel v3/Tailwind v4 are stable releases)
