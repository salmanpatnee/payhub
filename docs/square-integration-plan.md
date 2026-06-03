# Square Payment Processor — Phased Integration Plan

## Context

**Why:** PayHub's Stripe accounts keep getting **closed due to chargeback cases**. Single-processor setup means a closed account halts collection for affected brands. Goal: a **live, fully working second rail (Square)** so admins can immediately issue payment links on a Square account when a Stripe account dies. This is failover/redundancy — not cost or features.

**Approach:** Mirror the production-proven Stripe architecture **additively**, with **zero changes to working Stripe code paths**. Parallel `square_accounts` table, a `provider` discriminator on payments, and Square equivalents of every Stripe component.

**Locked decisions:**
- Surface: **Embedded** Square Web Payments SDK on `/pay/{uuid}` (mirror Stripe Elements).
- Provider UX: one **merged "Payment Account" dropdown** (Stripe + Square, provider-labeled).
- Data model: **mirror** (parallel table), not generalized refactor.
- Geography: US + UK → UK requires SCA (`verifyBuyer`).
- White-label: brand-forward, "powered by" OK.
- Status authority: **webhook-only** (never trust client charge response) — same rule as Stripe.

**Unavoidable divergence:** Stripe confirms client→Stripe directly. Square embedded needs `card.tokenize()` → POST to a **new server-side charge endpoint** → `payments->create()`. Charge response only stores the Square payment id; authoritative status comes from the `payment.updated` webhook.

**Branch:** `feat/square-payment-processor`

> Maps cleanly to the GSD workflow — each phase below can be run via `/gsd-plan-phase`.

## Execution Strategy — Subagents

Implementation is delegated to **subagents, one per phase**, dispatched by the main thread:

- **Sequential, not parallel** — phases are dependency-ordered (Phase 2 needs Phase 1 models, Phase 4 needs Phase 3 routing, Phase 5 needs Phase 4 `square_payment_id`, etc.). Each phase subagent runs only after the previous phase passes its acceptance gate.
- **Per-phase subagent brief:** the phase's Goal + Tasks + file targets + Tests + Acceptance criteria from this doc, plus the mirror-source Stripe files to copy patterns from.
- **Verification gate between phases:** main thread runs `php artisan test --compact` (filtered) + `vendor/bin/pint --dirty --format agent` and confirms acceptance criteria before dispatching the next phase subagent. A failed gate blocks progression.
- **Within a phase**, a subagent may parallelize independent files (e.g. the three admin Vue pages), but must keep backend contracts and tests consistent.
- **Atomic commits** per phase on `feat/square-payment-processor`.
- Suggested agent type: project `gsd-executor` (or `general-purpose`) per phase.

---

## Phase 1 — Data Model & Foundation

**Goal:** Schema + models + SDK in place. No behavior change to Stripe.

**Tasks:**
- `composer require square/square` (new official SDK). *(Dependency change — approved via this plan.)*
- Migrations (all additive):
  - `create_square_accounts_table` — mirror `stripe_accounts`: `account_name`, `application_id`, `location_id`, `access_token` (TEXT, **encrypted**), `webhook_signature_key` (TEXT nullable, **encrypted**), `environment` enum(`sandbox`,`production`) default `sandbox`, `is_active`, timestamps.
  - `add_provider_and_square_account_to_payments` — `provider` enum(`stripe`,`square`) default `stripe`; nullable FK `square_account_id`; nullable `square_payment_id`.
  - `create_processed_square_events_table` — `event_id` PK, `processed_at`.
  - `add_square_account_to_users` — nullable FK `square_account_id`.
- Models:
  - `app/Models/SquareAccount.php` — mirror `StripeAccount`; `access_token`/`webhook_signature_key` not mass-assignable; encrypted casts; `payments()`.
  - `app/Models/ProcessedSquareEvent.php` — mirror `ProcessedStripeEvent`.
  - `Payment.php` — add `provider`, `square_account_id`, `square_payment_id` to fillable; `squareAccount()` relation; `getAccountNameAttribute()` resolving per provider.
  - `User.php` — add `square_account_id` fillable + `squareAccount()`.
- Factories: `SquareAccountFactory` (sandbox defaults, placeholder token); extend `PaymentFactory` (`provider` default `stripe`, `square_account_id` null) + add `square` state.

**Tests:** `ModelRelationshipsTest` — `payment->squareAccount`, `user->squareAccount`, `account_name` accessor per provider.

