# 0012. NMI as the tenth payment provider, using the Payment Component and the v5 REST API

**Date**: 2026-11-12
**Status**: In Progress

## Summary

This spec adds NMI, the gateway behind Beyond Bancard, as PayHub's tenth provider. On the pay page, NMI's Payment Component puts its card fields (NMI frames) and an optional Google Pay button on the page. The browser gets a one time token, and PayHub's server charges that token with NMI's v5 sale call, using the amount from the `Payment` record. The status comes from NMI's reply to that server call, plus a read only `query.php` lookup that is triggered by the reply, a signed webhook or a 10 minute sweep. This is the same scoped exception that Clover, MPGS, Authorize.net and Kurv already use. 3D Secure (a bank check of the cardholder) can be turned on per account, and each account takes one currency, USD or GBP.

## Requirements

**User stories**:
- As an admin, I want to add and test an NMI account (private key, public tokenization key, webhook signing key) the same way as the other providers.
- As an agent, I want to create a payment link against an NMI account in that account's currency.
- As a client, I want to pay by card or Google Pay on the brand page, pass 3D Secure when the bank asks, and try another card if mine is declined.
- As the business, I want a payment marked paid only after PayHub's own server has seen an approved sale for the exact amount and currency.

**Acceptance criteria** (the contract, each one IDed and checkable on its own):
- **AC-1**: An admin (`role:admin`) can create, edit, activate, deactivate and test an NMI account with these fields: account name, prefix, environment (`sandbox` or `production`), currency (`usd` or `gbp`), private key, public tokenization key, webhook signing key (optional), `three_ds_enabled`, `google_pay_enabled`. The private key and the signing key are encrypted, not mass assignable, and never returned to the browser (only `has_private_key` / `has_webhook_signing_key` flags). A save is rejected when the public key doesn't match the dashed tokenization key format (`^[A-Za-z0-9]{6}(-[A-Za-z0-9]{6}){3}$`, confirmed in step 0) or equals the private key. The page shows the one message `"This is not a public tokenization key. It looks like xxxxxx-xxxxxx-xxxxxx-xxxxxx."` for both (decided 2026-11-14: no separate "private key" wording). The public key is not a secret, so Edit shows it filled in; only the private key and signing key fields are blank with a "Saved" placeholder. A public tokenization key is unique across all accounts, active or not. When there's no webhook signing key, the account list and form warn: "Webhooks are off for this account; payments settle by lookup only."
- **AC-2**: Test connection makes one read only `query.php` call (made up `order_id`, no charge) with the private key against the environment's host. A good result shows "Connected"; a bad key shows the gateway's auth error in plain words. It never creates a transaction.
- **AC-3**: An agent or admin can create a payment against an active NMI account only in that account's currency. `StorePaymentRequest` and `UpdatePaymentRequest` reject a mismatch with `"This NMI account only accepts {CURRENCY} payments."`, and `CurrencySupportResolver` treats `nmi` like Square (USD or GBP, never PKR).
- **AC-4**: NMI appears everywhere the other nine providers do: payment create and edit forms, agent account assignment (`user_payment_accounts`), `ProviderAccountTable`, CSV export, dashboard filters, totals and "Accounts Today", `CentralSummary`, provider badge, sidebar, `Payment::providerAccountName()` and prefix, the receipt pages (Success, Failed, Unavailable), and the `PaymentController` provider maps. The `provider-parity-reviewer` passes.
- **AC-5**: The pay page of an NMI payment mounts `mountNmiPayments` from the npm package `@nmipayments/nmi-pay`, only on that page, with the account's public tokenization key (a page prop). It shows card fields, plus the Google Pay button when `google_pay_enabled` is on, a "Name on card" field prefilled from `client_name`, and a pay button showing the amount. The existing consent check runs before the first submit. Card values stay inside NMI's frames. The token and the name never go into Inertia form state, a URL, storage, a log or the console. The component container is `sentry-block`.
- **AC-6**: When `three_ds_enabled` is on and the method is a card, the page runs `mountNmiThreeDSecure(...).startThreeDSecure` with the amount and currency from page props (both read from the `Payment` record on the server), the split name and the token, before calling PayHub. On `onFailure`, or when the customer cancels, no charge is sent. The page reports the failure to the charge endpoint as `three_ds_failed` (so an attempt row with status `3ds_failed` is written) and shows "We could not verify your card. Please try again or use a different card." Google Pay never runs 3DS.
- **AC-7**: `POST pay/{payment}/nmi/charge` (CSRF protected, `nmi-charge` limiter, consent required) takes these inputs:
  - `payment_token` (required unless `three_ds_failed`)
  - `method` (`card` or `google_pay`)
  - `first_name`, `last_name` (cut to 50 characters, not rejected)
  - the optional 3DS fields: `cavv`, `eci`, `xid`, `card_holder_auth`, `three_ds_version`, `directory_server_id`
  - optional `three_ds_failed`

  A request with `three_ds_failed` true only writes a `3ds_failed` attempt row. It takes no lock, makes no gateway call, and is limited by its own `nmi-3ds-report` limiter, not `nmi-charge`.

  Any other request is rejected with 422 unless all of these hold:
  - the provider is NMI, the status is `pending`, and the account is active
  - no `nmi_charge_attempts` row for this payment has status `approved`
  - when 3DS is on and the method is `card`, `cavv` and `eci` are present

  The resolver, the sweep and the webhook ignore `is_active`. Only new charges need an active account. The amount and currency always come from the `Payment` record (cents to a decimal string with integer math). The server calls `POST {host}/api/v5/payments/sale` with header `Authorization: <private key>` and sends `amount`, `currency`, `payment_details.payment_token`, `order_details.id` = `nmi_order_id`, `order_details.ip_address` from the request, `billing_address.first_name`, `billing_address.last_name`, `billing_address.email` = `client_email` (production accounts only), and `cardholder_auth` when present. A top level `order_id` is rejected by v5. `query.php` returns `order_details.id` as `order_id`. The sandbox only accepts `billing_address.email` equal to the merchant's own email address (otherwise 400 "Sandbox accounts can only send emails to their own email address"), so a sandbox account sends no email at all (decided 2026-11-14; confirmed in NMI's own query, email empty). The 3DS result goes out as `cardholder_auth` with `status` (the page's `card_holder_auth`, "verified" or "attempted"), `cavv`, `xid`, `three_ds_version` and `directory_server_id`. No `eci` key is sent, because NMI works the ECI out from `status` (sending `eci` and `cardholder_auth` keys gave `E_INVALID_SUBMISSION`). The page sends every 3DS value as text, because the component returns `eci` as a number. Just before sending, it sets `nmi_order_id` (a new UUID, only if empty), `nmi_first_attempt_at` (only if empty) and `nmi_last_attempt_at`. The token and the request body are never stored or logged.
- **AC-8**: One charge runs at a time per payment (`Cache::lock` `nmi-charge:{payment id}`, 60 seconds; a second caller gets 409). Inside the lock, and **before** the sale call, PayHub sets the pause key `nmi:pause:{payment id}` (120 seconds). It clears the key only on a clear outcome (declined, check_details, error, or approved and settled by the resolver). That way a timed out sale still in flight at NMI can never be sent twice. The reply is classified into one of these outcomes:
  - **approved**: HTTP 200, `response_code` 100, a transaction id, and a sale type.
  - **declined**: HTTP 200 with `response_code` 200 to 299 (a clear card decline), so the status doesn't change.
  - **check_details**: one of the card detail codes in the reply mapping.
  - **unclear**: a timeout, connection error, 5xx or unreadable reply, **or an HTTP 400 with `error_code` `E_DUPLICATE`** ("Duplicate transaction": NMI saw an identical sale a moment ago, so one may already exist).
  - **error**: any other HTTP 400 (`E_INVALID_SUBMISSION`, `E_GENERIC_ERROR` such as "Invalid payment token", or a rejected currency). It's logged and returns 502.

  NMI has no idempotency key on v5 (a repeated `Idempotency-Key` is just another sale), so the pause key and the `E_DUPLICATE` check are the double charge guard.

  An approved reply is never applied on its own. PayHub stores the transaction id on the attempt row only (never on `payments`). It then runs a `query.php` lookup by that `transaction_id` inline, and only `NmiChargeResolver` writes the status. If that lookup doesn't settle the payment as `completed` (NMI lags, or the result is unclear), the endpoint returns outcome `unclear` and the AC-10 path takes over.
- **AC-9**: `NmiChargeResolver` is the only status writer, and the only writer of `payments.nmi_transaction_id`. A lookup by `order_id` can return several transactions, because retries reuse the same `nmi_order_id`. The resolver keeps only the sale rows, ignores the declined ones, and needs exactly one approved row (more than one is logged at error level and never applied). It sets `completed` only for a sale whose condition is `pendingsettlement` or `complete`, whose `order_id` equals `nmi_order_id`, and whose amount exactly matches the `Payment`. It also checks the `<currency>` that `query.php` returns against the `Payment` (confirmed in step 0: it is returned), and a mismatch counts as a mismatched amount or currency below. It uses the guarded update `whereIn('status', ['pending','failed'])` and sets `paid_at` and `nmi_transaction_id` in the same update. It dispatches `SendPaymentNotification` only when a row changed. It handles the other cases like this:
  - A mismatched amount or currency: nothing changes and it's logged at error level.
  - A second approved sale with another id on a `completed` payment, or any sale on a `cancelled` payment: nothing changes and it's logged at error level.
  - A plain decline: never sets `failed` (the status stays `pending`).
- **AC-10**: After an **unclear** outcome PayHub doesn't guess. It keeps `nmi:pause:{payment id}` set from AC-8 (new charges get 409 with "We are confirming your payment. Please do not pay again.", and the page treats that 409 exactly like an unclear outcome). It then dispatches `ResolveNmiCharge` with a 15 second delay, which looks up by `order_id` and applies the result through the resolver. `GET pay/{payment}/nmi/status` runs the same guarded lookup, but only when the payment is `pending` and `nmi_first_attempt_at` is set (at most one lookup every 5 seconds per payment, via the cache key `nmi:lookup:{payment id}`). `ClientPaymentController::show` runs one lookup under the same condition, and redirects to Success if the payment has completed.
- **AC-11**: Every charge call and every 3DS failure writes an `nmi_charge_attempts` row. The row holds:
  - sequence, method, status (`sent`, `approved`, `declined`, `check_details`, `unclear`, `error`, `3ds_failed`)
  - response_code, a plain operator reason, transaction_id
  - avs_result, cvv_result, three_ds_result

  It never holds card values or the token. Admins see the rows on the payment page in the existing `attemptHistory()` shape. The resolver later settles an `unclear` row.
- **AC-12**: The client only ever sees fixed PayHub messages, never gateway text:
  - Approved: visits the Success route.
  - Declined: "Your card was declined. Please try a different card." The form stays so they can retry.
  - Check details: "Please check your card details and try again."
  - Unclear: the unclear message from AC-10, and the page polls the status endpoint every 3 seconds while the tab is visible. After 10 minutes it stops with "Still confirming, you will get a receipt by email".
  - Error: "Your payment could not be processed. Please try again or use a different card."

  Repeated declines trigger the shared card lock (`card_locked_until`, `card_attempts_since`) and its notice, using the same helper and thresholds as Authorize.net. Only `declined` and `check_details` count toward it. `3ds_failed`, `unclear` and `error` don't.

  After any outcome other than approved, the page resets the Payment Component and clears the 3DS result, so the next try always gets a new token (tokens are single use) and a 3DS result tied to that token.
- **AC-13**: The `nmi-charge` limiter counts real sale requests only. It allows 5 per payment UUID per hour and 20 per IP per hour. `nmi-3ds-report` allows 20 per payment UUID per hour. When it's hit, the endpoint returns 429 with "Too many attempts. Please try again later." and makes no gateway call. Once `nmi_first_attempt_at` is set, `UpdatePaymentRequest` rejects any change to the amount, currency, account or brand of the payment, and the payment can't be deleted.
- **AC-14**: `POST webhook/nmi/{nmiAccount}` is CSRF excluded and keeps the raw body. It reads `Webhook-Signature: t=<nonce>,s=<sig>` and checks `hash_equals(hash_hmac('sha256', nonce.'.'.rawBody, signingKey), sig)`. A request with a bad or missing signature, or for an account with no signing key, gets 401 and does nothing. A good request is answered 200 quickly and dispatches `HandleNmiWebhookJob`. The job skips an `event_id` that's already in `processed_nmi_events`, and writes that row only after it succeeds, so a failed job can still be retried. For `transaction.sale.*` events, the payment is found by `event_body.order_id` within the route's account, and the job runs a lookup through the resolver. An unknown or empty `order_id` is logged and ignored. The body is never trusted for status. Any other event type is logged and ignored.
- **AC-15**: `NmiStalePaymentSweep` runs every 10 minutes (`withoutOverlapping`). It looks up `pending` NMI payments whose `nmi_last_attempt_at` is within the last 48 hours, by `order_id`, through the resolver. It ignores `is_active`. If a lookup still finds no transaction 30 minutes after `nmi_last_attempt_at`, the sweep stops looking at that payment: it logs a warning, marks the last `unclear` attempt row `error` with reason "No transaction found at NMI", and clears the pause key. The sweep makes at most one call per second per account.
- **AC-16**: On the NMI pay page only, the CSP adds `https://secure.nmi.com` to `script-src`, `style-src`, `connect-src` and `frame-src`. The sandbox host `https://sandbox.nmi.com` is added the same way on a sandbox account, from config (`secure.nmi.com` answers a sandbox key with "Sandbox accounts must use a sandbox domain"). When `google_pay_enabled` is on, it also adds the Google Pay hosts from `services.wallet_hosts.google_pay`. When `three_ds_enabled` is on, it also adds the 3DS hosts from `services.nmi.three_ds_hosts` (found in the browser run on 2026-11-14: `https://secure.networkmerchants.com`, which serves `Gateway.js`; the sandbox challenge frame needed no further host). Every other page keeps its policy, and no `unsafe-inline` or `unsafe-eval` is added.
- **AC-17**: A sandbox check (Build plan step 0) confirms or corrects each item in **Open questions**. The answers go in `## Follow-up`. Any field name that differs is fixed in `NmiClient`, `NmiSaleReply` or `NmiQueryReply`, which are the only classes that know NMI field names.

## Decision

**Chosen option**: Option 1: Payment Component in the browser, server side v5 sale, status from `NmiChargeResolver` after a `query.php` lookup.

PayHub embeds NMI's Payment Component, which turns the card or Google Pay into a one time token. PayHub's server charges that token with the amount from the `Payment` record, then reads the transaction back with `query.php` before it writes any status. The webhook and the sweep are two more triggers for the same lookup.

**Implementation skills**: `laravel-best-practices` (`.claude/skills/laravel-best-practices/`) · `inertia-vue-development` (`.claude/skills/inertia-vue-development/`) · `wayfinder-development` (`.claude/skills/wayfinder-development/`) · `pest-testing` (`.claude/skills/pest-testing/`) · `provider-parity-check` (`.claude/skills/provider-parity-check/`)

## Rationale

The reasoning and the options are in `rationale.md`.

## Feature design

**Data model sketch**:

| Table | Columns | Notes |
|---|---|---|
| `nmi_accounts` (new) | `id`; `account_name` string; `prefix` string(10) nullable; `environment` string default `sandbox` (enum `NmiEnvironment`); `currency` string(3) default `usd`; `private_key` text (encrypted, not mass assignable); `public_tokenization_key` string unique (across all rows); `webhook_signing_key` text nullable (encrypted, not mass assignable; null means the webhook rejects everything); `three_ds_enabled` bool default false; `google_pay_enabled` bool default false; `is_active` bool default true; timestamps | 1 to many `payments` |
| `payments` (new columns) | `nmi_account_id` FK nullable (`nullOnDelete`); `nmi_order_id` uuid nullable unique; `nmi_transaction_id` string nullable index; `nmi_first_attempt_at`, `nmi_last_attempt_at` timestamp nullable; `nmi` added to the `provider` enum | Add them to `$fillable`, `$casts` and `nmiAccount()` |
| `nmi_charge_attempts` (new) | `id`; `payment_id` FK cascade; `sequence` unsigned int; `method` string (`card`, `google_pay`); `status` string default `sent`; `response_code` nullable; `reason` nullable (operators only); `transaction_id` nullable index; `avs_result`, `cvv_result`, `three_ds_result` string(8) nullable; timestamps; unique (`payment_id`, `sequence`) | Many to 1 `payments` |
| `processed_nmi_events` (new) | `id`; `event_id` string unique; timestamps | Webhook dedupe |

**State transitions** (Payment):
- `pending` to `completed`: the resolver sees an approved, matching sale.
- `pending` stays `pending`: after a decline, a 3DS failure, or an unclear reply (with a lookup queued).
- `completed` is final.
- v1 never sets `failed`.

**Reply mapping** (read only in `NmiSaleReply` and `NmiQueryReply`; every value comes from the docs and is confirmed in step 0):

| Source | Field | Meaning |
|---|---|---|
| v5 sale reply | `response_code` 100 | approved |
| v5 sale reply | HTTP 200, `response_code` 200 to 299 (`response_text` `DECLINE`, `status` `failed`) | declined. The card detail codes (expired, invalid card number, CVV mismatch) map to `check_details`; still to be listed in step 0 |
| v5 sale reply | HTTP 200, `response_code` 300+ | `error` (a gateway problem) |
| v5 sale reply | HTTP 400 `E_DUPLICATE` | `unclear` (a sale may already exist) |
| v5 sale reply | HTTP 400 any other `error_code` | `error` (a request problem; the body has `type`, `error_code`, `message`, `ref_id`) |
| v5 sale reply | `id`, `auth_code`, `avs_response`, `cvv_response` | stored in the attempt row |
| `query.php` | `condition` `pendingsettlement` or `complete`, `transaction_type` `cc` or wallet, action `sale` success | approved |
| `query.php` | `failed`, `canceled`, `abandoned` | not approved (logged, the status stays `pending`) |
| `query.php` | `pending`, `in_progress`, `unknown`, no match | unclear, so try again later (a sweep stops after 30 minutes with no match, see AC-15) |
| `query.php` | several rows for one `order_id` | keep sale rows, ignore declined, exactly one approved row needed (AC-9) |

There is no "held" outcome in v1. If step 0 finds a review or held condition, add it here and route it to `unclear`.

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `pay/{payment}` (NMI branch, `showNmi`) | GET | payment UUID | Inertia `ClientPayment/PayNmi` with amount, currency, tokenization key, `three_ds_enabled`, `google_pay_enabled`, `client_name` | public, consent gate | Unavailable page when the account is inactive or the payment isn't pending |
| `pay/{payment}/nmi/charge` | POST | `payment_token` (req, string max 4096), `method` (req), `first_name`, `last_name` (req, max 50), 3DS fields (opt), `three_ds_failed` (opt bool) | `{outcome, message}` | public, CSRF, consent, `throttle:nmi-charge` | 409 locked or paused, 422 invalid state, 429, 502 error |
| `pay/{payment}/nmi/status` | GET | payment UUID | `{status}` | public | 404 |
| `webhook/nmi/{nmiAccount}` | POST | raw body, `Webhook-Signature` | 200 | HMAC | 401 bad signature |
| `nmi-accounts` resource, `activate`, `deactivate`, `test-connection` (new and stored) | REST | AC-1 fields | Inertia pages, `has_*` flags | `role:admin` | 422 |

**Value sourcing**:

| Action | Value | Source |
|---|---|---|
| charge | amount, currency | `payments.amount` (cents to decimal) and `payments.currency` |
| charge | private key, host | `nmi_accounts.private_key`; `config('services.nmi.hosts.{environment}')` |
| charge | order id | `payments.nmi_order_id` (UUID made on the first charge), sent as `order_details.id` |
| charge | first and last name (`billing_address`) | the "Name on card" field, prefilled from `payments.client_name` and split on the last space (a single word is used for both) |
| charge | email, IP | `payments.client_email` as `billing_address.email` on production accounts only (a sandbox account sends none); `$request->ip()` as `order_details.ip_address` |
| charge | 3DS values | `mountNmiThreeDSecure` `onComplete` result, sent by the page |
| pay page | tokenization key, 3DS and Google Pay switches | `nmi_accounts` columns |
| 3DS start | amount, currency | page props from the `Payment` record (display only; the server never reads an amount from the client) |
| webhook | signing key | `nmi_accounts.webhook_signing_key` |
| webhook | which payment | `event_body.order_id` matched to `payments.nmi_order_id` and the route's account |
| resolver | approved or not | the `query.php` reply (condition, amount, currency, order id) |
| admin attempts panel | rows | `nmi_charge_attempts` |
| reference code prefix | prefix | `nmi_accounts.prefix` |
| admin payment page, CSV | `provider_reference` | `payments.nmi_transaction_id`, falling back to `payments.nmi_order_id` |
| admin lists, receipts | account name | `nmi_accounts.account_name` via `Payment::providerAccountName()` |
| status lookup throttle | last lookup | cache key `nmi:lookup:{payment id}` (5 seconds) |

**Key invariants**:
- Only `NmiChargeResolver` writes `payments.status` for NMI.
- No status is ever written from the browser, the sale reply alone, or the webhook body.
- The amount sent to NMI always equals `payments.amount`, and the currency always equals the account's currency.
- At most one approved sale per payment. A second one is logged at error level and never applied.
- The private key exists only in the encrypted column and the server side `NmiClient`. It's never in a page prop, a log or Sentry.
- `nmi_order_id` never changes once it is set.

**Security model**: Admins manage accounts. Agents only see the NMI accounts assigned to them. Clients reach only their own payment by UUID, behind the consent gate. Compliance: this is PCI DSS scope. Card data stays in NMI's frames (likely SAQ A, but confirm with the acquirer before production). Every charge is logged in `nmi_charge_attempts`, which is the money audit trail. Webhooks are HMAC checked and deduped. Public endpoints are rate limited.

**Configuration required**:
- `services.nmi.hosts.sandbox` (env `NMI_SANDBOX_HOST`, default `https://sandbox.nmi.com`, confirmed in step 0) and `services.nmi.hosts.production` (env `NMI_PRODUCTION_HOST`, default `https://secure.nmi.com`): the API, `query.php` and CSP host. A white label host from Beyond Bancard is only an env change.
- `services.wallet_hosts.google_pay`: the Google Pay CSP hosts (confirmed in step 0).
- `services.nmi.three_ds_hosts`: the 3DS challenge CSP hosts (confirmed in step 0).
- Named limiters `nmi-charge` and `nmi-3ds-report` in `AppServiceProvider`.
- No new secret in `.env`. The keys live encrypted on each `nmi_accounts` row.
- Before you build: in the NMI merchant portal, create a webhook endpoint for `transaction.sale.success`, `transaction.sale.failure` and `transaction.sale.unknown` on the staging URL, then paste the signing key into the account form.

**Open questions** (answered by the step 0 sandbox check):
Answered on 2026-11-13 (see `docs/research/nmi-sandbox-check.md` and Follow-up): 1, 2 (card detail decline codes still open), 3, 4, 8, 9 and 12. Answered in the 2026-11-14 browser run: 6, 9 (corrected) and 11. Still open for the staging run: 5 (Google Pay), 7 (webhook), 10 (ZIP/AVS) and the card detail decline codes.

1. ~~Sandbox host~~ Answered: `sandbox.nmi.com`; `query.php` accepts the v5 key.
2. ~~Sale reply shape~~ Answered; which `response_code` values mean a card detail decline is still open.
3. ~~`query.php` fields~~ Answered: `condition`, `order_id`, `currency`, `amount`, `transaction_id` and more; the currency is returned.
4. ~~Tokenization key format~~ Answered: dashed format confirmed.
5. Which hosts does Google Pay load inside the Payment Component, and does it work in the sandbox?
6. ~~3DS `onComplete` shape~~ Answered 2026-11-14: `{cardHolderAuth: "verified", cavv, directoryServerId, eci (a number, 5), threeDsVersion, xid}`. The sale accepts it as `cardholder_auth` with `status`, `cavv`, `xid`, `three_ds_version`, `directory_server_id`; the sale then went to `pendingsettlement` (transaction 12805059660).
7. Does the webhook `event_body` carry `order_id` as we sent it?
8. ~~Double charge guard~~ Answered: no idempotency key; an identical repeat sale gets HTTP 400 `E_DUPLICATE` (treated as unclear, AC-8).
9. ~~3DS request field names~~ Answered, and corrected 2026-11-14: `cardholder_auth` with `status`, `cavv`, `xid`, `three_ds_version`, `directory_server_id`. `eci` and a `cardholder_auth` key inside it are rejected (`E_INVALID_SUBMISSION`).
10. Do 3DS or the merchant's AVS rules need a ZIP or an address? If they do, add a ZIP field (the same risk as Authorize.net's AVS B setting), or record the portal setting to change.
11. ~~3DS challenge hosts~~ Answered 2026-11-14: only `https://secure.networkmerchants.com` (`Gateway.js`) was blocked; the sandbox challenge frame loaded with no further CSP violation. A live bank challenge may load other issuer hosts, so recheck on the first live 3DS payment.
12. ~~Bad key vs empty result~~ Answered: a bad key returns `<error_response>Specified API key not found</error_response>`; an unknown order returns an empty `<nm_response>`.

