# 0013. Bank of America as the eleventh payment provider, using Flex Microform and the REST payments API

**Date**: 2026-11-17
**Status**: Proposed

## Summary

This spec adds Bank of America Merchant Services (a CyberSource based gateway) as PayHub's eleventh provider. On the pay page, Bank of America's Microform puts the card number and CVV fields in its own frames. The browser gets a one time token, and PayHub's server charges it with the amount from the `Payment` record. Status comes from a read back of the transaction (and a search by our own reference code when the reply is unclear), never from the charge reply alone. This is the same scoped exception NMI, MPGS, Authorize.net and Kurv use. v1 is cards only, USD only, sale only, with no 3D Secure, no wallets and no webhook. Nothing has been tested against a real sandbox yet, so step 0 of the build plan confirms every field name before the pay page is built.

## Requirements

**User stories**:
- As an admin, I want to add and test a Bank of America account (Merchant ID, Shared Secret key ID, Shared Secret) the same way as the other providers.
- As an agent, I want to create a USD payment link against a Bank of America account.
- As a client, I want to pay by card with my billing address on the brand page, and try another card if mine is declined.
- As the business, I want a payment marked paid only after PayHub's own server has read back one captured sale for the exact amount in USD.

**Acceptance criteria** (the contract, each one IDed and checkable on its own):

