---
phase: 01-foundation
plan: 02
subsystem: data-layer
tags: [migrations, eloquent-models, encrypted-cast, factories, seeders, pest-tests]

# Dependency graph
requires: [01-01]
provides:
  - brands, stripe_accounts, payments MySQL tables with correct column types
  - Brand, StripeAccount, Payment Eloquent models with relationships and casts
  - User model updated with Spatie HasRoles trait
  - BrandFactory, StripeAccountFactory, PaymentFactory
  - Idempotent DatabaseSeeder (roles + Brand + StripeAccount + Admin user)
  - EncryptionRoundTripTest (SEC-01 verified)
  - ModelRelationshipsTest (all 4 relationship traversals)
  - PaymentAmountIntegrityTest (integer cents integrity)
  - tests/Pest.php bootstrap for Pest functional-style tests
affects: [phase-2, phase-3, phase-4, phase-5, phase-6, phase-7]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - Laravel encrypted cast on TEXT columns (AES-256-CBC via APP_KEY)
    - Integer cents storage (unsignedBigInteger) for payment amounts
    - Idempotent seeders via firstOrCreate
    - Pest.php bootstrap required for Pest functional tests in Laravel worktree context

key-files:
  created:
    - database/migrations/2026_05_03_000001_create_brands_table.php
    - database/migrations/2026_05_03_000002_create_stripe_accounts_table.php
    - database/migrations/2026_05_03_000003_create_payments_table.php
    - app/Models/Brand.php
    - app/Models/StripeAccount.php
    - app/Models/Payment.php
    - database/factories/BrandFactory.php
    - database/factories/StripeAccountFactory.php
    - database/factories/PaymentFactory.php
    - tests/Feature/EncryptionRoundTripTest.php
    - tests/Feature/ModelRelationshipsTest.php
    - tests/Feature/PaymentAmountIntegrityTest.php
    - tests/Pest.php
  modified:
    - app/Models/User.php - Added HasRoles import and trait use
    - database/seeders/DatabaseSeeder.php - Full replacement with idempotent seeder

key-decisions:
  - "Pest.php bootstrap required: Pest functional-style tests (uses() without extends TestCase) resolve Faker\\Generator from bare container without DatabaseServiceProvider, causing Unknown format errors. Adding tests/Pest.php with uses(Tests\\TestCase::class)->in('Feature','Unit') fixes this."
  - "BrandFactory hexColor() fix: Faker's hexColor() returns # prefix already (e.g. #FF5733). Plan example prepended another # creating ##FF5733 (8 chars), violating VARCHAR(7). Fixed by removing the manual # prefix."
  - "Worktree composer install required: git worktrees share git history but not vendor/. composer install run in worktree to get dependencies."
  - "Vite build assets copied from main repo: public/build/manifest.json needed for pre-existing Auth/Settings/Dashboard HTTP tests. Gitignored, copied at test time."

requirements-completed: [SEC-01]

# Metrics
duration: 90min
completed: 2026-05-03
---

# Phase 1 Plan 02: Migrations, Models, Factories, Seeder, Tests Summary

**Three schema migrations (brands, stripe_accounts, payments), four Eloquent models with encrypted casts and integer-cents amounts, three factories, idempotent seeder, and three Pest tests confirming SEC-01 encryption round-trip, model relationships, and payment amount integrity — all tests green.**

## Performance

- **Duration:** ~90 min
- **Started:** 2026-05-03T02:00:00Z
- **Completed:** 2026-05-03T03:30:00Z
- **Tasks:** 3
- **Files modified:** 15

## Accomplishments

- Created three schema migrations with correct column types: TEXT for encrypted fields (D-10), unsignedBigInteger for amount, ENUM status with 4 values, cascading FKs in correct dependency order
- Created Brand, StripeAccount, Payment models with relationships; updated User with HasRoles trait
- Authored BrandFactory, StripeAccountFactory, PaymentFactory, and idempotent DatabaseSeeder
- All three Pest tests pass: EncryptionRoundTripTest (SEC-01), ModelRelationshipsTest, PaymentAmountIntegrityTest
- `php artisan migrate:fresh --seed` exits 0; second seed run is idempotent (1 Brand, 1 StripeAccount, 1 User, 2 Roles)
- Full test suite: 49/49 tests passing

## Task Commits

1. **Task 1: Create migrations** - `f229321` (feat)
2. **Task 2: Create models** - `44dcd8c` (feat)
3. **Task 3: Factories, seeder, tests** - `67837ff` (feat)

## Confirmed MySQL Column Types (SHOW COLUMNS)

| Table | Column | Type |
|-------|--------|------|
| stripe_accounts | secret_key | text |
| stripe_accounts | webhook_secret | text |
| payments | amount | bigint unsigned |

## Test Results

```
Tests: 49, Passed: 49, Assertions: 153, Duration: ~3.7s
```

- EncryptionRoundTripTest: 2/2 passing (SEC-01 verified)
- ModelRelationshipsTest: 4/4 passing
- PaymentAmountIntegrityTest: 3/3 passing
- Pre-existing starter kit tests: 40/40 passing

## Seeder Verification (after migrate:fresh --seed, then db:seed second time)

