# 0002. Switch Clover to a Hosted Iframe integration

**Date**: 2026-09-17
**Status**: Proposed

## Summary

This spec redesigns Clover from a redirect based checkout (spec 0001, already built) to an embedded card form living directly on PayHub's own pay page. The client types their card into a Clover provided iframe, never leaving PayHub, and never sending raw card details to PayHub's own server. PayHub's server then charges the resulting token and, exactly like every other provider, only a verified webhook from Clover ever marks the payment paid. This supersedes spec 0001; the redirect based approach it built is replaced, not kept alongside this one.

## Context

See `rationale.md` for the full problem context, the premise challenge, and the options weighed.

## Requirements

**User stories**:
- As an admin, I want to create and manage a Clover account (now including a public access key alongside its existing credentials) the same way I manage every other provider account.
- As an agent, I want to create a payment link against a Clover account exactly like any other provider.
- As a client, I want to pay a Clover link by entering my card directly on PayHub's own page, and if my card is declined, try another card immediately without needing a new link.
- As the business, I want a Clover payment's paid status to only ever come from Clover's own signed webhook, never from the browser or from the synchronous response PayHub's own server gets back when it makes the charge call.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: An admin can create, edit, activate, and deactivate a Clover account (account name, prefix, merchant ID, public access key, private token, webhook secret, sandbox or production), restricted to `role:admin`. This replaces spec 0001's AC-1 with one new field (`api_access_key`).
- **AC-2**: Creating or updating a payment against a Clover account whose currency does not match the account's locked currency (always USD) is rejected with the existing Clover currency error message. Unchanged from spec 0001's AC-2.
- **AC-3**: An agent or admin can create a payment link against any active Clover account exactly like the other four providers; every provider aware dropdown and validation rule includes Clover. Unchanged from spec 0001's AC-3.
- **AC-4**: When a client opens a Clover payment link, PayHub renders the card form directly on the pay page using Clover's Hosted Iframe SDK, initialized with the account's `merchant_id` and public `api_access_key`. No redirect happens, and no raw card data is ever submitted to PayHub's own server, only the token Clover's SDK produces.
- **AC-5**: Submitting the card form calls a rate limited (10 attempts per payment per hour) PayHub endpoint that sends the resulting token, the payment's server side amount and currency, and `Payment.uuid` as Clover's external reference, to Clover's charge API. A clean synchronous response (approved or declined) is used only to log a charge attempt and to give the client immediate feedback (see AC-9); it never writes the payment's status. If the synchronous call itself fails (timeout, network error, 5xx) before Clover returns a clean outcome, PayHub treats it as unknown, not declined: no attempt row is logged, and the client is sent to the same wait-for-webhook state as a likely success, since the charge may have gone through on Clover's side even though the response was lost.
- **AC-6**: The Clover webhook endpoint verifies the `Clover-Signature` HMAC-SHA256 header against the account's stored webhook secret before doing anything else; a request that fails verification is rejected and never changes a payment's status. Unchanged from spec 0001's AC-5.
- **AC-7**: A verified `PAYMENT` webhook event with `status: APPROVED` resolves its payment via the external reference Clover echoes back (`Payment.uuid`), never by requiring a pre-existing `clover_charge_attempts` row, moves that payment to `completed` exactly once (idempotent per charge id, redelivery safe, and safe even if the synchronous call that created the charge never logged an attempt), and records the charge id on the payment. Once `completed`, no later Clover event for that payment changes its status again.
- **AC-8**: A verified `PAYMENT` webhook event with `status: DECLINED` resolves its payment the same way (via the external reference, never a pre-existing attempt row) and moves it to `failed`, but only while it is still `pending` (never overwrites a `completed` payment). `failed` is not terminal: a later `APPROVED` webhook for a subsequent attempt on the same payment can still move it to `completed`, matching how Stripe and Square already behave in PayHub. Any Clover charge status other than `APPROVED`/`DECLINED` is logged and ignored, never applied, so a status PayHub does not recognize can never throw or leave the payment in an undefined state.
- **AC-9**: After a charge attempt that looks likely to succeed, the client sees a processing state that polls the payment's real status and moves to the Success page once the webhook confirms `completed`. After a synchronously declined attempt, the client sees an inline decline message immediately and can retry with a different card on the same page without any redirect or new link.
- **AC-10**: Clover appears everywhere the other four providers already do: payment create/edit forms, agent locked account assignment, CSV export, the dashboard's account filters and "Accounts Today" figures, and client facing receipt pages. No such surface is left behind. Unchanged from spec 0001's AC-9.
- **AC-11**: `clover_accounts.private_token` and `webhook_secret` stay encrypted at rest and non mass-assignable; `api_access_key` is stored as a plain, non-encrypted public identifier (safe to send to the frontend, like Square's `application_id`). The webhook route stays CSRF-excluded and rate limited. The new charge-submission endpoint is rate limited per payment. The charge amount and currency are always read from the `Payment` record server side, never from the client.

## Decision

**Chosen option**: Option 1: Clover Hosted Iframe, embedded tokenize and charge

Replace Clover's Hosted Checkout redirect flow (spec 0001) with an embedded card form on PayHub's own pay page: Clover's Hosted Iframe SDK tokenizes the card client side, PayHub's server charges the token via Clover's Ecommerce API, and a verified webhook remains the sole writer of payment status, exactly like every other provider.

## Rationale

See `rationale.md` for the full comparison against Option 2 (Clover's raw Ecommerce API) and Option 3 (keep Hosted Checkout).

## Feature design

**Data model sketch**:

- `clover_accounts` (existing table, modified in place — this branch is unmerged, nothing has shipped past it)
  - Adds: `api_access_key` string, plain (not encrypted) — public identifier the client side SDK needs; safe in logs and in the frontend, unlike `private_token`
  - `private_token` — kept, still `encrypted`, NOT mass-assignable; must be regenerated for Clover's Hosted Iframe integration type (the Hosted Checkout token cannot be reused, per Clover's one-token-per-integration-type rule)
  - `webhook_secret` — unchanged, still `encrypted`, NOT mass-assignable
  - Everything else (`account_name`, `prefix`, `merchant_id`, `currency` locked `usd`, `environment`, `is_active`, timestamps) unchanged
- `payments` (existing table, modified in place)
  - **Removed**: `clover_checkout_session_id`, `clover_checkout_expires_at`, `clover_checkout_url` — the whole session concept Hosted Checkout needed; Hosted Iframe has none
  - Kept: `clover_account_id`, `clover_payment_id` (now set exactly once, by the webhook that moves the payment to `completed`, holding that attempt's charge id)
- `clover_charge_attempts` (new table — a pure log, never load bearing for webhook matching; see Key invariants)
  - `id`
  - `payment_id` — FK to `payments`, cascade on delete
  - `clover_charge_id` string, indexed — Clover's id for this specific attempt
  - `status` string (`approved`, `declined`, or `unknown` for any charge status Clover returns that PayHub does not recognize; a plain string, not a hard DB enum, so a status Clover adds later cannot fail the insert) — written only from a clean synchronous `/v1/charges` response, never from client input, and never written at all if the synchronous call itself errors (AC-5)
  - `decline_reason` string, nullable
  - `created_at` only (immutable log, no `updated_at`)
- `processed_clover_events` (existing table, no schema change; only the computed key changes — see Value sourcing)

**State transitions**:

`pending` → `failed` (a verified `DECLINED` webhook) → `completed` (a verified `APPROVED` webhook for a later attempt), or `pending` → `completed` directly on a first-try approval. `completed` is terminal. `failed` is not: unlike Hosted Checkout's spec, a decline here never dead-ends the link, matching Stripe's and Square's existing behavior in this app (`HandleStripeWebhookJob`, `HandleSquareWebhookJob`).

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `clover-accounts` (resource, except `show`) | GET/POST/PUT/DELETE | adds `api_access_key` to the existing field set | account rows (`private_token`/`webhook_secret` never returned, only `has_*` flags; `api_access_key` returned as-is) | `role:admin` | 422 validation |
| `clover-accounts/{account}/activate` / `/deactivate` / `test-connection` | PATCH/POST | unchanged from spec 0001 | unchanged | `role:admin` | 404 |
| `pay/{payment}` (existing route, Clover branch rewritten) | GET | payment UUID | Inertia page rendering the embedded card form, with `merchant_id`/`api_access_key`/`environment` in props | public (unguessable UUID) | 404 unknown payment, 410 already paid |
| `payments/{payment}/clover/charge` (new) | POST | Clover token from client-side `createToken()` | JSON `{outcome: approved\|declined\|unknown, message}` — informational only, never a DB write; `unknown` on any transport failure calling Clover (AC-5) | public (UUID), rate limited to 10 attempts/payment/hour | 422 invalid/missing token, 429 too many attempts |
| `payments/{payment}/clover/status` (new) | GET | payment UUID | JSON `{status: pending\|failed\|completed}`, polled by the processing state | public (UUID) | 404 unknown payment |
| `webhook/clover/{cloverAccount}` (existing) | POST | raw Clover `PAYMENT` event, `Clover-Signature` header | 200 ack | HMAC signed, no session | 401 bad signature |

**Removed**: `clover/return/{payment}` — there is no redirect to return from.

**Value sourcing**:

| Action | Value produced / displayed | Source |
|---|---|---|
| Pay page visit | `merchant_id`, `api_access_key`, `environment` passed to the embedded form | `CloverAccount` columns, never a request param |
| Charge submission | amount charged | `Payment.amount` (server side column, never the client or the request) |
| Charge submission | currency | `Payment.currency`, already validated against `CloverAccount.currency` at payment creation (AC-2) |
| Charge submission | external reference sent to Clover | `Payment.uuid`, passed as Clover's external reference/order id field at charge creation (exact Clover field name to confirm — see Follow-up). This is the value the webhook resolves the payment from, not the attempt log |
| Charge submission | attempt log row (`clover_charge_attempts`) | written only from a clean synchronous `/v1/charges` response body (charge id, status, decline reason), never from client input, and never written on a transport failure (AC-5) |
| Webhook handler | which payment to update | the external reference field in the verified webhook payload, matched directly against `Payment.uuid`. This never depends on a `clover_charge_attempts` row existing, so a lost synchronous response or a webhook that arrives before that row is written still resolves correctly |
| Webhook handler | idempotency key | computed server side as `"{payment_id}:{clover_charge_id}"`, where `clover_charge_id` comes from the webhook payload itself, not from a lookup against `clover_charge_attempts` |
| Webhook handler | whether/how to transition status | current `Payment.status`: `pending`/`failed` can move; `completed` is terminal and any further event is logged and ignored |
| Status poll | current status shown to the client | `Payment.status` column, read only |

**Key invariants**:
- Only a verified `APPROVED` webhook ever sets `Payment.status = completed`; no other code path does, including the charge-submission endpoint regardless of what Clover's synchronous response said.
- `completed` is terminal: once set, no later Clover event for that payment changes it again.
- `failed` is not terminal: a `DECLINED` webhook only ever moves `pending → failed`, never touches an already-`completed` payment, and a later `APPROVED` webhook can still move `failed → completed`.
- `clover_payment_id` is set exactly once, by the webhook that moves the payment to `completed`, holding that winning attempt's charge id.
- Every charge attempt with a clean synchronous outcome (approved or declined) gets exactly one `clover_charge_attempts` row, written only from Clover's synchronous response, never from client input; a transport failure on that call logs no row at all.
- The webhook's ability to resolve and update a payment never depends on a `clover_charge_attempts` row existing: it matches on the external reference (`Payment.uuid`) carried in the verified payload, so a lost synchronous response or a webhook that outruns PayHub's own attempt-logging write still resolves correctly.
- Any Clover charge status the webhook or the synchronous response reports that PayHub does not recognize is logged as `unknown`/ignored, never applied to `Payment.status` and never allowed to fail an insert.
- `private_token` and `webhook_secret` stay encrypted and non mass-assignable; `api_access_key` is a public identifier and is exempt from that treatment on purpose.
- A `CloverAccount`'s `currency` is always `usd` (unchanged from spec 0001).

**Security model**:
- Account management (`clover-accounts.*`) stays `role:admin` only, unchanged.
- The pay, charge, and status routes are public but scoped by an unguessable payment UUID; the webhook route by a verified HMAC signature. None trusts a value the client can freely set.
- The charge-submission endpoint is newly rate limited to 10 attempts per payment per hour — this is the one Clover route where PayHub's own server now accepts something originating from a card entry, unlike every redirect based route.
- `private_token` and `webhook_secret` remain `encrypted`, excluded from mass assignment, never sent to the frontend. `api_access_key` is deliberately public (needed by the client side SDK) and is never treated as a secret.
- The webhook route stays CSRF-excluded and rate limited, matching every other provider.

**Configuration required**:

None. Every Clover credential (merchant ID, public access key, private token, webhook secret, sandbox or production) stays per-account in `clover_accounts`, the same pattern as every other provider. No new `.env` variable.

**Critical test scenarios**:
- Happy path: admin creates an active, USD, sandbox Clover account with a valid `api_access_key`; an agent creates a payment link; a client opens it, submits a valid test card via the embedded form, sees a brief processing state, a signed `APPROVED` webhook arrives, the payment becomes `completed`, the client is redirected to Success. Verifies **AC-1, AC-3, AC-4, AC-5, AC-6, AC-7, AC-9**.
- Decline then retry: a client's first card is synchronously declined; they see an inline error immediately and retry with a different card on the same page without a new link; the second attempt is approved; the payment ends `completed`. Verifies **AC-5, AC-8, AC-9**.
- Idempotent webhook redelivery: the same `APPROVED` event for one charge id is delivered twice; the payment is marked `completed` once, no duplicate side effects. Verifies **AC-7**.
- Terminal guard: a payment is already `completed` from an earlier approved charge; a late `DECLINED` webhook for a different, earlier, abandoned attempt on the same payment arrives; the payment stays `completed`. Verifies **AC-7, AC-8**.
- Rate limit: the charge endpoint is hit 11 times for one payment within an hour; the 11th is rejected with 429 and never reaches Clover's API. Verifies **AC-11**.
- Lost synchronous response: the charge call to Clover times out on PayHub's side (simulated) before any attempt row is written; a signed `APPROVED` webhook for that charge still arrives and resolves the payment via `Payment.uuid`, moving it to `completed` with no attempt row ever having existed. Verifies **AC-7, AC-8**.
- Unrecognized charge status: a verified webhook reports a status other than `APPROVED`/`DECLINED`; the payment's status is unchanged and the event is logged. Verifies **AC-7, AC-8**.
- Auth/permission: a non-admin agent hitting any `clover-accounts.*` route receives a 403. Verifies **AC-1**.
- Currency lock: creating a GBP payment against a Clover account is rejected before any Clover API call is made. Verifies **AC-2**.
- Webhook signature: a tampered or missing `Clover-Signature` header is rejected and the payment's status is unchanged. Verifies **AC-6**.

## Build plan

No `AGENTS.md`/scope header declares a build approach for this project (it runs its own GSD phase workflow in `.planning/`, not `docs/scope/`, same as spec 0001); this plan defaults to end to end (Tracer Bullet) slices: get one thin path (account → embedded form → charge → webhook → completed) working first, then extend it across every parity surface.

Because `feat/clover-hosted-iframe-integration` is unmerged and nothing from spec 0001 has shipped past it, the migrations below are edited in place rather than layered with new ones (see Consequences).

1. Modify the existing migrations in place: add `api_access_key` to `clover_accounts`; drop `clover_checkout_session_id`/`clover_checkout_expires_at`/`clover_checkout_url` from `payments`; create `clover_charge_attempts`. Satisfies **AC-1, AC-5, AC-11**.
2. Update `CloverAccount` (fillable/casts for `api_access_key`), add a `CloverChargeAttempt` model, relationship, and factory. Satisfies **AC-1, AC-11**.
3. Rewrite `App\Services\Clover\CloverClient`: replace `createCheckoutSession()`/session-based `getCharge()` with `createCharge(token, amount, currency, externalReferenceId)` (calls `/v1/charges`) and `getCharge(chargeId)` (calls `/v1/charges/{chargeId}`, for reconciliation); keep `verifyCredentials()`. Satisfies **AC-5**.
4. Update `StoreCloverAccountRequest`/`UpdateCloverAccountRequest`, `Admin\CloverAccountController`, and the admin Vue pages (`Index`/`Create`/`Edit`) to add the `api_access_key` field. Satisfies **AC-1**.
5. Build the embedded card form: a Vue component that loads Clover's Hosted Iframe SDK (script URL depends on the account's `environment`), renders the card fields, and calls `clover.createToken()` on submit. Satisfies **AC-4**.
6. Build the charge-submission endpoint and its rate limiting (10/payment/hour): accepts the token, calls `CloverClient::createCharge()` with `Payment.amount`/`currency` (server side) and `Payment.uuid` as the external reference, writes the `clover_charge_attempts` row only on a clean synchronous response (approved/declined/unknown-status), returns `{outcome, message}` to the client without touching `Payment.status`; a transport failure on the Clover call logs no row and returns `unknown`. Satisfies **AC-5, AC-11**.
7. Rewrite `showClover()` in `ClientPaymentController` to render the embedded-form page instead of redirecting; remove the session-reuse logic and the `clover/return/{payment}` route entirely. Satisfies **AC-4, AC-9**.
8. Build the status-poll endpoint (JSON) and the processing state (Inertia polling): redirect to Success once `completed`, back to the pay page with a flash message once `failed`. Satisfies **AC-9**.
9. Rewrite `CloverWebhookController`/`HandleCloverWebhookJob`: resolve the payment via the webhook payload's external reference field matched against `Payment.uuid` (never via a `clover_charge_attempts` lookup, so a missing attempt row never blocks the update); implement the `pending → failed → completed` guard exactly like `HandleStripeWebhookJob`/`HandleSquareWebhookJob`; treat any status other than `APPROVED`/`DECLINED` as a logged no-op; recompute the idempotency key as `"{payment_id}:{clover_charge_id}"` using the charge id from the webhook payload itself. Satisfies **AC-7, AC-8**.
10. Update `ReconcileCloverPayments` to look up by charge id via the new `CloverClient::getCharge()`, scoped to attempts stuck without a resolving webhook past a grace period. Satisfies **AC-7, AC-8**.
11. Confirm the existing dashboard/CSV export/agent-locked-account parity surfaces still work unchanged (they key off `clover_account_id`/`clover_payment_id`, both kept); add nothing new unless a surface directly referenced a removed session column. Satisfies **AC-10**.
12. Update `CloverAccountFactory` (add `api_access_key`) and Pest coverage for every Critical test scenario above. Satisfies **AC-1 through AC-11**.

## Consequences

**Positive**:
- Clover's checkout now feels like Stripe Elements/Square's SDK: no redirect, one consistent on-page pattern for 4 of PayHub's 5 providers.
- Lighter PCI scope (SAQ A-EP) than the rejected Direct API option, matching the posture PayHub already carries for Stripe and Square.
- A declined card no longer dead-ends the payment link; the client can retry immediately, consistent with how Stripe and Square already behave.

**Negative / tradeoffs**:
- PayHub's own server now accepts a card token and triggers a real charge directly, a materially different risk surface than every redirect based provider (Viva, and the Hosted Checkout Clover this replaces); it needs its own rate limiting and careful separation between "what the sync response says" and "what actually gets written."
- The `AC-4` session-reuse machinery spec 0001 built (session id, expiry tracking, the 15-minute TTL handling) becomes dead code once this ships; it is removed rather than kept as an unused alternate path.
- Still no live Clover merchant validating any of this in production; the same go-live gate 0001 flagged still applies.

**Neutral**:
- `clover_accounts.private_token` must be regenerated against Clover's Hosted Iframe integration type; the token already stored for Hosted Checkout cannot be reused.
- Migrations are edited in place rather than layered, since nothing from spec 0001 has shipped past this unmerged branch.

## Follow-up

- [ ] Live sandbox pass before go-live: confirm the exact Hosted Iframe SDK script URL per environment, the exact `/v1/charges` request/response field names, the exact name of the field Clover uses for an external reference/order id at charge creation (the spec's webhook matching design in AC-7/AC-8 depends on Clover echoing this field back on the webhook payload — confirm it does before relying on it), and the full charge status enum beyond `APPROVED`/`DECLINED`.
- [ ] The 10 attempts/payment/hour rate limit is a reasonable starting default, not a business-reviewed figure; revisit if real usage shows it's too tight or too loose.
- [ ] Decide whether spec 0001's already-built Hosted Checkout code (session reuse, the `clover/return` route, `ReconcileCloverPayments`' session-based lookup) is deleted outright on this branch or kept isolated somewhere; the Build plan above assumes outright removal.
- [ ] Refunds and voids remain out of scope for Clover, unchanged from spec 0001.