- **AC-1**: An admin (`role:admin`) can create, edit, activate, deactivate and test a Bank of America account with these fields: account name, prefix (optional, max 10, same rules as NMI's prefix), environment (`sandbox` or `production`), Merchant ID, Shared Secret key ID, Shared Secret. The Shared Secret is encrypted, not mass assignable, and never returned to the browser (only a `has_shared_secret` flag; Edit shows the field blank with a "Saved" placeholder, and a blank field on Edit keeps the saved value). The key ID is unique across all accounts, active or not, and in every environment (changing the environment does not lift the check). Deleting an account keeps its payments (`nullOnDelete`, as NMI). A save is rejected when the key ID is not a UUID, the Shared Secret is not valid base64, or the Merchant ID is not 1 to 30 letters, digits or underscores (formats confirmed in step 0). The form shows the help text from `docs/research/bank-of-america.md` on where to make the key ("Key Management, + Generate key, REST Shared Secret; copy both values before closing the window").
- **AC-2**: Test connection makes one call that creates a Microform capture context (`POST /microform/v2/sessions`) with the account's keys against the environment's API host, using the same request as AC-6 (target origin from `APP_URL`). It never creates a transaction. Success shows "Connected". A 401 shows "Bank of America did not accept these keys. Check the Merchant ID, key ID and secret, and that they belong to this environment." Any other failure shows "Could not reach Bank of America. Try again later." and is logged with the HTTP status only.
- **AC-3**: An agent or admin can create a payment against an active Bank of America account only in USD and only with a `client_email` (validated as `email`, max 255). `StorePaymentRequest` and `UpdatePaymentRequest` reject other currencies with `"This Bank of America account only accepts USD payments."` and a missing email with `"A client email is required for Bank of America payments."` `CurrencySupportResolver` treats `bofa` like Kurv (USD only, never PKR).
- **AC-4**: Bank of America appears everywhere the other ten providers do: payment create and edit forms, agent account assignment (`user_payment_accounts`), `ProviderAccountTable`, CSV export, dashboard filters, totals and "Accounts Today", `CentralSummary`, provider badge, sidebar, `Payment::providerAccountName()` and prefix, the receipt pages (Success, Failed, Unavailable), `Admin\UserController` account lists and the admin user Store and Update request rules, the `PaymentController` provider maps, and `docs/integrations/central-dashboard-api.md`. The display name is "Bank of America". The `provider-parity-check` skill and `provider-parity-reviewer` agent are first updated to list all eleven providers, then the reviewer passes.
- **AC-5**: The pay page of a `bofa` payment (`ClientPayment/PayBofa`) shows, in this order: the amount, a "Name on card" field prefilled from `client_name`, the Microform card number frame, expiry month and year selects, the Microform CVV frame, and billing address fields (street, street line 2 optional, city, country, state or region, postal code), then a pay button showing the amount. Country is a picker of ISO 3166 two letter codes, defaulting to `US`. For `US` the state is a dropdown of US state codes; for other countries it is free text. State and postal code are required for `US` and `CA`, optional for others. The existing consent check runs before the first submit. Card values stay inside Microform's frames. Expiry, the token, the name and the address never go into Inertia form state, a URL, storage, a log or the console. The card and address area is `sentry-block`.
- **AC-6**: After consent, the page calls `POST pay/{payment}/bofa/session`, which creates a capture context with `clientVersion` `v2`, `allowedCardNetworks` from config, `allowedPaymentTypes` `CARD`, and `targetOrigins` = the origin of `APP_URL` (scheme, host and port; never the path, never from the request; a folder hosted site such as `britishhub.co.uk/payhub` uses `https://britishhub.co.uk`). The reply is a JWT; the page decodes its payload (no signature check in the browser) and reads the client library URL and integrity value (`ctx[0].data.clientLibrary` and `clientLibraryIntegrity`, names confirmed in step 0). It loads that script only when the URL starts with the configured Microform host plus `/` (`config('services.bofa.hosts.{environment}.flex').'/'`, a strict prefix so a look alike host can't pass), with the integrity value as the `integrity` attribute when present. Otherwise it reports `microform_load_failed` and shows "Card payments are unavailable right now. Please try again later." An origin mismatch surfaces as `microform_load_failed` or `token_failed`. The page asks for a new capture context when the current one is older than 14 minutes, after any outcome other than approved (tokens are single use), and when Microform reports the context expired (`session_expired` event); a refresh clears the card frames and shows "Please re-enter your card details." while name, expiry and address stay filled in.
- **AC-7**: On submit the page calls Microform `createToken` with `expirationMonth` (two digits) and `expirationYear` (four digits), then `POST pay/{payment}/bofa/charge` (CSRF protected, `bofa-charge` limiter, consent required exactly as NMI: `$payment->consents()->exists()`, same status code and message) with `transient_token`, `first_name`, `last_name` (each cut to 60 characters, not rejected; a single word name is sent as the last name and the first name falls back to the first word of `client_name`, then to the same word), `address1` (required, max 60), `address2` (optional, max 60), `locality` (required, max 50), `country` (required, ISO two letters), `administrative_area` (required for US and CA, max 20; a US state code for US), `postal_code` (required for US and CA, max 10; US must match `^\d{5}(-\d{4})?$`, CA must match `^[A-Za-z]\d[A-Za-z] ?\d[A-Za-z]\d$`). Before sending, name and address text is transliterated to ASCII (`Str::ascii`) and trimmed; processor field limits found in step 0 replace these maximums. Any request is rejected with 422 unless the provider is `bofa`, the status is `pending`, the account is active, and no possible charge exists (AC-8). The server charges with `POST /pts/v2/payments` and sends: `clientReferenceInformation.code` = `bofa_order_id`, `processingInformation.capture` = true (a sale), no partial authorization flag, `orderInformation.amountDetails.totalAmount` (cents to a decimal string with integer math) and `currency` `USD`, both from the `Payment` record, `orderInformation.billTo` (first name, last name, address1, address2, locality, administrativeArea, postalCode, country, email = `client_email`), `tokenInformation.transientTokenJwt`, and no `shipTo`. Just before sending it sets `bofa_order_id` (a new UUID, only if empty), `bofa_first_attempt_at` (only if empty) and `bofa_last_attempt_at`. The token, the address and the request body are never stored or logged.
- **AC-8**: One charge runs at a time per payment (`Cache::lock` `bofa-charge:{payment id}`, 60 seconds; a second caller gets 409). Inside the lock, **before** the charge call, PayHub sets the pause key `bofa:pause:{payment id}` (120 seconds). It clears the key only on a clear outcome (declined, check_details, error, or approved and settled by the resolver). A new charge (and a new session) is refused with 409 and "We are confirming your payment. Please do not pay again." while the pause key is set or while any attempt row is `approved`, `held` or `unclear` (`hasPossibleCharge`). This is stricter than NMI on purpose: an `unclear` row blocks even with no transaction id, because a timed out sale here can be found later by reference search. The `unclear` row keeps blocking until the resolver settles it or the sweep gives up (AC-15); the 120 second pause key is only a short backstop. The page treats any 409 exactly like an unclear outcome (message plus polling). The reply is classified in `BofaChargeReply` per the **Reply mapping** table into `approved`, `held`, `declined`, `check_details`, `unclear` or `error`. An approved reply is never applied on its own: PayHub stores the transaction id on the attempt row only (never on `payments`), runs the read back inline through `BofaChargeResolver`, and returns outcome `unclear` if that does not settle the payment as `completed`.
- **AC-9**: `BofaChargeResolver` is the only status writer and the only writer of `payments.bofa_transaction_id`. It runs inside `Cache::lock('bofa-resolve:{payment id}', 30)`; a caller that can't get the lock returns without a lookup. It ignores `is_active`. If the payment is already `completed` with the same transaction id, it is a no op that clears the pause key. It gathers candidate transactions: every `transaction_id` on this payment's attempt rows, plus the results of a Transaction Search (`query` `clientReferenceInformation.code:{bofa_order_id}`, `sort` `submitTimeUtc:desc`, `limit` 10, window from `bofa_first_attempt_at` minus 5 minutes; syntax confirmed in step 0). It reads back at most 5 candidates per run (newest first) with the transaction details call. Retries reuse the same reference code, so search also returns earlier declined attempts; a candidate with no successful capture application is ignored for completion. It sets `completed` only when exactly one candidate is a captured sale (the capture application is present and succeeded, not under review) whose reference code equals `bofa_order_id`, whose total amount equals `payments.amount` exactly and whose currency is `USD`. More than one such sale is logged at error level and never applied. It uses the guarded update `whereIn('status', ['pending','failed'])`, sets `paid_at` and `bofa_transaction_id` in the same update, and dispatches `SendPaymentNotification` only when a row changed. It also:
  - leaves a sale with no capture application yet (under review) `pending` and marks the attempt row `held`;
  - never completes on a partial approval (authorized amount below the total): logs at error level (which reaches Sentry) and marks the attempt `error` with reason "Partial approval. Money is held on the card. Reverse it in the Bank of America dashboard.", shown to admins in the attempts panel;
  - leaves a decline `pending` (v1 never sets `failed`);
  - logs at error level and changes nothing for a mismatched amount, currency or reference code, a second sale on a `completed` payment, or any sale on a `cancelled` payment;
  - updates only the attempt row whose `transaction_id` matches a read back (`unclear` or `held` to `approved`, `declined` or `error`). A sale found by search for an `unclear` row with no transaction id is written onto the newest `unclear` row with no transaction id. It clears the pause key once settled.
- **AC-10**: After an `unclear` or `held` outcome PayHub doesn't guess. It keeps the pause key, dispatches `ResolveBofaCharge` with a 15 second delay (`$tries` and `backoff()` copied from `ResolveNmiCharge`; when retries run out the job logs a warning and the sweep takes over), and the page polls `GET pay/{payment}/bofa/status` every 3 seconds while the tab is visible and the status is `pending`. On `completed` it visits Success; on `cancelled` it visits Unavailable. The job, the status endpoint and the sweep ignore `is_active`. The status endpoint (same route limiter as NMI's status route) runs the same resolver lookup only when the payment is `pending` and `bofa_first_attempt_at` is set, at most once every 5 seconds per payment (cache key `bofa:lookup:{payment id}`). `ClientPaymentController::show` runs one lookup under the same condition and redirects to Success if the payment completed. After 10 minutes of polling the page stops with "Still confirming, you will get a receipt by email."
- **AC-11**: Every charge call writes a `bofa_charge_attempts` row: sequence, status (`sent`, `approved`, `held`, `declined`, `check_details`, `unclear`, `error`), Bank of America's `gateway_status` (for example `AUTHORIZED`, `DECLINED`), `reason_code` (for example `EXPIRED_CARD`), a plain operator `reason`, `transaction_id`, `avs_result`, `cvv_result`, and `client_info` (`App\Support\ClientInfo`). It never holds card values, the token or the address. Admins see the rows on the payment page in the existing `attemptHistory()` shape.
- **AC-12**: The client only ever sees fixed PayHub messages, never gateway text:
  - Approved and settled: visits the Success route.
  - Declined: "Your card was declined. Please try a different card." The form stays so they can retry.
  - Check details: "Please check your card details and billing address and try again."
  - Unclear or held: "We are confirming your payment. Please do not pay again." with polling (AC-10).
  - Error: "Your payment could not be processed. Please try again or use a different card."

  Repeated declines trigger the shared card lock (`CardLockGuard`, `card_locked_until`, `card_attempts_since`) and its existing notice. The thresholds come only from `CardLockGuard`'s own constants (no new numbers). Only `declined` and `check_details` count; `held`, `unclear` and `error` don't. A 422 from the session or charge endpoint because the account was deactivated or the payment is no longer pending visits the Unavailable page. After any outcome other than approved, the page clears the Microform fields and gets a new capture context; the address and name stay filled in (page memory only).
- **AC-13**: The `bofa-charge` limiter allows 5 per payment UUID per hour and 20 per IP per hour. The `bofa-session` limiter allows 20 per payment UUID per hour and 60 per IP per hour. The `bofa-event` limiter matches NMI's page event limiter. When hit, the endpoint returns 429 with "Too many attempts. Please try again later." and makes no gateway call. Once `bofa_first_attempt_at` is set, `UpdatePaymentRequest` rejects any change to the amount, currency, account or brand, and the payment can't be deleted.
- **AC-14**: `POST pay/{payment}/bofa/event` (`BofaPageEventRequest`, `bofa-event` limiter, never a lock) accepts one of a fixed list of events: `session_failed`, `microform_load_failed`, `session_expired`, `token_failed`, `timed_out`, with Microform's own `reason` code and a cleaned message (letters, digits, spaces and basic punctuation, max 200 characters). It writes a log line with the payment id, the event and `client_info`; it never writes an attempt row and never changes status. Admins find stalls in the logs only (decided 2026-11-17, same as NMI).
- **AC-15**: `BofaStalePaymentSweep` runs every 10 minutes (`withoutOverlapping`). It runs the resolver lookup for `pending` `bofa` payments whose `bofa_last_attempt_at` is within the last 48 hours, ignoring `is_active`. A payment with a `held` attempt keeps being checked for the full 48 hours. A payment whose lookup still finds no transaction 30 minutes after `bofa_last_attempt_at` is given up: it logs a warning, marks the last `unclear` attempt `error` with reason "No transaction found at Bank of America", and clears the pause key, so the client may pay again. Because search can lag, the sweep keeps looking up given up payments once an hour (cache key `bofa:late-check:{payment id}`) until 48 hours after `bofa_last_attempt_at`; a late single matching sale still completes the payment through the resolver, and a late second sale is the "two sales" error case in AC-9. The throttle of at most one gateway call per second per account uses the same mechanism as `NmiStalePaymentSweep`.
- **AC-16**: On the Bank of America pay page only, the CSP (the existing per page path in `SecurityHeaders`, the same one NMI uses) adds the account environment's Microform host (`services.bofa.hosts.{environment}.flex`) to `script-src`, `frame-src` and `connect-src`, plus `style-src` and `img-src` and any further host only if the step 0 browser run shows Microform needs them. Every other page keeps its policy, and no `unsafe-inline` or `unsafe-eval` is added. The API host is server side only and never in the CSP.
- **AC-17**: `BofaClient` is built per account (`app()->make(BofaClient::class, ['account' => $account])`) on top of the CyberSource PHP SDK (`cybersource/rest-client-php`) with HTTP Signature auth (Shared Secret), the account's environment host, a 20 second timeout, and SDK logging off. `BofaClient`, `BofaChargeReply`, `BofaTransactionReply` and `BofaSearchReply` are the only classes that know Bank of America or SDK field names. No other class imports the SDK.
- **AC-18**: A sandbox check (Build plan step 0) confirms or corrects every item in **Open questions**. The answers go in `## Follow-up`, and any field name that differs is fixed only in the AC-17 classes. The pay page (step 7) is not started until step 0 has confirmed the capture context, charge, read back and search calls in a real sandbox.

## Decision

**Chosen option**: Option 1: Flex Microform on the pay page, server side sale through the CyberSource PHP SDK, status from `BofaChargeResolver` after a read back (and a search by reference code when no transaction id is known).

PayHub embeds Microform for card number and CVV, gets a one time token, charges it from the server with the amount from the `Payment` record, and reads the transaction back before it writes any status. The 15 second job, the pay page poll and the 10 minute sweep are three more triggers for the same lookup.

**Implementation skills**: `laravel-best-practices` (`.claude/skills/laravel-best-practices/`) · `inertia-vue-development` (`.claude/skills/inertia-vue-development/`) · `wayfinder-development` (`.claude/skills/wayfinder-development/`) · `pest-testing` (`.claude/skills/pest-testing/`) · `provider-parity-check` (`.claude/skills/provider-parity-check/`)

## Rationale

The reasoning and the options are in `rationale.md`.

## Feature design

**Data model sketch**:

| Table | Columns | Notes |
|---|---|---|
| `bofa_accounts` (new) | `id`; `account_name` string; `prefix` string(10) nullable; `environment` string default `sandbox` (enum `BofaEnvironment`); `merchant_id` string(30); `key_id` string unique (across all rows); `shared_secret` text (encrypted, not mass assignable); `is_active` bool default true; timestamps | 1 to many `payments`. No currency column (USD only) |
| `payments` (new columns) | `bofa_account_id` FK nullable (`nullOnDelete`); `bofa_order_id` uuid nullable unique; `bofa_transaction_id` string nullable index; `bofa_first_attempt_at`, `bofa_last_attempt_at` timestamp nullable; `bofa` added to the `provider` enum | Add to `$fillable`, `$casts` and `bofaAccount()`. No address columns |
| `bofa_charge_attempts` (new) | `id`; `payment_id` FK cascade; `sequence` unsigned int; `status` string default `sent`; `gateway_status` string(40) nullable; `reason_code` string(60) nullable; `reason` string nullable (operators only); `transaction_id` string nullable index; `avs_result`, `cvv_result` string(8) nullable; `client_info` string nullable; timestamps; unique (`payment_id`, `sequence`) | Many to 1 `payments`. Never card values, token or address |

No webhook table (no webhook in v1).

**State transitions** (Payment):
- `pending` to `completed`: the resolver reads back one captured, matching sale.
- `pending` stays `pending`: after a decline, a held (review) sale, a partial approval, or an unclear reply (with a lookup queued).
- `completed` is final. v1 never sets `failed`.

**Attempt row states**: `sent` → one of `approved`, `held`, `declined`, `check_details`, `unclear`, `error`. The resolver may later move `unclear` or `held` to `approved`, `declined` or `error`, and `approved` to `error` (partial or mismatch).

**Reply mapping** (read only in `BofaChargeReply`, `BofaTransactionReply` and `BofaSearchReply`; every value comes from the CyberSource style docs and is confirmed in step 0):

| Source | Signal | Outcome |
|---|---|---|
| charge reply | HTTP 201, `status` `AUTHORIZED` | `approved` (then read back) |
| charge reply | HTTP 201, `status` `AUTHORIZED_PENDING_REVIEW` or `PENDING_REVIEW` | `held` |
| charge reply | HTTP 201, `status` `PARTIAL_AUTHORIZED` | `error`, logged at error level (admin reverses by hand) |
| charge reply | HTTP 201, `status` `AUTHORIZED_RISK_DECLINED` (authorized, then rejected by fraud rules and reversed by the gateway) | `declined` (never the "check details" message) |
| charge reply | HTTP 201, `status` `DECLINED` with `errorInformation.reason` in the card detail list (`EXPIRED_CARD`, `INVALID_ACCOUNT`, `INVALID_CVN`, `CV_FAILED`, `AVS_FAILED`; list finalised in step 0) | `check_details` |
| charge reply | HTTP 201, `status` `DECLINED` with any other reason | `declined` |
| charge reply | HTTP 400 `INVALID_REQUEST` with a duplicate reason (`DUPLICATE_REQUEST`) | `unclear` |
| charge reply | HTTP 400 any other reason (bad token, missing field) | `error`, logged, endpoint returns 502 |
| charge reply | HTTP 401 | `error`, logged at error level (bad keys) |
| charge reply | timeout, connection error, 5xx, unreadable body | `unclear` |
| charge reply | `id`, `processorInformation.avs.code`, `processorInformation.cardVerification.resultCode` | stored on the attempt row |
| transaction details | authorization and capture applications both successful, no pending review, reference code, total amount, currency | captured sale (completes if it matches) |
| transaction details | authorized but no capture application yet (review pending) | held |
| transaction details | authorized amount below total | partial (never completes) |
| transaction details | declined or reversed | not approved |
| search | no result | unclear; the sweep stops after 30 minutes (AC-15) |

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `pay/{payment}` (bofa branch, `showBofa`) | GET | payment UUID | Inertia `ClientPayment/PayBofa` with amount, currency, `client_name`, environment's Microform host | public, consent gate | Unavailable page when the account is inactive or the payment isn't pending |
| `pay/{payment}/bofa/session` | POST | none | `{capture_context}` | public, CSRF, consent, `throttle:bofa-session` | 409 paused, 422 invalid state, 429, 502 gateway error |
| `pay/{payment}/bofa/charge` | POST | `transient_token` (req, string max 8192), name and address fields (AC-7) | `{outcome, message}` | public, CSRF, consent, `throttle:bofa-charge` | 409 locked or paused, 422 invalid, 429, 502 error |
| `pay/{payment}/bofa/status` | GET | payment UUID | `{status}` | public, NMI's status route limiter | 404, 429 |
| `pay/{payment}/bofa/event` | POST | `event` (req, fixed list), `reason` (opt), `message` (opt) | 204 | public, CSRF, `throttle:bofa-event` | 422, 429 |
| `bofa-accounts` resource, `activate`, `deactivate`, `test-connection` (new and stored) | REST | AC-1 fields | Inertia pages, `has_shared_secret` | `role:admin` | 422 |

All five `pay/{payment}/bofa/*` routes sit in a route group copied from NMI's: `{payment}` bound by UUID, the same middleware, and the consent check on `session` and `charge`.

**Value sourcing**:

| Action | Value | Source |
|---|---|---|
| session | target origin | scheme and host of `config('app.url')` |
| session | allowed card networks | `config('services.bofa.card_networks')` (default `VISA`, `MASTERCARD`, `AMEX`, `DISCOVER`) |
| session, charge, lookups | Merchant ID, key ID, secret, API host | `bofa_accounts` columns; `config('services.bofa.hosts.{environment}.api')` |
| pay page | Microform script URL | the capture context's client library field, accepted only when it starts with `config('services.bofa.hosts.{environment}.flex')` |
| charge | amount, currency | `payments.amount` (cents to decimal string) and the fixed `USD` (equals `payments.currency` by AC-3) |
| charge | reference code | `payments.bofa_order_id` (UUID made on the first charge) |
| charge | first and last name | the "Name on card" field, prefilled from `payments.client_name`, split on the last space; a single word is the last name, and the first name falls back to the first word of `client_name`, then to the same word |
| charge | billing address | the pay page fields (AC-5), sent only, never stored |
| charge | email | `payments.client_email` (required by AC-3) |
| charge | expiry | inside the transient token (passed to `createToken`, never to PayHub) |
| resolver | candidate transactions | `bofa_charge_attempts.transaction_id` plus Transaction Search by `bofa_order_id` |
| resolver | captured or not, amount, currency, reference | transaction details reply |
| admin attempts panel | rows | `bofa_charge_attempts` |
| reference code prefix | prefix | `bofa_accounts.prefix` |
| admin payment page, CSV | `provider_reference` | `payments.bofa_transaction_id`, falling back to `payments.bofa_order_id` |
| admin lists, receipts | account name | `bofa_accounts.account_name` via `Payment::providerAccountName()` |
| status lookup throttle | last lookup | cache key `bofa:lookup:{payment id}` (5 seconds) |
| capture context age | issued at | page memory (time the session call returned) |

**Key invariants**:
- Only `BofaChargeResolver` writes `payments.status` and `payments.bofa_transaction_id` for Bank of America.
- No status is ever written from the browser or the charge reply alone.
- The amount sent always equals `payments.amount`, and the currency is always `USD`.
- At most one captured sale per payment is ever applied; a second is logged at error level.
- The Shared Secret exists only in the encrypted column and inside `BofaClient`. It's never in a page prop, a log or Sentry.
- `bofa_order_id` never changes once it is set.
- The billing address and the token are never written to the database, a log or Sentry.

**Security model**: Admins manage accounts. Agents only see the Bank of America accounts assigned to them. Clients reach only their own payment by UUID, behind the consent gate. Compliance: PCI DSS scope. Card number and CVV stay in Bank of America's frames (likely SAQ A; expiry is our own field, so confirm the SAQ level with Bank of America before production). Billing address is personal data: sent once, never stored. Every charge is logged in `bofa_charge_attempts`, which is the money audit trail. Public endpoints are rate limited (AC-13). No inbound webhook, so no new CSRF exclusion.

**Configuration required**:
- `composer require cybersource/rest-client-php` (new dependency).
- `services.bofa.hosts.sandbox.api` (env `BOFA_SANDBOX_API_HOST`, default `apitest.merchant-services.bankofamerica.com`), `services.bofa.hosts.production.api` (env `BOFA_PRODUCTION_API_HOST`, default `api.merchant-services.bankofamerica.com`).
- `services.bofa.hosts.sandbox.flex` (default `https://testflex.merchant-services.bankofamerica.com`), `services.bofa.hosts.production.flex` (default `https://flex.merchant-services.bankofamerica.com`).
- `services.bofa.card_networks`.
- Named limiters `bofa-charge`, `bofa-session`, `bofa-event` in `AppServiceProvider`.
- No new secret in `.env`; keys live encrypted on each `bofa_accounts` row.
- Before step 0: a working sandbox account (sign up page was under maintenance on 2026-11-17; fallback is the Bank of America rep or 855.225.9303) and a REST Shared Secret key made in it.

**Open questions** (answered by step 0 and by Bank of America sales):
1. **(Gate for steps 1, 2 and 6.)** Does the SDK with HTTP Signature (Shared Secret) work for `/pts/v2/payments` against the Bank of America hosts today, or is JWT with a `.p12` certificate and message level encryption (MLE) now required (the Sept 2026 date has passed)? If required, this spec is updated to add an encrypted `.p12` upload and password to the account before those steps start (a spec update, not a build guess).
2. Exact capture context request and reply: field names, and where the Microform script URL and its integrity value sit.
3. Charge reply shape: `status` values, `errorInformation.reason` list, where AVS and CVV codes sit, and which reasons mean "check your details".
4. Is `shipTo` truly optional? Which `billTo` fields are enforced for non US addresses?
5. Read back call: does `GET /tss/v2/transactions/{id}` work on this gateway (fallback `GET /pts/v2/payments/{id}`), and what shows a captured sale, a held review and a partial approval?
6. Does Transaction Search (`POST /tss/v2/searches`) find a sale by `clientReferenceInformation.code`, and how long until a new sale appears in it?
7. Does a repeated identical charge get a duplicate reply? What exact reason?
8. Formats of Merchant ID, key ID and Shared Secret (AC-1 checks).
9. Which hosts does Microform load in the browser, and does it need `style-src` or `img-src` (AC-16)?
11. Processor limits on `billTo` fields (name, address, postal code lengths) and whether non ASCII is rejected.
10. Where is the Merchant ID shown in the dashboard, and can the Shared Secret be viewed again after generation? (For the admin help text.)

**Critical test scenarios**:
- Happy path: sandbox card, sale approved, read back matches, payment `completed`, one notification. Verifies **AC-7**, **AC-8**, **AC-9**.
- Timeout on the charge: pause set before the call, a second charge gets 409, the queued job finds the sale by reference code search and completes it with no second charge. Verifies **AC-8**, **AC-10**.
- Decline then retry: status stays `pending`, attempt row written, new capture context, second card succeeds; the resolver sees the declined and the captured transactions and applies only the captured one. Verifies **AC-9**, **AC-11**, **AC-12**.
- Held for review: payment stays `pending`, attempt `held`, sweep keeps checking, completes once read back shows the capture. Verifies **AC-9**, **AC-15**.
- Partial approval in the reply or read back: never completes, error logged. Verifies **AC-9**.
- Amount or currency mismatch in the read back: no completion, error logged. Verifies **AC-9**.
- Two captured sales for one reference: neither applied, error logged. Verifies **AC-9**.
- No transaction found 30 minutes after the last attempt: sweep marks the attempt `error` and clears the pause. Verifies **AC-15**.
- Microform script URL on a foreign host: not loaded, `microform_load_failed` reported. Verifies **AC-6**, **AC-14**.
- Missing state for a US address: 422 and no gateway call. Verifies **AC-7**.
- Non USD payment or missing email on create: 422 with the fixed message. Verifies **AC-3**.
- Auth: an agent can't open Bank of America account admin (403); the Shared Secret never appears in any page prop or log. Verifies **AC-1**, **AC-17**.
- Rate limit: the sixth charge in an hour gets 429 and no gateway call. Verifies **AC-13**.
- Tests mock `BofaClient` through the container (the SDK makes its own HTTP calls, so `Http::fake` does not reach it).

## Build plan

Build approach: none is recorded in `AGENTS.md`, so end to end Tracer Bullet slices are assumed. The sandbox is down, so the thread is split: the backend thread is built first against a mocked `BofaClient`, and the browser half of the thread waits for step 0.

0. **Sandbox check** (blocked until a sandbox account works), in two parts. **0a, signing spike (about one day, a hard gate for steps 1, 2 and 6):** with the SDK in a scratch script, make a capture context and one sale with HTTP Signature and the Shared Secret (Open question 1). If it fails, update this spec for `.p12` and MLE before going on. **0b, the rest:** tokenize a test card in a minimal page, charge, read back, search by reference, repeat a charge, and record every answer to the Open questions in Follow-up. Fix field names in the AC-17 classes only. Satisfies **AC-18**. Steps 3 to 5 may start before 0a; steps 1, 2 and 6 wait for 0a; step 7 waits for 0b.
1. Migrations (the three changes), `PaymentProvider::Bofa`, `BofaEnvironment`, models, factories, `config/services.php` entries. Satisfies **AC-1**, **AC-11**.
2. `BofaClient` interface plus the SDK backed implementation (session, charge, transaction details, search, ping), `BofaChargeReply`, `BofaTransactionReply`, `BofaSearchReply`, all built from the docs and marked for step 0 confirmation. Satisfies **AC-17**.
3. `BofaChargeResolver` with the guarded update, candidate gathering and every AC-9 rule. Satisfies **AC-9**.
4. Session and charge endpoints, limiters, lock and pause, attempt rows, `hasPossibleCharge`, `ResolveBofaCharge` job, status endpoint, page event endpoint. Satisfies **AC-6** (server side), **AC-7**, **AC-8**, **AC-10**, **AC-11**, **AC-13**, **AC-14**.
5. `BofaStalePaymentSweep`, scheduled in `routes/console.php`. Satisfies **AC-15**.
6. Admin account CRUD, activate, deactivate, test connection, format checks; payment requests (USD and email rules), `CurrencySupportResolver`, edit, lock and delete guards, agent assignment. Satisfies **AC-1**, **AC-2**, **AC-3**, **AC-13**.
7. After step 0: `showBofa`, `PayBofa.vue` (Microform frames, expiry selects, name and address fields, consent, capture context refresh, fixed messages, polling, card lock), and the CSP for this page. This completes the thread: a sandbox card goes all the way to `completed`. Satisfies **AC-5**, **AC-6**, **AC-12**, **AC-16**.
8. Admin attempts panel on the payment page. Satisfies **AC-11**.
9. Parity sweep: first update the `provider-parity-check` skill and `provider-parity-reviewer` agent to eleven providers. Then CSV, `DashboardMetrics`, `CentralSummary` and `docs/integrations/central-dashboard-api.md`, receipts, agent forms, `PaymentController` maps (`provider_reference`, account list, `clearStaleProviderTransactionIds`), `Admin\UserController` and the admin user Store and Update requests, `Payment` helpers. Run the `provider-parity-reviewer`. Satisfies **AC-4**.
10. Pest tests for the scenarios (`php artisan test --parallel`), front end checks, `npm run build`, regenerate Wayfinder, update `AGENTS.md` through /sync. Satisfies **AC-1** to **AC-18**.
11. Staging run with the sandbox (approve, decline, check details, held if the sandbox can trigger it, timeout by blocking the API host), then one small live payment once Bank of America activates the production MID.

## Consequences

**Positive**:
- Bank of America merchants can take card payments in PayHub, with settlement into their own Bank of America account.
- Card number and CVV never touch PayHub's scripts, so the PCI level is lighter than Authorize.net's own fields.
- It reuses proven PayHub patterns: the resolver, attempts table, pause key, sweep, card lock, page events and per page CSP.

**Negative / tradeoffs**:
- An eleventh parallel path; every future payment feature has eleven branches.
- The pay page gets longer (billing address) than any other provider's, which may lower completion.
- The CyberSource SDK is a large dependency with its own HTTP stack, so tests mock `BofaClient` instead of faking HTTP.
- No webhook: a sale that settles late is found only by the job, the poll and the sweep, at most 10 minutes late.
- The whole design rests on docs we have not run. Open question 1 (Shared Secret vs certificate and MLE) could force an account form change.
- Refunds and voids are done by hand in the Bank of America dashboard, so PayHub won't show them.

**Neutral**:
- USD only; a GBP merchant cannot use this provider until Bank of America confirms GBP and the spec is updated.
- Keys expire every 3 years and must be replaced by hand in the account form.

## Follow-up

- [ ] Step 0 sandbox answers (Open questions 1 to 10), recorded here before step 7 starts.
- [ ] Ask Bank of America sales: 3D Secure with Microform, GBP, webhooks, Apple Pay and Google Pay, `shipTo`, Merchant ID location, whether the Shared Secret can be viewed again, test keys from a live account, and Shared Secret vs `.p12` for JWT and MLE (research file open items 1 to 9).
- [ ] Confirm the SAQ level with Bank of America before production (expiry is our own field).
- [ ] Later: refunds and voids, automatic reversal of partial approvals, 3D Secure, Google Pay, a webhook if one exists.
- [x] Cross check on 2026-11-17 (another model): 20 points, all fixed in this spec on the engineer's pick.
- [ ] Later: key expiry reminder (keys expire every 3 years).
- [ ] Agent skills not installed (skipped 2026-11-17, may be offered again): `visa/ai@visa-acceptance-best-practices` (Visa org name, unverified) and `membranedev/application-skills@cybersource` (third party). MCP servers noted, not connected: CyberSource Developer MCP SDK and Visa Acceptance Agent Toolkit (pilot); both target CyberSource, not the Bank of America hosts.
- [ ] /sync: add Bank of America to the provider list and Critical rules in `AGENTS.md` (eleven providers, `shared_secret` encrypted and not mass assignable).