**Acceptance:** `migrate:fresh --seed` clean; existing Stripe tests green; new relationship tests pass.

---

## Phase 2 — Admin Square Account CRUD

**Goal:** Admins can add/manage Square accounts (sandbox + production), parity with Stripe account admin.

**Tasks:**
- `app/Http/Controllers/Admin/SquareAccountController.php` — mirror `StripeAccountController`: index (masked previews), create, store (explicit `access_token` assignment), edit (never return secrets; return `has_webhook_signature_key` + computed `webhook_endpoint_url`), update (blank secret = preserve), destroy (block if payments exist), activate/deactivate, `testKeyConnection`/`testStoredConnection` (validate via `locations` list; skip real call in `testing` env).
- Requests: `StoreSquareAccountRequest`, `UpdateSquareAccountRequest` — admin-only; validate `application_id`, `location_id`, `access_token`, `environment in sandbox,production`, `webhook_signature_key` nullable; production-env guard against sandbox creds.
- Per-account `SquareClient` factory (container-bindable for test mocking): `new SquareClient($account->access_token, options:['baseUrl'=>Environments::{Production|Sandbox}->value])`.
- Routes: admin `square-accounts` resource + activate/deactivate/test, `role:admin`.
- UI: `resources/js/pages/admin/square-accounts/{Index,Create,Edit}.vue` — mirror Stripe pages; fields incl. `environment` select; Edit shows copyable webhook endpoint URL + masked secret indicators; "Test connection" button.

**Tests:** `tests/Feature/Admin/SquareAccountManagementTest.php` — CRUD, blank-secret-preserve, role gating.

**Acceptance:** Admin creates a sandbox Square account; test-connection passes; non-admins blocked.

---

## Phase 3 — Merged Payment-Account Selection

**Goal:** Admin creates a payment against either Stripe or Square via one dropdown.

**Tasks:**
- `PaymentController@create` — return `paymentAccounts` (active Stripe + Square unioned), each `{ value:"{provider}:{id}", label, provider }`; provide `isAccountLocked` + locked value for agents.
- `StorePaymentRequest` — accept single `payment_account` field `"{provider}:{id}"`; split → validate id `exists` in correct table where `is_active=true`; set `provider` + the matching FK (other null); keep amount→cents conversion; agent override locks provider/account.
- `PaymentController@index` — eager load both accounts; map `provider` + `account_name` into rows.
- UI `payments/Create.vue` — replace Stripe `<Select>` with merged "Payment Account" select; provider-labeled options; honor agent lock.

**Tests:** extend `PaymentCreationTest` — create via `"stripe:{id}"` and `"square:{id}"`; inactive Square rejected; correct provider/FK set.

**Acceptance:** Payment created on a Square account shows provider=Square; Stripe path unchanged.

---

## Phase 4 — Embedded Client Payment + Server Charge

**Goal:** Customer pays a Square payment via inline Square card form on `/pay/{uuid}`.

**Tasks:**
- `ClientPaymentController@show` — branch on `provider`: stripe → existing PI logic; square → return `squareAccount` props `{ application_id, location_id, environment }` (NEVER `access_token`), no `clientSecret`.
- NEW charge endpoint `POST /pay/{payment}/square` (`ClientPaymentController@chargeSquare` or `SquareChargeController`): public, CSRF-excluded, throttled. Guard provider+status; validate `source_id` + nullable `verification_token`; per-account `SquareClient`; `payments->create` with `amountMoney` from **DB cents** (never client), `locationId`, idempotency key, `referenceId`=reference_code, `note`=uuid; store `square_payment_id`; **do not set completed** (webhook authoritative); return sanitized JSON.
- UI `ClientPayment/Pay.vue` — branch on `provider`: square loads Web Payments SDK from environment CDN (`sandbox.web.squarecdn.com` vs `web.squarecdn.com`); vanilla wrapper component: `payments.card()` → attach (brand color via Square card `style`, approximate) → `tokenize()` → UK SCA `verifyBuyer()` (isolated function, deprecated-but-current) → POST to charge endpoint → success redirect / error. Null-guard SDK load + loading skeleton (mirror existing WR-01/WR-02 guards).

**Tests:** `tests/Feature/SquarePaymentChargeTest.php` — guest charge; amount from DB not client; `SquareClient` mocked via `app()->bind`; `square_payment_id` stored; status stays `pending`; `access_token` never in props.

**Acceptance:** Sandbox test card charges; payment id stored; status still `pending` (awaiting webhook).

