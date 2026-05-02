# PayHub — Claude Code Guide

## Project

Centralized multi-brand payment hub for an agency. Laravel 12 + Inertia.js v2 + Vue 3 + Tailwind CSS 4 + shadcn-vue + Stripe Elements. Replaces fragmented WooCommerce/Stripe setup with one app powering multiple brands.

See `.planning/PROJECT.md` for full context.

## GSD Workflow

This project uses the Get-Shit-Done (GSD) planning workflow.

- **Roadmap**: `.planning/ROADMAP.md` — 7 phases, execute in order
- **Requirements**: `.planning/REQUIREMENTS.md` — 40 REQ-IDs, all mapped to phases
- **State**: `.planning/STATE.md` — current phase and progress
- **Research**: `.planning/research/` — stack, features, architecture, pitfalls, summary

### Current status

Phase 1 is next. Run `/gsd-discuss-phase 1` or `/gsd-plan-phase 1` to start.

### Phase order

1. Foundation (Laravel install + Inertia + schema + encryption)
2. Auth + User Management
3. Brand + Stripe Account Management
4. Payment Creation + Link Generation
5. Client Payment Page
6. Webhooks + Status Sync
7. Notifications + Dashboard

## Stack

- **Backend**: Laravel 12, PHP 8.3
- **Frontend**: Vue 3 (Composition API + `<script setup>`), Inertia.js v2
- **Styling**: Tailwind CSS 4, shadcn-vue 2.6 (Reka UI primitives)
- **Auth**: Laravel Fortify — invite-only, no public registration
- **RBAC**: spatie/laravel-permission v7 (Admin / User roles)
- **Payments**: Stripe Elements, stripe/stripe-php v20, vue-stripe-js v2
- **Webhooks**: spatie/laravel-stripe-webhooks v3.11
- **Queues**: Laravel Queues (database driver for dev, Redis for prod)

## Git workflow

- **Always** create a new branch before starting a new phase: `git checkout -b phase-{N}-{short-description}`
- Merge to `master` only after phase is complete and tests pass

## Critical rules

- **Never** call `Stripe::setApiKey()` globally — always `new StripeClient($account->secret_key)`
- **Never** trust client-side `confirmPayment()` for DB writes — all payment status comes from webhooks only
- **Never** accept amount from client request — always read from server-side `Payment` record
- Stripe `secret_key` and `webhook_secret` columns must use Laravel `encrypted` cast (TEXT columns, no SQL WHERE)
- Webhook routes excluded from CSRF middleware; raw body preserved for `constructEvent()`
- `PaymentIntent client_secret` never logged, stored in URLs, or exposed beyond the page load response
- Amounts always stored as integer cents — no floats
- Currency: USD and GBP only

## Commands

```bash
php artisan serve          # Start Laravel dev server
npm run dev                # Start Vite dev server
php artisan migrate:fresh --seed   # Reset DB with seed data
php artisan test           # Run Pest test suite
stripe listen --forward-to localhost:8000/webhook/stripe/{accountId}  # Test webhooks
```
