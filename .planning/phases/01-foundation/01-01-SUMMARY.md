---
phase: 01-foundation
plan: 01
subsystem: infra
tags: [laravel, inertia, vue3, tailwind-v4, shadcn-vue, stripe, spatie, mysql, scaffold]

# Dependency graph
requires: []
provides:
  - Laravel 13.7.0 application scaffold with Inertia v3, Vue 3, Tailwind v4
  - MySQL payhub database configured and migrated (starter kit tables)
  - All five D-03 backend packages installed at locked versions
  - spatie/laravel-permission migrations published (pending Wave 2 run)
  - Five shadcn-vue seed components (button, input, label, card, badge)
  - brand-theme.css CSS variable scaffold for multi-brand theming
  - APP_PREVIOUS_KEYS rotation placeholder in .env.example
affects: [01-02, phase-2, phase-3, phase-4, phase-5, phase-6, phase-7]

# Tech tracking
tech-stack:
  added:
    - laravel/framework v13.7.0
    - inertiajs/inertia-laravel v3.0.6
    - laravel/fortify v1.37.0
    - spatie/laravel-permission 7.4.1
    - stripe/stripe-php v20.1.0
    - spatie/laravel-stripe-webhooks 3.11.0
    - laravel/telescope v5.20.0 (dev)
    - vue-stripe-js ^2.0.2
    - "@stripe/stripe-js ^9.4.0"
    - shadcn-vue new-york-v4 (starter kit)
    - reka-ui v2 (starter kit)
  patterns:
    - CSS variable multi-brand theming via [data-brand] attribute selector
    - APP_PREVIOUS_KEYS rotation placeholder pattern for encrypted column safety

key-files:
  created:
    - composer.json - Laravel 13 + all D-03 backend package manifest
    - package.json - Vue 3 + Inertia + Tailwind + Stripe npm manifest
    - components.json - shadcn-vue new-york-v4 style configuration
    - resources/css/brand-theme.css - Multi-brand CSS variable scaffold
    - config/permission.php - spatie/laravel-permission configuration
    - database/migrations/2026_05_02_201349_create_permission_tables.php
    - config/telescope.php - Laravel Telescope dev configuration
    - resources/js/components/ui/button/Button.vue
    - resources/js/components/ui/input/Input.vue
    - resources/js/components/ui/label/Label.vue
    - resources/js/components/ui/card/Card.vue
    - resources/js/components/ui/badge/Badge.vue
  modified:
    - resources/css/app.css - Added @import ./brand-theme.css after tailwindcss
    - .env.example - Added APP_PREVIOUS_KEYS commented placeholder with rotation warning

key-decisions:
  - "Laravel 13.7.0 accepted (not 12) — installer ships L13 since March 2026; zero breaking changes for Phase 1 scope; CSRF middleware rename (PreventRequestForgery) affects Phase 6 only"
  - "Inertia v3 / @inertiajs/vue3 v3 accepted (not v2) — starter kit ships v3; all userland APIs unchanged"
  - "Scaffold done via laravel new --vue --pest --database=mysql into temp dir then robocopy to worktree — directory was non-empty"
  - "All five shadcn-vue seed components already present in starter kit — no npx shadcn-vue add needed"
  - "fortify published config found in config/fortify.php from starter kit — this is expected (starter kit ships it), not a violation of the deferred-config rule"
  - "components.json tailwind.config has tailwind.config.js path despite no file existing — Tailwind v4 still resolves via @tailwindcss/vite plugin; build passes"

patterns-established:
  - "Pattern: brand-theme.css [data-brand] CSS variable injection — Phase 5 applies data-brand attr with inline --brand-primary/secondary vars"
  - "Pattern: .env.example as operator documentation surface — APP_PREVIOUS_KEYS warning prevents encrypted credential loss on key rotation"

requirements-completed: []

# Metrics
duration: 65min
completed: 2026-05-03
---

# Phase 1 Plan 01: Laravel Scaffold + Package Installation Summary

**Laravel 13.7.0 + Inertia v3 + shadcn-vue new-york-v4 scaffold with all D-03 packages installed, spatie/laravel-permission migrations published, brand-theme.css multi-brand CSS variable scaffold created, and APP_PREVIOUS_KEYS rotation placeholder added**

## Performance

- **Duration:** ~65 min
- **Started:** 2026-05-03T00:55:00Z
- **Completed:** 2026-05-03T02:00:00Z
- **Tasks:** 4
- **Files modified:** ~300 (full Laravel scaffold + packages)

## Accomplishments

- Scaffolded complete Laravel 13 application with Vue 3, Inertia v3, Tailwind v4, shadcn-vue new-york-v4 via official starter kit
- Installed all five D-03 backend packages at exact locked versions from RESEARCH.md
- Created `brand-theme.css` with CSS variable scaffold for Phase 5 multi-brand theming; `app.css` imports it
- Added `APP_PREVIOUS_KEYS` rotation warning to `.env.example` preventing accidental encrypted credential loss

## Task Commits

1. **Task 1: Scaffold Laravel + Vue starter kit** - `5203403` (chore)
2. **Task 2: Install D-03 backend packages** - `93cbb79` (chore)
3. **Task 3: Stripe npm packages + brand-theme.css** - `ff8294d` (feat)
4. **Task 4: APP_PREVIOUS_KEYS placeholder** - `49a8d86` (chore)

