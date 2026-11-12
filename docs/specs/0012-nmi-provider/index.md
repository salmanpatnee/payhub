# 0012. NMI as the tenth payment provider, using the Payment Component and the v5 REST API

**Date**: 2026-11-12
**Status**: Proposed

## Summary

This spec adds NMI, the gateway behind Beyond Bancard, as PayHub's tenth provider. On the pay page, NMI's Payment Component puts its card fields (NMI frames) and an optional Google Pay button on the page. The browser gets a one time token, and PayHub's server charges that token with NMI's v5 sale call, using the amount from the `Payment` record. The status comes from NMI's reply to that server call, plus a read only `query.php` lookup that is triggered by the reply, a signed webhook or a 10 minute sweep. This is the same scoped exception that Clover, MPGS, Authorize.net and Kurv already use. 3D Secure (a bank check of the cardholder) can be turned on per account, and each account takes one currency, USD or GBP.

## Requirements

**User stories**:
- As an admin, I want to add and test an NMI account (private key, public tokenization key, webhook signing key) the same way as the other providers.
- As an agent, I want to create a payment link against an NMI account in that account's currency.
- As a client, I want to pay by card or Google Pay on the brand page, pass 3D Secure when the bank asks, and try another card if mine is declined.
- As the business, I want a payment marked paid only after PayHub's own server has seen an approved sale for the exact amount and currency.

**Acceptance criteria** (the contract, each one IDed and checkable on its own):
- **AC-1**: An admin (`role:admin`) can create, edit, activate, deactivate and test an NMI account with these fields: account name, prefix, environment (`sandbox` or `production`), currency (`usd` or `gbp`), private key, public tokenization key, webhook signing key (optional), `three_ds_enabled`, `google_pay_enabled`. The private key and the signing key are encrypted, not mass assignable, and never returned to the browser (only `has_private_key` / `has_webhook_signing_key` flags). A save is rejected when the public key doesn't match the dashed tokenization key format (`^[A-Za-z0-9]{6}(-[A-Za-z0-9]{6}){3}$`, confirmed in step 0) or equals the private key: `"This looks like a private key. Paste the public tokenization key."` A public tokenization key is unique across all accounts, active or not. When there's no webhook signing key, the account list and form warn: "Webhooks are off for this account; payments settle by lookup only."
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

  The resolver, the sweep and the webhook ignore `is_active`. Only new charges need an active account. The amount and currency always come from the `Payment` record (cents to a decimal string with integer math). The server calls `POST {host}/api/v5/payments/sale` with header `Authorization: <private key>` and sends `amount`, `currency`, `payment_details.payment_token`, `order_id` = `nmi_order_id`, `first_name`, `last_name`, `email` = `client_email`, `ip_address` from the request, and `cardholder_auth` when present. Just before sending, it sets `nmi_order_id` (a new UUID, only if empty), `nmi_first_attempt_at` (only if empty) and `nmi_last_attempt_at`. The token and the request body are never stored or logged.
- **AC-8**: One charge runs at a time per payment (`Cache::lock` `nmi-charge:{payment id}`, 60 seconds; a second caller gets 409). Inside the lock, and **before** the sale call, PayHub sets the pause key `nmi:pause:{payment id}` (120 seconds). It clears the key only on a clear outcome (declined, check_details, error, or approved and settled by the resolver). That way a timed out sale still in flight at NMI can never be sent twice. The reply is classified into one of these outcomes:
  - **approved**: `response_code` 100, a transaction id, and a sale type.
  - **declined**: a clear card decline, so the status doesn't change.
  - **check_details**: one of the card detail codes in the reply mapping.
  - **unclear**: a timeout, connection error, 5xx or unreadable reply.
  - **error**: any other rejection of the request itself. It's logged and returns 502.

  An approved reply is never applied on its own. PayHub stores the transaction id on the attempt row only (never on `payments`). It then runs a `query.php` lookup by that `transaction_id` inline, and only `NmiChargeResolver` writes the status. If that lookup doesn't settle the payment as `completed` (NMI lags, or the result is unclear), the endpoint returns outcome `unclear` and the AC-10 path takes over.
