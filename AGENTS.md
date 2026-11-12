# PayHub — Claude Code Guide

## Project

Centralized multi-brand payment hub for an agency. Laravel 13 + Inertia.js v3 + Vue 3 + Tailwind CSS 4 + shadcn-vue + Stripe Elements. Replaces fragmented WooCommerce/Stripe setup with one app powering multiple brands.

See `.planning/PROJECT.md` for full context. See `docs/agent.md` for RBAC rules, nav access matrix, and E2E testing runbook.

Read only central dashboard API: `GET /api/central/v1/summary` (`App\Services\CentralSummary`), token protected; contract in `docs/integrations/central-dashboard-api.md` (spec 0008). Deploy guide: `docs/deployment.md`.

## GSD Workflow

The original build used the Get-Shit-Done (GSD) planning workflow. All 10 phases are complete (`.planning/STATE.md`); `.planning/` is kept as history only.

- **Roadmap**: `.planning/ROADMAP.md`
- **Requirements**: `.planning/REQUIREMENTS.md`
- **State**: `.planning/STATE.md`
- **Research**: `.planning/research/` — stack, features, architecture, pitfalls, summary

New work is planned as a spec under `docs/specs/` (see `## Specs`), not as a GSD phase.

## Stack

- **Backend**: Laravel 13, PHP 8.3
- **Frontend**: Vue 3 (Composition API + `<script setup>`), Inertia.js v3
- **Styling**: Tailwind CSS 4, shadcn-vue 2.6 (Reka UI primitives)
- **Auth**: Laravel Fortify — invite-only, no public registration
- **RBAC**: spatie/laravel-permission v7 (Admin / Agent / Account roles)
- **Payments**: Stripe Elements (stripe/stripe-php v20, vue-stripe-js v2) + Revolut Merchant API (@revolut/checkout Web SDK) + Square (square/square PHP SDK, Web Payments SDK) + Authorize.net (Accept.js) + Whop (`@whop/elements` embedded checkout, or Whop's hosted page by redirect) + Kurv (hosted payment page by redirect) — nine parallel providers (Viva, Clover, MPGS, Authorize.net, Whop and Kurv are documented in `docs/agent.md` and/or their specs)
- **Webhooks**: spatie/laravel-stripe-webhooks v3.11
- **Queues**: Laravel Queues (database driver for dev, Redis for prod)
- **Job processing on shared hosting**: staging runs `QUEUE_CONNECTION=sync` (webhook jobs run inside the webhook request, no worker needed). With the database driver, add a cron job `php artisan queue:work --stop-when-empty --max-time=55` every minute; `docs/deployment.md` has no queue or cron section yet.
- **Other**: Laravel Wayfinder (typed route helpers in `resources/js/actions` and `resources/js/routes`), Sentry (`sentry/sentry-laravel`, `@sentry/vue`), maatwebsite/excel (CSV export)

## Payment providers

PayHub supports nine parallel providers behind the `PaymentProvider` enum (`stripe` / `revolut` / `square` / `viva` / `clover` / `mpgs` / `authorizenet` / `whop` / `kurv`). Each `Payment` has a `provider` column and provider-specific FKs/columns. Resolve provider-specific logic by branching on `$payment->provider`.

- **Stripe**: `StripeClient`, PaymentIntents, `stripe_account_id` / `stripe_payment_intent_id`, webhook `webhook/stripe/{account}`
- **Revolut**: `App\Services\Revolut\RevolutClient`, Merchant API orders, `revolut_account_id` / `revolut_order_id`, webhook `webhook/revolut/{account}`
- **Square**: `Square\SquareClient` (square/square SDK), Payments API, `square_account_id` / `square_payment_id`, webhook `webhook/square/{squareAccount}`
- **MPGS (Mastercard Gateway, Hosted Session)**: `App\Services\Mpgs\MpgsClient`, `mpgs_account_id` / `mpgs_order_id`, webhook `webhook/mpgs/{mpgsAccount}`. Card fields are gateway-hosted frames and 3DS runs on the pay page (spec `docs/specs/0005-mpgs-hosted-session-provider/`). **Scoped exception to webhooks-only status** (same as Clover): MPGS payment status comes from the gateway's own response to PayHub's server-to-server PAY call, or a read-only order lookup (resolver job, webhook job, 10-minute sweep). All status writes go through `MpgsChargeResolver`. The webhook body is never trusted: it only triggers a lookup. Approved needs success + captured + exact amount/currency match, else `unknown`.
- **Authorize.net (Accept.js, own card fields)**: `App\Services\Authorizenet\AuthorizenetClient`, `authorizenet_account_id` / `authorizenet_transaction_id`, webhook `webhook/authorizenet/{authorizenetAccount}` (spec `docs/specs/0007-authorizenet-acceptjs-own-card-fields/`). PayHub renders its own card inputs on the pay page; Accept.js turns the card into a one time code and the server charges it with `createTransaction`. **Scoped exception to webhooks-only status** (same as Clover and MPGS): status comes from the gateway's own reply plus a read-back of the transaction, a read-only invoice-number lookup (`AuthorizenetInvoiceLookup`: status endpoint, `ResolveAuthorizenetCharge` job, 10-minute sweep) or the webhook; every status write goes through `AuthorizenetChargeResolver`. The webhook body is never trusted: it only triggers a lookup. Every charge call writes a row in `authorizenet_charge_attempts` (status, response/error codes, plain reason, transaction id, AVS/CVV/3DS letters; diagnostic only, never card values), shown to admins on the payment page; `AuthorizenetChargeResolver` later settles an unclear row. A held (fraud review) charge stays `pending` with a transaction id; an unclear reply (timeout, duplicate) pauses new charges for 120 seconds and queues a lookup. Card values never touch Inertia form state, a URL, storage or logs (the card form is `sentry-block`). Each account needs a public client key; it is fetched from `getMerchantDetails`, never typed in. No ZIP is collected (card number, expiry, CVV only), so each merchant account must set AVS response **B** to Allow, or every charge is declined. **PCI**: own fields on our page usually means SAQ A-EP, not SAQ A. Confirm with the acquirer or an assessor before production. Only the Accept.js script and connect hosts are added to the CSP, and only on this pay page.
- **Whop (Checkout configuration + embedded checkout or redirect)**: `App\Services\Whop\WhopClient` (the only place that knows Whop request fields, with `WhopWebhookPayload` for the webhook body), `whop_account_id` / `whop_checkout_configuration_id` / `whop_purchase_url`, webhook `webhook/whop/{whopAccount}` (spec `docs/specs/0009-whop-payment-provider/`). Status comes from the signed webhook only (`payment.succeeded`; `payment.failed` and every other event are logged, never change status); no lookup fallback. Signature is Standard Webhooks style (`webhook-id`, `webhook-timestamp`, `webhook-signature`) with a 5 minute window, dedupe in `ProcessedWhopEvent`; Whop's replay carries a fresh timestamp. `WhopPaymentController::checkout` (`pay/{payment}/whop/checkout`, `whop-checkout` limiter) creates one hidden checkout per payment from the server side amount (`variant` with `adaptive_pricing_enabled: false`; no `payment_method_configuration` is sent, so the methods shown, such as cards, Apple Pay, Google Pay and PayPal, follow each Whop account's dashboard checkout settings) and reuses it; `status` only reads `Payment.status`. The account's `checkout_mode` is `embed` (checkout mounts on page load with no Pay now step; Whop's summary and email field hidden with `appearance.classes`, email prefilled and locked, billing name prefilled) or `redirect` (Pay now goes to Whop's hosted page). Billing details cannot be hidden (Whop blocks payment without them). Amount check compares `subtotal`. CSP adds the Whop hosts only on an embed account's pay page. Once a Whop checkout exists, the payment's amount, currency, account, brand and deletion are locked. Use the `whop` Agent Skill for Whop API questions.
- **Kurv (hosted payment page by redirect)**: `App\Services\Kurv\KurvClient` (with `KurvPaymentRequestReply` and `KurvLookupReply`, the only classes that know Kurv field names), `kurv_account_id` / `kurv_request_id` / `kurv_payment_url` / `kurv_payment_id`, callback `webhook/kurv/{kurvAccount}` (spec `docs/specs/0010-kurv-provider/`). Hosts come from `config('services.kurv.hosts.{environment}')` (`sandbox` or `production`); `api_key` is encrypted and not mass assignable, and a `kp_test_` key only goes with `sandbox`, `kp_live_` only with `production`. `pay/{payment}/kurv/checkout` (`kurv-checkout` limiter, consent required) creates one Kurv payment request per payment from the server side amount and returns `{url}`; later calls reuse the saved link. **Scoped exception to webhooks-only status** (same family as Clover, MPGS and Authorize.net): Kurv's callback is unsigned, so it only triggers a read only lookup (checked by an HMAC token of the payment UUID keyed with `APP_KEY`, always answers 200). `KurvChargeResolver` is the only status writer: `completed` needs a successful sale with matching transaction id, amount and currency; v1 never sets `failed`. Lookups also come from the pay page poll (`pay/{payment}/kurv/status`) and `KurvStalePaymentSweep` every 10 minutes. Every lookup writes a `kurv_lookup_attempts` row, shown to admins on the payment page. USD only (`"This Kurv account only accepts USD payments."`), `client_email` required. Once `kurv_charge_started_at` is set, amount, currency, account, brand and deletion are locked until `kurv_link_closed_at` is set. No CSP change (redirect only).
- When adding a feature that touches payments, implement it for ALL NINE providers — pay page, CSV export, webhook handling, admin account CRUD, dashboard/filter surfaces, and client-facing receipt pages all have parallel paths. Square landed after Stripe/Revolut and several read-side surfaces (dashboard filters, "Accounts Today" account resolution) were missed on first pass — grep for `'stripe', 'revolut'` two-way ternaries/`in_array`s before assuming a Square-touching feature is complete.
- **Square currency lock**: a `SquareAccount` is single-currency (`square_accounts.currency`). `StorePaymentRequest`/`UpdatePaymentRequest` reject creating/updating a payment against a Square account when the payment currency doesn't match the account's currency (`"This Square account only accepts {currency} payments."`). Stripe/Revolut accounts have no such restriction.

## Git workflow

- Branch from `staging` for each change: `feature/<name>`, `fix/<name>` or `chore/<name>`
- Merge the branch into `staging`, then `staging` into `master`, only after tests pass. Production runs `master`

## RBAC

Three roles: `admin`, `agent`, and `account` (read-only Payments viewer, but full manage rights on Bank Accounts). See `docs/agent.md` for nav access matrix, implementation rules, and seeded users.

## Bank Accounts

Separate from the payment providers. Admin/account roles manage them; agents only see active accounts assigned to them (`bank_account_user` pivot). Soft-deleted, with an activity log (`ActivityLogger`, `bank-accounts/activity-log`). At least one of sort code / routing number / IBAN is required. Full details in `docs/agent.md` → "Bank Accounts".

## Zelle Accounts

Separate from the payment providers and from Bank Accounts. Same roles/assignment model, but no activity log; paginated admin list, no currency input (new accounts default to USD; the card still shows the currency badge). Full details in `docs/agent.md` → "Zelle Accounts".

## Critical rules

- **Never** instantiate a global payment client — always per-account: `new StripeClient($account->secret_key)` / `app()->make(RevolutClient::class, ['secretKey' => $account->secret_key])` / `new SquareClient($account->access_token, options: [...])`. Never `Stripe::setApiKey()` globally
- **Never** trust client-side confirmation (`confirmPayment()` / Revolut Card Field `onSuccess` / Square Web Payments SDK tokenize callback) for DB writes — all payment status comes from webhooks only
- **Never** accept amount from client request — always read from the server-side `Payment` record
- Provider `secret_key`/`access_token` and `webhook_secret`/`webhook_signature_key` columns must use Laravel `encrypted` cast (TEXT columns, no SQL WHERE). On `RevolutAccount` and `SquareAccount` these two are NOT mass-assignable — assign explicitly. `WhopAccount` encrypts `api_key` and `webhook_secret` the same way (also not mass-assignable). `KurvAccount` encrypts `api_key` the same way (not mass-assignable; no stored callback secret)
- Webhook routes (`webhook/stripe/*`, `webhook/revolut/*`, `webhook/square/*`, `webhook/viva/*`, `webhook/mpgs/*`, `webhook/authorizenet/*`, `webhook/whop/*`, `webhook/kurv/*`) excluded from CSRF middleware; raw body preserved for signature verification. Also excluded: `pay/*/square`, `pay/*/clover/charge`, `pay/*/mpgs/3ds-return` (list in `bootstrap/app.php`)
- Revolut webhooks carry no event id: idempotency keys on `order_id:event_type` (`ProcessedRevolutEvent`); signature is HMAC-SHA256 of `v1.{timestamp}.{rawBody}` with 300s replay tolerance
- Square webhooks verified via `x-square-hmacsha256-signature` header against `webhook_signature_key` (Square SDK's `WebhooksHelper`); idempotency tracked in `ProcessedSquareEvent`
- Secrets exposed to the page (Stripe `client_secret` / Revolut `orderToken`) never logged, stored in URLs, or exposed beyond the page load response. (Square's `application_id`/`location_id` are public SDK identifiers, not secrets — safe to expose, unlike `access_token`)
- Amounts always stored as integer cents/minor units — no floats
- Currency: Payments support USD and GBP, plus PKR on MPGS only: an MPGS account must have `supports_pkr` on (`CurrencySupportResolver::supports(..., $accountAcceptsPkr)`). Admins can use PKR directly; an agent can take PKR only after an admin gives them a PKR payment-account slot (`user_payment_accounts.currency = pkr`, which must be a `supports_pkr` MPGS account). No other provider accepts it (Whop takes USD and GBP only; Kurv takes USD only). Bank Accounts additionally support PKR (`App\Enums\BankAccountCurrency`, decoupled from `App\Enums\SupportedCurrency`).

## Commands

```bash
php artisan serve          # Start Laravel dev server
npm run dev                # Start Vite dev server
php artisan migrate:fresh --seed   # Reset DB with seed data
php artisan test           # Run Pest test suite
composer dev               # server, queue worker, logs and Vite together
php artisan test --parallel  # faster way to run the Pest suite
npm run types:check && npm run lint:check && npm run format:check  # front end checks
composer lint              # Pint (PHP formatting)
npm run build              # compiled assets in public/build are committed
stripe listen --forward-to localhost:8000/webhook/stripe/{accountId}  # Test Stripe webhooks
php artisan revolut:register-webhook {account?} --url=https://...    # Register Revolut webhook, store signing secret
php artisan whop:register-webhook {account?} --url=https://...       # Register Whop webhook (payment.succeeded, payment.failed), store signing secret
```

## Specs

Stored in `docs/specs/NNNN-title/` (one folder per feature: `index.md`, `rationale.md`, sometimes `verify.md`).

## Agent skills

- [whop](.agents/skills/whop/): `whopio/plugins@whop`, Whop API, SDK and account tools
- MCP servers: Whop Docs (`https://docs.whop.com/mcp`, recommended) · Whop (`https://mcp.whop.com/mcp`, recommended; acts on live account data through a browser sign in)
- Declined: `whopio/skills@whop-webhooks`, `whopio/whop-payments-network-skill`

## Context files

- [resources/js/pages/ClientPayment/AGENTS.md](resources/js/pages/ClientPayment/AGENTS.md) (client pay pages: consent gate, card value handling, fixed messages, polling, card lock notice)

## General conventions

Framework-level engineering conventions (Laravel Boost guidelines, PHP/Inertia/Pest/Pint
rules, Artisan & tooling usage) live in `@AGENT.md` — read it before writing code.