## Files Created/Modified

- `composer.json` - All D-03 backend packages with correct version constraints
- `composer.lock` - Locked dependency tree (134 packages)
- `package.json` - vue-stripe-js@^2.0.2 and @stripe/stripe-js@^9.4.0 added
- `package-lock.json` - Locked npm dependency tree
- `components.json` - shadcn-vue new-york-v4 configuration
- `resources/css/brand-theme.css` - Multi-brand CSS variable scaffold (created)
- `resources/css/app.css` - Added `@import './brand-theme.css'` after tailwindcss
- `.env.example` - APP_PREVIOUS_KEYS commented placeholder with rotation warning
- `config/permission.php` - spatie/laravel-permission config (published)
- `config/telescope.php` - Laravel Telescope config (published, dev only)
- `database/migrations/2026_05_02_201349_create_permission_tables.php` - Permission tables
- `database/migrations/2026_05_02_201355_create_telescope_entries_table.php` - Telescope table
- All starter kit files: app/, routes/, resources/js/, tests/, etc.

## Confirmed Package Versions (from composer.lock)

| Package | Locked Version |
|---------|---------------|
| laravel/framework | v13.7.0 |
| laravel/fortify | v1.37.0 |
| spatie/laravel-permission | 7.4.1 |
| stripe/stripe-php | v20.1.0 |
| spatie/laravel-stripe-webhooks | 3.11.0 |
| laravel/telescope | v5.20.0 (dev) |
| inertiajs/inertia-laravel | v3.0.6 |

## Decisions Made

- **Laravel 13 (not 12):** The `laravel new` installer ships L13 since March 17, 2026. Plan explicitly accepts this — zero breaking changes for Phase 1 scope. The CSRF middleware rename to `PreventRequestForgery` only affects Phase 6.
- **Inertia v3 (not v2):** The starter kit ships inertia-laravel v3 / @inertiajs/vue3 v3. All userland APIs unchanged. Phase 6 research should note the `Inertia::optional()` change from `Inertia::lazy()`.
- **Scaffold via temp directory + robocopy:** `laravel new .` refused to scaffold into a non-empty directory (`.planning/`, `CLAUDE.md` present). Used `laravel new payhub-scaffold` in temp dir, then robocopy'd to worktree. `.planning/` and `CLAUDE.md` preserved.
- **Skipped npx shadcn-vue add for all 5 components:** Starter kit pre-shipped all five required components (button, input, label, card, badge) plus 17 additional components. Per Pitfall 3 mitigation — no re-init needed.
- **components.json tailwind.config path:** Starter kit sets `"tailwind.config": "tailwind.config.js"` even though Tailwind v4 needs no config file. Build passes — the `@tailwindcss/vite` plugin handles resolution.

## Deviations from Plan

### Auto-fixed Issues

None — plan executed as written. All deviations are expected behaviors documented in RESEARCH.md.

### Noteworthy Behaviors (not deviations)

**1. `config/fortify.php` present at project root**
- The starter kit ships a pre-published `config/fortify.php` (it wires Fortify routes automatically). The plan says "do NOT publish Fortify config in this phase." The config was shipped by the starter kit itself, not published by this plan. This is expected and does not violate the phase boundary — Phase 2 will modify this file when configuring invite-only auth.

**2. Auth pages at `resources/js/pages/auth/` (lowercase) not `Auth/`**
- The starter kit uses lowercase `auth/` directory. Plan acceptance criteria referenced `Auth/Login.vue`. All pages (Login, Register, ForgotPassword, etc.) are present and untouched at `resources/js/pages/auth/`. D-02 satisfied.

## Issues Encountered

- `mysql` binary not on PATH in bash environment — used PHP PDO to create the `payhub` database (`php -r "new PDO(...)->exec('CREATE DATABASE IF NOT EXISTS payhub')"`).
- `laravel` installer not on bash PATH — located at `C:\Users\Salman\.config\herd\bin\laravel.bat` and invoked directly.

## Known Stubs

None — Plan 01-01 creates infrastructure only. No UI components render data. No stubs introduced.

## Threat Flags

No new threat surface introduced beyond what the PLAN.md threat model covers. All T-01-01-xx mitigations applied:
- T-01-01-01: `.env` excluded by `.gitignore` — verified not committed
- T-01-01-02: APP_PREVIOUS_KEYS placeholder with exact warning text present in `.env.example`
- T-01-01-03: Version constraints pinned per RESEARCH.md; `composer.lock` committed
- T-01-01-04: All 5 components from official shadcn-vue registry (pre-shipped by starter kit)
- T-01-01-05: Telescope is in `require-dev` section — acceptable for dev environment

## Next Phase Readiness

- Wave 2 (Plan 01-02) can proceed: migrations, models, factories, seeders, and Pest tests
- `php artisan migrate` will run permission and telescope migrations alongside Wave 2 schema migrations
- All D-03 packages installed and discoverable; spatie/laravel-permission commands registered
- brand-theme.css scaffold ready for Phase 5 consumption
- `.env` configured for MySQL `payhub` with working DB connection

---
*Phase: 01-foundation*
*Completed: 2026-05-03*
