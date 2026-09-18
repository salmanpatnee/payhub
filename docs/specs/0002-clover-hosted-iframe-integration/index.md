# 0002. Switch Clover to a Hosted Iframe integration

**Date**: 2026-09-17
**Status**: Accepted

## Summary

This spec redesigns Clover from a redirect based checkout (spec 0001, already built) to an embedded card form living directly on PayHub's own pay page. The client types their card into a Clover provided iframe, never leaving PayHub, and never sending raw card details to PayHub's own server. PayHub's server then charges the resulting token and reads the outcome straight back from Clover's own response to that charge call — Clover documents no webhook for this integration type (only for a different, redirect based Clover product), so unlike every other provider in PayHub, this one is confirmed by a direct server to server response rather than an asynchronous webhook. A durable local record of every attempt, written before Clover is ever called, and a background resolver job give a lost or ambiguous response a real recovery path, and a per-payment lock stops the same card from being charged twice by a double click. This supersedes spec 0001; the redirect based approach it built is replaced, not kept alongside this one.

## Context

See `rationale.md` for the full problem context, the premise challenge, the options weighed, the 2026-09-17 correction to the status confirmation mechanism, and the 2026-09-17 cross check that reshaped the reconciliation design below.

## Requirements

**User stories**:
- As an admin, I want to create and manage a Clover account (now including a public access key alongside its existing credentials) the same way I manage every other provider account.
- As an agent, I want to create a payment link against a Clover account exactly like any other provider.
- As a client, I want to pay a Clover link by entering my card directly on PayHub's own page, and if my card is declined, try another card immediately without needing a new link, and I want a double click or a slow connection to never charge my card twice.
- As the business, I want a Clover payment's paid status to only ever be set from Clover's own response to PayHub's own charge request, protected against being set twice for the same charge, against a client controlled value influencing it, and against firing a second real charge while one is already in flight.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: An admin can create, edit, activate, and deactivate a Clover account (account name, prefix, merchant ID, public access key, private token, sandbox or production), restricted to `role:admin`. This replaces spec 0001's AC-1 with one new field (`api_access_key`) and drops the webhook signing secret field — this integration type has no webhook to verify.
- **AC-2**: Creating or updating a payment against a Clover account whose currency does not match the account's locked currency (always USD) is rejected with the existing Clover currency error message. Unchanged from spec 0001's AC-2.
- **AC-3**: An agent or admin can create a payment link against any active Clover account exactly like the other four providers; every provider aware dropdown and validation rule includes Clover. Unchanged from spec 0001's AC-3.
- **AC-4**: When a client opens a Clover payment link, PayHub renders the card form directly on the pay page using Clover's Hosted Iframe SDK, initialized with the account's `merchant_id` and public `api_access_key`. No redirect happens, and no raw card data is ever submitted to PayHub's own server, only the token Clover's SDK produces.
- **AC-5**: Submitting the card form calls a rate limited (10 attempts per payment per hour) PayHub endpoint. Before doing anything else, it rejects the request (422, no Clover call made) unless: the payment's provider is Clover, its status is `pending` or `failed`, its Clover account exists and is active, and no other attempt for this payment is currently in flight (see the concurrency lock in Key invariants — a second submit while one is in flight gets 409, not a second charge). Once accepted, it generates an idempotency key, writes a `clover_charge_attempts` row (status `pending`) holding it *before* calling Clover, then sends the token, `Payment.amount`, `Payment.currency`, and that same key (as both Clover's idempotency key and its `external_reference_id`) to `/v1/charges`. The response is classified into exactly one of four buckets (see Feature design's **Charge response classification**): **approved**, **declined**, **hard error** (bad credentials, malformed request — logged, the attempt marked failed-to-process, no retry, payment untouched), or **unknown** (a timeout, network error, 5xx, or any response PayHub cannot classify — resolved later, see AC-8). Approved and declined resolve immediately in this same request (AC-7, AC-8); unknown and hard error do not touch `Payment.status`.
- **AC-6**: A given Clover charge (`clover_charge_id`) can move a payment to `completed` at most once, enforced by an atomic, status guarded update — `whereIn('status', ['pending', 'failed'])` for the completion write, exactly matching `HandleSquareWebhookJob`'s and `HandleStripeWebhookJob`'s existing guard (a `failed` payment must still be completable by a later approved attempt, see AC-8) — and `where('status', 'pending')` for the decline write (never overwrites a `completed` payment). Even though Clover has no webhook here, the same idempotency discipline applies to the synchronous write path and to the resolver job.
- **AC-7**: A charge attempt classified **approved** — `paid === true` AND `captured === true` AND the charge's amount and currency match `Payment.amount`/`Payment.currency` exactly (anything less, including `paid` true but `captured` false, is **unknown**, never approved) — moves its payment to `completed`, sets `paid_at` to the moment of that write, and records the charge id on the payment, all in the same guarded update as AC-6. The completion notification (`SendPaymentNotification`) is dispatched once, only when that guarded update actually affected a row (mirroring `HandleSquareWebhookJob`'s `$updated > 0` gate) — from whichever path performed the write, the synchronous handler or the resolver job, never both. Once `completed`, no later Clover response for that payment changes its status again.
- **AC-8**: A charge attempt classified **declined** moves its payment to `failed` (guarded as in AC-6), but only while it is still `pending`. `failed` is not terminal: a later approved attempt on the same payment can still move it to `completed`, matching how Stripe and Square already behave in PayHub. An attempt classified **unknown** is resolved by a queued resolver job (`ResolveCloverChargeAttempt`, dispatched immediately with a short delay, Laravel's own `$tries`/backoff retrying it): once a `clover_charge_id` is known for the attempt, it looks the charge up directly (`GET /v1/charges/{id}`); if no charge id was ever captured (Clover's response never arrived at all), it reads — never re-posts — by searching Clover for a charge carrying the attempt's `external_reference_id`. After the job's retries are exhausted without a resolution, the attempt is marked `abandoned` and logged for operator attention; the payment stays `pending`. A thin scheduled sweep (every 10 minutes) exists only as a backstop for an attempt orphaned by a dead queue worker, applying the same resolution logic. Any charge status this can't map to approved/declined is logged and left alone, never applied, so a status PayHub does not recognize can never throw or leave the payment in an undefined state.
- **AC-9**: After a charge attempt classified **unknown**, the client sees a processing state that polls the payment's real status (every 3 seconds, for up to 2 minutes) and moves to the Success page once it reads `completed`, back to the pay page with a message once it reads `failed`, to the existing Unavailable page if it reads `cancelled`, or — past the 2 minute mark — a terminal "still confirming, you'll receive a receipt by email" state rather than an indefinite spinner. After a **declined** response, the client sees an inline decline message (a fixed PayHub string keyed off the outcome, never Clover's raw processor text) immediately and can retry with a different card on the same page without any redirect or new link. After an **approved** response, the client is sent straight to the Success page — the same request that approved the charge already completed the payment, there is nothing left to wait for.
- **AC-10**: Clover appears everywhere the other four providers already do: payment create/edit forms, agent locked account assignment, CSV export, the dashboard's account filters and "Accounts Today" figures, and client facing receipt pages. No such surface is left behind. Unchanged from spec 0001's AC-9.
- **AC-11**: `clover_accounts.private_token` stays encrypted at rest and non mass-assignable; `api_access_key` is stored as a plain, non-encrypted public identifier (safe to send to the frontend, like Square's `application_id`). The charge submission endpoint is rate limited per payment via a named rate limiter keyed on the payment's UUID (not the default IP keyed throttle every other route in this app uses), returning a JSON 429 body the Vue component can render. The charge amount and currency are always read from the `Payment` record server side, never from the client. There is no webhook route for Clover in this spec — nothing needs CSRF exclusion or signature verification for this provider.

## Decision

**Chosen option**: Option 1: Clover Hosted Iframe, embedded tokenize and charge, with a synchronous response as the authoritative status source

Replace Clover's Hosted Checkout redirect flow (spec 0001) with an embedded card form on PayHub's own pay page: Clover's Hosted Iframe SDK tokenizes the card client side, and PayHub's server charges the token via Clover's Ecommerce `/v1/charges` API. Because Clover documents no webhook for this integration type (see `rationale.md`'s 2026-09-17 correction), PayHub treats the synchronous response to that server to server charge call as authoritative — a scoped, explicit exception to "status comes from webhooks only," justified because this is a channel the browser never touches, unlike the client side confirmations that rule exists to guard against. A `clover_charge_attempts` row is written before Clover is ever called, so a lost or ambiguous response still has a durable local record; a queued resolver job (not a token replay — see `rationale.md`'s 2026-09-17 cross check) resolves it by reading Clover's own record of the charge, never by re-submitting it.

## Rationale

See `rationale.md` for the full comparison against Option 2 (Clover's raw Ecommerce API) and Option 3 (keep Hosted Checkout), the 2026-09-17 correction's comparison of status confirmation mechanisms, and the 2026-09-17 cross check that found the original reconciliation design unbuildable and reshaped it into the read based, queued job design above.

## Feature design

**Data model sketch**:

- `clover_accounts` (existing table, modified in place — this branch is unmerged, nothing has shipped past it)
  - Adds: `api_access_key` string, plain (not encrypted) — public identifier the client side SDK needs; safe in logs and in the frontend, unlike `private_token`
  - `private_token` — kept, still `encrypted`, NOT mass-assignable; must be regenerated for Clover's Hosted Iframe integration type (the Hosted Checkout token cannot be reused, per Clover's one-token-per-integration-type rule). Used as the Bearer token for both `/v3/merchants` (credential verification) and `/v1/charges` (charging), which live on different hosts — see Configuration required.
  - **Removed**: `webhook_secret` — this integration type has no webhook to verify a signature against; keeping an unused secret column would misleadingly imply one exists.
  - Everything else (`account_name`, `prefix`, `merchant_id`, `currency` locked `usd`, `environment`, `is_active`, timestamps) unchanged
- `payments` (existing table, already modified in place by the first version of this spec)
  - Unchanged: `clover_account_id`, `clover_payment_id` (set exactly once, by whichever path — the synchronous handler or the resolver job — first completes the payment, holding that attempt's charge id), `paid_at` (existing generic column, set at the same moment)
- `clover_charge_attempts` (new table — the durable local record of every attempt, written *before* the Clover API call so a lost or ambiguous response still has something to resolve against)
  - `id`
  - `payment_id` — FK to `payments`, cascade on delete
  - `idempotency_key` string, unique, indexed — generated server side, written before the Clover call, sent as both Clover's idempotency key and its `external_reference_id` on `/v1/charges`. The resolver job searches by this value; it is never used to re-submit a charge (see the 2026-09-17 cross check in `rationale.md` for why a replay is unsafe — Clover tokens are single-use and PayHub never stores raw card data to retry with)
  - `clover_charge_id` string, nullable, indexed — Clover's id for this attempt; null until a clean response (or a later resolver lookup) supplies it
  - `status` string, default `pending` (`pending` while in flight or unresolved, `approved`, `declined`, `hard_error` — a non-retryable rejection like bad credentials, logged and left alone — or `abandoned` — the resolver job exhausted its retries without resolving; a plain string, not a hard DB enum, so a status Clover adds later cannot fail the update)
  - `decline_reason` string, nullable — Clover's raw decline code/text, for operators only; never sent to the client (see Security model)
  - `attempts_count` integer, default 0 — how many times a resolution check (the resolver job's own retries, or the scheduled backstop sweep) has checked this attempt, used to decide when to give up and mark `abandoned`
  - `last_checked_at` timestamp, nullable
  - `created_at`, `updated_at` (status/charge id are updated in place as the attempt resolves)
- `processed_clover_events` — **removed**. It existed only to dedupe webhook deliveries; there is no webhook for this integration type.

**State transitions**:

Payment: `pending` → `failed` (a declined attempt) → `completed` (an approved attempt on a later try), or `pending` → `completed` directly on a first-try approval. `completed` is terminal. `failed` is not: a decline here never dead-ends the link, matching Stripe's and Square's existing behavior in this app. A payment can also sit `pending` while its latest attempt is unresolved, worked by the resolver job.

`clover_charge_attempts.status`: `pending` → `approved` | `declined` | `hard_error` | `abandoned`. `pending` is the only non-terminal state; every other value is written once and never changes.

**Charge response classification** (applied the instant PayHub's server gets a response from `/v1/charges`, or when the resolver job later reads the charge):

| Bucket | Condition | Effect |
|---|---|---|
| **Approved** | `paid === true` AND `captured === true` AND response `amount`/`currency` match `Payment.amount`/`Payment.currency` exactly | Attempt → `approved`; payment → `completed` (AC-6, AC-7) |
| **Declined** | A response (2xx or 4xx) carrying a parseable decline signal (e.g. `paid === false` with a decline/error code) | Attempt → `declined`; payment → `failed` if still `pending` (AC-6, AC-8) |
| **Hard error** | A response indicating the request itself was invalid or unauthorized (401/403 bad credentials, 400 malformed, 404 unknown merchant) | Attempt → `hard_error`, logged; payment untouched; not retried — retrying an invalid request produces the same invalid request |
| **Unknown** | A transport failure (timeout, network error, 5xx) before any classifiable response arrives, OR a response that matches none of the above (including `paid === true` with `captured === false`) | Attempt stays `pending`; payment untouched; resolved later by the resolver job (AC-8) |

`CloverClient::createCharge()` must not `->throw()` on a 4xx/5xx — it returns the status code and body so the caller can classify a real response (declined, hard error) instead of every non-2xx collapsing into "transport failed."

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `clover-accounts` (resource, except `show`) | GET/POST/PUT/DELETE | adds `api_access_key`; drops `webhook_secret` | account rows (`private_token` never returned, only a `has_private_token` flag; `api_access_key` returned as-is) | `role:admin` | 422 validation |
| `clover-accounts/{account}/activate` / `/deactivate` / `test-connection` | PATCH/POST | unchanged from spec 0001 | unchanged | `role:admin` | 404 |
| `pay/{payment}` (existing route, Clover branch rewritten) | GET | payment UUID | Inertia page rendering the embedded card form, with `merchant_id`/`api_access_key`/`environment` in props | public (unguessable UUID) | 404 unknown payment, 410 already paid |
| `payments/{payment}/clover/charge` (new) | POST | Clover token from client-side `createToken()` | JSON `{outcome: approved\|declined\|unknown, message}` — `message` is always a fixed PayHub string keyed off `outcome`, never Clover's raw text; informational only, the same handler already wrote the DB just before responding | public (UUID), rate limited to 10 attempts/payment/hour on a payment-UUID keyed limiter | 422 invalid token or payment not chargeable, 409 an attempt is already in flight for this payment, 429 too many attempts |
| `payments/{payment}/clover/status` (new) | GET | payment UUID | JSON `{status: pending\|failed\|completed\|cancelled}`, polled by the processing state only while the latest attempt is unresolved | public (UUID) | 404 unknown payment |

**Removed**: `clover/return/{payment}` (no redirect to return from — already removed by the first version of this spec) and `webhook/clover/{cloverAccount}` (no webhook for this integration type).

**Value sourcing**:

| Action | Value produced / displayed | Source |
|---|---|---|
| Pay page visit | `merchant_id`, `api_access_key`, `environment` passed to the embedded form | `CloverAccount` columns, never a request param |
| Charge submission | idempotency key / `external_reference_id` sent to Clover | generated server side, written to `clover_charge_attempts.idempotency_key` before the Clover call |
| Charge submission | amount charged | `Payment.amount` (server side column, never the client or the request) |
| Charge submission | currency | `Payment.currency`, already validated against `CloverAccount.currency` at payment creation (AC-2) |
| Charge submission | which of the 4 response buckets applies | Clover's `/v1/charges` response body (`paid`/`captured`/`amount`/`currency`/error fields — exact field names to confirm, see Follow-up) plus the HTTP status, classified server side per the table above; never a value the client sends |
| Charge submission | which payment's status to write | resolved from the route's `{payment}` parameter (an unguessable UUID already scoping the request), not from anything in the Clover response |
| Charge submission | `decline_reason` (stored) vs `message` (shown to client) | `decline_reason` = Clover's raw code/text, operators only; `message` = a fixed PayHub string keyed off `outcome`, mirroring the existing Square charge handler's sanitized strings — never Clover's text |
| Charge submission | the concurrency lock (only one attempt in flight per payment) | a DB row lock (`lockForUpdate`) or cache lock scoped to `payment_id`, held from the precondition check through the Clover call |
| Resolver job | which attempts to work | `clover_charge_attempts` rows still `pending`, dispatched on creation (immediate path) or picked up by the scheduled backstop sweep (orphaned path) |
| Resolver job | how to resolve an attempt | the stored `clover_charge_id` via `GET /v1/charges/{id}` once known, else a read only search by the stored `idempotency_key`/`external_reference_id` — never a re-POST of the charge |
| Status poll | current status shown to the client | `Payment.status` column, read only |

**Key invariants**:
- Only a clean response from Clover's own `/v1/charges` (read the instant PayHub's server gets it back) or the resolver job's read only lookup ever sets `Payment.status = completed`; the browser never writes status directly, and no code path trusts a value the client submits for this.
- `completed` is terminal: once set, nothing later changes it.
- `failed` is not terminal: a decline only ever moves `pending → failed`, never touches an already-`completed` payment, and a later approval can still move `failed → completed`.
- A given `clover_charge_id` moves a payment to `completed` at most once, enforced by `whereIn('status', ['pending', 'failed'])` for the completion write and `where('status', 'pending')` for the decline write — the same two-guard shape `HandleSquareWebhookJob`/`HandleStripeWebhookJob` already use, not a single shared guard.
- **At most one charge attempt is ever in flight per payment.** The charge endpoint claims the payment (a DB row lock or cache lock scoped to `payment_id`) before writing the attempt row or calling Clover, and releases it once the attempt resolves or is left `pending` for the resolver job to pick up; a second submit while one is in flight gets 409, never a second real charge.
- A `clover_charge_attempts` row is written, holding its idempotency key, *before* PayHub's server calls Clover — so a call that times out, errors, or returns something PayHub can't classify still leaves a durable local record the resolver job can act on.
- An **approved** classification requires `paid === true` AND `captured === true` AND the charge's amount/currency matching `Payment.amount`/`Payment.currency` exactly; anything less (including `paid` true but `captured` false, or a mismatched amount) is `unknown`, never applied to `Payment.status`.
- The resolver job never re-submits a charge; it only reads (`GET /v1/charges/{id}`, or a read only search by `external_reference_id`). A charge attempt exhausting its retries is marked `abandoned` and logged, never retried forever.
- Any Clover charge outcome PayHub does not recognize is logged as `unknown` or `hard_error` as appropriate, never applied to `Payment.status`, and never allowed to fail an insert or update.
- `private_token` stays encrypted and non mass-assignable; `api_access_key` is a public identifier and is exempt from that treatment on purpose; `webhook_secret` does not exist for this integration type.
- A `CloverAccount`'s `currency` is always `usd` (unchanged from spec 0001).

**Security model**:
- Account management (`clover-accounts.*`) stays `role:admin` only, unchanged.
- The pay, charge, and status routes are public but scoped by an unguessable payment UUID. None trusts a value the client can freely set.
- The charge-submission endpoint is rate limited to 10 attempts per payment per hour, keyed on the payment's UUID via a named rate limiter (not the default IP keyed throttle) — this is the one Clover route where PayHub's own server now accepts something originating from a card entry, unlike every redirect based route.
- Clover's raw decline text/code (`decline_reason`) is stored for operators only and never returned to the client; the client always sees a fixed PayHub string, matching the existing Square charge handler's posture of never surfacing raw processor errors.
- `private_token` remains `encrypted`, excluded from mass assignment, never sent to the frontend. `api_access_key` is deliberately public (needed by the client side SDK) and is never treated as a secret.
- There is no webhook route for Clover in this spec, so no signature verification or CSRF exclusion is needed for it (unlike Stripe, Revolut, Square, and Viva).

**Configuration required**:

None. Every Clover credential (merchant ID, public access key, private token, sandbox or production) stays per-account in `clover_accounts`, the same pattern as every other provider. No new `.env` variable.

One internal note for the Build plan: `CloverClient` currently uses one host (`apisandbox.dev.clover.com` / `api.clover.com`) for `verifyCredentials()` (`/v3/merchants`). Clover's docs describe `/v1/charges` living on a different host (`scl-sandbox.dev.clover.com` / `scl.clover.com`). The client needs two base URLs, not one — see Follow-up for confirming this against a live sandbox before go-live.

**Critical test scenarios**:
- Happy path: admin creates an active, USD, sandbox Clover account with a valid `api_access_key`; an agent creates a payment link; a client opens it, submits a valid test card via the embedded form, and is sent straight to the Success page once the charge endpoint's own synchronous response confirms `paid && captured` with a matching amount/currency. Verifies **AC-1, AC-3, AC-4, AC-5, AC-6, AC-7, AC-9**.
- Decline then retry: a client's first card is synchronously declined; they see an inline (fixed, non-Clover-text) error immediately and retry with a different card on the same page without a new link; the second attempt is approved via the `whereIn(['pending','failed'])` completion guard; the payment ends `completed`. Verifies **AC-5, AC-6, AC-8, AC-9**.
- Concurrent double-submit: two charge requests for the same payment fire near-simultaneously (e.g. a double click); exactly one reaches Clover, the other gets 409 without a second real charge. Verifies **AC-5**'s concurrency lock and the Key invariants.
- Precondition guard: the charge endpoint is called against a payment that is already `completed`, or whose Clover account is inactive; it 422s before any Clover call is made. Verifies **AC-5**.
- Idempotent double resolution: the same `clover_charge_id` is processed twice (the resolver job runs after the synchronous handler already completed the payment); the payment is marked `completed` once, `SendPaymentNotification` dispatched once, no duplicate side effects. Verifies **AC-6, AC-7**.
- Terminal guard: a payment is already `completed` from an earlier approved attempt; the resolver job later resolves a different, earlier, abandoned attempt on the same payment as `declined`; the payment stays `completed`. Verifies **AC-6, AC-7, AC-8**.
- Rate limit: the charge endpoint is hit 11 times for one payment within an hour; the 11th is rejected with 429 and never reaches Clover's API. Verifies **AC-11**.
- Unresolved response, resolved by the resolver job: the charge call to Clover times out on PayHub's side (simulated) after the `clover_charge_attempts` row was already written; the payment stays `pending`; the resolver job later reads the charge (by charge id if known, else by a search on `external_reference_id`) and finds it was actually approved; the payment moves to `completed` with no duplicate charge ever created (nothing was ever re-submitted). Verifies **AC-8**.
- Declined response classified correctly even as an HTTP error: Clover returns a 4xx with a decline body (not a 2xx); the response is still classified **declined**, not **unknown** — the client sees the inline decline immediately, not the processing/poll state. Verifies **AC-5**'s classification table.
- `paid` true but `captured` false: a charge response has `paid: true, captured: false`; the attempt is classified **unknown**, never **approved**, and the payment is not completed. Verifies **AC-7**'s approval condition.
- Abandoned attempt: the resolver job exhausts its retries without a resolvable response; the attempt is marked `abandoned` and logged; the payment stays `pending`, never silently retried forever. Verifies **AC-8**.
- Auth/permission: a non-admin agent hitting any `clover-accounts.*` route receives a 403. Verifies **AC-1**.
- Currency lock: creating a GBP payment against a Clover account is rejected before any Clover API call is made. Verifies **AC-2**.

## Build plan

No `AGENTS.md`/scope header declares a build approach for this project (it runs its own GSD phase workflow in `.planning/`, not `docs/scope/`, same as spec 0001); this plan defaults to end to end (Tracer Bullet) slices: get one thin path (account → embedded form → charge → completed) working first, then extend it across every parity surface and the resolver path.

Because `feat/clover-hosted-iframe-integration` is unmerged and nothing from spec 0001 has shipped past it, the migrations below are edited in place rather than layered (see Consequences). Some of this build plan resumes work already started against earlier versions of this spec (the `payments` session columns are already dropped, `CloverAccount`/`Payment` models and `CloverClient` already partly updated) — re-check each task against the current code before assuming it's still needed as originally done, since the status confirmation mechanism and the reconciliation design both changed underneath it.

1. Finish the account migration edit: add `api_access_key` to `clover_accounts` (already done), and drop `webhook_secret` from it (new in this correction). Rework the `clover_charge_attempts` migration (already created against an earlier version of this spec) to the corrected shape: `idempotency_key` (unique, indexed), nullable `clover_charge_id`, `status` default `pending` with the `hard_error`/`abandoned` values, `attempts_count`, `last_checked_at`, and `updated_at` alongside `created_at`. Drop the `processed_clover_events` table (no webhook to dedupe). Satisfies **AC-1, AC-5, AC-6, AC-11**.
2. Update `CloverAccount` (fillable/casts for `api_access_key`; remove `webhook_secret` entirely), update the `CloverChargeAttempt` model/factory for the corrected columns. Satisfies **AC-1, AC-11**.
3. Rewrite `App\Services\Clover\CloverClient`: `createCharge(idempotencyKey, externalReferenceId, token, amount, currency)` posting to `/v1/charges` on its own (likely distinct) host, returning the status code and parsed body rather than `->throw()`-ing on a 4xx/5xx so the caller can classify per the Charge response classification table; `getCharge(chargeId)` and a read only `findChargeByReference(externalReferenceId)` for the resolver job (exact search endpoint/params to confirm, see Follow-up); `verifyCredentials()` unchanged. Confirm and, if needed, split the base URL between the `/v3/merchants` host and the `/v1/charges` host (see Feature design's Configuration required note). Satisfies **AC-5, AC-8**.
4. Update `StoreCloverAccountRequest`/`UpdateCloverAccountRequest` (add `api_access_key`, remove `webhook_secret`), `Admin\CloverAccountController`, and the admin Vue pages (`Index`/`Create`/`Edit`) to add the `api_access_key` field and drop the webhook secret field and the webhook endpoint URL display (there is no webhook endpoint for this account type). Satisfies **AC-1**.
5. Build the embedded card form: a Vue component that loads Clover's Hosted Iframe SDK (script URL depends on the account's `environment`), renders the card fields, and calls `clover.createToken()` on submit. Satisfies **AC-4**.
6. Register a named, payment-UUID keyed rate limiter (`clover-charge`, 10/hour) alongside the existing IP keyed throttles. Build the charge-submission endpoint: runs the precondition checks (provider, status, account active) and the per-payment concurrency lock (a DB row lock or cache lock on `payment_id`) before anything else; generates the idempotency key; writes the `clover_charge_attempts` row (status `pending`) *before* calling Clover; calls `CloverClient::createCharge()`; classifies the response per the table in Feature design; for **approved**, writes the attempt and `Payment.status`/`paid_at`/`clover_payment_id` atomically in the same guarded update as AC-6, dispatches `SendPaymentNotification` only if the update affected a row; for **declined**, the equivalent `failed` write; for **hard error**, logs and marks the attempt `hard_error`, payment untouched, no retry; for **unknown**, leaves the attempt `pending` and dispatches `ResolveCloverChargeAttempt` with a short delay. Releases the concurrency lock once the attempt is written. Satisfies **AC-5, AC-6, AC-7, AC-8, AC-11**.
7. Rewrite `showClover()` in `ClientPaymentController` to render the embedded-form page (no session reuse logic — already partly done against an earlier version of this spec; confirm nothing still references the removed session columns). Satisfies **AC-4, AC-9**.
8. Build the status-poll endpoint (JSON, including `cancelled`) and the processing state (Inertia polling every 3s, up to 2 minutes, then a terminal "still confirming" state), used only while the latest attempt is unresolved: redirect to Success once `completed`, back to the pay page with a flash message once `failed`, to Unavailable if `cancelled`. An approved synchronous response skips this entirely and goes straight to Success (AC-9). Satisfies **AC-9**.
9. Build `ResolveCloverChargeAttempt` (a queued job, Laravel's own `$tries`/backoff), replacing `ReconcileCloverPayments`'s old session-expiry-based command: for an attempt with a known `clover_charge_id`, calls `CloverClient::getCharge()`; for one with none, calls `CloverClient::findChargeByReference()` using the stored `idempotency_key`/`external_reference_id` — a read, never a re-POST. Applies the same guarded updates as step 6. On exhausting its retries (`failed()`), marks the attempt `abandoned` and logs it. Add a thin scheduled sweep (every 10 minutes, `withoutOverlapping`) that re-dispatches the job for any `pending` attempt whose `last_checked_at` is stale, as a backstop for a lost queue worker. Satisfies **AC-6, AC-7, AC-8**.
10. Remove `CloverWebhookController`, `HandleCloverWebhookJob`, the `webhook/clover/{cloverAccount}` route, and the `ProcessedCloverEvent` model — none of this integration type has a webhook to handle. Satisfies **AC-11** (no webhook surface left half-built).
11. Confirm the existing dashboard/CSV export/agent-locked-account parity surfaces still work unchanged (they key off `clover_account_id`/`clover_payment_id`, both kept); add nothing new unless a surface directly referenced a removed column. Satisfies **AC-10**.
12. Update `CloverAccountFactory` (add `api_access_key`, remove `webhook_secret`) and Pest coverage for every Critical test scenario above, replacing the removed `CloverWebhookTest.php` with coverage of the synchronous charge handler, the concurrency lock, the response classification table, and the resolver job. Satisfies **AC-1 through AC-11**.

## Consequences

**Positive**:
- Clover's checkout now feels like Stripe Elements/Square's SDK: no redirect, one consistent on-page pattern for 4 of PayHub's 5 providers.
- Lighter PCI scope (SAQ A-EP) than the rejected Direct API option, matching the posture PayHub already carries for Stripe and Square.
- A declined card no longer dead-ends the payment link; the client can retry immediately, consistent with how Stripe and Square already behave.
- Removing the (likely nonexistent) webhook path is a net simplification: no `CloverWebhookController`, signature verification, or event-dedupe table to build and maintain for this provider.

**Negative / tradeoffs**:
- Clover is now the one PayHub provider whose paid status is confirmed by a direct server to server response rather than a webhook — a materially different trust model from Stripe/Revolut/Square/Viva that anyone reading this code later needs to understand explicitly, not assume away. `CLAUDE.md`'s "all payment status comes from webhooks only" critical rule should be updated (via `/sync`, once this ships) to name Clover Hosted Iframe as the scoped exception, or the rule as written becomes inaccurate for one provider.
- Clover payment state is a point-in-time snapshot taken at charge time, with no channel for anything that happens afterward — a later void, refund, chargeback, or risk hold on Clover's own dashboard will never reach PayHub the way a webhook based provider's would. Refunds/voids are explicitly out of scope (unchanged from spec 0001), but this is worth naming before that scope ever expands.
- PayHub's own server now accepts a card token and triggers a real charge directly, a materially different risk surface than every redirect based provider (Viva, and the Hosted Checkout Clover this replaces); it needs its own rate limiting, a per-payment concurrency lock, and a durable, idempotency-key based record of every attempt written before Clover is ever called.
- The resolver job's ability to recover an unresolved attempt depends on Clover exposing some way to look up a charge by the `external_reference_id` PayHub sent — confirmed to be accepted on the request, not yet confirmed to be searchable afterward (see Follow-up). If it isn't, an unresolved attempt can only be recovered by a charge id lookup (when one was captured) or manually.
- The `AC-4` session-reuse machinery spec 0001 built (session id, expiry tracking, the 15-minute TTL handling) becomes dead code once this ships; it is removed rather than kept as an unused alternate path.
- Still no live Clover merchant validating any of this in production; the same go-live gate 0001 flagged still applies, now with more riding on it than before.

**Neutral**:
- `clover_accounts.private_token` must be regenerated against Clover's Hosted Iframe integration type; the token already stored for Hosted Checkout cannot be reused.
- `clover_accounts.webhook_secret` is removed outright, not just unused — keeping a dead secret column would misleadingly suggest a webhook exists.
- Migrations are edited in place rather than layered, since nothing from spec 0001 has shipped past this unmerged branch.

## Follow-up

- [ ] Live sandbox pass before go-live: confirm the exact Hosted Iframe SDK script URL per environment, the exact `/v1/charges` request/response field names (particularly `paid`/`captured`/`amount`/`currency`/decline and error fields), and whether `/v1/charges` genuinely lives on a different host than `/v3/merchants`.
- [ ] Confirm Clover exposes a way to look up a charge after the fact by `external_reference_id` (a list/search endpoint) for the resolver job's read only path when no `clover_charge_id` was ever captured. This is what an unresolved attempt with no charge id depends on to ever recover — if Clover has no such lookup, that specific recovery path needs a different design (this is a spec-level question, not a build-time workaround).
- [ ] Confirm whether Clover honors the idempotency key sent on `/v1/charges` at all; it's sent as defence-in-depth even though the design above no longer depends on it (the resolver job reads, it never replays a charge).
- [ ] If Clover developer support later confirms an async webhook does exist for this integration type after all, revisit this correction: the synchronous-response design here would then become an additional safety net rather than the primary mechanism, and Clover could rejoin PayHub's normal webhook-only pattern.
- [ ] Update `CLAUDE.md`'s "all payment status comes from webhooks only" critical rule (via `/sync`, once this ships) to name Clover Hosted Iframe as the scoped, documented exception.
- [ ] The 10 attempts/payment/hour rate limit, the resolver job's retry/backoff schedule, and the 10 minute backstop sweep interval are reasonable starting defaults, not business-reviewed figures; revisit if real usage shows any of them is too tight or too loose.
- [ ] An `abandoned` attempt is currently only logged, with no admin surfaced view of stuck Clover payments; consider one if this happens often enough in practice to matter (mirrors the existing stuck-payment gap already tracked for Viva).
- [ ] Refunds and voids remain out of scope for Clover, unchanged from spec 0001; revisit the lifecycle-visibility gap in Consequences before they ever are scoped.
