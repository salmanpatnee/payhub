# Phase 1: Foundation - Context

**Gathered:** 2026-05-02
**Status:** Ready for planning

<domain>
## Phase Boundary

Scaffold the Laravel 12 application with the full stack (Inertia v2, Vue 3, Tailwind CSS 4, shadcn-vue, Fortify, spatie/laravel-permission), then create all four core database migrations with their final complete schemas, define models with relationships and encrypted casts, add factories and seeders, and verify AES-256 encryption round-trips correctly on StripeAccount credentials.

No Stripe API calls in this phase. No auth flows. No UI beyond confirming Tailwind renders on the Inertia root page.

</domain>

<decisions>
## Implementation Decisions

### Scaffold Strategy
- **D-01:** Use the official Laravel 12 Vue+Inertia starter kit: `laravel new payhub --kit=vue`. It ships Inertia v2, Vue 3, Tailwind CSS 4, and Vite pre-wired — fastest path to a working Inertia page without manual adapter config.
- **D-02:** Keep the Breeze-style auth pages that ship with the starter kit (`resources/js/pages/Auth/Login.vue`, `Register.vue`, `ForgotPassword.vue`) as placeholders. Do NOT remove them in Phase 1. Phase 2 installs Fortify, disables public registration, and wires the Fortify backend routes to these existing Vue pages.
- **D-03:** After scaffolding, install the additional packages not in the starter kit: `laravel/fortify`, `spatie/laravel-permission`, `stripe/stripe-php`, `spatie/laravel-stripe-webhooks`, `laravel/telescope` (dev), and npm packages `vue-stripe-js`, `@stripe/stripe-js`.

### shadcn-vue Style
- **D-04:** Initialize shadcn-vue CLI with **New York** style. This is a one-time choice — all components added in any phase must use the same style.
- **D-05:** Seed these five components into `resources/js/components/ui/` in Phase 1: **Button**, **Input**, **Label**, **Card**, **Badge**. These are required across every downstream phase and seeding them now avoids CLI interruptions mid-phase.

### Database Schema
- **D-06:** Build the **full final schema** in Phase 1 migrations. All four models get every column they will ever need across all 7 phases — no alter-table migrations in later phases.
- **D-07:** `payments.status` column: MySQL `ENUM('pending', 'completed', 'failed', 'cancelled')` with default `'pending'`. Enforced at the database level.
- **D-08:** `brands.logo_path`: nullable `VARCHAR(255)` storing a Laravel Storage path (e.g. `brands/abc123.png`). Phase 3 handles the upload; display via `Storage::url($brand->logo_path)`. Do NOT use `logo_url` — this is local storage, not an external CDN URL.
- **D-09:** `payments.expires_at`: nullable `TIMESTAMP`, default `NULL`. NULL means the link never expires. Include in Phase 1 migration per research recommendation.
- **D-10:** `stripe_accounts.secret_key` and `stripe_accounts.webhook_secret`: TEXT columns (not VARCHAR) with the Laravel `encrypted` cast. They cannot be searched via SQL WHERE — this is intentional. All encrypted columns must be TEXT.

### Seeder Scope
- **D-11:** Phase 1 seeder populates: one Brand record, one StripeAccount record with a **fake/placeholder** secret key (e.g., `sk_test_placeholder_for_dev_only`) to test the encrypted cast without requiring a real Stripe account, and one Admin user. Seeders must be idempotent (use `firstOrCreate`).

### APP_KEY Rotation
- **D-12:** Not discussed — deferred. However, per STATE.md concern: Phase 1 must add `APP_PREVIOUS_KEYS=` as a commented placeholder to `.env.example` with a comment explaining the rotation risk. No runbook needed in Phase 1.

### Claude's Discretion
- Migration file naming and ordering (as long as `migrate:fresh --seed` runs clean)
- Factory definitions (Faker-based realistic data)
- Model cast definitions beyond `encrypted` (dates, integers already implied by column types)
- Pest test structure for encryption round-trip verification

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project Definition
- `.planning/PROJECT.md` — Core constraints, tech stack decisions, out-of-scope list
- `.planning/REQUIREMENTS.md` — SEC-01 (Phase 1 requirement: AES-256 encrypted casts)
- `.planning/ROADMAP.md` — Phase 1 goal, success criteria (7 items), depends-on chain

### Research
- `.planning/research/SUMMARY.md` — Recommended exact package versions, architecture highlights, top pitfalls (CRITICAL-1 through CRITICAL-5, MODERATE-6 through MODERATE-7). **Read the Top Pitfalls section before writing any Stripe-touching code.**
- `.planning/research/STACK.md` — Exact package versions with installation notes and what NOT to install (Cashier, Breeze as auth backend, Jetstream, Vuex, cloudcreativity/laravel-stripe)
- `.planning/research/ARCHITECTURE.md` — Four core model definitions, StripeService pattern, two route surfaces, webhook fulfillment rule

### Original Brief
- `docs/payment_infrastructure_project.md` — Original project brief; use if context in PROJECT.md needs clarification

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- None — codebase is empty. Phase 1 creates all assets from scratch.

### Established Patterns
- None yet — Phase 1 establishes the patterns all downstream phases follow.

### Integration Points
- Phase 1 output (migrations, models, factories, seeders) is the foundation every subsequent phase depends on. Schema correctness and encryption verification are non-negotiable before Phase 2 begins.

</code_context>

<specifics>
## Specific Ideas

- The Breeze auth Vue pages should be kept as-is from the starter kit output. Do not style or modify them in Phase 1 — that's Phase 2's job.
- The encryption round-trip success criterion (criterion 5) must be verified with an actual Pest test: create a StripeAccount with a known secret_key, save it, retrieve it fresh from DB, assert decrypted value matches original. This test stays in the suite as a regression guard.

</specifics>

<deferred>
## Deferred Ideas

- APP_KEY rotation runbook — noted in STATE.md as a concern; full runbook deferred to v2. Phase 1 only adds `APP_PREVIOUS_KEYS=` placeholder to `.env.example`.
- Laravel Horizon — research recommends it for production queue UI; install deferred until Phase 6 (queue infrastructure phase).
- Additional shadcn-vue components beyond the core set (Select, Dialog, Table, etc.) — added per-phase as needed.

</deferred>

---

*Phase: 1-Foundation*
*Context gathered: 2026-05-02*
