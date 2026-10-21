# 0006. Add Authorize.net (Accept Hosted iframe) as a payment provider

**Date**: 2026-10-21
**Status**: In Progress

## Summary

This spec adds Authorize.net as the 7th PayHub payment provider. The client pays inside Authorize.net's own hosted card form, shown in an iframe (a page inside a page) on PayHub's brand pay page, so card details never touch PayHub's server or page code. PayHub only marks a payment paid after its own server reads the transaction from Authorize.net and the amount, invoice and status all match. The sandbox spike showed that the iframe's message back to our page (the communicator callback) never fires, so PayHub does not wait for the browser to tell it anything. Instead the pay page polls PayHub's own status endpoint, and that endpoint finds the transaction itself by asking Authorize.net for it by the payment's invoice number. The webhook and a scheduled sweep are two more independent ways to find the same transaction. Each account is USD only, like Square's currency lock.

## Requirements

**User stories**:
- As an admin, I want to create and manage an Authorize.net account (API login ID, transaction key, signature key, sandbox or production) the same way I manage every other provider account.
- As an agent, I want to create a USD payment link against an Authorize.net account exactly like any other provider.
- As a client, I want to type my card into the form on the brand page and see a clear result without leaving the page, and retry another card if mine is declined.
- As the business, I want a payment marked paid only from a result PayHub itself read from Authorize.net's servers, never from anything the browser says, and I want a payment held for fraud review to stay pending until it is decided.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: An admin can create, edit, activate, deactivate and test the connection of an Authorize.net account (account name, prefix, API login ID, transaction key, signature key, environment of `sandbox` or `production`), restricted to `role:admin`. The transaction key and signature key are encrypted at rest, not mass assignable, and never returned to the browser (only `has_*` flags). Test connection makes one authenticated `getMerchantDetails` call with the stored credentials, reports success or failure, and warns when the response's `currencies` list (a plain array of upper case strings, confirmed in the spike) does not include `USD`. It never touches a payment. Two active accounts cannot share one `api_login_id` (the create and update requests reject it), because the invoice number lookup must not see another account's transactions.
- **AC-2**: An agent or admin can create a payment link against an active Authorize.net account only in USD. `authorizenet_accounts.currency` is stored lower case (`usd`, matching `SupportedCurrency`) and defaults to `usd`. `StorePaymentRequest` and `UpdatePaymentRequest` reject a payment whose currency differs from the account's, with `"This Authorize.net account only accepts {currency} payments."`, and `CurrencySupportResolver` treats `authorizenet` like Square (the account's own currency). The gateway request itself carries only the decimal amount, because Authorize.net has no currency field on a transaction (confirmed in the spike): the account decides the currency.
- **AC-3**: Authorize.net appears everywhere the other providers already do: payment create and edit forms, agent account assignment (`user_payment_accounts`), the `ProviderAccountTable` and `CurrencySupportResolver` maps, CSV export, the dashboard account filters and "Accounts Today", the provider badge, the sidebar, and the client facing Success, Failed and Unavailable pages. No surface is left behind (grep for the other providers' `match`, ternary and `in_array` lists).
- **AC-4**: A token endpoint creates a hosted payment page token on the server with `getHostedPaymentPageRequest` using the account's credentials. The amount comes from the `Payment` record, never the request. It sends `transactionType` `authCaptureTransaction`, `refId` and `order.invoiceNumber` both set to the payment's gateway invoice number (the 6 digit zero padded payment id followed by the first 8 hex characters of the payment's UUID, for example `0001239f3a1c2b`, 20 characters at most, unique per payment and per PayHub install), the client's email and name (a single word name is sent as both first and last name; first and last name are cut to 50 characters, the email to 255), and `hostedPaymentSettings` for the iframe (receipt off, card only, no billing address shown, no saved card profile, a pay button label, and the communicator URL, which must start with `https://` or the gateway returns error `E00013`). It returns only the token and the form post URL. It rejects with 422 unless the payment's provider is Authorize.net, its status is `pending`, it holds no `authorizenet_transaction_id` yet (a held or approved transaction exists, so a second form could double charge), and its account exists and is active. On success it sets `authorizenet_token_issued_at` (only if empty) and `authorizenet_token_last_issued_at` (every time). On a gateway failure it returns 502 and the page shows a fixed message with a Retry button, never an automatic retry loop. The token is never stored, logged, or placed in a URL.
- **AC-5**: The pay page loads the token when it opens, then posts it from a hidden form into a named iframe (`https://accept.authorize.net/payment/payment`, sandbox `https://test.authorize.net/payment/payment`). A PayHub route serves a small communicator page on the same HTTPS domain, as a best effort extra only (the sandbox never loaded it): if the form ever relays a resize or cancel message, the pay page resizes the iframe or returns to the unpaid state, and if it relays a transaction response with a `transId`, the page passes it to the status endpoint as an optional query parameter hint. The page never depends on any message. The token is refreshed only on the next focus or tab visibility after the token window has passed, so a client who is typing never loses the form. The CSP (browser rule list) is widened for `frame-src` and `form-action` only on the pay page of an Authorize.net payment, and the communicator route is the one route that allows framing by Authorize.net.
- **AC-6**: PayHub's status endpoint (the one the pay page polls) resolves a `pending` Authorize.net payment on the server. A lookup runs only when the payment is `pending`, a token was issued in the last 60 minutes, fewer than 120 lookups ran for this payment in the last hour, and a cache key `authorizenet:lookup:{payment id}` could be added (`Cache::add`, never released, 5 seconds for the first 15 minutes after the token and 60 seconds after that, so a caller who loses simply returns the current state). Per account, no more than one list call per second (`Cache::add` on `authorizenet:account:{id}`). The lookup asks Authorize.net for the payment's invoice number with `getUnsettledTransactionListRequest` (`sorting` `submitTimeUTC` descending, `paging` limit 100 and a 1 based offset). It stops when a page has fewer than 100 rows, when the last row is older than `authorizenet_token_issued_at` minus 5 minutes, or after 5 pages; a response with no `transactions` key means zero rows. It collects every row whose `invoiceNumber` equals the payment's invoice number and whose `submitTimeUTC` is not earlier than the first token time minus 5 minutes, skips rows the list already shows as declined, and calls `getTransactionDetails` for the others. If the browser passed a `transId` hint, the list is skipped and that one transaction is read directly, then checked the same way. Each transaction is classified into exactly one bucket: **approved** (`responseCode` `1`, a `transactionStatus` of `capturedPendingSettlement` or `settledSuccessfully`, `order.invoiceNumber` equals this payment's invoice number, and `authAmount` equals `Payment.amount` compared in integer cents, never as floats), **held** (`transactionStatus` `FDSPendingReview`, `FDSAuthorizedPendingReview`, `underReview` or `authorizedPendingRelease`, or `responseCode` 4), **declined** (`responseCode` 2, or status `declined` or `failedReview`), or **unknown** (`responseCode` 3, any other status such as `voided`, `expired`, `communicationError`, `generalError`, `approvedReview` or `authorizedPendingCapture`, a timeout, a 5xx, or anything unclassifiable). When one invoice has several transactions, approved wins over held, the earliest approved completes the payment and the rest are logged under the duplicate rule in AC-7. A match with the wrong amount, or from an account other than the payment's, is unknown and is reported once per transaction (error level log, which reaches Sentry) and then skipped in later lookups. The endpoint returns the payment status plus a state word, never the gateway's text (AC-9). If the list call or a details call fails, the endpoint returns the current state, logs it, and the next poll tries again.
- **AC-7**: All status writes go through one class, `AuthorizenetChargeResolver`, using the same guards as the other providers: `whereIn('status', ['pending','failed'])` for completion and `where('status','pending')` for a decline. A given transaction moves a payment to `completed` at most once. An approved result sets `paid_at` and `authorizenet_transaction_id` in the same guarded update and dispatches `SendPaymentNotification` only when a row was updated. A held result stores `authorizenet_transaction_id` but leaves the status `pending`. A second approved transaction with a different id for an already `completed` payment changes nothing and is logged at error level (so it reaches Sentry and operators see it, since refunds are out of scope). An approved transaction for a `cancelled` payment (an agent cancelled it while the client was paying) is not applied and is logged at error level the same way.
- **AC-8**: The webhook route `webhook/authorizenet/{authorizenetAccount}` is excluded from CSRF, reads the raw body, verifies the `X-ANET-Signature` header (HMAC-SHA512 of the raw body with the account's signature key, compared with `hash_equals`; an account with no signature key rejects everything), and only queues a job. The job reads the transaction through `getTransactionDetails` and applies it through the shared resolver; it never trusts the webhook body. Subscribed events are `net.authorize.payment.authcapture.created`, `net.authorize.payment.fraud.approved`, `net.authorize.payment.fraud.declined` and `net.authorize.payment.fraud.held`. Duplicate deliveries are dropped through `processed_authorizenet_events` (keyed on `notificationId`). A held payment later settled by `fraud.approved` completes, and by `fraud.declined` becomes `failed`. If the transaction's invoice number resolves to no payment, or to a soft deleted one, or to a payment of a different account, the job logs it and drops it.
- **AC-9**: The client experience works with no callback from the form. Once the token exists, the pay page polls the status endpoint every 3 seconds (pausing while the browser tab is hidden). The endpoint returns `{status, state}` with `state` one of `waiting`, `held` (a pending payment that holds an `authorizenet_transaction_id`), `completed`, `failed` or `unavailable`, and PayHub shows a fixed message for each. When the state becomes `completed`, the page navigates (`router.visit`) to the existing success route, which renders the normal Success page, even though the form was still showing "Processing...". A fraud declined payment (`failed`) opens the Failed page, not the form, because the decline is final. A plain decline stays inside Authorize.net's form where the client retries (PayHub sees no event for it). A `held` state shows a processing message. The give up timer runs on the page, starts at page load, pauses while the tab is hidden and restarts on reload: after 10 minutes of visible waiting the page stops polling and shows "still confirming, you will get a receipt by email", and the sweep and webhook keep trying. When a client returns to the pay link, `ClientPaymentController::show` runs one lookup for a pending payment first, and redirects to the success route if it has completed, so a returning client sees the right page at once.
- **AC-10**: Security rules hold: amount, currency, account and payment are resolved server side; the transaction key and signature key are encrypted and never exposed; the token endpoint has a named rate limiter keyed on the payment UUID, and the public status endpoint is bounded by the lookup rules in AC-6 (payment state, token age, 120 lookups per hour, a 5 second key, and one list call per second per account); raw gateway text is stored for operators only; no secret, token or key appears in logs. Once `authorizenet_token_issued_at` is set, `UpdatePaymentRequest` rejects a change to the amount, currency or account of an Authorize.net payment, because the client may already be paying the old values.
- **AC-11**: A scheduled sweep every 10 minutes re-reads `pending` Authorize.net payments whose `authorizenet_token_last_issued_at` is within the last 24 hours. For each active account that has such payments it runs one paged list pass (same paging rules as AC-6) and matches every candidate invoice number in that one pass. A candidate that already holds an `authorizenet_transaction_id` is read directly with `getTransactionDetails` and needs no list. Each account is wrapped on its own, so one bad account never stops the others. A pending payment whose token is older than 24 hours with no transaction id is left alone and logged for operators.
- **AC-12**: A sandbox spike confirms or corrects each item in this spec's **Open questions**. Status: partly done on 2026-10-21 (Q1, Q2 in part, Q4, Q5 in part, the communicator failure and the `https` rule are answered, see `## Follow-up`). The webhook questions (Q3, Q9) and the rest are waived for now by the engineer's decision; the webhook is built from documentation only and is verified by the first live sandbox run in its build step, with any fixes landing in that step.

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
  - `signature_key` text nullable, `encrypted` cast, NOT mass assignable (null means the webhook route rejects everything and PayHub relies on the status lookup and the sweep)
  - `environment` string, `sandbox` or `production` (a plain string with a PHP enum cast `AuthorizenetEnvironment`, not a DB enum)
  - `currency` string(3) default `usd` (lower case, like the app's other currency values)
  - `is_active` boolean default true, timestamps
- `payments` (existing, additive migration)
  - Adds `authorizenet_account_id` FK nullable (cascade on delete, like the others), `authorizenet_transaction_id` string nullable, indexed, and `authorizenet_token_issued_at` and `authorizenet_token_last_issued_at` timestamps nullable (first and latest token issue, used by the paging stop, the sweep window, the lookup guard and the edit lock)
  - The `provider` enum gains `authorizenet`
- `processed_authorizenet_events` (new): `id`, `event_key` string unique (the webhook `notificationId`), timestamps
- `user_payment_accounts` (existing): `provider` is a plain string, so `authorizenet` needs only the enum cast and the `ProviderAccountTable` map

Relationships: `authorizenet_accounts` 1:N `payments` · agents reach an account through `user_payment_accounts` (`provider = authorizenet`, `account_id`).

No attempts table: Authorize.net keeps the retry loop inside its own form with one token, so PayHub records only the final transaction id on the payment.

**State transitions**:

Payment: `pending` → `completed` (approved), `pending` → `failed` (fraud declined only), `pending` stays `pending` while held, after a plain decline inside the form, or while unknown. `completed` is terminal. A held payment is `pending` with an `authorizenet_transaction_id` set; the status endpoint reports it as the `held` state. A plain decline never sets `failed`, because the client can retry in the same form and a failed state would only mislead.

**Classification** (applied to the status endpoint lookup, the webhook job lookup and the sweep; field names confirmed in the 2026-10-21 spike, see `## Follow-up`):

| Bucket | Condition | Effect |
|---|---|---|
| **Approved** | `responseCode` 1 AND `transactionStatus` `capturedPendingSettlement` or `settledSuccessfully` AND `order.invoiceNumber` matches this payment AND `authAmount` converted to cents equals `Payment.amount` | `completed` (AC-7) |
| **Held** | `responseCode` 4, or `transactionStatus` `FDSPendingReview`, `FDSAuthorizedPendingReview`, `underReview` or `authorizedPendingRelease` | store transaction id, status stays `pending` (state `held`) |
| **Declined** | response code 2 (or fraud declined event) | `failed` only for a fraud decline from a held payment, otherwise no status change |
| **Unknown** | `responseCode` 3, any other status, transport failure, timeout, 5xx, anything not matching above, or success with a wrong amount, invoice or account | no change, logged (once per transaction), sweep or webhook resolves it |

`AuthorizenetClient::getTransactionDetails()` and `getUnsettledTransactionList()` must not `->throw()` on 4xx or 5xx. They return status and body so the caller classifies. Amounts from the gateway are JSON floats (`12.34`): convert with `(int) round($value * 100)` after casting through a string, then compare as integer cents. When one invoice number matches several transactions (a client who pays twice), only the first approved one completes the payment and the rest are logged under the duplicate rule in AC-7.

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `authorizenet-accounts` (resource, except `show`) | GET/POST/PUT/DELETE | account fields from AC-1 | rows with `has_transaction_key`, `has_signature_key` flags, never the secrets | `role:admin` | 422 |
| `authorizenet-accounts/{account}/activate`, `/deactivate`, `test-connection` (stored), `authorizenet-accounts/test-connection` (unsaved keys) | PATCH/POST | credentials for the unsaved form | success or failure message, USD warning | `role:admin` | 404, 422 |
| `pay/{payment}` (existing route, new branch `showAuthorizenet`) | GET | payment UUID | Inertia page with brand data and environment form host (no token) | public (UUID) | 404, redirect to Unavailable when not payable |
| `pay/{payment}/authorizenet/token` | POST | none (payment from route) | JSON `{token, form_url}` | public (UUID), limiter `authorizenet-token` | 422 not payable or a transaction already exists, 429, 502 gateway failure |
| `pay/{payment}/authorizenet/status` | GET | payment UUID, optional `trans_id` hint | JSON `{status, state}` after a guarded server side lookup by invoice number (AC-6) | public (UUID), the AC-6 lookup guards | 404 |
| `pay/authorizenet/communicator` | GET | none | tiny HTML page plus script file that relays form messages to the pay page (best effort, the sandbox never loaded it), HTTPS only, `frame-ancestors` allows only the Authorize.net form hosts | public, static | none |
| `webhook/authorizenet/{authorizenetAccount}` | POST | raw body, `X-ANET-Signature` header | 200 after queuing | signature, CSRF excluded | 401/403 signature mismatch or none set, 404 unknown account |

**Value sourcing**:

| Action | Value produced / displayed | Source |
|---|---|---|
| Token | API endpoint host | `authorizenet_accounts.environment` mapped through `config('services.authorizenet.hosts')` (api, form) |
| Token | amount sent | `Payment.amount` (integer cents) converted to the decimal string with integer math (`sprintf('%d.%02d')`, no floats) |
| Token | `order.invoiceNumber` and `refId` | the payment's id zero padded to 6 digits plus the first 8 hex characters of `Payment.uuid` (20 characters at most), built by one helper that the lookup also uses |
| Token | customer email and name | `Payment.client_email`, `Payment.client_name` (name split at the first space into first and last name; a single word is used for both; first and last name cut to 50 characters, email to 255) |
| Token | `hostedPaymentIFrameCommunicatorUrl` | absolute HTTPS URL of route `pay.authorizenet.communicator` on this app's domain |
| Token | pay button label | fixed string with `Payment.amount` formatted |
| Pay page | form post URL | environment host from config, never request |
| Status | which payment | route `{payment}`; the lookup's `invoiceNumber` must equal the payment's invoice number and the payment's account must be the account used for the lookup |
| Status | account credentials for the lookup | the payment's `authorizenet_account_id` |
| Status | amount and invoice to compare | `Payment`, never the request (the optional request carries only `trans_id`, which is checked against the invoice number) |
| Status | which transaction | `getUnsettledTransactionListRequest` rows (descending by `submitTimeUTC`, 100 per page, 5 pages at most) whose `invoiceNumber` equals the payment's invoice number and whose time is not before `authorizenet_token_issued_at` minus 5 minutes, then `getTransactionDetails` on each `transId` |
| Webhook | which payment to resolve | `payload.id` is the `transId`; the job calls `getTransactionDetails` and reads `order.invoiceNumber`, then loads the payment and checks it belongs to the route's account |
| Webhook | idempotency key | body `notificationId` |
| Status poll | status and state shown | `Payment.status`, and `held` when `authorizenet_transaction_id` is set on a pending payment, read only |
| Sweep | which payments | `provider = authorizenet`, `status = pending`, `authorizenet_token_last_issued_at` in the last 24 hours (with or without an `authorizenet_transaction_id`), matched by invoice number |

**Key invariants**:
- Only a `getTransactionDetails` result read by PayHub's server ever sets `Payment.status = completed`. The browser message and the webhook body never do.
- `completed` is terminal. A held transaction never completes or fails the payment until Authorize.net decides it.
- Approved requires response code 1, a captured or settled status, a matching invoice number and an exact amount match; anything less is unknown.
- The status endpoint, webhook job and sweep only read Authorize.net. They never create a charge. A public status poll never makes more than one gateway lookup per payment per 5 seconds, and never starts before a token exists.
- A payment is marked paid only when the transaction's invoice number equals that payment's own invoice number (unique per payment and install), its amount matches in cents, and its time is not before the payment's first token.
- The token is used once on the page and is never stored, logged or returned a second time.
- An Authorize.net account is single currency (`currency`, `usd`).
- `transaction_key` and `signature_key` are encrypted columns, not mass assignable, and never logged or sent to the browser.
- Amounts are integer minor units everywhere in PayHub; the decimal string exists only in the gateway request.
- Any gateway outcome PayHub does not recognize is logged, treated as unknown, and never allowed to fail an insert or update.

**Security model**:
- Account management is `role:admin` only (and the `account` role has no access, matching the other provider account pages). Pay, token and status routes are public but scoped by the unguessable payment UUID. The webhook route is protected by the HMAC signature.
- Compliance scope is PCI DSS. The hosted form keeps card data inside Authorize.net's frame, so the target is the lightest self assessment (SAQ A, the level Authorize.net documents for the hosted form). The assessor or acquiring bank makes the final call.
- Clickjacking: `X-Frame-Options: DENY` stays everywhere except the communicator route, which instead sends a CSP `frame-ancestors` that allows only the Authorize.net form hosts.
- CSP widening (`frame-src`, `form-action`) is limited to the Authorize.net pay page and names only the account's form host.
- Raw gateway response text and codes go to the logs for operators only. Clients always see fixed PayHub strings.
- Audit trail: the stored `authorizenet_transaction_id` on the payment plus the activity of the shared resolver are the durable record; a duplicate paid attempt is logged with both transaction ids.

**Configuration required**:
- `config/services.php` `authorizenet.hosts`: a map of environment to API host and form host (sandbox `apitest.authorize.net` and `test.authorize.net`, production `api.authorize.net` and `accept.authorize.net`). Constants, not secrets, so no new env vars.
- `AUTHORIZENET_TOKEN_LIFETIME_MINUTES`: optional, how long the page treats a token as valid before refreshing (default 14, the documented lifetime is 15).
- Prerequisites outside the code: a sandbox account and a production account, each with an API login ID, a transaction key and a signature key from Merchant Interface (Account > Settings > Security Settings > General Security Settings > API Credentials and Keys); the PayHub webhook URL added by the admin in the Merchant Interface (Account > Webhooks) with the four events in AC-8; the hosted payment form fields configured in the Merchant Interface (the API does not enforce "required" fields).

**Open questions** (11 and 12 were added after the cross check; status after the 2026-10-21 spike; answers in `## Follow-up`. Answered: 1, 2 in part, 4, 5 in part, 6 and 7 (the communicator never loaded), 10 in part (communicator URL must be `https://`). Still unverified: 3, 8, 9, 11, 12, and whether the form hides address and email fields):
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
11. Whether held (pending review) transactions and declined retries show in the default `getUnsettledTransactionListRequest` result, and the exact status strings for them.
12. Whether `transactionSettings` `duplicateWindow` in the token request can stop a second charge of the same invoice, and what a second try after an approval does.

**Critical test scenarios** (all gateway calls use `Http::fake`; the real gateway is covered only by the spike):
- Happy path: admin creates an account, agent creates a USD link, client opens the page, the token is created, the status endpoint finds the transaction by invoice number and it is approved with a matching invoice and amount, payment is `completed`, `paid_at` and `authorizenet_transaction_id` set, one notification dispatched. Verifies **AC-1, AC-2, AC-4, AC-6, AC-7**.
- A GBP payment against an Authorize.net account is rejected with the currency message. Verifies **AC-2**.
- Token precondition guard: a `completed` payment, an inactive account, a non Authorize.net payment: 422 with no gateway call. Verifies **AC-4**.
- Token request body: amount from the record, invoice number, no card data, secret never in the response beyond the token. Verifies **AC-4, AC-10**.
- Amount mismatch, wrong invoice or a lookup from another account is classified unknown, never approved. Verifies **AC-6**.
- No callback at all: a payment paid at the gateway with no `transId` ever sent by the browser becomes `completed` after the next status poll. Verifies **AC-6, AC-9**.
- Invoice uniqueness: two payments with the same padded id on two installs differ by the UUID part; a transaction whose invoice belongs to another payment, or whose time is before the first token, is never applied. Two active accounts with one `api_login_id` are rejected. Verifies **AC-1, AC-6**.
- Lookup guard: no lookup runs for a non pending payment, with no token, with a token older than 60 minutes, after 120 lookups in an hour, or inside the 5 second key; an account gets at most one list call per second. Paging stops on a short page, an old row, or 5 pages, and a response with no `transactions` key is zero rows. Verifies **AC-6, AC-10**.
- Held then approved: a held transaction sets the `held` state; a later approved one for the same invoice completes the payment. Verifies **AC-6, AC-9**.
- Token guards: a second token request is refused once a transaction id exists; editing amount, currency or account after a token was issued is rejected; a fraud declined payment opens the Failed page. Verifies **AC-4, AC-9, AC-10**.
- Returning client: `show` on a pending payment that completed at the gateway redirects to the success route. Verifies **AC-9**.
- Status throttle: ten status polls inside 5 seconds make one gateway list call (flush the cache between tests). A float amount like `12.34` converts to 1234 cents exactly, and `0.1 + 0.2` style float noise never causes a false mismatch. Verifies **AC-6, AC-10**.
- Two approved transactions for one invoice complete the payment once and log the second. Verifies **AC-6, AC-7**.
- Held (response code 4) stores the transaction id, leaves the payment `pending`, shows the processing message. Verifies **AC-7, AC-9**.
- `fraud.approved` webhook after a held result completes once; `fraud.declined` sets `failed`; a repeat of either changes nothing. Verifies **AC-8, AC-7**.
- Webhook: wrong signature or no stored signature key gets 401 or 403 and changes nothing; correct signature queues a job that reads the transaction; the same `notificationId` twice completes once; a body claiming success for a transaction Authorize.net reports as declined changes nothing. Verifies **AC-8**.
- Duplicate paid: a second approved transaction with another id on a `completed` payment changes nothing and is logged. Verifies **AC-7**.
- Terminal guard: a `completed` payment is never changed by a later decline. Verifies **AC-7**.
- Sweep: a `pending` payment with a transaction id is read directly and resolved; one without a transaction id is matched by invoice number in one list pass per account; one whose token is older than 24 hours is untouched; one account failing does not stop another. Verifies **AC-11**.
- Rate limits: the 21st token request for one payment in an hour gets 429 with no gateway call; status polls are bounded by the lookup guards, not by a named limiter. Verifies **AC-10**.
- CSP and headers: the Authorize.net pay page widens `frame-src` and `form-action` to its form host; the Stripe, MPGS and Clover pay pages and every other route keep the strict policy; the communicator route drops `X-Frame-Options` and sets `frame-ancestors`. Verifies **AC-5**.
- Secrets: serialized account props never contain the transaction key or signature key; logs contain no token. Verifies **AC-1, AC-10**.
- Auth/permission: a non admin hitting any `authorizenet-accounts.*` route gets 403. Verifies **AC-1**.

## Build plan

No build approach is recorded in `AGENTS.md` or a `docs/scope/` header (this project runs its own planning workflow), so this plan defaults to end to end Tracer Bullet slices and states that assumption. The first slice proves the riskiest thread (token, iframe, communicator, CSP, lookup) before any parity work is spent on it.

0. **Sandbox spike (partly done, then waived).** Done on 2026-10-21 with a real sandbox merchant: `getMerchantDetails`, `getHostedPaymentPageRequest` with the settings, the iframe post, card payments, `getTransactionDetails` and `getUnsettledTransactionList`. The communicator relay failed in the sandbox, which drove the redesign of AC-6, AC-9 and AC-11. The webhook delivery and signature were not tested and are verified live in step 6. Answers are in `## Follow-up`. Satisfies **AC-12** (waived for the rest).
1. Migrations (the token timestamps and the unique active `api_login_id` rule were added after the cross check and need a follow up migration and a request change on top of the committed step 1 and step 3): `authorizenet_accounts`, `payments` additions and provider enum widening, `processed_authorizenet_events`. Models (`AuthorizenetAccount` with encrypted casts and guarded keys, `ProcessedAuthorizenetEvent`), factories, `AuthorizenetEnvironment` enum, `PaymentProvider::Authorizenet`, `Payment` fillable and relation, `ProviderAccountTable`, `CurrencySupportResolver` (`authorizenet` supports only the account currency, `usd`), the `config/services.php` hosts map. Satisfies **AC-1, AC-2, AC-10**.
2. `App\Services\Authorizenet\AuthorizenetClient` built per account through `app()->make()` over the Laravel Http client: `getHostedPaymentPage`, `getTransactionDetails`, `getUnsettledTransactionList`, `getMerchantDetails`, host from environment, no `->throw()` on lookups. Plus the UTF-8 BOM stripping on every response, with a test, `AuthorizenetChargeClassifier` (pure, unit tested, amounts compared in cents), `AuthorizenetInvoice` (builds the invoice number from the payment) and `AuthorizenetInvoiceLookup` (paged list pass and matching, shared by the status endpoint, the sweep and the webhook, with the lookup guards), `AuthorizenetChargeResolver` (the one place guarded status writes happen), and the amount conversion helpers (cents to decimal string, gateway float to cents). Satisfies **AC-6, AC-7**.
3. Admin slice: `StoreAuthorizenetAccountRequest`, `UpdateAuthorizenetAccountRequest`, `Admin\AuthorizenetAccountController` with activate, deactivate, both test connection routes, routes, and the admin Vue pages (webhook URL shown for copying; secret fields write only). Satisfies **AC-1**.
4. Thin end to end pay thread: `ClientPaymentController::showAuthorizenet`, `AuthorizenetPaymentController::token` and `::status` (guarded lookup by invoice number, optional `trans_id` hint), the communicator route plus `public/authorizenet-communicator.js` (best effort), `PayAuthorizenet.vue` and `AuthorizenetPaymentForm.vue` (requests the token, posts it into the iframe, starts polling status right away, defines `AuthorizeNetIFrame.onReceiveCommunication` as an optional hint, refreshes the token on the next focus after expiry), the named limiter `authorizenet-token` in `AppServiceProvider`, and the Authorize.net only CSP widening and communicator header exception in `SecurityHeaders`. Satisfies **AC-4, AC-5, AC-6, AC-7, AC-10**.
5. Client states: polling every 3 seconds while the page is visible, the 10 minute give up message, held message, replacing the iframe with Success when the status turns `completed`, fixed messages. Satisfies **AC-9**.
6. Webhook: `AuthorizenetWebhookController`, `HandleAuthorizenetWebhookJob`, route outside CSRF (add `webhook/authorizenet/*` to the exclusion list in `bootstrap/app.php`), signature check, idempotency table use. The signature header format and delivery timing are unverified, so this step ends with one live sandbox run (needs a public tunnel and a signature key) and fixes any mismatch. Satisfies **AC-8**.
7. Sweep: scheduled command in `routes/console.php` (every 10 minutes, `withoutOverlapping`) that re-reads pending payments from the last 24 hours through `AuthorizenetInvoiceLookup` and the resolver. Satisfies **AC-11**.
8. Parity sweep (use the `provider-parity-reviewer` agent): `StorePaymentRequest`, `UpdatePaymentRequest`, `PaymentController`, `Admin\UserController` and user requests, `PaymentsExport`, `DashboardController`, `DashboardMetrics`, `DashboardFilters.vue`, `PaymentProviderBadge.vue`, `AppSidebar.vue`, payments Create, Edit, Index, Show pages, `PaymentLayout.vue`, users Create and Edit pages, Success, Failed, Unavailable pages, `PaymentFactory`. Satisfies **AC-2, AC-3**.
9. Pest coverage for every Critical test scenario, plus unit tests for the classifier, signature check and amount conversion. Satisfies **AC-1 through AC-11**.
10. Close out: update `AGENTS.md` and `docs/agent.md` (via `/sync`) for the seventh provider and the scoped server to server exception. Satisfies **AC-7** (the rule it relaxes is documented).

## Consequences

**Positive**:
- Clients get the same on page experience as the other providers, with card data staying inside Authorize.net's frame (lightest PCI scope).
- No attempts table and no card capture code, so the build is smaller than MPGS.
- A status lookup by invoice number, a webhook and a sweep give three independent ways to learn a payment finished, so a lost or never sent browser message cannot strand a paid payment.
- One shared resolver keeps all status writes in one guarded place.

**Negative / tradeoffs**:
- The iframe result callback did not work at all in the sandbox (four payments, zero requests to the communicator). The design no longer depends on it, but the cost is that the form itself keeps showing "Processing..." after the client pays, until the pay page notices the payment through polling and swaps the iframe for the Success page. There is a short delay, bounded by the 3 second poll and the 5 second lookup throttle. Production behaviour of the callback is unknown and may differ.
- Declines are not recorded by PayHub at all (no webhook for a plain decline). Operators see them only in the Authorize.net Merchant Interface.
- Finding a transaction by invoice number relies on `getUnsettledTransactionListRequest`, which only lists transactions that have not settled yet (settlement usually runs overnight) and is capped per page. Payments older than about a day with no transaction id are not found and are logged for operators. A busy merchant needs paging, newest first, and a stop once rows are older than the payment.
- Authorize.net fields the form shows (required fields, address, AVS) are configured in the Merchant Interface, not enforced by the API, so two environments can drift.
- Widening `frame-src` and `form-action` lowers CSP strength on this one pay page, and the communicator route allows framing by a third party.
- The webhook (signature header format, delivery delay, whether declines send an event) is still unverified. It is the secondary path, so a wrong guess delays recovery but cannot mark a wrong payment paid.
- Paid status for Authorize.net comes from a server to server lookup, a third exception to the "webhooks only" rule stated in `AGENTS.md`.

**Neutral**:
- USD only per account, stored as lower case `usd`; a different currency needs a separate Authorize.net account.
- Refunds and voids are out of scope (PayHub has no refund feature today). A duplicate paid transaction is only logged.
- The provider list in `AGENTS.md` still names six providers; this makes seven.
- Migrations are additive and can layer on `master` normally.

## Follow-up

**Spike answers (2026-10-21, sandbox, API only; the rest needs a browser and a public URL):**
- Q1 confirmed: `getMerchantDetails` returns `currencies` as a plain array of strings (`["USD"]`) and `messages.resultCode` `Ok`. It is a good credential check. The response starts with a UTF-8 BOM.
- Q2 partly confirmed: `getHostedPaymentPageRequest` with `transactionType` `authCaptureTransaction`, `refId`, `order.invoiceNumber`, `customer.email`, `billTo` names, and the settings `hostedPaymentReturnOptions` (`showReceipt` false), `hostedPaymentButtonOptions`, `hostedPaymentPaymentOptions`, `hostedPaymentBillingAddressOptions`, `hostedPaymentCustomerOptions`, `hostedPaymentIFrameCommunicatorUrl` is accepted (`Ok`, token returned, about 1950 characters). Whether the form really hides those fields still needs a look in a browser.
- Communicator URL must start with `https://` (an `http://localhost` URL gets error `E00013`, "must begin with https://"). Answers part of Q7 and Q10.
- A real sandbox card payment (Visa test card, $12.34, invoice 432439) completed and was captured: the form accepted it and the merchant email receipt arrived. The form stayed on "Processing..." and our page received no communicator message (cause still being checked, Q6).
- Q6/Q7 result: the communicator relay does NOT work in the sandbox as tested. Four sandbox card payments (normal Chrome with extensions, then an incognito window) were all captured, and every time the form stayed on "Processing..." and our page got no message. A server-side log on the communicator URL (HTTPS, valid cert, no framing headers) recorded zero requests, so the form never even fetched it. The form's own console showed its CSP blocking an inline script (`script-src` listed our communicator URL). Cause is on the Authorize.net form side or an unknown condition; not yet explained.
- Consequence for the design: the browser `transId` (AC-6 confirm) cannot be relied on, and it was not delivered in this test. Payment status would depend on the webhook (AC-8) or on a lookup by invoice number (for example `getUnsettledTransactionListRequest` returns `invoiceNumber` and `transId`). The spec needs an `/architect` decision before steps 4 to 7 are built.
- Q4 confirmed from `getTransactionDetails` (sandbox, after that payment): `transaction.responseCode` is the integer `1`; `transaction.transactionStatus` is `capturedPendingSettlement` (settled later); `transaction.order.invoiceNumber` is `"432439"`; amount fields `authAmount` and `settleAmount` are JSON floats (`12.34`), so the classifier must compare them as strings or in cents, never as floats; there is NO currency field on the transaction. Also `transrefId` echoes the request `refId`.
- Q5 partly confirmed: `order.invoiceNumber` comes back on the transaction, and `refId` comes back as `transrefId`. The webhook side is still unchecked.
- Spike stopped early on 2026-10-21 by the user's decision (AC-12 gate waived). The webhook test (Q3, Q9) was not run; the spike files were deleted. The design change for the lost callback is still owed through `/architect` before steps 4 to 7.
- Not yet answered: Q3, Q6 to Q10 (need a card payment in the iframe, `getTransactionDetails`, the signature key and a public webhook URL). The dev sandbox account has no signature key saved yet.

- [x] Run the sandbox spike (Build plan step 0): done in part on 2026-10-21, the rest waived. Answers are in the bullets below.
- [x] Reconciliation for payments with no transaction id: decided on 2026-10-21 (invoice number lookup through the status endpoint and the sweep, AC-6 and AC-11).
- [ ] Accepted gaps from the cross check on 2026-10-21: transactions that settle before the sweep sees them leave the unsettled list (the webhook must cover it; add a `getSettledBatchListRequest` pass only if it happens); the redirect (non iframe) Accept Hosted flow is the fallback if the production iframe also freezes on "Processing..." and the 95th percentile time to Success exceeds 30 seconds.
- [ ] Update `AGENTS.md` and `docs/agent.md` through `/sync` when this ships (seventh provider, scoped exception).
- [ ] The `provider-parity-reviewer` agent and `provider-parity-check` skill text still say four providers; refresh them to cover all seven.
- [ ] Rate limits (20 token requests per payment per hour), the 14 minute token window, the 5 second status lookup throttle, the 3 second poll, the 10 minute give up time, the 24 hour sweep window and the 10 minute sweep are starting defaults, not reviewed figures.
- [ ] Refunds and voids stay out of scope; revisit only if PayHub adds a refund feature for any provider.
- [ ] This branch (`feat/mpgs-hosted-session`) is 2 commits behind `master`; start the build on a new branch from the current `master`.