- **AC-9**: `NmiChargeResolver` is the only status writer, and the only writer of `payments.nmi_transaction_id`. A lookup by `order_id` can return several transactions, because retries reuse the same `nmi_order_id`. The resolver keeps only the sale rows, ignores the declined ones, and needs exactly one approved row (more than one is logged at error level and never applied). It sets `completed` only for a sale whose condition is `pendingsettlement` or `complete`, whose `order_id` equals `nmi_order_id`, and whose amount exactly matches the `Payment`. It also checks the currency against the `Payment` when `query.php` returns one. When it doesn't, the account's single currency lock (AC-3) is the guarantee. Step 0 records which case applies. It uses the guarded update `whereIn('status', ['pending','failed'])` and sets `paid_at` and `nmi_transaction_id` in the same update. It dispatches `SendPaymentNotification` only when a row changed. It handles the other cases like this:
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
- **AC-16**: On the NMI pay page only, the CSP adds `https://secure.nmi.com` to `script-src`, `style-src`, `connect-src` and `frame-src`. If step 0 shows the sandbox uses another host, that host is added too, from config. When `google_pay_enabled` is on, it also adds the Google Pay hosts from `services.wallet_hosts.google_pay`. When `three_ds_enabled` is on, it also adds the 3DS challenge hosts that step 0 finds, from `services.nmi.three_ds_hosts`. Every other page keeps its policy, and no `unsafe-inline` or `unsafe-eval` is added.
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
| v5 sale reply | `response_code` 200 to 299 | declined. The card detail codes (expired, invalid card number, CVV mismatch) map to `check_details`; step 0 lists them |
| v5 sale reply | `response_code` 300+ | `error` (a gateway or request problem) |
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
| charge | order id | `payments.nmi_order_id` (UUID made on the first charge) |
| charge | first and last name | the "Name on card" field, prefilled from `payments.client_name` and split on the last space (a single word is used for both) |
| charge | email, IP | `payments.client_email`; `$request->ip()` |
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
- `services.nmi.hosts.sandbox` and `services.nmi.hosts.production` (env `NMI_SANDBOX_HOST`, `NMI_PRODUCTION_HOST`, both defaulting to `https://secure.nmi.com` until step 0 confirms the sandbox host): the API, `query.php` and CSP host. A white label host from Beyond Bancard is only an env change.
- `services.wallet_hosts.google_pay`: the Google Pay CSP hosts (confirmed in step 0).
- `services.nmi.three_ds_hosts`: the 3DS challenge CSP hosts (confirmed in step 0).
- Named limiters `nmi-charge` and `nmi-3ds-report` in `AppServiceProvider`.
- No new secret in `.env`. The keys live encrypted on each `nmi_accounts` row.
- Before you build: in the NMI merchant portal, create a webhook endpoint for `transaction.sale.success`, `transaction.sale.failure` and `transaction.sale.unknown` on the staging URL, then paste the signing key into the account form.

**Open questions** (answered by the step 0 sandbox check):
1. Is the sandbox API host `secure.nmi.com` or `sandbox.nmi.com`? And does `query.php` work with the v5 private key?
2. What is the exact v5 sale reply shape (`id`, `response_code`, the AVS and CVV fields), and which codes mean a card detail decline?
3. What do the `query.php` XML fields look like for a sale (condition, amount, currency, order id), and is the currency returned?
4. Does the tokenization key always match the dashed format?
5. Which hosts does Google Pay load inside the Payment Component, and does it work in the sandbox?
6. What does the 3DS `onComplete` result look like with Test 3DS, and how does the sale reply show a 3DS result?
7. Does the webhook `event_body` carry `order_id` as we sent it?
8. **Double charge guard:** does the v5 sale reject or flag a repeated `order_id`, or accept an idempotency key? If it accepts a key, send `nmi_order_id` plus the attempt sequence as that key.
9. What are the v5 request field names for the 3DS values (`cardholder_auth` and its children)?
10. Do 3DS or the merchant's AVS rules need a ZIP or an address? If they do, add a ZIP field (the same risk as Authorize.net's AVS B setting), or record the portal setting to change.
11. Which hosts does the 3DS challenge frame load (for the CSP)?
12. Does `query.php` tell a bad key apart from an empty result? If it can't use the v5 key at all, which read path is the fallback (a v5 transaction GET, if one exists)?

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

0. Sandbox check with the keys you already have: answer every Open question, write the answers in Follow-up, and fix the field names. This is a hard gate: nothing below starts until it's recorded. Satisfies **AC-17**.
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

- [ ] Step 0 answers (Open questions 1 to 12) go here.
- [ ] Cross check on 2026-11-12 (another model): 14 gaps found, all fixed in this spec on the engineer's pick.
- [ ] Later: rotating the webhook signing key (today it's a manual paste in the form).
- [ ] Ask Beyond Bancard to confirm the production host (secure.nmi.com or a white label domain), whether GBP is available on the processor, and whether Payer Authentication and Google Pay work on the live account.
- [ ] Check Country/Currency Configuration in the sandbox merchant portal for GBP.
- [ ] Confirm the SAQ level with the acquirer before production.
- [ ] Later: refunds and voids, Apple Pay (blocked while Square owns the Apple Pay domain file).
- [ ] /sync: add `hookdeck/webhook-skills@nmi-webhooks` to the `Declined:` line in `AGENTS.md`. It's not official NMI and was declined on 2026-11-12. No official NMI skill or MCP server exists.