---

## Phase 5 — Square Webhook & Status Sync

**Goal:** Webhook is the authoritative status writer (mirror Stripe).

**Tasks:**
- `app/Http/Controllers/SquareWebhookController.php` — route `POST /webhook/square/{squareAccount}` (throttle 120/min); inactive → 200; raw body; `WebhooksHelper::verifySignature($body, header('x-square-hmacsha256-signature'), $account->webhook_signature_key, route('webhook.square',$account,true))` → 403 on fail; handle `payment.updated` only; idempotency via `ProcessedSquareEvent` on `event_id`; dispatch `HandleSquareWebhookJob($accountId, $squarePaymentId, $squareStatus)`.
- `app/Jobs/HandleSquareWebhookJob.php` — mirror Stripe job (`tries=3`, backoff `[1,5,10]`); map `COMPLETED`→`completed`+`paid_at` (from pending/failed), `FAILED`→`failed` (from pending), `CANCELED`→`cancelled`; match on `square_payment_id`+`square_account_id`; on confirmed completion dispatch existing `SendPaymentNotification`.
- `bootstrap/app.php` — add `'webhook/square/*'` to `preventRequestForgery(except:[...])`.

**Tests:** `tests/Feature/SquareWebhookTest.php` — build `x-square-hmacsha256-signature` via WebhooksHelper algorithm; 403 bad sig; `payment.updated`→completed sets `paid_at`; idempotency; CSRF-excluded; failed→completed retry.

**Acceptance:** Sandbox `payment.updated` flips status to completed, sends notification; replay is a no-op.

---

## Phase 6 — Unified Reporting, Agent Assignment, Docs

**Goal:** One dashboard across providers; agents assignable to Square; ops documented.

**Tasks:**
- Dashboard `payments/Index.vue` — add `provider` to row type; add **Provider** column (badge); rename `account_name` column label "Stripe Account" → **"Payment Account"** (provider-agnostic via accessor).
- User management — `users/{Create,Edit}.vue` + `StoreUserRequest`/`UpdateUserRequest`: allow assigning agent to a Square account (mirror `stripe_account_id`).
- `docs/agent.md` — Square account setup runbook (developer app, application_id, access_token, location_id, `payment.updated` subscription → `/webhook/square/{id}`, copy signature key, sandbox-first; one app per Square account = isolated signature keys; webhook subscriptions are application-owned).

**Tests:** dashboard renders provider; user-square-assignment feature test.

**Acceptance:** Mixed Stripe+Square payments list with provider column + working filters; agent can be Square-assigned.

---

## Phase 7 — Sandbox E2E & Production Cutover

**Goal:** Prove the full rail in sandbox, then enable production.

**Tasks:**
- Full sandbox run-through (verification checklist below).
- `vendor/bin/pint --dirty --format agent`; `php artisan test --compact` all green.
- Add production Square credentials per account only after sandbox passes.
- Merge `feat/square-payment-processor` → `master`.

**End-to-end verification:**
1. `migrate:fresh --seed` — schema applies; Stripe tests green.
2. Admin creates sandbox Square account; test-connection OK.
3. Full test suite green (Stripe + Square).
4. Create payment via merged dropdown (Square) → show page provider=Square.
5. `/pay/{uuid}` renders branded Square card form; sandbox card charges; `square_payment_id` stored; status `pending`.
6. `payment.updated` webhook → signature verifies → `completed` + `paid_at` + notification; replay no-op.
7. Dashboard shows Provider=Square in "Payment Account" column; filters work across providers.
8. One Stripe payment confirms zero regression.
9. Add production creds; smoke test live.

---

## Out of Scope
- Subscriptions / recurring (links-only; Square links don't support recurring).
- POS / in-person, invoicing, marketplace payouts.
- Hosted Square checkout redirect (embedded chosen).
- Generalizing `stripe_accounts` → `payment_accounts` (mirror chosen to protect live Stripe).
- Migrating existing open Stripe payments to Square (failover is forward-looking).

## Risks / Notes
- `verifyBuyer` (UK SCA) is **deprecated** by Square but still current — isolated in one function for easy future swap.
- Square webhook signature requires the **notification URL to byte-match** the subscription config — surface the exact URL in admin Edit.
- Square card-form styling is **more limited** than Stripe Elements — brand colors approximated.
- No official Square Vue SDK — embedded form uses a thin vanilla `window.Square` wrapper.