**Critical test scenarios**:
- Happy path: a card in the sandbox, 3DS passes, the sale is approved, the lookup matches, the payment is `completed` and one notification is sent. Verifies **AC-7**, **AC-8**, **AC-9**.
- Google Pay: the token is charged without 3DS. Verifies **AC-5**, **AC-6**.
- Decline then retry: the status stays `pending`, an attempt row is written, and a second card succeeds. The lookup returns the declined and the approved rows, and only the approved one is applied. Verifies **AC-9**, **AC-11**, **AC-12**.
- Timeout while the first sale is still in flight: a second charge request gets 409 (the pause is set before the sale). Verifies **AC-8**.
- A webhook job that fails is retried and not deduped away. Verifies **AC-14**.
- Timeout on the sale: pause, queued lookup, and the payment later completes from the lookup with no double charge. Verifies **AC-10**.
- Amount mismatch in the lookup: there's no completion and an error is logged. Verifies **AC-9**.
- Webhook with a bad signature gets 401. A replayed `event_id` is a no op. Verifies **AC-14**.
- 3DS failure: no sale call and a `3ds_failed` row. Verifies **AC-6**, **AC-11**.
- Auth: an agent can't open NMI account admin (403). A private key never appears in any page prop. Verifies **AC-1**, **AC-5**.
- Currency mismatch on create: 422 with the fixed message. Verifies **AC-3**.

