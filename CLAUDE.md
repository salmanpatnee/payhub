# PayHub — Claude Code Guide

## Project

Centralized multi-brand payment hub for an agency. Laravel 13 + Inertia.js v3 + Vue 3 + Tailwind CSS 4 + shadcn-vue + Stripe Elements. Replaces fragmented WooCommerce/Stripe setup with one app powering multiple brands.

See `.planning/PROJECT.md` for full context. See `docs/agent.md` for RBAC rules, nav access matrix, and E2E testing runbook.

## GSD Workflow

This project uses the Get-Shit-Done (GSD) planning workflow.

- **Roadmap**: `.planning/ROADMAP.md` — 7 phases, execute in order
- **Requirements**: `.planning/REQUIREMENTS.md` — 40 REQ-IDs, all mapped to phases
- **State**: `.planning/STATE.md` — current phase and progress
- **Research**: `.planning/research/` — stack, features, architecture, pitfalls, summary

Phase 1 is next. Run `/gsd-discuss-phase 1` or `/gsd-plan-phase 1` to start. See `.planning/ROADMAP.md` for phase order.

## Stack

- **Backend**: Laravel 13, PHP 8.3
- **Frontend**: Vue 3 (Composition API + `<script setup>`), Inertia.js v3
- **Styling**: Tailwind CSS 4, shadcn-vue 2.6 (Reka UI primitives)
- **Auth**: Laravel Fortify — invite-only, no public registration
- **RBAC**: spatie/laravel-permission v7 (Admin / Agent roles)
- **Payments**: Stripe Elements (stripe/stripe-php v20, vue-stripe-js v2) + Revolut Merchant API (@revolut/checkout Web SDK) — two parallel providers
- **Webhooks**: spatie/laravel-stripe-webhooks v3.11
- **Queues**: Laravel Queues (database driver for dev, Redis for prod)

## Payment providers

PayHub supports two parallel providers behind the `PaymentProvider` enum (`stripe` / `revolut`). Each `Payment` has a `provider` column and provider-specific FKs/columns. Resolve provider-specific logic by branching on `$payment->provider`.

- **Stripe**: `StripeClient`, PaymentIntents, `stripe_account_id` / `stripe_payment_intent_id`, webhook `webhook/stripe/{account}`
- **Revolut**: `App\Services\Revolut\RevolutClient`, Merchant API orders, `revolut_account_id` / `revolut_order_id`, webhook `webhook/revolut/{account}`
- When adding a feature that touches payments, implement it for BOTH providers — pay page, CSV export, webhook handling, and admin account CRUD all have parallel paths.

## Git workflow

- **Always** create a new branch before starting a new phase: `git checkout -b phase-{N}-{short-description}`
- Merge to `master` only after phase is complete and tests pass

## RBAC

Two roles: `admin` and `agent`. See `docs/agent.md` for nav access matrix, implementation rules, and seeded users.

## Critical rules

- **Never** instantiate a global payment client — always per-account: `new StripeClient($account->secret_key)` / `app()->make(RevolutClient::class, ['secretKey' => $account->secret_key])`. Never `Stripe::setApiKey()` globally
- **Never** trust client-side confirmation (`confirmPayment()` / Revolut Card Field `onSuccess`) for DB writes — all payment status comes from webhooks only
- **Never** accept amount from client request — always read from the server-side `Payment` record
- Provider `secret_key` and `webhook_secret` columns must use Laravel `encrypted` cast (TEXT columns, no SQL WHERE). On `RevolutAccount` these two are NOT mass-assignable — assign explicitly
- Webhook routes (`webhook/stripe/*`, `webhook/revolut/*`) excluded from CSRF middleware; raw body preserved for signature verification
- Revolut webhooks carry no event id: idempotency keys on `order_id:event_type` (`ProcessedRevolutEvent`); signature is HMAC-SHA256 of `v1.{timestamp}.{rawBody}` with 300s replay tolerance
- Secrets exposed to the page (Stripe `client_secret` / Revolut `orderToken`) never logged, stored in URLs, or exposed beyond the page load response
- Amounts always stored as integer cents/minor units — no floats
- Currency: USD and GBP only

## Commands

```bash
php artisan serve          # Start Laravel dev server
npm run dev                # Start Vite dev server
php artisan migrate:fresh --seed   # Reset DB with seed data
php artisan test           # Run Pest test suite
stripe listen --forward-to localhost:8000/webhook/stripe/{accountId}  # Test Stripe webhooks
php artisan revolut:register-webhook {account?} --url=https://...    # Register Revolut webhook, store signing secret
```

## General conventions

Framework-level engineering conventions (Laravel Boost guidelines, PHP/Inertia/Pest/Pint
rules, Artisan & tooling usage) live in `@AGENT.md` — read it before writing code.
