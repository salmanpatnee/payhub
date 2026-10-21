# 0006. Add Authorize.net (Accept Hosted iframe) as a payment provider

**Date**: 2026-10-21
**Status**: Proposed

## Summary

This spec adds Authorize.net as the 7th PayHub payment provider. The client pays inside Authorize.net's own hosted card form, shown in an iframe (a page inside a page) on PayHub's brand pay page, so card details never touch PayHub's server or page code. PayHub only marks a payment paid after its own server asks Authorize.net for the transaction and the amount, invoice and status all match. The browser's iframe message and the webhook are only hints that trigger that lookup. Each account is USD only, like Square's currency lock. Several Authorize.net details are not proven yet, so the first build step is a sandbox spike that gates the rest.

## Requirements

**User stories**:
- As an admin, I want to create and manage an Authorize.net account (API login ID, transaction key, signature key, sandbox or production) the same way I manage every other provider account.
- As an agent, I want to create a USD payment link against an Authorize.net account exactly like any other provider.
- As a client, I want to type my card into the form on the brand page and see a clear result without leaving the page, and retry another card if mine is declined.
- As the business, I want a payment marked paid only from a result PayHub itself read from Authorize.net's servers, never from anything the browser says, and I want a payment held for fraud review to stay pending until it is decided.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: An admin can create, edit, activate, deactivate and test the connection of an Authorize.net account (account name, prefix, API login ID, transaction key, signature key, environment of `sandbox` or `production`), restricted to `role:admin`. The transaction key and signature key are encrypted at rest, not mass assignable, and never returned to the browser (only `has_*` flags). Test connection makes one authenticated `getMerchantDetails` call with the stored credentials, reports success or failure, and warns when the account's currency list does not include USD. It never touches a payment.
- **AC-2**: An agent or admin can create a payment link against an active Authorize.net account only in USD. `authorizenet_accounts.currency` defaults to `USD`. `StorePaymentRequest` and `UpdatePaymentRequest` reject a payment whose currency differs from the account's, with `"This Authorize.net account only accepts {currency} payments."`, and `CurrencySupportResolver` treats `authorizenet` as supporting USD only.
- **AC-3**: Authorize.net appears everywhere the other providers already do: payment create and edit forms, agent account assignment (`user_payment_accounts`), the `ProviderAccountTable` and `CurrencySupportResolver` maps, CSV export, the dashboard account filters and "Accounts Today", the provider badge, the sidebar, and the client facing Success, Failed and Unavailable pages. No surface is left behind (grep for the other providers' `match`, ternary and `in_array` lists).
- **AC-4**: A token endpoint creates a hosted payment page token on the server with `getHostedPaymentPageRequest` using the account's credentials. The amount and currency come from the `Payment` record, never the request. It sends `transactionType` `authCaptureTransaction`, `order.invoiceNumber` set to the payment's 6 digit zero padded id, the client's email and name, and `hostedPaymentSettings` for the iframe (receipt off, card only, no billing address shown, no saved card profile, the communicator URL, a pay button label). It returns only the token and the form post URL. It rejects with 422 unless the payment's provider is Authorize.net, its status is `pending`, and its account exists and is active. The token is never stored, logged, or placed in a URL.
- **AC-5**: The pay page loads the token when it opens, then posts it from a hidden form into a named iframe (`https://accept.authorize.net/payment/payment`, sandbox `https://test.authorize.net/payment/payment`). A PayHub route serves the communicator page on the same HTTPS domain. It relays the form's resize, cancel and transaction response messages to the pay page, which resizes the iframe and reacts. When the form says the token expired, the page asks for a fresh token. The CSP (browser rule list) is widened for `frame-src` and `form-action` only on the pay page of an Authorize.net payment, and the communicator route is the one route that allows framing by Authorize.net.
- **AC-6**: When the pay page receives a transaction response message, it sends only the `transId` to a confirm endpoint (rate limited per payment). The server calls `getTransactionDetails` with the account's credentials and classifies the result into exactly one of four buckets: **approved** (response code 1, a captured or settled status, `order.invoiceNumber` equals this payment, and the amount equals `Payment.amount` exactly), **held** (response code 4 or a pending review status), **declined**, or **unknown** (timeout, 5xx, no match, anything unclassifiable). The browser is told only a fixed outcome word and message, never the gateway's text.
- **AC-7**: All status writes go through one class, `AuthorizenetChargeResolver`, using the same guards as the other providers: `whereIn('status', ['pending','failed'])` for completion and `where('status','pending')` for a decline. A given transaction moves a payment to `completed` at most once. An approved result sets `paid_at` and `authorizenet_transaction_id` in the same guarded update and dispatches `SendPaymentNotification` only when a row was updated. A held result stores `authorizenet_transaction_id` but leaves the status `pending`. A second approved transaction with a different id for an already `completed` payment changes nothing and is logged for operators.
- **AC-8**: The webhook route `webhook/authorizenet/{authorizenetAccount}` is excluded from CSRF, reads the raw body, verifies the `X-ANET-Signature` header (HMAC-SHA512 of the raw body with the account's signature key, compared with `hash_equals`; an account with no signature key rejects everything), and only queues a job. The job reads the transaction through `getTransactionDetails` and applies it through the shared resolver; it never trusts the webhook body. Subscribed events are `net.authorize.payment.authcapture.created`, `net.authorize.payment.fraud.approved`, `net.authorize.payment.fraud.declined` and `net.authorize.payment.fraud.held`. Duplicate deliveries are dropped through `processed_authorizenet_events` (keyed on `notificationId`). A held payment later settled by `fraud.approved` completes, and by `fraud.declined` becomes `failed`.
- **AC-9**: The client experience matches MPGS: an approved result goes to Success; a decline stays inside Authorize.net's form where the client retries; a held or unknown result, or a callback that never arrives, shows a processing state that polls the payment status every 3 seconds for up to 2 minutes, then a final "still confirming, you will get a receipt by email" message. Cancel from the form returns the client to the unpaid pay page.
- **AC-10**: Security rules hold: amount, currency, account and payment are resolved server side; the transaction key and signature key are encrypted and never exposed; the token and confirm endpoints each have a named rate limiter keyed on the payment UUID; raw gateway text is stored for operators only; no secret, token or key appears in logs.
- **AC-11**: A scheduled sweep every 10 minutes re-reads `pending` Authorize.net payments that already hold an `authorizenet_transaction_id` (held payments) through the resolver, and never changes anything for a payment with no transaction id.
- **AC-12**: Before any build task beyond account management begins, a sandbox spike with a real test merchant confirms or corrects each item in this spec's **Open questions** and records the answers in `## Follow-up`. Where an answer changes a field name, header or sequence, the spec is updated through `/architect` before the dependent task is built.

## Decision

**Chosen option**: Option 1: Accept Hosted in an iframe, with a server side transaction lookup as the only source of paid status

Embed Authorize.net's Accept Hosted form in an iframe on the brand pay page. PayHub's server creates the one time token, and later reads the transaction back from Authorize.net before changing any status. The webhook and the iframe message are hints that trigger that read. This is the same scoped exception to "status comes from webhooks only" that specs 0002 (Clover) and 0005 (MPGS) made: the channel is server to server and the browser is never trusted.

**Implementation skills**: `laravel-best-practices` (`.claude/skills/laravel-best-practices/`) · `inertia-vue-development` (`.claude/skills/inertia-vue-development/`) · `pest-testing` (`.claude/skills/pest-testing/`) · `provider-parity-check` (`.claude/skills/provider-parity-check/`)

## Rationale

Reasoning and options: see `rationale.md`.

## Feature design

**Data model sketch**:

- `authorizenet_accounts` (new)
  - `id`, `account_name` string, `prefix` string(10) nullable (reference code prefix, as other accounts)
  - `api_login_id` string, plain (identifies the merchant, not a secret)
  - `transaction_key` text, `encrypted` cast, NOT mass assignable
  - `signature_key` text nullable, `encrypted` cast, NOT mass assignable (null means the webhook route rejects everything and PayHub relies on the confirm lookup and the sweep)
  - `environment` string, `sandbox` or `production` (a plain string with a PHP enum cast `AuthorizenetEnvironment`, not a DB enum)
  - `currency` string(3) default `USD`
  - `is_active` boolean default true, timestamps
- `payments` (existing, additive migration)
  - Adds `authorizenet_account_id` FK nullable (cascade on delete, like the others) and `authorizenet_transaction_id` string nullable, indexed
  - The `provider` enum gains `authorizenet`
- `processed_authorizenet_events` (new): `id`, `event_key` string unique (the webhook `notificationId`), timestamps
- `user_payment_accounts` (existing): `provider` is a plain string, so `authorizenet` needs only the enum cast and the `ProviderAccountTable` map

Relationships: `authorizenet_accounts` 1:N `payments` · agents reach an account through `user_payment_accounts` (`provider = authorizenet`, `account_id`).

No attempts table: Authorize.net keeps the retry loop inside its own form with one token, so PayHub records only the final transaction id on the payment.

**State transitions**:

Payment: `pending` → `completed` (approved), `pending` → `failed` (fraud declined only), `pending` stays `pending` while held, after a plain decline inside the form, or while unknown. `completed` is terminal. A plain decline never sets `failed`, because the client can retry in the same form and a failed state would only mislead.

**Classification** (applied to the confirm lookup, the webhook job lookup and the sweep; field names marked in Open questions):

| Bucket | Condition | Effect |
|---|---|---|
| **Approved** | response code 1 AND status captured or settled AND invoice number matches this payment AND amount equals `Payment.amount` | `completed` (AC-7) |
| **Held** | response code 4, or a pending review status | store transaction id, status stays `pending` |
| **Declined** | response code 2 (or fraud declined event) | `failed` only for a fraud decline from a held payment, otherwise no status change |
| **Unknown** | transport failure, timeout, 5xx, anything not matching above, or success with a wrong amount or invoice | no change, logged, sweep or webhook resolves it |

`AuthorizenetClient::getTransactionDetails()` must not `->throw()` on 4xx or 5xx. It returns status and body so the caller classifies.

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `authorizenet-accounts` (resource, except `show`) | GET/POST/PUT/DELETE | account fields from AC-1 | rows with `has_transaction_key`, `has_signature_key` flags, never the secrets | `role:admin` | 422 |
| `authorizenet-accounts/{account}/activate`, `/deactivate`, `test-connection` (stored), `authorizenet-accounts/test-connection` (unsaved keys) | PATCH/POST | credentials for the unsaved form | success or failure message, USD warning | `role:admin` | 404, 422 |
| `pay/{payment}` (existing route, new branch `showAuthorizenet`) | GET | payment UUID | Inertia page with brand data and environment form host (no token) | public (UUID) | 404, redirect to Unavailable when not payable |
| `pay/{payment}/authorizenet/token` | POST | none (payment from route) | JSON `{token, form_url}` | public (UUID), limiter `authorizenet-token` | 422 not payable, 429, 502 gateway failure |
| `pay/{payment}/authorizenet/confirm` | POST | `trans_id` | JSON `{outcome: approved\|held\|declined\|unknown, message}` fixed PayHub message | public (UUID), limiter `authorizenet-confirm` | 422 bad id, 429 |
| `pay/{payment}/authorizenet/status` | GET | payment UUID | JSON `{status}` | public (UUID) | 404 |
| `pay/authorizenet/communicator` | GET | none | tiny HTML page plus script file that relays form messages to the pay page, `frame-ancestors` allows only the Authorize.net form hosts | public, static | none |
| `webhook/authorizenet/{authorizenetAccount}` | POST | raw body, `X-ANET-Signature` header | 200 after queuing | signature, CSRF excluded | 401/403 signature mismatch or none set, 404 unknown account |

**Value sourcing**:

| Action | Value produced / displayed | Source |
|---|---|---|
| Token | API endpoint host | `authorizenet_accounts.environment` mapped through `config('services.authorizenet.hosts')` (api, form) |
| Token | amount sent | `Payment.amount` (integer cents) converted to the decimal string with integer math (`sprintf('%d.%02d')`, no floats) |
| Token | `order.invoiceNumber` | the payment's id zero padded to 6 digits (also `refId`, within the 20 character limit) |
| Token | customer email and name | `Payment.client_email`, `Payment.client_name` (name split at the first space into first and last name, last name falls back to the first) |
| Token | `hostedPaymentIFrameCommunicatorUrl` | absolute HTTPS URL of route `pay.authorizenet.communicator` on this app's domain |
| Token | pay button label | fixed string with `Payment.amount` formatted |
| Pay page | form post URL | environment host from config, never request |
| Confirm | which payment | route `{payment}`; the lookup's `invoiceNumber` must equal it and the payment's account must be the account used for the lookup |
| Confirm | account credentials for the lookup | the payment's `authorizenet_account_id` |
| Confirm | amount and invoice to compare | `Payment`, never the request (the request carries only `trans_id`) |
| Webhook | which payment to resolve | `payload.id` is the `transId`; the job calls `getTransactionDetails` and reads `order.invoiceNumber`, then loads the payment and checks it belongs to the route's account |
| Webhook | idempotency key | body `notificationId` |
| Status poll | status shown | `Payment.status`, read only |
| Sweep | which payments | `provider = authorizenet`, `status = pending`, `authorizenet_transaction_id` not null |

**Key invariants**:
- Only a `getTransactionDetails` result read by PayHub's server ever sets `Payment.status = completed`. The browser message and the webhook body never do.
- `completed` is terminal. A held transaction never completes or fails the payment until Authorize.net decides it.
- Approved requires response code 1, a captured or settled status, a matching invoice number and an exact amount match; anything less is unknown.
- The webhook job, confirm endpoint and sweep only read Authorize.net. They never create a charge.
- The token is used once on the page and is never stored, logged or returned a second time.
- An Authorize.net account is single currency (`currency`, USD).
- `transaction_key` and `signature_key` are encrypted columns, not mass assignable, and never logged or sent to the browser.
- Amounts are integer minor units everywhere in PayHub; the decimal string exists only in the gateway request.
- Any gateway outcome PayHub does not recognize is logged, treated as unknown, and never allowed to fail an insert or update.

**Security model**:
- Account management is `role:admin` only (and the `account` role has no access, matching the other provider account pages). Pay, token, confirm and status routes are public but scoped by the unguessable payment UUID. The webhook route is protected by the HMAC signature.
- Compliance scope is PCI DSS. The hosted form keeps card data inside Authorize.net's frame, so the target is the lightest self assessment (SAQ A, the level Authorize.net documents for the hosted form). The assessor or acquiring bank makes the final call.
- Clickjacking: `X-Frame-Options: DENY` stays everywhere except the communicator route, which instead sends a CSP `frame-ancestors` that allows only the Authorize.net form hosts.
- CSP widening (`frame-src`, `form-action`) is limited to the Authorize.net pay page and names only the account's form host.
- Raw gateway response text and codes go to the logs for operators only. Clients always see fixed PayHub strings.
- Audit trail: the stored `authorizenet_transaction_id` on the payment plus the activity of the shared resolver are the durable record; a duplicate paid attempt is logged with both transaction ids.

**Configuration required**:
- `config/services.php` `authorizenet.hosts`: a map of environment to API host and form host (sandbox `apitest.authorize.net` and `test.authorize.net`, production `api.authorize.net` and `accept.authorize.net`). Constants, not secrets, so no new env vars.
- `AUTHORIZENET_TOKEN_LIFETIME_MINUTES`: optional, how long the page treats a token as valid before refreshing (default 14, the documented lifetime is 15).
- Prerequisites outside the code: a sandbox account and a production account, each with an API login ID, a transaction key and a signature key from Merchant Interface (Account > Settings > Security Settings > General Security Settings > API Credentials and Keys); the PayHub webhook URL added by the admin in the Merchant Interface (Account > Webhooks) with the four events in AC-8; the hosted payment form fields configured in the Merchant Interface (the API does not enforce "required" fields).

**Open questions** (unverified; the sandbox spike, AC-12, must confirm each):
1. Whether `getMerchantDetails` returns the account's currencies in the shape the warning in AC-1 expects, and whether it is a good credential check.
2. The exact `hostedPaymentSettings` setting names and JSON that give: receipt off, card only, no billing address, no email or customer fields shown, no saved profile, and whether passing name and email through `billTo` and `customer` without showing them is accepted.
3. The `X-ANET-Signature` header format (a `sha512=` prefix, letter case of the hex) and that the HMAC is over the raw body.
4. The `getTransactionDetails` fields to read: response code, transaction status values for captured, settled, held and declined, `order.invoiceNumber`, the amount field, and whether a currency field exists.
5. Whether `refId` and `order.invoiceNumber` from the token request come back on the transaction and in the webhook as `merchantReferenceId`.
6. The communicator message format today (string versus object), the exact `AuthorizeNetIFrame.onReceiveCommunication` hook, and whether `transId` is in the transaction response.
7. Whether the communicator route needs `frame-ancestors` for both form hosts and whether anything else (cookies, `SameSite`) blocks the relay.
8. Whether a second successful payment on one token is possible, and Authorize.net's duplicate transaction window behavior for a retry after decline.
9. The delay between the transaction and the webhook, and whether declines produce any event (the plan assumes they do not).
10. The CSP hosts needed beyond the two form hosts, and whether the sandbox form host is `test.authorize.net`.

**Critical test scenarios** (all gateway calls use `Http::fake`; the real gateway is covered only by the spike):
- Happy path: admin creates an account, agent creates a USD link, client opens the page, the token is created, the confirm lookup is approved with a matching invoice and amount, payment is `completed`, `paid_at` and `authorizenet_transaction_id` set, one notification dispatched. Verifies **AC-1, AC-2, AC-4, AC-6, AC-7**.
- A GBP payment against an Authorize.net account is rejected with the currency message. Verifies **AC-2**.
- Token precondition guard: a `completed` payment, an inactive account, a non Authorize.net payment: 422 with no gateway call. Verifies **AC-4**.
- Token request body: amount from the record, invoice number, no card data, secret never in the response beyond the token. Verifies **AC-4, AC-10**.
- Amount mismatch, wrong invoice or a lookup from another account is classified unknown, never approved. Verifies **AC-6**.
- Held (response code 4) stores the transaction id, leaves the payment `pending`, shows the processing message. Verifies **AC-7, AC-9**.
- `fraud.approved` webhook after a held result completes once; `fraud.declined` sets `failed`; a repeat of either changes nothing. Verifies **AC-8, AC-7**.
- Webhook: wrong signature or no stored signature key gets 401 or 403 and changes nothing; correct signature queues a job that reads the transaction; the same `notificationId` twice completes once; a body claiming success for a transaction Authorize.net reports as declined changes nothing. Verifies **AC-8**.
- Duplicate paid: a second approved transaction with another id on a `completed` payment changes nothing and is logged. Verifies **AC-7**.
- Terminal guard: a `completed` payment is never changed by a later decline. Verifies **AC-7**.
- Sweep: a `pending` payment with a transaction id is re-read and resolved; one without a transaction id is untouched. Verifies **AC-11**.
- Rate limits: the 21st token or confirm request for one payment in an hour gets 429 with no gateway call. Verifies **AC-10**.
- CSP and headers: the Authorize.net pay page widens `frame-src` and `form-action` to its form host; the Stripe, MPGS and Clover pay pages and every other route keep the strict policy; the communicator route drops `X-Frame-Options` and sets `frame-ancestors`. Verifies **AC-5**.
- Secrets: serialized account props never contain the transaction key or signature key; logs contain no token. Verifies **AC-1, AC-10**.
- Auth/permission: a non admin hitting any `authorizenet-accounts.*` route gets 403. Verifies **AC-1**.

## Build plan

No build approach is recorded in `AGENTS.md` or a `docs/scope/` header (this project runs its own planning workflow), so this plan defaults to end to end Tracer Bullet slices and states that assumption. The first slice proves the riskiest thread (token, iframe, communicator, CSP, lookup) before any parity work is spent on it.

0. **Sandbox spike (gate).** With a real sandbox merchant, prove in a throwaway script and a throwaway page: `getMerchantDetails`, `getHostedPaymentPageRequest` with the settings, the iframe post, the communicator relay, a card payment, `getTransactionDetails`, a held transaction, and a webhook delivery with its signature. Write every Open question answer into `## Follow-up`, and amend this spec through `/architect` where an answer changes it. Satisfies **AC-12**.
1. Migrations: `authorizenet_accounts`, `payments` additions and provider enum widening, `processed_authorizenet_events`. Models (`AuthorizenetAccount` with encrypted casts and guarded keys, `ProcessedAuthorizenetEvent`), factories, `AuthorizenetEnvironment` enum, `PaymentProvider::Authorizenet`, `Payment` fillable and relation, `ProviderAccountTable`, `CurrencySupportResolver` (`authorizenet` supports only the account currency, USD), the `config/services.php` hosts map. Satisfies **AC-1, AC-2, AC-10**.
2. `App\Services\Authorizenet\AuthorizenetClient` built per account through `app()->make()` over the Laravel Http client: `getHostedPaymentPage`, `getTransactionDetails`, `getMerchantDetails`, host from environment, no `->throw()` on lookups. Plus `AuthorizenetChargeClassifier` (pure, unit tested) and `AuthorizenetChargeResolver` (the one place guarded status writes happen), and the amount conversion helper. Satisfies **AC-6, AC-7**.
3. Admin slice: `StoreAuthorizenetAccountRequest`, `UpdateAuthorizenetAccountRequest`, `Admin\AuthorizenetAccountController` with activate, deactivate, both test connection routes, routes, and the admin Vue pages (webhook URL shown for copying; secret fields write only). Satisfies **AC-1**.
4. Thin end to end pay thread: `ClientPaymentController::showAuthorizenet`, `AuthorizenetPaymentController::token` and `::confirm` and `::status`, the communicator route plus `public/authorizenet-communicator.js`, `PayAuthorizenet.vue` and `AuthorizenetPaymentForm.vue` (requests the token, posts it into the iframe, defines `AuthorizeNetIFrame.onReceiveCommunication`, resizes, refreshes on expiry, sends `trans_id` to confirm), named limiters `authorizenet-token` and `authorizenet-confirm` in `AppServiceProvider`, and the Authorize.net only CSP widening and communicator header exception in `SecurityHeaders`. Satisfies **AC-4, AC-5, AC-6, AC-7, AC-10**.
5. Client states: processing poll with a 2 minute limit, held message, cancel handling, fixed messages, Success redirect. Satisfies **AC-9**.
6. Webhook: `AuthorizenetWebhookController`, `HandleAuthorizenetWebhookJob`, route outside CSRF (add `webhook/authorizenet/*` to the exclusion list in `bootstrap/app.php`), signature check, idempotency table use. Satisfies **AC-8**.
7. Sweep: scheduled command in `routes/console.php` (every 10 minutes, `withoutOverlapping`) that re-reads held payments through the resolver. Satisfies **AC-11**.
8. Parity sweep (use the `provider-parity-reviewer` agent): `StorePaymentRequest`, `UpdatePaymentRequest`, `PaymentController`, `Admin\UserController` and user requests, `PaymentsExport`, `DashboardController`, `DashboardMetrics`, `DashboardFilters.vue`, `PaymentProviderBadge.vue`, `AppSidebar.vue`, payments Create, Edit, Index, Show pages, `PaymentLayout.vue`, users Create and Edit pages, Success, Failed, Unavailable pages, `PaymentFactory`. Satisfies **AC-2, AC-3**.
9. Pest coverage for every Critical test scenario, plus unit tests for the classifier, signature check and amount conversion. Satisfies **AC-1 through AC-11**.
10. Close out: update `AGENTS.md` and `docs/agent.md` (via `/sync`) for the seventh provider and the scoped server to server exception. Satisfies **AC-7** (the rule it relaxes is documented).

## Consequences

**Positive**:
- Clients get the same on page experience as the other providers, with card data staying inside Authorize.net's frame (lightest PCI scope).
- No attempts table and no card capture code, so the build is smaller than MPGS.
- A webhook, a confirm lookup and a sweep give three independent ways to recover from a lost browser message.
- One shared resolver keeps all status writes in one guarded place.

**Negative / tradeoffs**:
- The iframe result callback is known to be fragile in practice (message format changes, cookie and cross frame blocks). The design survives it because the webhook and the polling state do the real work, but a lost callback means the client waits for the webhook.
- Declines are not recorded by PayHub at all (no webhook for a plain decline). Operators see them only in the Authorize.net Merchant Interface.
- A payment with no transaction id (callback lost and webhook never delivered) is not found by the sweep. Revisit with a transaction list reconciliation if it happens.
- Authorize.net fields the form shows (required fields, address, AVS) are configured in the Merchant Interface, not enforced by the API, so two environments can drift.
- Widening `frame-src` and `form-action` lowers CSP strength on this one pay page, and the communicator route allows framing by a third party.
- Several field names rest on documentation read through summaries. The spike may force edits to this spec.
- Paid status for Authorize.net comes from a server to server lookup, a third exception to the "webhooks only" rule stated in `AGENTS.md`.

**Neutral**:
- USD only per account; a different currency needs a separate Authorize.net account.
- Refunds and voids are out of scope (PayHub has no refund feature today). A duplicate paid transaction is only logged.
- The provider list in `AGENTS.md` still names six providers; this makes seven.
- Migrations are additive and can layer on `master` normally.

## Follow-up

- [ ] Run the sandbox spike (Build plan step 0) and record the answer to each Open question here.
- [ ] Decide after the spike whether to add a transaction list reconciliation for payments with no transaction id.
- [ ] Update `AGENTS.md` and `docs/agent.md` through `/sync` when this ships (seventh provider, scoped exception).
- [ ] The `provider-parity-reviewer` agent and `provider-parity-check` skill text still say four providers; refresh them to cover all seven.
- [ ] Rate limits (20 token and 20 confirm requests per payment per hour), the 14 minute token window and the 10 minute sweep are starting defaults, not reviewed figures.
- [ ] Refunds and voids stay out of scope; revisit only if PayHub adds a refund feature for any provider.
- [ ] This branch (`feat/mpgs-hosted-session`) is 2 commits behind `master`; start the build on a new branch from the current `master`.