## Build plan

Build approach: none is recorded in `AGENTS.md`, so end to end Tracer Bullet slices are assumed. The first thread is a sandbox card charge that goes all the way to `completed`.

0. Sandbox check with the keys you already have: answer every Open question, write the answers in Follow-up, and fix the field names. Done in part on 2026-11-13 (server side questions answered). The browser questions (5, 6, 7, 10, 11, card detail codes) are answered during steps 4 to 6 and 7 as each page piece exists, and recorded in Follow-up before the matching step is ticked. Steps 1 to 3 may start now. Satisfies **AC-17**.
1. Migrations (the four tables), `PaymentProvider::Nmi`, `NmiEnvironment`, models, factories, `config/services.php`. Satisfies **AC-1**, **AC-11**.
2. `NmiClient` (sale, query, ping), `NmiSaleReply`, `NmiQueryReply`, `NmiChargeResolver` with its guarded update. Satisfies **AC-8**, **AC-9**.
3. Charge endpoint, `nmi-charge` limiter, cache lock and pause, attempt rows, `ResolveNmiCharge` job. Satisfies **AC-7**, **AC-8**, **AC-10**, **AC-11**, **AC-13**.
4. `showNmi`, `PayNmi.vue` (Payment Component cards only first, then the name field, consent, fixed messages, polling, card lock), the status endpoint, and the CSP for this page. Satisfies **AC-5**, **AC-10**, **AC-12**, **AC-16**. This completes the thread: a sandbox card goes all the way to `completed`.
5. 3DS on the page, and the `three_ds_failed` path. Satisfies **AC-6**.
6. Google Pay (button, `method`, wallet CSP hosts). Satisfies **AC-5**, **AC-16**.
7. Webhook route, signature check, CSRF exclusion in `bootstrap/app.php`, `HandleNmiWebhookJob`, `processed_nmi_events`. Satisfies **AC-14**.
8. `NmiStalePaymentSweep`, scheduled in `routes/console.php`. Satisfies **AC-15**.
9. Admin account CRUD, activate, deactivate, test connection, and the key format checks. Satisfies **AC-1**, **AC-2**.
10. Payment requests, `CurrencySupportResolver`, edit, lock and delete guards, agent assignment. Satisfies **AC-3**, **AC-13**.
11. Admin attempts panel on the payment page. Satisfies **AC-11**.
12. Parity sweep: CSV, `DashboardMetrics`, `CentralSummary`, receipts, agent forms, `PaymentController` maps (`provider_reference`, the account list, `clearStaleProviderTransactionIds`), and `Payment` helpers. Run the `provider-parity-reviewer`. Satisfies **AC-4**.
13. Pest tests for the scenarios (`php artisan test --parallel`), `npm run build`, regenerate Wayfinder, and update `AGENTS.md` through /sync. Satisfies **AC-1** to **AC-17**.
14. Run it on staging with the sandbox (approve, decline, 3DS challenge, Google Pay, webhook, sweep), then one small live payment once Beyond Bancard confirms the production host.

