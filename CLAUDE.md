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
- **Payments**: Stripe Elements (stripe/stripe-php v20, vue-stripe-js v2) + Revolut Merchant API (@revolut/checkout Web SDK) + Square (square/square PHP SDK, Web Payments SDK) — three parallel providers
- **Webhooks**: spatie/laravel-stripe-webhooks v3.11
- **Queues**: Laravel Queues (database driver for dev, Redis for prod)

## Payment providers

PayHub supports three parallel providers behind the `PaymentProvider` enum (`stripe` / `revolut` / `square`). Each `Payment` has a `provider` column and provider-specific FKs/columns. Resolve provider-specific logic by branching on `$payment->provider`.

- **Stripe**: `StripeClient`, PaymentIntents, `stripe_account_id` / `stripe_payment_intent_id`, webhook `webhook/stripe/{account}`
- **Revolut**: `App\Services\Revolut\RevolutClient`, Merchant API orders, `revolut_account_id` / `revolut_order_id`, webhook `webhook/revolut/{account}`
- **Square**: `Square\SquareClient` (square/square SDK), Payments API, `square_account_id` / `square_payment_id`, webhook `webhook/square/{squareAccount}`
- When adding a feature that touches payments, implement it for ALL THREE providers — pay page, CSV export, webhook handling, admin account CRUD, dashboard/filter surfaces, and client-facing receipt pages all have parallel paths. Square landed after Stripe/Revolut and several read-side surfaces (dashboard filters, "Accounts Today" account resolution) were missed on first pass — grep for `'stripe', 'revolut'` two-way ternaries/`in_array`s before assuming a Square-touching feature is complete.
- **Square currency lock**: a `SquareAccount` is single-currency (`square_accounts.currency`). `StorePaymentRequest`/`UpdatePaymentRequest` reject creating/updating a payment against a Square account when the payment currency doesn't match the account's currency (`"This Square account only accepts {currency} payments."`). Stripe/Revolut accounts have no such restriction.

## Git workflow

- **Always** create a new branch before starting a new phase: `git checkout -b phase-{N}-{short-description}`
- Merge to `master` only after phase is complete and tests pass

## RBAC

Two roles: `admin` and `agent`. See `docs/agent.md` for nav access matrix, implementation rules, and seeded users.

## Critical rules

- **Never** instantiate a global payment client — always per-account: `new StripeClient($account->secret_key)` / `app()->make(RevolutClient::class, ['secretKey' => $account->secret_key])` / `new SquareClient($account->access_token, options: [...])`. Never `Stripe::setApiKey()` globally
- **Never** trust client-side confirmation (`confirmPayment()` / Revolut Card Field `onSuccess` / Square Web Payments SDK tokenize callback) for DB writes — all payment status comes from webhooks only
- **Never** accept amount from client request — always read from the server-side `Payment` record
- Provider `secret_key`/`access_token` and `webhook_secret`/`webhook_signature_key` columns must use Laravel `encrypted` cast (TEXT columns, no SQL WHERE). On `RevolutAccount` and `SquareAccount` these two are NOT mass-assignable — assign explicitly
- Webhook routes (`webhook/stripe/*`, `webhook/revolut/*`, `webhook/square/*`) excluded from CSRF middleware; raw body preserved for signature verification
- Revolut webhooks carry no event id: idempotency keys on `order_id:event_type` (`ProcessedRevolutEvent`); signature is HMAC-SHA256 of `v1.{timestamp}.{rawBody}` with 300s replay tolerance
- Square webhooks verified via `x-square-hmacsha256-signature` header against `webhook_signature_key` (Square SDK's `WebhooksHelper`); idempotency tracked in `ProcessedSquareEvent`
- Secrets exposed to the page (Stripe `client_secret` / Revolut `orderToken`) never logged, stored in URLs, or exposed beyond the page load response. (Square's `application_id`/`location_id` are public SDK identifiers, not secrets — safe to expose, unlike `access_token`)
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
