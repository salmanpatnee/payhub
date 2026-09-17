# 0001. Add Clover as a fifth payment provider

**Date**: 2026-09-16
**Status**: In Progress

## Summary

This spec designs adding Clover as a fifth payment provider in PayHub, alongside Stripe, Revolut, Square, and Viva. Clover only supports USD (no GBP), so it is added as a USD only option, not a general replacement for any existing provider. It follows the same redirect based pattern already built for Viva: PayHub creates a short lived Clover checkout session, sends the client to Clover's own payment page, and only a signed webhook from Clover (never the browser redirect) is trusted to mark a payment as paid.

_Context, Options considered, and Rationale moved to `rationale.md` when this spec was promoted to a directory (see `/develop`'s verify.md save step)._

## Requirements

**User stories**:
- As an admin, I want to create and manage a Clover account (credentials, USD lock, sandbox or production) the same way I already manage Stripe, Revolut, Square, and Viva accounts.
- As an agent, I want to create a payment link against a Clover account the same way I create one for any other provider, with the currency restricted to what that account accepts.
- As a client, I want to open a Clover payment link and be taken to a real payment page that still works even if I open the link after the checkout session has gone stale.
- As the business, I want a Clover payment's paid status to only ever come from Clover's own signed confirmation, never from what the browser reports after redirect, so a manipulated redirect can never mark a payment paid.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: An admin can create, edit, activate, and deactivate a Clover account (account name, prefix, merchant ID, private token, webhook secret, sandbox or production environment), the same actions already available for Viva accounts, restricted to `role:admin`.
- **AC-2**: Creating or updating a payment against a Clover account whose currency does not match the account's locked currency is rejected with a clear error ("This Clover account only accepts USD payments."), the same way a Square currency mismatch is rejected today.
- **AC-3**: An agent or admin can create a payment link against any active Clover account, exactly like the other four providers; the provider and account dropdowns, and every provider agnostic validation rule, include Clover.
- **AC-4**: When a client opens a Clover payment link, PayHub reuses the payment's current Clover Hosted Checkout session if Clover's `expirationTime` for it has not yet passed; only once it has does PayHub create a fresh session. At most one Clover checkout session is ever live for a given payment at a time, and the client is always redirected to a working session regardless of how many times the link is opened.
- **AC-5**: The Clover webhook endpoint verifies the `Clover-Signature` HMAC-SHA256 header against the account's stored webhook secret before doing anything else; a request that fails verification is rejected and never changes a payment's status.
- **AC-6**: A verified `PAYMENT` webhook event with `status: APPROVED` marks the matching payment paid exactly once, even if Clover redelivers the same event more than once. Once a payment is paid or failed, that status is terminal: any later webhook for the same payment (e.g. a delayed decline from an earlier, abandoned session) is logged and ignored, never applied.
- **AC-7**: A verified `PAYMENT` webhook event with `status: DECLINED` marks the matching payment failed (subject to the same terminal-status guard as AC-6) and records Clover's payment id for that attempt; the amount recorded is always the server side `Payment.amount`, never anything read from the webhook payload or the client.
- **AC-8**: After Clover redirects the browser back to PayHub, the landing route decides which page to show purely from the payment's current status in the database, never from Clover's redirect query string: `paid` shows the Success page, `failed` shows the Failed page, and `pending` or `expired` shows the Unavailable page.
- **AC-9**: Clover appears everywhere the other four providers already do: the payment create/edit forms, agent locked account assignment, CSV export, the dashboard's account filters and "Accounts Today" figures, and the client facing pay and receipt pages. No such surface is left Stripe/Revolut/Square/Viva only after this ships.
- **AC-10**: `clover_accounts.private_token` and `clover_accounts.webhook_secret` are encrypted at rest and not mass-assignable; the webhook route is excluded from CSRF and rate limited; the Clover checkout URL is never logged or exposed beyond the single redirect response that uses it.

## Decision

**Chosen option**: Option 1: Clover Hosted Checkout, redirect flow, USD only fifth provider

Add Clover as a USD only fifth `PaymentProvider`, using Clover's Hosted Checkout redirect flow and a per-account `CloverClient`, mirroring the Viva integration's shape wherever Clover's own rules do not force a difference.

## Feature design

**Data model sketch**:

- `clover_accounts` (new table)
  - `id` primary key
  - `account_name` string
  - `prefix` string(10), nullable — reference code prefix, same role as every other account's `prefix`
  - `merchant_id` string — Clover merchant ID, not secret
  - `private_token` text, cast `encrypted`, NOT mass-assignable
  - `webhook_secret` text, cast `encrypted`, NOT mass-assignable
  - `currency` string, locked to `usd`
  - `environment` enum(`sandbox`, `production`), default `sandbox`
  - `is_active` boolean, default `true`
  - timestamps
  - relationship: `hasMany(Payment::class)`
- `payments` (new columns, `provider` enum widened to include `clover`)
  - `clover_account_id` — FK to `clover_accounts`, cascade on delete, nullable
  - `clover_checkout_session_id` — string, indexed, nullable; set when a session is created, only replaced once `clover_checkout_expires_at` has passed (AC-4)
  - `clover_checkout_expires_at` — datetime, nullable; copied verbatim from Clover's `expirationTime` response field when the session is created, the single source of truth for whether the stored session can still be reused
  - `clover_payment_id` — string, indexed, nullable; set by the webhook handler on both approval and decline (Clover assigns an id to a declined attempt too, so a decline is still traceable)
- `processed_clover_events` (new table, idempotency)
  - `event_key` string primary key, not auto-incrementing, `"{payment_id}:{checkout_session_id}"`
  - `processed_at` timestamp
  - no `created_at`/`updated_at`
- `users` (new column)
  - `clover_account_id` — FK to `clover_accounts`, null on delete, nullable; agent locked account, mirrors the other four provider FKs already on `users`

**State transitions**:

No new state machine. A Clover payment moves through PayHub's existing generic payment states (pending, paid, failed, expired); only the trigger differs: a verified `PAYMENT`/`APPROVED` webhook moves it to paid, `PAYMENT`/`DECLINED` moves it to failed, exactly like the other providers' webhooks already do.

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `clover-accounts` (resource, except `show`) | GET/POST/PUT/DELETE | `account_name`, `prefix`, `merchant_id`, `private_token`, `webhook_secret`, `environment` | account rows (secrets never returned, only `has_private_token`/`has_webhook_secret` flags) | `role:admin` | 422 validation |
| `clover-accounts/{account}/deactivate` | PATCH | — | `is_active = false` | `role:admin` | 404 |
| `clover-accounts/{account}/activate` | PATCH | — | `is_active = true` | `role:admin` | 404 |
| `clover-accounts/test-connection` | POST | raw `merchant_id`, `private_token` | connectivity ok/fail | `role:admin` | 422 |
| `clover-accounts/{account}/test-connection` | POST | — | connectivity ok/fail, using stored credentials | `role:admin` | 404 |
| `pay/{payment}` (existing route, Clover branch added) | GET | payment UUID | redirect to the current (reused or freshly created) Clover checkout URL | public (unguessable UUID) | 404 unknown payment, 410 already paid |
| `clover/return/{payment}` (new) | GET | payment UUID | redirect to Success (`paid`), Failed (`failed`), or Unavailable (`pending`/`expired`), chosen from the payment's DB status | public | 404 unknown payment |
| `webhook/clover/{cloverAccount}` (new) | POST | raw Clover `PAYMENT` event body, `Clover-Signature` header | 200 ack | HMAC signed, no session | 401 bad signature |

**Value sourcing**:

| Action | Value produced / displayed | Source |
|---|---|---|
| Pay page visit | reuse or recreate the session | `Payment.clover_checkout_expires_at` compared to the current time; reuse `clover_checkout_session_id` while unexpired, else call `CloverClient::createCheckoutSession()` again (AC-4) |
| Create checkout session | amount charged | `Payment.amount` (server side column, never the client or the request) |
| Create checkout session | currency | `Payment.currency`, already validated against `CloverAccount.currency` at payment creation time (AC-2) |
| Create checkout session | reference shown to the client | `Payment.formattedReferenceCode()` / `service` / `package` (existing `Payment` fields, unchanged) |
| Webhook handler | which payment to update | `clover_checkout_session_id` stored on the `Payment` row, matched against the checkout session id field in the verified webhook payload (the exact JSON field name is not confirmed from Clover's docs as retrieved; confirm it during the sandbox pass in Follow-up, alongside the payment id field used to populate `clover_payment_id`) |
| Webhook handler | idempotency key | computed server side as `"{payment_id}:{checkout_session_id}"`, not read verbatim from the payload |
| Webhook handler | whether to apply the transition at all | `Payment.status`, read immediately before applying: a payment already `paid` or `failed` is terminal and the incoming event is logged and ignored (AC-6, AC-7) |
| Return route | which page to show | `Payment.status` in the database (already set by the webhook in the normal case), never the redirect query string; mapped `paid`→Success, `failed`→Failed, `pending`/`expired`→Unavailable (AC-8) |

**Key invariants**:
- A `CloverAccount`'s `currency` is always `usd`; there is no path that creates one with any other value.
- Every `payments` row with `provider = 'clover'` has `clover_account_id` set and `stripe_account_id`/`revolut_account_id`/`square_account_id`/`viva_account_id` all null (the existing null-out rule in `StorePaymentRequest`/`UpdatePaymentRequest` gets a Clover arm).
- At most one live (unexpired) Clover checkout session exists per payment at any time: a new one is only created once `clover_checkout_expires_at` has passed, never on a routine revisit. This is what closes the matching gap described in Context: because a session is never replaced while still valid, a webhook referencing an old session id can only arrive after that session has genuinely expired on Clover's side too, at which point Clover itself would not have accepted a payment against it.
- `clover_payment_id` is written only by the verified webhook handler; no other code path (the return route, the pay page, any client input) ever sets it.
- Payment status transitions triggered by a Clover webhook are terminal: once `paid` or `failed`, no later Clover webhook for that payment changes its status again (see Value sourcing and AC-6/AC-7). This is what stops a delayed decline for an earlier, already superseded session from overwriting a genuine approval.
- `processed_clover_events.event_key` is unique by being the primary key; a given payment and checkout session pair is applied at most once.

**Security model**:
- Account management (`clover-accounts.*`) is `role:admin` only, identical to every other provider's account CRUD; agents cannot create, edit, or view Clover credentials.
- The pay, return, and webhook routes are public but scoped by an unguessable payment UUID (pay/return) or a verified HMAC signature (webhook); none of the three trust a value the client can freely set.
- `private_token` and `webhook_secret` are `encrypted` cast columns, excluded from mass assignment, and never sent to the frontend (only a boolean "is it set" flag, matching the Viva account edit page's pattern).
- The webhook route is excluded from CSRF (it is an external POST) and rate limited, matching the other three providers' webhook routes.
- This feature touches payment data; no new PII is introduced beyond what the other four providers already handle the same way.

**Configuration required**:

None. Unlike a global API key setup, every Clover credential (merchant ID, private token, webhook secret, sandbox or production) is stored per account in `clover_accounts`, the same pattern already used for Stripe, Revolut, Square, and Viva. No new `.env` variable is needed.

**Critical test scenarios**:
- Happy path: admin creates an active, USD, sandbox Clover account; an agent creates a $50 payment link against it; a client opens the link, is redirected to Clover's checkout, pays; a signed `PAYMENT`/`APPROVED` webhook arrives; the payment is marked paid; the client's return visit lands on the Success page. Verifies **AC-1, AC-3, AC-4, AC-5, AC-6, AC-8**.
- Failure case: a webhook POST with a tampered or missing `Clover-Signature` header is rejected and the payment stays pending. Verifies **AC-5**.
- Failure case: the same `PAYMENT`/`APPROVED` event is delivered twice; the payment is marked paid once, not twice, and no duplicate side effects occur. Verifies **AC-6**.
- Idempotency/race case: a client visits the pay link twice while the first session is still within `clover_checkout_expires_at`; both visits redirect to the same `clover_checkout_session_id`, and a single `APPROVED` webhook for it marks the payment paid. Verifies **AC-4, AC-6**.
- Terminal state case: a payment is already `paid` from an `APPROVED` webhook; a later `DECLINED` webhook (from an earlier, now expired session) arrives; the payment stays `paid`, and the late event is logged, not applied. Verifies **AC-6, AC-7**.
- Auth/permission: a non-admin agent hitting any `clover-accounts.*` route receives a 403. Verifies **AC-1**.
- Edge case: creating a GBP payment against a Clover account is rejected at validation with the Clover specific error message, before any Clover API call is made. Verifies **AC-2**.

## Build plan

No `AGENTS.md`/scope header declares a build approach for this project (the project runs its own GSD phase workflow in `.planning/`, not `docs/scope/`); this plan defaults to end to end (Tracer Bullet) slices, standard for production work: get one thin path from account to paid webhook working first, then extend it across every parity surface.

1. [x] Migration: create `clover_accounts`, widen `payments.provider` to include `clover`, add `clover_account_id`/`clover_checkout_session_id`/`clover_checkout_expires_at`/`clover_payment_id` to `payments`, create `processed_clover_events`, add `clover_account_id` to `users`. Satisfies **AC-1, AC-2, AC-6, AC-9**.
2. [x] Add `PaymentProvider::Clover` and its `label()` arm; add the `clover` arm to `ProviderAccountTable::for()`, `CurrencySupportResolver::supports()`, and `Payment`'s `formattedReferenceCode()`/`providerAccountName()` matches (these are exhaustive with no `default`, so a missing arm throws rather than silently misbehaving). Satisfies **AC-2, AC-9**.
3. [x] Build `App\Services\Clover\CloverClient` (per-account, instantiated via `app()->make()`, never a shared client): `createCheckoutSession()` (returns the session id, URL, and `expirationTime`), `getCharge()` (reconciliation backstop, wired in step 9a below), `verifyCredentials()`. Satisfies **AC-1, AC-4**.
4. [x] Build `Admin\CloverAccountController` plus `Store`/`UpdateCloverAccountRequest`, and the `clover-accounts` routes (resource except `show`, `activate`, `deactivate`, `test-connection`), mirroring `VivaAccountController`. Satisfies **AC-1, AC-10**.
5. [x] Build the admin Vue pages `resources/js/pages/admin/clover-accounts/{Index,Create,Edit}.vue`, mirroring the `viva-accounts` pages. Satisfies **AC-1**.
6. [x] Wire Clover into `StorePaymentRequest`/`UpdatePaymentRequest` (`provider` validation list, the account column null-out arm, the currency lock failure message) and into `PaymentController` (`formOptions()`, `clearStaleProviderTransactionIds()`, the `edit()` account id fallback chain). Satisfies **AC-2, AC-3, AC-9**.
7. [x] Wire Clover into the payments `Create.vue`/`Edit.vue` provider and account dropdowns, and into agent locked account assignment (`StoreUserRequest`/`UpdateUserRequest`, `Admin\UserController`, `admin/users/{Create,Edit}.vue`). Satisfies **AC-3, AC-9**.
8. [x] Build the pay page flow: a `showClover()` path in `ClientPaymentController` that reuses `clover_checkout_session_id` while `clover_checkout_expires_at` is still in the future, otherwise calls `createCheckoutSession()` and stores the new session id and expiry, then redirects; a `PayClover` handoff, mirroring `PayViva.vue`'s redirect only shape. Satisfies **AC-4**.
9. [x] Build the webhook: the `webhook/clover/{cloverAccount}` route (CSRF excluded, throttled), a `CloverWebhookController` verifying `Clover-Signature` (HMAC-SHA256 over `{timestamp}.{raw_body}`, per the research doc — confirm the exact header/timestamp placement against Clover's own docs before this ships, per Follow-up), trusting the verified payload directly (no re-fetch, per the confirmed webhook trust decision), checking the matched payment's current status is not already terminal (`paid`/`failed`) before applying a transition, recording a `ProcessedCloverEvent`, and marking the payment paid or failed. Satisfies **AC-5, AC-6, AC-7, AC-10**.
9a. [x] Build a scheduled reconciliation command (Laravel's scheduler, matching the project's existing queue/schedule conventions) that calls `CloverClient::getCharge()` for any Clover payment still `pending` a set grace period after its `clover_checkout_expires_at`, as a backstop for the rare case a webhook never arrives; it never overrides a payment already `paid`/`failed` by webhook. Satisfies **AC-6**.
10. [x] Build the `clover/return/{payment}` route: reads the payment's current DB status only and maps it to Success/Failed/Unavailable per the table in AC-8. Satisfies **AC-8**.
11. [x] Wire Clover into `DashboardMetrics` (Accounts Today, account filters, account name lookups) and confirm `PaymentsExport` picks it up through the `Payment` model helpers already updated in step 2 (add an explicit column if it does not). Satisfies **AC-9**.
12. [x] Add Clover to any provider aware branding or messaging in `PaymentLayout.vue` and the client facing Success/Failed/Unavailable pages, if such branching exists for the other providers. Satisfies **AC-9**.
13. [x] Add a `CloverAccountFactory` (mirroring `VivaAccountFactory`) and Pest coverage for the critical test scenarios above: currency lock rejection, webhook signature verification, webhook idempotency, the session reuse/terminal-state race scenarios, admin only authorization, and the end to end happy path. Satisfies **AC-1 through AC-10**.

## Consequences

**Positive**:
- PayHub can onboard a US or Canada merchant already using Clover's point of sale without asking them to also set up Stripe, Revolut, or Square.
- The currency lock pattern (already proven for Square) gets reused rather than reinvented, keeping the "one account, one currency" rule consistent across providers.
- Building on the Viva pattern means the redirect-and-webhook shape, and its idempotency handling, is proven in production before Clover reuses it.

**Negative / tradeoffs**:
- A fifth provider means a fifth arm in every exhaustive match and every parity surface listed in AC-9; each one is a place a future change can silently forget Clover, the same way Square's "Accounts Today" resolution was missed on first pass.
- The 15 minute Hosted Checkout session still needs an API call to Clover whenever it has genuinely expired, an added latency and API dependency the other providers with longer lived checkout sessions don't have; session reuse (AC-4) keeps this to roughly once per 15 minutes of dawdling rather than once per page load.
- Clover's docs (as retrieved for the research this spec is based on) do not document a session cancellation endpoint, so PayHub cannot proactively cancel a session on Clover's side when it expires; this is accepted because sessions are reused rather than replaced until genuinely expired (see Key invariants), so there is no routinely-orphaned live session to cancel.
- This ships with no real Clover merchant using it, so its first real test is whenever one signs up; bugs specific to Clover's actual sandbox or production behavior (its post-payment redirect shape, its full charge status enum, its webhook retry timing) won't surface until then.

**Neutral**:
- No shared `PaymentProviderInterface` is introduced; this spec keeps the existing per-provider standalone client pattern, deliberately, since Clover is only the second redirect based provider after Viva.
- No new `.env` variables; every credential is per-account in the database, consistent with every other provider.

## Follow-up

- [ ] Before enabling any Clover account in production, run a real Clover sandbox pass covering what the research could not confirm from docs alone: the exact shape of Clover's post-payment redirect (this spec's design does not depend on it, but it is still worth knowing), the full `GET /v1/charges` status enum, Clover's webhook retry/redelivery timing, the exact `Clover-Signature` construction (which header carries the timestamp, and the replay tolerance window), and the exact JSON field names in the webhook payload for the checkout session id and payment id.
- [ ] This project has never used `docs/specs/`/`docs/scope/` before; it runs its own GSD phase workflow in `.planning/`. Decide whether Clover becomes a tracked phase there too, or whether `docs/specs/` now runs alongside `.planning/` for future architecture decisions, and record that choice in the project's context docs.
- [ ] Refunds and voids are out of scope for Clover, matching every other provider (no refund UI exists anywhere in PayHub today). If refund support is ever prioritized, design it once, across all five providers, rather than adding it ad hoc for whichever provider asks first.