## Consequences

**Positive**:
- Beyond Bancard merchants can take payments in PayHub with the card and Google Pay flow they expect.
- Card data never touches PayHub's scripts (NMI frames), so the PCI level is lighter than with Authorize.net's own fields.
- It reuses proven PayHub patterns: the resolver, the attempts table, the sweep, the card lock and per page CSP.

**Negative / tradeoffs**:
- This is a tenth parallel path. Every future payment feature now has ten branches to touch.
- NMI's webhook has no timestamp, so replay protection relies only on `event_id` dedupe.
- `query.php` is XML and "Classic", next to a JSON v5 API, so `NmiClient` speaks two formats.
- The Payment Component is a new npm dependency, and its frames limit how closely the fields match the brand styling.
- Per account currency means a merchant who takes both USD and GBP needs two accounts.

**Neutral**:
- The webhook is registered by hand in the NMI portal, and the signing key is pasted into the admin form.
- Refunds, voids and Apple Pay are out of scope in v1.

## Follow-up

- [ ] Step 0 answers: 2026-11-13 server side run recorded in `docs/research/nmi-sandbox-check.md` (open questions 1, 3, 4, 8, 9, 12 and most of 2). 2026-11-14 browser run answered 6, 9 (corrected) and 11 (see above). Still to record here: 5, 7, 10 and the card detail decline codes (staging run).
- 2026-11-14 browser run (`verify.md`, 26 of 28 steps ticked; Google Pay and the webhook stay for staging). Three bugs found and fixed in commit `4971b25`: the CSP blocked `Gateway.js` (empty `three_ds_hosts`), the page sent `eci` as a number (422), and the sale used wrong `cardholder_auth` keys. Decisions made the same day: one generic public key error, public key shown on Edit, email points skipped in verification, and the form locks currency to the account's.
- Sandbox facts for testing: the dummy token `00000000-000000-000000-000000000000` works for server side sale tests; amounts 0.01 and 0.99 decline; this sandbox processor refuses GBP ("cc payment type [Visa] and/or currency [GBP] is not accepted"), so only USD can be tested until GBP is enabled; the sandbox only accepts the merchant's own email in `billing_address.email`, so sandbox accounts send no email (production sends `client_email`).
- [ ] Cross check on 2026-11-12 (another model): 14 gaps found, all fixed in this spec on the engineer's pick.
- [ ] Later: rotating the webhook signing key (today it's a manual paste in the form).
- [ ] Ask Beyond Bancard to confirm the production host (secure.nmi.com or a white label domain), whether GBP is available on the processor, and whether Payer Authentication and Google Pay work on the live account.
- [ ] Check Country/Currency Configuration in the sandbox merchant portal for GBP.
- [ ] Confirm the SAQ level with the acquirer before production.
- [ ] Later: refunds and voids, Apple Pay (blocked while Square owns the Apple Pay domain file).
- [ ] /sync: add `hookdeck/webhook-skills@nmi-webhooks` to the `Declined:` line in `AGENTS.md`. It's not official NMI and was declined on 2026-11-12. No official NMI skill or MCP server exists.