| Entity | Count after first run | Count after second run |
|--------|----------------------|------------------------|
| Brands | 1 | 1 |
| StripeAccounts | 1 | 1 |
| Users | 1 | 1 |
| Roles | 2 | 2 |
| Admin has role | yes | yes |

## Files Created/Modified

- `database/migrations/2026_05_03_000001_create_brands_table.php`
- `database/migrations/2026_05_03_000002_create_stripe_accounts_table.php`
- `database/migrations/2026_05_03_000003_create_payments_table.php`
- `app/Models/Brand.php` - hasMany stripeAccounts/payments
- `app/Models/StripeAccount.php` - encrypted casts, belongsTo brand, hasMany payments
- `app/Models/Payment.php` - integer cast, stripeAccount() camelCase relationship
- `app/Models/User.php` - added HasRoles trait preserving all starter-kit content
- `database/factories/BrandFactory.php`
- `database/factories/StripeAccountFactory.php`
- `database/factories/PaymentFactory.php`
- `database/seeders/DatabaseSeeder.php`
- `tests/Feature/EncryptionRoundTripTest.php`
- `tests/Feature/ModelRelationshipsTest.php`
- `tests/Feature/PaymentAmountIntegrityTest.php`
- `tests/Pest.php` (added — required for Laravel bootstrap in Pest functional tests)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] BrandFactory hexColor double-hash**
- **Found during:** Task 3 (factory test failures)
- **Issue:** Plan's BrandFactory example uses `'#' . strtoupper($this->faker->hexColor())`. Faker's `hexColor()` already returns a `#`-prefixed string (e.g., `#FF5733`), making the result `##FF5733` — 8 characters, violating the `VARCHAR(7)` constraint
- **Fix:** Removed the manual `#` prefix: `strtoupper($this->faker->hexColor())`
- **Files modified:** `database/factories/BrandFactory.php`
- **Commit:** `67837ff`

**2. [Rule 2 - Missing critical functionality] tests/Pest.php bootstrap missing**
- **Found during:** Task 3 (all factory-using tests failed with `Unknown format "company"`)
- **Issue:** Pest functional-style tests (`it()` + `uses()` without `extends TestCase`) resolved `Faker\Generator` from a bare `Illuminate\Container\Container` that had no providers loaded. The `DatabaseServiceProvider` was not bootstrapped. All `$this->faker->*()` calls failed.
- **Fix:** Created `tests/Pest.php` with `uses(Tests\TestCase::class)->in('Feature', 'Unit')` to wire Laravel's full application bootstrap into every test
- **Files modified:** `tests/Pest.php` (created)
- **Commit:** `67837ff`

**3. [Rule 3 - Blocking] Worktree missing vendor/ directory**
- **Found during:** Task 1
- **Issue:** Git worktrees share git history but not `vendor/` (gitignored). `php artisan` commands failed with "Failed to open stream: vendor/autoload.php"
- **Fix:** Ran `composer install --no-interaction` in the worktree
- **Impact:** No files committed; build environment setup only

**4. [Rule 3 - Blocking] Worktree missing .env**
- **Found during:** Task 1 (after composer install)
- **Issue:** No `.env` file in worktree; SQLite was attempted; MySQL credentials needed
- **Fix:** Created `.env` with `DB_DATABASE=payhub`, `DB_PASSWORD=root` (Herd MySQL password)
- **Impact:** `.env` is gitignored, not committed

**5. [Rule 3 - Blocking] Vite manifest missing for pre-existing tests**
- **Found during:** Task 3 (full test suite)
- **Issue:** `public/build/manifest.json` not present in worktree (gitignored). Pre-existing Auth/Dashboard/Settings tests failed with `ViteManifestNotFoundException`
- **Fix:** Copied build artifacts from main repo's `public/build/` to worktree
- **Impact:** `public/build/` is gitignored, not committed; main repo's 40 existing tests all pass

## Known Stubs

None — all factory data uses Faker, seeder uses firstOrCreate with real placeholder values.

## Threat Flags

All T-01-02-xx mitigations applied per PLAN.md threat model:
- T-01-02-01: `secret_key` confirmed TEXT (not VARCHAR) — verified via `SHOW COLUMNS` → `text`
- T-01-02-02: `amount` confirmed `bigint unsigned` — verified via `SHOW COLUMNS`
- T-01-02-03: Placeholder strings `sk_test_placeholder_for_dev_only` / `whsec_placeholder_for_dev_only` used in factory and seeder — no real keys committed
- T-01-02-04: Accepted by design — DB dump shows only ciphertext without APP_KEY
- T-01-02-05: `Role::firstOrCreate` seeded BEFORE `syncRoles` — verified in DatabaseSeeder
- T-01-02-06: All tests use `uses(RefreshDatabase::class)` + `TestCase` bootstrap — SQLite in-memory
- T-01-02-07: Accepted — `stripe_payment_intent_id` stores only non-secret `pi_xxx` reference

## Self-Check: PASSED

- Migration files exist: confirmed via `php artisan migrate:status`
- Models exist and parse: confirmed via `php -l`
- Factory files exist: confirmed via `git status`
- Test files exist: confirmed via `git log`
- Commits exist: f229321, 44dcd8c, 67837ff (verified via `git log`)
- `migrate:fresh --seed` exits 0: confirmed
- Second seed run produces 1/1/1/2 counts: confirmed
- All 49 tests pass: confirmed
