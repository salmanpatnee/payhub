# 0007. Authorize.net: take cards with Accept.js and PayHub's own card fields

**Date**: 2026-10-21
**Status**: In Progress (built and merged to `staging` on 2026-10-21; waiting on the staging webhook check and a held transaction test before Accepted)

## Summary

This spec replaces the Authorize.net hosted iframe (spec 0006) with PayHub's own card fields on the brand pay page, so Authorize.net looks and behaves like the other providers. The browser sends the card to Authorize.net's Accept.js script, which swaps it for a one time code. PayHub's server then charges that code and reads the transaction back from Authorize.net before it marks anything paid. This also removes the iframe message that never fired in the sandbox. The cost is a heavier PCI level (SAQ A-EP), because card numbers now pass through PayHub's page scripts.

## Requirements

**User stories**:
- As an admin, I want to create and test an Authorize.net account the same way as every other provider, and have PayHub fetch the public client key by itself.
- As an agent, I want to create a USD payment link against an Authorize.net account exactly like any other provider.
- As a client, I want to type my card into a form that looks like the rest of the brand page, see a clear result at once, and retry another card if mine is declined.
- As the business, I want a payment marked paid only from a transaction PayHub's own server read back from Authorize.net, and I want a payment held for fraud review to stay pending until it is decided.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: An admin can create, edit, activate, deactivate and test the connection of an Authorize.net account (account name, prefix, API login ID, transaction key, signature key, environment of `sandbox` or `production`), restricted to `role:admin`. The transaction key and signature key are encrypted at rest, not mass assignable, and never returned to the browser (only `has_*` flags). Creating, updating and testing an account makes one `getMerchantDetails` call with the stored credentials; on success it stores the response's `publicClientKey` in `authorizenet_accounts.public_client_key` (a public identifier, not a secret). If the account has no public client key, saving still works but the test and the account list show "Generate a public client key in Merchant Interface (Account > Settings > Security Settings > General Security Settings > Manage Public Client Key)", and the pay page treats the account as unavailable. Test connection also warns when the response's `currencies` list does not include `USD`. Two active accounts cannot share one `api_login_id`.
- **AC-2**: An agent or admin can create a payment link against an active Authorize.net account only in USD. `authorizenet_accounts.currency` is stored lower case (`usd`). `StorePaymentRequest` and `UpdatePaymentRequest` reject a payment whose currency differs from the account's, with `"This Authorize.net account only accepts {currency} payments."`, and `CurrencySupportResolver` treats `authorizenet` like Square. The gateway request carries only the decimal amount (Authorize.net has no currency field on a transaction): the account decides the currency.
- **AC-3**: Authorize.net appears everywhere the other providers already do (payment forms, agent account assignment, `ProviderAccountTable`, `CurrencySupportResolver`, CSV export, dashboard filters and "Accounts Today", provider badge, sidebar, and the client Success, Failed and Unavailable pages). This is already built on the branch; nothing here may regress, and the admin account pages gain only the client key status from AC-1.
- **AC-4**: The pay page of an Authorize.net payment shows PayHub's own card form: card number, expiry (MM/YY), CVV and ZIP, with a pay button that shows the amount. Name and email come from the payment, not the form. The existing consent check runs before the first submit. Browser checks run before anything is sent: card number passes the Luhn check and is 13 to 19 digits, expiry is a real month and not in the past, CVV is 3 or 4 digits, ZIP is 3 to 10 letters, digits, spaces or hyphens. The inputs use the `autocomplete` values `cc-number`, `cc-exp`, `cc-csc` and `postal-code`, and are never inside a form that can submit natively. Card values live only in local component state, are cleared as soon as tokenizing returns (number and CVV; expiry and ZIP stay for a retry), and never go into Inertia form state, a URL, `localStorage`, a log or the console.
- **AC-5**: The page loads Accept.js only on the pay page of an Authorize.net payment, from the account's environment host in config (never from the request), and calls `Accept.dispatchData` with the account's `api_login_id` and `public_client_key` (both passed as page props). The result is a one time `opaqueData` pair (`dataDescriptor`, `dataValue`). A tokenize error shows the "check your card details" message and sends nothing to PayHub. No other third party script runs on this page, and the card form container is blocked from Sentry session replay (`sentry-block` class), because the current replay setup records the page.
- **AC-6**: A charge endpoint takes `data_descriptor`, `data_value` and `postal_code` and charges the card on the server with `createTransactionRequest`. It rejects with 422 unless the data descriptor equals `COMMON.ACCEPT.INAPP.PAYMENT`, `data_value` is a string of at most 2048 characters, `postal_code` passes the AC-4 rule, the payment's provider is Authorize.net, its status is `pending`, it holds no `authorizenet_transaction_id` (a held or approved transaction exists, so a second charge could double charge), and its account exists, is active and has a public client key. The amount always comes from the `Payment` record (integer cents converted to the decimal string with integer math). The request sends `transactionType` `authCaptureTransaction`, `refId` and `order.invoiceNumber` both set to the payment's gateway invoice number (6 digit zero padded payment id plus the first 8 hex characters of the payment UUID, 20 characters at most), `order.description` set to the payment's reference code, the client's email, first and last name (a single word name is sent as both; each cut to 50 characters) and ZIP, `customerIP` from the request, and the setting `duplicateWindow` of 300 seconds. It sets `authorizenet_first_attempt_at` (only if empty) and `authorizenet_last_attempt_at` (every time) just before sending. The data value, card data and gateway request body are never stored or logged.
- **AC-7**: One charge runs at a time per payment (`Cache::lock` of 60 seconds; a second caller gets 409). The server decides the outcome from the charge reply and a read back: a reply with `responseCode` `1` (approved) or `4` (held) and a `transId` is followed by `getTransactionDetails` for that `transId`, and the result goes through the classifier and `AuthorizenetChargeResolver`. Outcomes: **approved** (payment becomes `completed`), **held** (transaction id stored, payment stays `pending`), **declined** (`responseCode` `2`; no status change), **check_details** (a decline whose error code is one of the card detail codes in the **Reply mapping** table), **unclear** (a timeout, a connection error, a 5xx, an unreadable reply, the duplicate error code, or an approved or held reply whose read back fails or does not classify as approved or held; PayHub cannot tell whether the card was charged) and **error** (any other gateway rejection of the request itself, logged with the gateway code, returned as 502). A read back that does not match this payment's invoice number or exact cent amount is unclear, never approved.
- **AC-8**: All status writes go through `AuthorizenetChargeResolver` with the guards the other providers use: `whereIn('status', ['pending','failed'])` for completion, `where('status','pending')` for a decline. A transaction moves a payment to `completed` at most once, sets `paid_at` and `authorizenet_transaction_id` in the same guarded update, and dispatches `SendPaymentNotification` only when a row was updated. A second approved transaction with another id for a `completed` payment, or an approved transaction for a `cancelled` payment, changes nothing and is logged at error level (reaches Sentry). A plain decline never sets `failed`; only a held transaction later declined by fraud review (webhook) does.
- **AC-9**: After an **unclear** outcome PayHub does not guess. It sets a cache pause key `authorizenet:pause:{payment id}` for 120 seconds (new charges get 409 with the unclear message), and dispatches `ResolveAuthorizenetCharge` with a 15 second delay. The job runs the invoice lookup (the lookup rules in `## Feature design`) and applies what it finds through the resolver. The status endpoint `pay/{payment}/authorizenet/status` also runs that guarded lookup, only when the payment is `pending` and `authorizenet_first_attempt_at` is set. When a client returns to the pay link, `ClientPaymentController::show` runs one lookup first (same condition) and redirects to the success route if the payment has completed.
- **AC-10**: The client sees only fixed PayHub messages, never gateway text. Approved navigates (`router.visit`) to the Success route. Declined shows "Your card was declined. Please try a different card." and keeps the form. Check details shows "Please check your card details and try again." Unclear shows "We are confirming your payment. Please do not pay again." and polls the status endpoint every 3 seconds while the tab is visible. Held shows "Your payment is being processed and needs a quick review. You will get a receipt by email once it is confirmed." and polls the same way. The polling stops after 10 minutes of visible waiting with "Still confirming, you will get a receipt by email" (the webhook and sweep keep trying). A `failed` state (fraud declined) opens the Failed page. Error shows "Your payment could not be processed. Please try again or use a different card."
- **AC-11**: A named limiter `authorizenet-charge` allows 5 charge requests per payment UUID per hour and 20 per IP per hour; the first one hit returns 429 with "Too many attempts. Please try again later." and makes no gateway call. The public status endpoint stays bounded by the lookup rules (payment state, attempt age, 120 lookups per hour, a 5 second key, one list call per second per account). Once `authorizenet_first_attempt_at` is set, `UpdatePaymentRequest` rejects a change to the amount, currency or account of an Authorize.net payment.
- **AC-12**: The webhook route `webhook/authorizenet/{authorizenetAccount}` (CSRF excluded, raw body, `X-ANET-Signature` HMAC-SHA512 against the account's signature key with `hash_equals`, queue only) and the 10 minute sweep keep working as built, as two more independent ways to find a transaction. The webhook job never trusts the body and applies the transaction through the resolver; duplicates drop through `processed_authorizenet_events`. The sweep re-reads `pending` payments whose `authorizenet_last_attempt_at` is within 24 hours. A webhook arriving during the charge request is safe because the resolver is idempotent.
- **AC-13**: The Accept Hosted code is gone: the token endpoint and route, `getHostedPaymentPage`, the communicator route, view and script, the iframe component, the `authorizenet-token` limiter, the `form` host and `token_lifetime_minutes` config, and the Authorize.net `frame-src`, `form-action` and `frame-ancestors` rules in `SecurityHeaders`. The generated route files under `resources/js/routes` and `resources/js/actions` are regenerated so nothing refers to them.
- **AC-14**: On the pay page of an Authorize.net payment only, the Content Security Policy adds exactly the Accept.js script host to `script-src` and the Accept.js API host to `connect-src` for that account's environment. Every other route and provider keeps its current policy, and no `unsafe-inline` or `unsafe-eval` is added.
- **AC-15**: A sandbox spike confirms or corrects each item in **Open questions** before the charge thread is built (Build plan step 0). Answers are recorded in `## Follow-up`, and any field name that differs is fixed in `AuthorizenetClient` or `AuthorizenetChargeReply`, the only two places that know gateway field names.

## Decision

**Chosen option**: Option 2: Accept.js with PayHub's own card fields, paid status from the server's charge plus a read back

PayHub renders the card fields, Accept.js turns the card into a one time code in the browser, and PayHub's server charges the code with `createTransactionRequest` and then reads the transaction back with `getTransactionDetails` before changing any status. The webhook, the invoice number lookup and the sweep stay as independent ways to resolve a charge whose outcome was unclear. This is the same scoped exception to "status comes from webhooks only" that specs 0002 (Clover), 0005 (MPGS) and 0006 made: the channel is server to server and the browser is never trusted.

**Implementation skills**: `laravel-best-practices` (`.claude/skills/laravel-best-practices/`) · `inertia-vue-development` (`.claude/skills/inertia-vue-development/`) · `pest-testing` (`.claude/skills/pest-testing/`) · `provider-parity-check` (`.claude/skills/provider-parity-check/`)

## Rationale

Reasoning and options: see `rationale.md`.

## Feature design

**Data model sketch** (changes on top of what spec 0006 built; edit the unmerged branch migrations in place):

- `authorizenet_accounts` (migration `2026_10_21_000001`): add `public_client_key` string nullable, plain (public identifier). Filled from `getMerchantDetails` on create, update and test connection. All other columns unchanged (`api_login_id`, encrypted `transaction_key`, encrypted nullable `signature_key`, `environment`, `currency` default `usd`, `is_active`, `prefix`, `account_name`).
- `payments` (migration `2026_10_21_000004`, renamed to `..._add_authorizenet_attempt_timestamps_to_payments_table`): replace `authorizenet_token_issued_at` and `authorizenet_token_last_issued_at` with `authorizenet_first_attempt_at` and `authorizenet_last_attempt_at`, both timestamp nullable. `authorizenet_account_id` and `authorizenet_transaction_id` stay.
- `processed_authorizenet_events`: unchanged.
- No attempts table. The one charge at a time lock (`authorizenet-charge:{payment id}`) and the unclear pause (`authorizenet:pause:{payment id}`) live in the cache.

Relationships are unchanged: `authorizenet_accounts` 1:N `payments`; agents reach an account through `user_payment_accounts`.

**State transitions**: unchanged from spec 0006. Payment: `pending` to `completed` (approved), `pending` to `failed` (fraud declined only), `pending` stays `pending` while held, after a plain decline, or while unclear. `completed` is terminal. A held payment is `pending` with an `authorizenet_transaction_id`.

**Reply mapping** (the only place gateway reply fields are read: `AuthorizenetChargeReply`; every code below is from documentation and must be confirmed in the spike):

| Reply | Outcome |
|---|---|
| `transactionResponse.responseCode` `1` with `transId`, then read back classifies as approved | `approved` |
| `responseCode` `4` with `transId`, then read back classifies as held | `held` |
| `responseCode` `2` and first error code not in the card detail list | `declined` |
| `responseCode` `2` and first error code in the card detail list: `6` (invalid card number), `7` (invalid expiry), `8` (expired), `27` (address mismatch), `44` and `45` (card code mismatch), `65` (card code declined) | `check_details` |
| `responseCode` `3` with error code `11` (duplicate) | `unclear` |
| `responseCode` `3` with any other code | `error` |
| timeout, connection error, 5xx, body that is not valid JSON, read back call failing, approved or held reply with no `transId` | `unclear` |

**Classification** (read back, shared by the charge, status endpoint, webhook job and sweep; already built in `AuthorizenetChargeClassifier`): approved needs `responseCode` 1, `transactionStatus` `capturedPendingSettlement` or `settledSuccessfully`, `order.invoiceNumber` equal to this payment's, and `authAmount` equal to `Payment.amount` in integer cents (never floats). Held is `FDSPendingReview`, `FDSAuthorizedPendingReview`, `underReview`, `authorizedPendingRelease`, or `responseCode` 4. Declined is `responseCode` 2 or `declined` or `failedReview`. Anything else, or a wrong invoice, amount or account, is unknown: logged once, no status change.

**Lookup rules** (already built in `AuthorizenetInvoiceLookup`; only the start time field is renamed): a lookup runs only for a `pending` payment with `authorizenet_first_attempt_at` set within the last 60 minutes, fewer than 120 lookups in the last hour, `Cache::add` on `authorizenet:lookup:{payment id}` winning (5 seconds for the first 15 minutes, 60 seconds after), and one list call per second per account (`authorizenet:account:{id}`). It pages `getUnsettledTransactionListRequest` (newest first, 100 per page, offset from 1) and stops on a short page, when the last row is older than `authorizenet_first_attempt_at` minus 5 minutes, or after 5 pages; no `transactions` key means zero rows. It keeps rows whose `invoiceNumber` equals the payment's invoice number and whose time is not earlier than that cutoff, skips rows the list shows as declined, reads the rest with `getTransactionDetails`, and classifies each. Approved wins over held; the earliest approved completes the payment and the rest are logged under AC-8.

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `authorizenet-accounts` (resource, except `show`) and `activate`, `deactivate`, both `test-connection` routes | GET/POST/PUT/DELETE/PATCH | account fields from AC-1 | rows with `has_transaction_key`, `has_signature_key`, `has_public_client_key` flags, never the secrets | `role:admin` | 403, 404, 422 |
| `pay/{payment}` (existing, branch `showAuthorizenet`) | GET | payment UUID | Inertia page with brand data, `api_login_id`, `public_client_key` and the Accept.js script URL; redirects to success when the lookup finds it paid | public (UUID) | 404, redirect to Unavailable when not payable |
| `pay/{payment}/authorizenet/charge` (replaces `.../token`) | POST | `data_descriptor`, `data_value`, `postal_code` (payment from route) | JSON `{outcome, message}` where outcome is `approved`, `held`, `declined`, `check_details`, `unclear` or `error` | public (UUID), limiter `authorizenet-charge` | 409 busy or paused (body carries the unclear outcome), 422 not payable or invalid input, 429, 502 error |
| `pay/{payment}/authorizenet/status` | GET | payment UUID | JSON `{status, state}` where state is `waiting`, `held`, `completed`, `failed` or `unavailable` | public (UUID), the lookup guards | 404 |
| `webhook/authorizenet/{authorizenetAccount}` | POST | raw body, `X-ANET-Signature` | 200 after queuing | signature, CSRF excluded | 401/403, 404 |

The status endpoint no longer takes the optional `trans_id` hint (there is no browser message to relay it). The `communicator` route is deleted.

**Value sourcing**:

| Action | Value produced / displayed | Source |
|---|---|---|
| Pay page | `api_login_id`, `public_client_key` | the payment's `authorizenet_accounts` row (both public identifiers; the transaction key and signature key are never sent) |
| Pay page | Accept.js script URL | `config('services.authorizenet.hosts.{environment}.script')`, environment from the account |
| Pay page | button label amount | `Payment.amount` formatted |
| Tokenize | opaque data | Accept.js in the browser from the typed card fields |
| Charge | API host | `authorizenet_accounts.environment` mapped through `config('services.authorizenet.hosts')` (`api`) |
| Charge | amount | `Payment.amount` cents converted with integer math (`sprintf('%d.%02d')`), never the request |
| Charge | `order.invoiceNumber` and `refId` | `AuthorizenetInvoice::for($payment)` (the one helper the lookup also uses): the reference code with the account prefix, e.g. `AUTH-001020`, or `001020` with no prefix. The invoice number is the only column the Authorize.net reports show. **Known limitation:** it is built from the live account prefix, so editing a prefix after a charge stops that payment from matching its transaction (prefix edits are not blocked yet). Payments charged earlier with the old id + UUID format still match. |
| Charge | `order.description` | `Payment::formattedReferenceCode()` |
| Charge | email, first and last name | `Payment.client_email`, `Payment.client_name` (split at the first space; a single word used for both; each cut to 50; email cut to 255) |
| Charge | ZIP | request `postal_code`, validated by AC-6 |
| Charge | `customerIP` | `$request->ip()` |
| Charge | `duplicateWindow` | `config('services.authorizenet.duplicate_window_seconds')`, default 300 |
| Charge | unclear pause length | `config('services.authorizenet.unclear_pause_seconds')`, default 120 |
| Charge | client message | `AuthorizenetChargeReply` outcome mapped to the fixed strings in AC-10 |
| Status | state shown | `Payment.status`, and `held` when `authorizenet_transaction_id` is set on a pending payment |
| Status, show, job | which transaction | invoice number lookup (rules above), then `getTransactionDetails` |
| Webhook | which payment | `payload.id` is the `transId`; the job reads `order.invoiceNumber`, finds the payment by reference code (or the legacy id + UUID format) and checks it belongs to the route's account |
| Webhook | idempotency key | body `notificationId` |
| Sweep | which payments | `provider = authorizenet`, `status = pending`, `authorizenet_last_attempt_at` in the last 24 hours |

**Key invariants**:
- Only a `getTransactionDetails` result read by PayHub's server ever sets `Payment.status = completed`. The browser, the Accept.js code and the webhook body never do.
- Approved needs response code 1, a captured or settled status, a matching invoice number and an exact cent match; anything less is unknown.
- At most one charge request is in flight per payment, and no new charge is accepted while the unclear pause is active or a transaction id is already stored.
- The charge never starts for an amount, account or currency taken from the request.
- `completed` is terminal. A held transaction never completes or fails the payment until Authorize.net decides it.
- The card number, CVV, opaque data value and keys are never logged, stored, put in a URL or returned.
- An Authorize.net account is single currency (`usd`).
- `transaction_key` and `signature_key` are encrypted, not mass assignable and never sent to the browser. `public_client_key` is a public identifier and may be sent.
- Amounts are integer minor units everywhere in PayHub; the decimal string exists only in the gateway request.
- Any gateway outcome PayHub does not recognize is logged, treated as unclear or unknown, and never allowed to fail an insert or update.

**Security model**:
- Account management is `role:admin` only. Pay, charge and status routes are public but scoped by the unguessable payment UUID. The webhook route is protected by the HMAC signature.
- **Compliance scope is PCI DSS, and it rises**: card numbers now pass through PayHub's page scripts before Accept.js turns them into a code, so the target is SAQ A-EP, not the lighter SAQ A that the hosted iframe allowed. The engineer accepted this. The acquiring bank or assessor makes the final call, and PCI DSS 4.0 asks for an inventory and tamper detection of the scripts on a payment page.
- The pay page of an Authorize.net payment loads only PayHub's own bundle and Accept.js from Authorize.net's host. No analytics or other third party script is added to it. Sentry session replay must not record the card form.
- The card form lives in memory only (AC-4). The server only ever receives the one time code and the ZIP.
- Card testing abuse is limited by the charge limiter, the one charge at a time lock and the unclear pause (AC-7, AC-9, AC-11).
- Raw gateway text and codes go to the logs for operators only. Clients always see the fixed strings in AC-10.
- Audit trail: the stored `authorizenet_transaction_id` on the payment plus the activity of the shared resolver; a duplicate paid transaction is logged with both ids. Every charge call is also recorded in `authorizenet_charge_attempts` (status, response and error codes, a plain reason, transaction id, AVS/CVV/3DS result letters; never a card value, the Accept.js code or the request body), shown to admins on the payment page. Added after this spec; the attempt rows are diagnostic only and never decide payment status.

**Configuration required**:
- `config/services.php` `authorizenet.hosts` per environment: `api` (already there), `script` (sandbox `https://jstest.authorize.net`, production `https://js.authorize.net`) and `connect` (the host Accept.js posts to: sandbox `https://apitest.authorize.net`, production `https://api2.authorize.net`). Remove `form`. These are constants, not secrets.
- `authorizenet.duplicate_window_seconds` (default 300) and `authorizenet.unclear_pause_seconds` (default 120). Remove `token_lifetime_minutes` and the env var `AUTHORIZENET_TOKEN_LIFETIME_MINUTES`.
- Outside the code: each Authorize.net account needs an API login ID, a transaction key, a signature key and a public client key (generated in Merchant Interface under Account > Settings > Security Settings > General Security Settings > Manage Public Client Key). The PayHub webhook URL and the four events (`net.authorize.payment.authcapture.created`, `net.authorize.payment.fraud.approved`, `.fraud.declined`, `.fraud.held`) are added by the admin in Merchant Interface under Account > Webhooks.

**Open questions** (answered by the spike in step 0; none of these were tested yet; 0006's Q3 and Q9 about the webhook are carried over as 9 and 10):
1. Does `getMerchantDetails` return `publicClientKey` for an account that has generated one, and what does it return for one that has not?
2. Does Accept.js load and tokenize from the sandbox host with the exact CSP in AC-14 (no `unsafe-inline`, no `unsafe-eval`), and which origins does it post to?
3. The real shape of the `createTransactionRequest` reply: is `responseCode` a string, where do `transId`, `errors[].errorCode` and `messages` sit for approved, declined, held and error cases?
4. Which error codes does the sandbox return for a bad card number, bad expiry, wrong card code and a forced decline, and do they match the **Reply mapping** table?
5. Does `duplicateWindow` stop a second approved charge of the same card, amount and invoice, what is the reply, and does a retry after a decline with the same invoice still work?
6. Does a declined or held charge show in `getUnsettledTransactionListRequest` with the invoice number (this decides how well the unclear lookup finds a declined attempt)?
7. Does a held (fraud review) transaction come back with `responseCode` 4 and the status strings in the classifier?
8. Does `getTransactionDetails` right after the charge reply always return the transaction (no read after write delay)?
9. The `X-ANET-Signature` header format (a `sha512=` prefix, letter case of the hex) and that the HMAC is over the raw body.
10. The delay between a charge and the `authcapture.created` webhook, and whether declines produce any event.

**Critical test scenarios** (all gateway calls use `Http::fake`; the real gateway is covered only by the spike):
- Happy path: admin creates an account (client key stored from `getMerchantDetails`), agent creates a USD link, client posts a charge with opaque data, the reply is approved, the read back classifies approved with a matching invoice and amount, payment is `completed`, `paid_at` and `authorizenet_transaction_id` set, one notification. Verifies **AC-1, AC-2, AC-6, AC-7, AC-8**.
- A GBP payment against an Authorize.net account is rejected with the currency message. Verifies **AC-2**.
- Charge guards: a `completed` payment, a payment holding a transaction id, an inactive account, an account with no client key, a non Authorize.net payment, a wrong data descriptor and a bad ZIP each get 422 with no gateway call. Verifies **AC-6**.
- Request body: amount from the record (a request `amount` is ignored), invoice number, `refId`, `duplicateWindow`, `customerIP`, no card data in anything logged. Verifies **AC-6, AC-11**.
- Reply mapping: one test per row of the table (approved, held, declined, check details codes, duplicate, other code 3, timeout, 5xx, bad JSON, read back failure, reply with no `transId`). Verifies **AC-7**.
- Read back mismatch: an approved reply whose transaction has a wrong invoice number, wrong cents (float noise like `0.1 + 0.2` included) or wrong account is unclear and never `completed`. Verifies **AC-7, AC-8**.
- Held reply stores the transaction id, leaves the payment `pending`, returns the held outcome and message. Verifies **AC-7, AC-10**.
- Unclear path: a timeout sets the pause key, dispatches `ResolveAuthorizenetCharge`, a second charge during the pause gets 409, the job finds an approved transaction by invoice number and completes the payment once. Verifies **AC-9**.
- Concurrency: a charge while the lock is held gets 409 and makes no second gateway call. Verifies **AC-7**.
- Declined then approved: a decline leaves the payment `pending`; a later charge with the same invoice number approves and completes it; the lookup skips the declined row. Verifies **AC-8, AC-9**.
- Duplicate paid and terminal guard: a second approved transaction with another id for a `completed` payment changes nothing and is logged; a `completed` payment is never changed by a later decline; an approved transaction for a `cancelled` payment is not applied. Verifies **AC-8**.
- Limiter: the 6th charge request for one payment in an hour and the 21st for one IP get 429 with no gateway call. Verifies **AC-11**.
- Edit lock: after `authorizenet_first_attempt_at` is set, changing the amount, currency or account is rejected. Verifies **AC-11**.
- Status and returning client: ten status polls inside 5 seconds make one list call; no lookup runs with no attempt time, a non pending payment, an attempt older than 60 minutes or after 120 lookups; `show` on a pending payment that completed at the gateway redirects to success. Verifies **AC-9, AC-11**.
- Webhook: wrong signature or no stored signature key gets 401 or 403 and changes nothing; a correct signature queues a job that reads the transaction; the same `notificationId` twice completes once; `fraud.approved` completes a held payment; `fraud.declined` sets `failed`; a body claiming success for a transaction Authorize.net reports as declined changes nothing. Verifies **AC-12**.
- Sweep: a pending payment with a transaction id is read directly; one without is matched by invoice number in one list pass per account; one whose last attempt is older than 24 hours is untouched; one failing account does not stop another. Verifies **AC-12**.
- Hosted code removed: the old token and communicator routes return 404 and no `communicator` or `getHostedPaymentPage` reference remains. Verifies **AC-13**.
- CSP: the Authorize.net pay page adds the Accept.js script and connect hosts only; the Stripe, MPGS, Clover, Square pay pages and every other route are unchanged; no `unsafe-inline` or `unsafe-eval` appears. Verifies **AC-14**.
- Secrets and card data: serialized account props and pay page props never contain the transaction key or signature key; the pay page markup marks the card form `sentry-block`; logs contain no data value. Verifies **AC-1, AC-5**.
- Auth/permission: a non admin hitting any `authorizenet-accounts.*` route gets 403. Verifies **AC-1**.
- Front end (unit tests for the card helpers): Luhn, expiry in the past, CVV length, ZIP pattern. Verifies **AC-4**.

## Build plan

No build approach is recorded in `AGENTS.md` or a scope header, so this plan defaults to end to end Tracer Bullet slices and states that assumption. Steps 1 to 3 of spec 0006 (migrations, models, client, classifier, resolver, lookup, admin pages) and its webhook, sweep and parity work are already on this branch, so this plan only changes them where noted. The first slice proves the riskiest new thread (Accept.js under the CSP, then a charge and the read back) before the polish.

0. **Sandbox spike.** A throwaway script outside the repo's `app` code plus one small HTTPS test page: call `getMerchantDetails`, load Accept.js from `jstest.authorize.net` under the AC-14 CSP, tokenize a test card, send `createTransactionRequest` for approved, declined, held and error cases, and read back with `getTransactionDetails` and the unsettled list. Record answers to Open questions 1 to 8 in `## Follow-up`. Satisfies **AC-15**.
1. **Data and config.** Edit migration `000001` (add `public_client_key`) and rename and edit `000004` (attempt timestamps); update `AuthorizenetAccount`, `Payment` casts and fillable, factories, `AuthorizenetInvoiceLookup`, `AuthorizenetStalePaymentSweep`, `UpdatePaymentRequest` for the renamed fields; update `config/services.php` (add `script` and `connect`, add the two seconds settings, remove `form` and `token_lifetime_minutes`). Satisfies **AC-1, AC-11, AC-12**.
2. **Client and reply mapping.** In `AuthorizenetClient` add `createTransaction` (opaque data, no `->throw()`, 30 second timeout), a `publicClientKey` reader on the `getMerchantDetails` result, and remove `getHostedPaymentPage`. Add `AuthorizenetChargeReply` (pure, unit tested: reply to outcome and fixed message key, the only place gateway reply fields are read). Satisfies **AC-6, AC-7**.
3. **Admin slice.** Store and refresh `public_client_key` in the store, update and both test connection routes; show `has_public_client_key` and the missing key message on Create, Edit and Index. Satisfies **AC-1**.
4. **Charge thread (tracer).** `AuthorizenetPaymentController::charge` (guards, lock, attempt timestamps, pause key, read back, resolver), `ResolveAuthorizenetCharge` job, route `pay.authorizenet.charge`, limiter `authorizenet-charge` in `AppServiceProvider` (replace `authorizenet-token`), status endpoint without the hint, `SecurityHeaders` change (script and connect hosts, remove frame rules). Delete the token action, communicator route, view and script, and regenerate the route files. Satisfies **AC-6 to AC-9, AC-11, AC-13, AC-14**.
5. **Pay page.** `AuthorizenetCardForm.vue` and `resources/js/lib/card.ts` (Luhn, expiry, formatting), Accept.js loader, `sentry-block`, rewrite `PayAuthorizenet.vue` (consent gate, outcome messages, polling only after held or unclear, 10 minute give up), pass the new props from `showAuthorizenet`, and delete `AuthorizenetPaymentForm.vue`. Satisfies **AC-4, AC-5, AC-10**.
6. **Webhook and sweep check.** Confirm both still apply through the resolver with the renamed fields; run one live sandbox webhook (public tunnel, signature key) and fix any header format mismatch. Satisfies **AC-12**.
7. **Tests.** Rewrite `AuthorizenetPaymentFlowTest` and the account tests for every Critical test scenario; add unit tests for `AuthorizenetChargeReply`, the classifier and `card.ts`. Satisfies **AC-1 to AC-14**.
8. **Parity check.** Run the `provider-parity-reviewer` agent once to confirm nothing regressed. No new surfaces are added. Satisfies **AC-3**.
9. **Close out.** `/sync` updates `AGENTS.md` and `docs/agent.md` for the seventh provider, the scoped server to server exception and the PCI note. Satisfies **AC-8**.

## Migration plan

**Strategy**: no migration needed for live data. The Authorize.net migrations and code exist only on the branch `feat/authorizenet-accept-hosted`, not on `master` or production.
**Phases**:
1. Edit the branch migrations in place (Build plan step 1).
2. Any database that already ran the old migrations (local, staging) just runs `php artisan migrate`. Migration `2026_10_21_000005_upgrade_authorizenet_to_accept_js` adds `public_client_key`, copies the old token timestamps into the new attempt columns and drops the old ones; every step checks first, so fresh databases pass through untouched. No `migrate:fresh` needed.
**Rollback**: revert the commits on the branch; nothing reached production.
**Risks**: a shared development database that already ran the old migrations would fail on the renamed columns until it is rebuilt.

## Consequences

**Positive**:
- Authorize.net looks and behaves like the other providers, in the brand's own style.
- The result is immediate and server side, so a lost iframe message can no longer strand a client on "Processing...".
- Every attempt's result is seen by PayHub's server (logged), including plain declines.
- Most built code is reused: invoice number, lookup, classifier, resolver, webhook, sweep and admin pages.
- Less custom plumbing: no token polling loop for the normal path, no communicator page, no framing exceptions.

**Negative / tradeoffs**:
- PCI scope rises from SAQ A to SAQ A-EP: card numbers pass through PayHub's page scripts, so a script injected into the pay page could steal cards. The strict CSP, no third party scripts and replay blocking reduce this, but do not remove it. The assessor decides the exact obligations.
- PayHub now owns the card form: validation, input behaviour on every browser and accessibility are PayHub's job.
- A charge call that times out can leave the outcome unknown; the lock, pause, duplicate window and invoice lookup handle it, but the client may wait a couple of minutes.
- Browser-side failures (Accept.js could not read the card) never reach the server, so they are in neither the logs nor `authorizenet_charge_attempts`. Plain declines are stored there, one row per charge call.
- The unclear lookup depends on `getUnsettledTransactionListRequest`, which lists only unsettled transactions; a charge that settles before the lookup sees it leaves the list (the webhook must cover it).
- The webhook format and delay are still unverified (Open questions 9 and 10); it is the secondary path.
- Paid status again comes from a server to server read, a third exception to the "webhooks only" rule in `AGENTS.md`.

**Neutral**:
- USD only per account, stored as `usd`.
- Refunds and voids stay out of scope; a duplicate paid transaction is only logged.
- The client key is a new per account prerequisite in Merchant Interface.
- 3D Secure is out of scope (US card not present flow).
- The provider list in `AGENTS.md` still names six providers; this makes seven.

## Follow-up

- [x] Partly answered by real sandbox payments on 2026-10-21 (payments 29, 30, 32, 33 all ended `completed` through the pay page; the gateway read back for txn 80061360417 shows `responseCode` 1, `transactionStatus` `capturedPendingSettlement`, `authAmount` 1000 for a 100000 cent payment, invoice number present, `cardCodeResponse` "P").
  - Q1: yes, `getMerchantDetails` returned a public client key on the sandbox account (the pay page loaded and tokenized).
  - Q2: yes, Accept.js loaded and tokenized under the AC-14 CSP (no CSP block stopped a charge).
  - Q3: approved reply matches `AuthorizenetChargeReply` (charge resolved to completed, no field name fix needed).
  - Q8: `getTransactionDetails` returned the transaction right after the charge.
  - Q9 and Q10: not tested. `processed_authorizenet_events` is empty, so no live webhook has arrived yet.
  - Payment 31 is still `pending` with no transaction id (last attempt 16:39, before the missing `public_client_key` column was fixed at 17:17); probably that error, not confirmed.
- [x] Direct sandbox probe 2026-10-21 (raw card sent straight to the gateway, mapped through `AuthorizenetChargeReply`):
  - Q4: bad card number returns response 3, error 6; expired card returns response 3, error 8 (NOT response 2). Fixed: `fromResult` now maps those to `check_details`, test added. Card code 901 returns response 2, error 65 with cvv N: `check_details` as designed. ZIP 46282 returns response 2, error 2: `declined`.
  - Q5: yes. The second identical charge (same card, amount, invoice, 300 second window) returns response 3, error 11 and the first charge's transaction id; maps to `unclear`.
  - The amount trick (card 4222 2222 2222 2, amount = reason code) did NOT work: the sandbox account is not in Test Mode, so every amount was approved. Forcing declines needs the CVV 901 / ZIP 46282 filters above.
- [x] Upgrade migration `2026_10_21_000005_upgrade_authorizenet_to_accept_js` added (idempotent, tested), so staging only needs `php artisan migrate`. Held-state reload fixed (`held` page prop). Card form restyled to match MPGS and Clover, with per field errors cleared on edit.
- [ ] Staging webhook check (Q9, Q10): set the webhook URL to the staging address, make a payment, confirm a row in `processed_authorizenet_events`, the `X-ANET-Signature` header format and the delay.
- [ ] Still to run: held transaction (Q7; needs a holding fraud filter or Test Mode), unsettled list for declines (Q6), retry after a decline with the same invoice.
- [ ] Run `/check verify`, write `verify.md`, then set Status to Accepted.
- [ ] Ask the acquiring bank or a PCI assessor to confirm the SAQ type (A-EP expected) and what the pay page script inventory and tamper detection must look like (PCI DSS 4.0 payment page script rules).
- [ ] Decide whether card values should be cleared on a "check card details" outcome (this spec clears number and CVV and keeps expiry and ZIP).
- [ ] Review the starting figures: 5 charges per payment and 20 per IP per hour, 300 second duplicate window, 120 second unclear pause, 3 second poll, 10 minute give up, 24 hour sweep window. They are defaults, not reviewed numbers.
- [ ] Accepted gap: transactions that settle before the lookup sees them leave the unsettled list; add a `getSettledBatchListRequest` pass only if it happens in practice.
- [x] `AGENTS.md` and `docs/agent.md` updated (seventh provider, scoped exception, PCI note, Accept.js flow, upgrade path). Done 2026-10-21.
- [ ] The `provider-parity-reviewer` agent and `provider-parity-check` skill text still say four providers; refresh them to cover all seven.
- [ ] Refunds and voids stay out of scope; revisit only if PayHub adds a refund feature for any provider.
