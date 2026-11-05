# 0010. Kurv as the ninth payment provider, using Kurv's hosted payment page

**Date**: 2026-11-05
**Status**: In Progress

## Summary

This spec adds Kurv as the ninth payment provider. PayHub's server asks Kurv for a hosted payment link built from the amount on its own payment record, then sends the client to Kurv's page to pay by card. Kurv's callback is unsigned, so it only tells PayHub to look again: a payment becomes `completed` only after PayHub reads the result from Kurv's own API and the transaction id, amount and currency match. This is a scoped exception to "status comes from webhooks only", the same family as Viva, Clover, MPGS and Authorize.net.

## Requirements

**User stories**:
- As an admin, I want to add, test, activate and deactivate a Kurv account for a brand, with the key kept secret, the same way I manage other providers.
- As an agent, I want to create a USD payment link against an active Kurv account like any other provider.
- As a client, I want to agree to the terms, go to Kurv's page, pay, come back, and see a clear result.
- As the business, I want a payment marked paid only from what Kurv's API says, never from what the browser or the callback body says.
- As an admin, I want to see what Kurv said on each check, so I can understand a decline or a stuck payment.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: An admin can create, edit, activate, deactivate, delete (when no payment uses it) and test a Kurv account (account name, prefix, API key, environment of `sandbox` or `production`), restricted to `role:admin`. `api_key` is encrypted at rest, not mass assignable, and never returned to the browser (only a `has_api_key` flag). A `kp_test_` key is accepted only with `sandbox` and a `kp_live_` key only with `production`; a mismatch is a validation error. Test connection makes one read only Kurv call with the stored key and reports in plain words: 401 is "wrong key", 403 is "missing permission", 422 mentioning the plan is "your Kurv plan does not allow this", anything else a generic message. On update, a blank key keeps the stored key, and the environment is checked against the incoming key or, if blank, the stored key. Test connection works with an unsaved key and with the stored key (two routes, as for Whop). `prefix` is the reference prefix, used as on other accounts. There is no stored callback secret and no currency column.
- **AC-2**: A Kurv payment may only be `usd`. `StorePaymentRequest`, `UpdatePaymentRequest` and `CurrencySupportResolver` reject GBP and PKR for a Kurv account with "This Kurv account only accepts USD payments." `client_email` is required for Kurv payments (as for Whop); `client_name` is already required for every payment.
- **AC-3**: Kurv appears everywhere the eight providers already do: `PaymentProvider` enum, payment forms, agent account assignment (`user_payment_accounts`), `ProviderAccountTable`, CSV export, dashboard filters and "Accounts Today", provider badge, sidebar, `CentralSummary`, and the client Success, Failed and Unavailable pages. A Kurv payment never falls through to the Stripe pay page. A parity review (grep for two way to eight way provider branches, and the `provider-parity-reviewer`) passes before merge.
- **AC-4**: The pay page of a Kurv payment shows PayHub's brand layout and the existing consent check first, then the amount and a "Pay now" button. Only after consent and a click does the page call the checkout endpoint, and then it sends the browser to the returned Kurv URL. Props are payment, brand, policies, amount, currency, `hasConsent`, `hasLink` and `returnStatus`; they never include the API key, `kurv_payment_url` or `kurv_request_id`. With a saved link the page shows the same button and checkout returns the saved URL. No card value ever touches PayHub (Kurv collects it). `ClientPaymentController::show` redirects a `completed` payment to the Success route before the Unavailable guard. An inactive account, a payment with `kurv_link_closed_at` set, and a payment that is not `pending` render the Unavailable page; an already open Kurv link can still be paid on Kurv's side and the sweep or callback still completes it.
- **AC-5**: The checkout endpoint `pay/{payment}/kurv/checkout` takes no amount, currency or account from the request. It returns 422 unless the provider is Kurv, the status is `pending`, the account exists and is active, and a consent record exists for the payment. It creates one Kurv payment request per payment: the first call sends `POST /payment-requests` with the web request method, a sale payment type, `amount` converted from `Payment.amount` with integer math, `currency`, `customer_first_name` from `client_name`, `email` from `client_email`, `reference_number` (the reference code), `response_url` (the callback URL of AC-8), `redirect_url` (the payment's own https pay page URL plus `kurv_return=1`) and `cancel_url` (the pay page URL without the flag). The returned Kurv `transaction_id` is saved in `kurv_request_id`, the hosted link in `kurv_payment_url`, and `kurv_charge_started_at` is set. Later calls reuse the saved link and make no Kurv call, and a dead link is never recreated. The reply is JSON `{url}` only. The Kurv timeout is 10 seconds to connect and 30 in total (config). A Kurv 4xx, 5xx, timeout or unreadable reply returns 502 and saves nothing; a 429 is retried once with a short wait before giving up. If the saved link has an expiry time in the past, the call returns 422 (a link already open still resolves). `customer_first_name` gets the full `client_name` (split on the last space only if step 0 says a last name is required). `amount` is built with integer math only, never `number_format` on a float.
- **AC-6**: One checkout creation runs at a time per payment (`Cache::lock('kurv:checkout:{payment id}', 60)->block(5)`; a caller that gets the lock after waiting reuses the saved link; one that cannot get it in 5 seconds gets 409). A named limiter `kurv-checkout` allows 10 requests per payment UUID per hour and 30 per IP per hour (reuse calls count); the first limit hit returns a JSON 429 with "Too many attempts. Please try again later." and makes no Kurv call. Once `kurv_charge_started_at` is set, `UpdatePaymentRequest` rejects a change to the amount, currency, account or brand, and the payment cannot be deleted (PayHub has no cancel action today). When `kurv_link_closed_at` is set, delete and an account or amount change are allowed again, and a change clears the saved link columns.
- **AC-7**: `KurvChargeResolver` is the only code that writes Kurv payment status. It always looks up the payment request on Kurv (`GET /payment-requests/{kurv_request_id}`); it searches the payment history (`GET /payments?keywords={reference}`) only for a callback on a `completed` payment (AC-12) or when a successful request shows no payment id. It matches rows on the Kurv transaction id saved on the payment, never on the reference alone. It sets `completed` only when Kurv reports a successful sale for that transaction id, the amount equals `Payment.amount` and the currency equals `Payment.currency` (compared in integer cents), and the payment type is a sale. The update is one guarded query (`whereIn('status', ['pending','failed'])`) that sets `completed`, `paid_at` and `kurv_payment_id`, and dispatches `SendPaymentNotification` after the transaction commits only when a row was updated. One lookup runs at a time per payment (`Cache::lock('kurv:lookup:{payment id}', 60)`); a caller that cannot get the lock sets `kurv:recheck:{payment id}` (60 seconds) and returns, and the lock holder runs once more before releasing, so a callback that arrives mid lookup is not lost. Lookups use a 10 second total timeout, and the lock is at least twice that. A different amount, currency or payment type is recorded as `mismatch` with no status change and an error log. `paid_at` is Kurv's payment time if present, else `now()`. The resolver sets `kurv_last_lookup_at` on every call and runs for any account, active or not. With no `kurv_request_id` it makes no call and writes no row. A 404 or 429 on a lookup is outcome `error` (error log, no retry, no status change).
- **AC-8**: The callback route `POST webhook/kurv/{kurvAccount}?payment={uuid}&token={token}` is excluded from CSRF (`webhook/kurv/*` is added to the list in `bootstrap/app.php`) and always answers `200` with a short body (Kurv sends JSON, not a form). `{kurvAccount}` is read as a raw id and looked up by hand, because route model binding would answer 404. The token is an HMAC (SHA256, keyed with `APP_KEY`) of the payment UUID, compared with `hash_equals` (the Kurv request id does not exist yet when the callback URL is built). A missing or wrong token, an unknown payment, or a payment of another account is logged at warning level and does nothing. A good token dispatches one lookup (`source=callback`) with `->afterResponse()`, so the 200 returns at once even on the sync queue, and never reads the body for a decision (the body keys are logged for diagnosis only). Limits live inside the controller with `RateLimiter::attempt` (per IP 60 per minute) and a per payment limit of one callback lookup per 10 seconds (`Cache::add`); over a limit it still answers 200 and skips the lookup.
- **AC-9**: After Kurv sends the client back, the pay page reads `kurv_return` on the server (`showKurv`) and passes a display only `returnStatus` (`'returned'` when `kurv_return=1`, else `null`; any other query values Kurv appends are ignored); it shows "We are confirming your payment. Please do not pay again." and starts polling, and it removes the query values from the address bar. The confirming state and its start time are kept in `sessionStorage` under the payment UUID so a reload keeps waiting. The page polls `pay/{payment}/kurv/status` every 3 seconds while the tab is visible. The endpoint reads `Payment.status` and, only for a `pending` payment with a `kurv_request_id`, at most once every 10 seconds per payment (`Cache::add`), runs the resolver (`source=poll`). It returns `state` only: `completed`, `waiting`, `retry` (the last attempt outcome is `declined`; the page clears its `sessionStorage` waiting state and shows "Pay now" again) or `unavailable` (status is neither `pending` nor `completed`, `kurv_link_closed_at` is set, or the account is inactive). It is throttled to 60 per minute per IP. `completed` navigates to Success. After 10 minutes of wall clock time since the first return the page shows "Still confirming, you will get a receipt by email." A return without `kurv_return` shows "Pay now" again.
- **AC-10**: A scheduled sweep `KurvStalePaymentSweep` runs every 10 minutes (`withoutOverlapping`), picks Kurv payments that are `pending`, whose link is older than 2 minutes and younger than 72 hours (config), and whose `kurv_last_lookup_at` is empty or older than 5 minutes, and runs the resolver for each (`source=sweep`). It ignores `is_active`. It never creates a Kurv request and makes only read calls. Each run logs one warning with the count of Kurv payments still `pending` past the window. It also works when `QUEUE_CONNECTION=sync` (no delayed jobs are used).
- **AC-11**: Every lookup writes one `kurv_lookup_attempts` row (source, outcome, Kurv status, result code, plain reason, `kurv_request_id`, `kurv_payment_id`). Outcomes are `paid`, `declined`, `pending`, `mismatch`, `closed`, `duplicate`, `error` and `unknown`. `closed` means Kurv reports the request expired, voided or otherwise unpayable (the exact status values come from step 0); the resolver then sets `kurv_link_closed_at` once, and that column, not the latest attempt row, decides that the payment is Unavailable. A decline, a pending result, an API error, an unknown status and a Kurv value PayHub does not recognize leave `Payment.status` unchanged (v1 never sets `failed`) and are logged; an unknown value never fails a payment. The pay page shows the checkout again after a decline so the client can retry on the same link (only if step 0 shows Kurv allows it, else `closed`). Fixed client messages (never Kurv text): 409 "Your payment is being prepared. Please try again in a moment.", 422 "This payment link can no longer be used.", 429 "Too many attempts. Please try again later.", 502 "We could not start your payment. Please try again."; 409 and 502 show a retry button. Admins see the attempt rows on the payment page, ordered by `created_at`: `kurv_request_id` as the reference, outcome plus Kurv status and result code as the status, `reason`, and `source` as an extra column, with a summary of `kurv_request_id`, `kurv_payment_id` and `kurv_charge_started_at`.
- **AC-12**: A second successful Kurv payment for an already `completed` payment changes nothing. When a callback arrives for a `completed` payment, the resolver runs one history lookup, and a successful row with the same Kurv transaction id but a different payment id than `kurv_payment_id` is recorded as `duplicate` and logged at error level with both ids ("refund the extra payment in Kurv"). A success for a `cancelled` payment is also logged at error level with no change.
- **AC-13**: A step 0 check against the Kurv sandbox and docs answers every item under **Open questions** before the checkout code is built. Answers go in `## Follow-up`. Any field, status or code name that differs is fixed in `KurvClient`, `KurvPaymentRequestReply` and `KurvLookupReply`, the only classes that know Kurv field names. Step 0 is a hard gate: no later build step starts until its answers are recorded.
- **AC-14**: No Content Security Policy change is made (the page only redirects). The API key, the callback token and `kurv_payment_url` are never logged in full, put in a URL the client sees, or returned to the browser; logs mask the token. Card values never exist in PayHub.

## Decision

**Chosen option**: Option 1: Kurv's hosted payment page by redirect, with paid status read from Kurv's API after an unsigned callback, a throttled poll lookup and a timed sweep.

PayHub's server creates one Kurv payment request per payment from the `Payment` amount and sends the client to Kurv's page. The callback, the waiting page and a 10 minute sweep only trigger a read only lookup; `KurvChargeResolver` alone writes status, and only from Kurv's own answer. The Gateway Payment Component (embed) is kept as the fallback if the hosted page proves too limited.

**Implementation skills**: `laravel-best-practices` (`.claude/skills/laravel-best-practices/`) · `inertia-vue-development` (`.claude/skills/inertia-vue-development/`) · `wayfinder-development` (`.claude/skills/wayfinder-development/`) · `pest-testing` (`.claude/skills/pest-testing/`) · `provider-parity-check` (`.claude/skills/provider-parity-check/`)

## Rationale

Reasoning and options: see `rationale.md`.

## Feature design

**Data model sketch** (new migrations; nothing built yet):

- `kurv_accounts`: id, `account_name`, `prefix` nullable, `api_key` (encrypted, not mass assignable), `environment` (`sandbox` or `production`), `is_active`, timestamps.
- `payments` (new columns): `kurv_account_id` FK nullable, `kurv_request_id` string nullable indexed (Kurv's payment request `transaction_id`), `kurv_payment_url` text nullable (never sent to the page), `kurv_payment_id` string nullable (Kurv's confirmed payment id), `kurv_charge_started_at` timestamp nullable (the link exists: amount, currency, account, brand lock; delete blocked), `kurv_last_lookup_at` timestamp nullable (set by the resolver on every lookup; the sweep reads it), `kurv_link_closed_at` timestamp nullable (set once when Kurv reports the link closed; unlocks delete and edits).
- `kurv_lookup_attempts`: id, `payment_id` FK, `source` (`callback`, `return`, `poll`, `sweep`), `outcome` (`paid`, `declined`, `pending`, `mismatch`, `closed`, `duplicate`, `error`, `unknown`), `kurv_status` nullable, `result_code` nullable, `reason` nullable (plain words), `kurv_request_id` nullable, `kurv_payment_id` nullable, `created_at`. Diagnostic only, never decides status, never holds card data or the key. (`return` is reserved for a future return time lookup; v1 uses `poll`.)
- No processed events table: the callback has no event id, and the guarded update makes repeats harmless.

Relationships: `kurv_accounts` 1:N `payments`; `payments` 1:N `kurv_lookup_attempts`. Agents reach an account through `user_payment_accounts`, as for the other providers.

**State transitions**: `pending` to `completed` only through `KurvChargeResolver` (it also accepts `failed` as a source like the other providers, but Kurv never sets `failed` in v1). A decline, an error, an unknown status and a pending result leave `pending` unchanged. `completed` is terminal.

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `kurv-accounts` (resource, except `show`), `activate`, `deactivate`, `test-connection` | GET/POST/PUT/DELETE/PATCH | account fields from AC-1 | rows with `has_api_key`, never the key | `role:admin` | 403, 404, 422 |
| `pay/{payment}` and `pay/{payment}/{ref}` (existing, new `showKurv` branch) | GET | payment UUID, optional `kurv_return` | Inertia page with brand, policies, amount, currency, `hasLink`, `returnStatus` | public (UUID) | 404, redirect to Success when completed, Unavailable otherwise |
| `pay/{payment}/kurv/checkout` | POST | none (UUID in path) | JSON `{url}` | public (UUID), limiter `kurv-checkout` | 409 busy, 422 not payable or no consent, 429, 502 |
| `pay/{payment}/kurv/status` | GET | payment UUID | JSON `{state}` (`waiting`, `completed`, `retry`, `unavailable`) | public (UUID), throttle 60 per minute | 404, 429 |
| `webhook/kurv/{kurvAccount}` | POST | query `payment`, `token`; body ignored | `200` always | token, CSRF excluded, limits inside the controller | none (always 200) |
| `KurvStalePaymentSweep` | scheduled, every 10 min | none | lookup attempts | system | none |

**Value sourcing**:

| Action | Value produced / displayed | Source |
|---|---|---|
| Pay page | button amount, currency | `Payment.amount`, `Payment.currency` |
| Pay page | `hasLink` | `payments.kurv_request_id` is not null (the id and URL are not sent) |
| Pay page | `hasConsent` | the existing `payment_consents` record |
| Pay page | `returnStatus` | `'returned'` when the `kurv_return` query is `1`, else `null`, read server side in `showKurv`, display only |
| Checkout | API key, host | the payment's `kurv_accounts` row (`api_key` decrypted, `config('services.kurv.hosts.{environment}')`) |
| Checkout | `amount` | `Payment.amount` cents converted to Kurv's decimal with integer math (format per step 0), never the request |
| Checkout | `currency` | `Payment.currency` (case per step 0) |
| Checkout | `customer_first_name`, `email` | `Payment.client_name`, `Payment.client_email` (whether a last name is also needed is a step 0 question) |
| Checkout | `reference_number` | `Payment::formattedReferenceCode()` |
| Checkout | request method, payment type | constants (web, sale) |
| Checkout | `response_url` | `route('webhook.kurv', $account)` plus the `payment` UUID and the callback token (HMAC of the UUID, key `APP_KEY`; the callback check recomputes the same value) |
| Checkout | `redirect_url`, `cancel_url` | `route('pay.show', $payment)` (https), with `kurv_return=1` on redirect only |
| Checkout | reply `url` | `payments.kurv_payment_url`, saved from Kurv's `long_url` (or `short_url`, per step 0) |
| Checkout | consent check | the existing `payment_consents` record |
| Checkout | timeouts, lock length | `config('services.kurv.connect_timeout')` (10), `timeout` (30), `lock_seconds` (60) |
| Limiter | keys | `kurv-payment:{uuid}` and `kurv-ip:{ip}` |
| Callback | which account and key | route `{kurvAccount}`; the payment is found by the `payment` query UUID and must belong to that account |
| Resolver | which Kurv request | `payments.kurv_request_id` (never a value from the callback body) |
| Resolver | paid, amount, currency, payment type, payment id | Kurv lookup fields (names per step 0) compared with `Payment` in integer cents |
| Resolver | `paid_at` | Kurv's payment time if present, else `now()` |
| Resolver | closed link | Kurv status values from step 0; sets `kurv_link_closed_at` |
| Resolver | last lookup time | `kurv_last_lookup_at`, set on every call |
| Poll | state shown | `Payment.status`, `kurv_link_closed_at`, the account's `is_active`, and the last attempt outcome (`declined` gives `retry`) |
| Poll | lookup throttle | `Cache::add('kurv:poll:{payment id}', 1, config('services.kurv.poll_lookup_seconds'))` (10) |
| Sweep | which payments | provider `kurv`, `pending`, link age between `min_age_seconds` (120) and `sweep_hours` (72), `kurv_last_lookup_at` empty or older than `lookup_gap_seconds` (300) |
| Attempts | rows | one per lookup, from the Kurv reply and the source |

**Key invariants**:
- Only `KurvChargeResolver` sets `completed`, and only from Kurv's own API answer with matching transaction id, amount, currency and a sale payment type. The browser, the return URL, the callback body and the checkout reply never do.
- The callback token uses the payment UUID alone (known when the link is built) keyed with `APP_KEY`; it only decides whether a lookup is queued, never what status is. A forged callback can cost one throttled lookup at most.
- A checkout never uses an amount, currency or account from the request.
- Each payment has at most one Kurv payment request, and its amount, currency, account and brand never change after it exists.
- `completed` is terminal. A second success or a success for a `cancelled` payment changes nothing and logs at error level.
- Rows are matched on the Kurv transaction id: Kurv returns old rows for a reused reference (for example `wc-31`), so a reference match alone never counts.
- Amounts are integer minor units everywhere in PayHub; Kurv's decimal format exists only at the edge of `KurvClient` and the reply classes.
- Any Kurv status or code PayHub does not recognize is logged, recorded as `unknown`, and never fails a payment.

**Security model**:
- Account management is `role:admin` only. Pay, checkout and status routes are public but scoped by the unguessable payment UUID. The callback is public and protected by the token and a throttle, and it cannot change status on its own.
- **Compliance scope is PCI DSS.** Kurv collects the card on its own page and PayHub only redirects, so the target is SAQ A, the lightest case. Confirm with Kurv or the acquirer before production.
- Kurv's hosted page cannot be embedded or styled by PayHub, and PayHub cannot hide the ZIP or country fields. Live payments need a Kurv plan that allows payment requests (the Start plan returns 422 "Invoicing"); this is a business prerequisite.
- Checkout abuse is limited by the limiter, the lock and the one request per payment rule (AC-6).
- Raw Kurv text goes to logs and the attempts table for operators only. Clients see the fixed strings in AC-11. The API key and the callback token are never logged in full.
- Audit trail: `kurv_request_id` and `kurv_payment_id` on the payment plus `kurv_lookup_attempts`; every refused or mismatched result is logged with both ids.

**Configuration required**:
- `config/services.php` `kurv`: `hosts` (`sandbox` `https://api-sandbox.kurv.app`, `production` `https://api.kurv.app`), `lock_seconds` (60), `connect_timeout` (10), `timeout` (30, for create), `lookup_timeout` (10, total, for lookups), `poll_lookup_seconds` (10), `min_age_seconds` (120), `lookup_gap_seconds` (300), `sweep_hours` (72).
- No `.env` secrets: the API key lives encrypted per account. The scheduler must run (`php artisan schedule:run` every minute by cron), as it does for MPGS and Authorize.net.
- Outside the code: a Kurv merchant account per brand with a `kp_test_` key (sandbox) and later a `kp_live_` key on a plan that allows payment requests.

**Open questions** (answered by the step 0 check; none tested yet unless noted):
1. Create reply fields: is `long_url` or `short_url` the page to redirect to; is `customer_first_name` enough or is a last name needed; does `request_methods: WEB` alone return a link?
2. `amount` format (whole units with decimals, such as `15` or `15.00`) and `currency` case. The WooCommerce test paid `$10` and `$123` correctly, so decimals in dollars work; confirm cents like `12.34`.
3. `GET /payment-requests/{id}` fields: the status values (`success` seen; pending, expired, voided, failed unknown, and which of them mean the link is closed), the expiry field name (`expires_at`?), and where `amount`, `currency`, `payment_type` and `payment_id` sit.
4. Decline: the status, `result_code` and description (code 253 was seen on `pay.kurv.app` and is unexplained), and whether the client can retry on the same link.
5. Does a link expire (`expiry_date` format is rejected), and what happens when a paid link is opened again? Can one link be paid twice (order 31 was paid twice through two links)?
6. What query values does Kurv append to `redirect_url`, and does `cancel_url` exist and work?
7. Callback: POST JSON raw body (seen); does Kurv retry it, from which IPs, and can it arrive before the lookup shows the payment (seen once)?
8. A cheap read only call for Test connection, and whether it needs a plan feature.
9. `GET /payments?keywords=` paging, and whether a row can be matched on transaction id.
10. Sandbox decline test cards or amounts, and 429 limits.
11. Live prerequisites: the plan that allows payment requests, the failed PCI scan, approval for live.

**Critical test scenarios** (all Kurv calls use `Http::fake`; the real service is covered only by step 0 and a staging run):
- Happy path: checkout creates one request, the callback queues a lookup, the resolver completes the payment once, one notification, poll then returns `completed`, verifies **AC-5**, **AC-7**, **AC-8**, **AC-9**
- Second checkout call reuses the saved link with no Kurv call; two at once make one Kurv call; the eleventh call in an hour is a JSON 429, verifies **AC-5**, **AC-6**
- No consent record, wrong provider, not pending, inactive account: 422 and no Kurv call; a body with an amount or account is ignored, verifies **AC-5**
- Kurv 4xx, 5xx or timeout on create: 502, nothing saved, the edit lock is not set, verifies **AC-5**
- Cancel, delete, brand, amount, currency and account changes are rejected once the link exists, verifies **AC-6**
- Lookup success with a different amount, currency or payment type: `mismatch`, no status change, error log, verifies **AC-7**
- A row with the right reference but another transaction id is ignored, verifies **AC-7**
- Two resolvers at once: one update, one notification, verifies **AC-7**
- Callback with a bad token, an unknown payment or another account's payment: 200, warning, no lookup, no status change; a forged body is never read for a decision, verifies **AC-8**
- Callback before the lookup shows the payment: `pending`, then the sweep completes it, verifies **AC-8**, **AC-10**
- Return with `kurv_return=1` shows the confirming state and strips the query; reload keeps it; no flag shows "Pay now", verifies **AC-9**
- Poll runs at most one lookup per 10 seconds and returns `state` only; it is throttled, verifies **AC-9**
- Sweep picks only pending Kurv payments in the age window, skips ones checked in the last 10 minutes, makes no create call, and works with the sync queue, verifies **AC-10**
- Decline, pending, unknown status and API error: status unchanged, one attempt row each, an unknown value never throws, verifies **AC-11**
- Client messages for 409, 422, 429 and 502 are the fixed strings, never Kurv text, verifies **AC-11**
- A second paid row for a completed payment: `duplicate` row and error log, no status change; a success for a cancelled payment: no change, verifies **AC-12**
- A completed payment redirects to Success; a Kurv payment page never renders Stripe, verifies **AC-3**, **AC-4**
- GBP and PKR are rejected for Kurv; `client_email` is required, verifies **AC-2**
- CSV export, dashboard filter, "Accounts Today" and `CentralSummary` include a Kurv payment, verifies **AC-3**
- Account form: key prefix and environment must match; the key is never returned; Test connection reports the plain messages, verifies **AC-1**
- The security headers test shows no CSP change on the Kurv pay page; logs hold no key or full token, verifies **AC-14**
- Create gets a 429 once and succeeds on the single retry; a caller that cannot get the checkout lock within 5 seconds gets 409, verifies **AC-5**, **AC-6**
- A closed link or an inactive account renders Unavailable; page props hold no key, URL or request id, verifies **AC-4**
- A decline makes the poll return `retry` and the page shows "Pay now" again, verifies **AC-9**, **AC-11**
- A `closed` result sets `kurv_link_closed_at`; delete and an account change are then allowed, verifies **AC-6**, **AC-11**
- A callback for an unknown account or over a limit answers 200, and the lookup runs after the response, verifies **AC-8**
- A callback for a completed payment triggers exactly one history lookup; a callback that loses the lock causes one more run by the holder, verifies **AC-7**, **AC-12**
- The sweep and the callback complete a payment on an inactive account, verifies **AC-4**, **AC-10**
- A 404 or 429 on a lookup is outcome `error` with no status change; no `kurv_request_id` means no call and no row, verifies **AC-7**
- Account form: delete is blocked while payments use the account; activate and deactivate work; a blank key keeps the stored one; an environment change is checked against the stored key; both Test connection routes work; the plan 422 gives the plain message, verifies **AC-1**
- The 10 minute message appears after 10 wall clock minutes and the query values leave the address bar, verifies **AC-9**
- The callback token is masked in log lines, verifies **AC-14**

## Build plan

Build approach: none recorded in `AGENTS.md`, so end to end Tracer Bullet slices are assumed. The first thread goes from checkout to callback to a lookup to a completed payment against the Kurv sandbox, because it is the one path testable end to end.

0. [~] Kurv check against the sandbox (API part done 2026-11-05, browser part open) and docs: answer every Open question, record answers in Follow-up, fix field names in `KurvClient`, `KurvPaymentRequestReply` and `KurvLookupReply`; hard gate: nothing after it starts until the answers are recorded, satisfies **AC-13**
1. [x] Migrations (`kurv_accounts`, payments columns, `kurv_lookup_attempts`), `PaymentProvider::Kurv`, models, factories, `config/services.php`, satisfies **AC-1**, **AC-11**
2. [x] `KurvClient` (create request, get request, search payments, a read only ping), the two reply classes, `KurvChargeResolver` with its lock and guarded update, satisfies **AC-7**, **AC-11**, **AC-12**
3. [x] Callback route, token helper, per IP throttle, CSRF exclusion in `bootstrap/app.php`, satisfies **AC-8**
4. [x] Admin account CRUD, activate, deactivate, test connection, satisfies **AC-1**
5. [x] Payment create and update requests, `CurrencySupportResolver`, `client_email` rule, edit and cancel locks, agent assignment, satisfies **AC-2**, **AC-6**
6. [x] Checkout endpoint with consent check, lock, saved link reuse and the `kurv-checkout` limiter, satisfies **AC-5**, **AC-6**
7. [x] `showKurv` in `ClientPaymentController`, the status endpoint with its throttled lookup, and `PayKurv.vue` (consent, amount, button, redirect, `returnStatus`, waiting state in `sessionStorage`, polling, query clean up, fixed messages), satisfies **AC-3**, **AC-4**, **AC-9**, **AC-11**, **AC-14**
8. [x] `KurvStalePaymentSweep` and its schedule in `routes/console.php`, satisfies **AC-10**
9. [x] Admin attempts panel on the payment page, mapping rows into the existing `attemptHistory()` shape as in AC-11, satisfies **AC-11**
10. Parity sweep: CSV export match arm, `DashboardMetrics` (filter whitelist, account names, "Accounts Today", totals), `CentralSummary`, receipt pages, agent forms, and `PaymentController` (the `show()` `provider_reference` `match` as `kurv_payment_id ?? kurv_request_id`, the account list, the `account_id` chain, `agentAccountData`, and the `clearStaleProviderTransactionIds` map for `kurv_account_id`), plus `Payment::providerAccountName()`, `$fillable` and `$casts`, with the `provider-parity-reviewer`, satisfies **AC-3**
11. Pest tests for the scenarios above (`php artisan test --parallel`), then `npm run build` and regenerate Wayfinder files, satisfies **AC-1** to **AC-14**
12. Staging run with the Kurv sandbox (success, decline, closed tab, repeated callback), then a small live payment and a refund by hand once the plan allows it

## Consequences

**Positive**:
- Card details never touch PayHub, and the page is the lightest PCI case (SAQ A).
- One more provider on the existing rules with a known pattern (unsigned notice, read back from the API), reusing the sweep and attempts ideas from Authorize.net and MPGS.
- The attempts table will show the real decline values, which Kurv's docs do not give.
- The Gateway embed stays available as a later, separate spec.

**Negative / tradeoffs**:
- The client leaves PayHub for Kurv's page, which PayHub cannot style or embed, and ZIP and country cannot be hidden.
- Status needs an API lookup, so a Kurv outage or a slow first lookup leaves a paid payment `pending` until the sweep catches it.
- v1 never sets `failed`, so a declined payment stays `pending` until it is paid, cancelled by an admin before a link exists, or ages out of the sweep window.
- A paid link may be payable twice; only an error log marks the extra payment, and staff must refund it in Kurv by hand.
- Live use needs a paid Kurv plan and a clean PCI scan, outside PayHub's control.
- Kurv's docs differ from the live API (callback body, response shape), so step 0 is mandatory and more differences may appear.

**Neutral**:
- Refunds and captures stay in the Kurv dashboard; PayHub does not track them.
- `AGENTS.md` still says "eight providers" until `/sync` updates it.
- The WooCommerce plugin fix (`docs/kurv-woocommerce-main`, 1.0.1) is separate and unaffected.

## Follow-up

- [x] Pulled `master` into `feature/kurv-provider-spec` before building (2026-11-05).
- [ ] Build steps 1 to 5 landed 2026-11-05 (52 Kurv tests green). Open: `KurvLookupReply::CLOSED_STATUSES` (`expired`, `voided`, `void`, `cancelled`, `canceled`) is a guess until a real closed link is seen; a PKR payment on Kurv shows the USD only message (AC-2).
- [ ] Build steps 6 and 7 landed 2026-11-05 (checkout, status poll, `showKurv`, `PayKurv.vue`; 35 more tests). Deviations to ratify: (a) the expiry check in AC-5 ("saved link past its expiry returns 422") is not built, because PayHub stores no expiry (the create reply has none and `expiration_date` was null in every lookup); a dead link is instead caught by the lookup setting `kurv_link_closed_at`. (b) `retry` is returned only when the latest attempt is a `declined` row less than 60 seconds old, so a stale decline cannot bounce a client who is paying again. (c) `public/build` is not rebuilt yet (step 11).
- [ ] Build steps 8 and 9 landed 2026-11-05 (`KurvStalePaymentSweep`, schedule `kurv:sweep-stale-payments`, admin attempts panel in `payments/Show.vue`; 8 more tests). Deviations to ratify: the sweep also skips payments whose `kurv_link_closed_at` is set (a closed link cannot be paid, so looking again only repeats calls), and it uses the 5 minute `lookup_gap_seconds` from AC-10 (the test list in this spec says 10 minutes; the AC and config win).
- [ ] Until build step 10, exhaustive `PaymentProvider` matches elsewhere (CSV export, dashboard, `CentralSummary`, client pages) are not yet Kurv aware; do not create Kurv payments on staging before then.
- [~] Step 0 check, sandbox part done 2026-11-05 (API calls only; the browser parts are still open, see below). Answers:
  - **Q1** Create with `request_methods: ["WEB"]`, `customer_first_name` (full name), `email` returns `result: success`, `transaction_id`, `short_url`, `long_url`, `qrcode_link`. Redirect to `long_url` (host `sandbox-pay.kurv.app`); `short_url` is `krpy.me`. The request lookup later shows a `last_name` split from the email, so no last name is needed. Keep `fixed_amount: true`.
  - **Q2** `amount` is a JSON number in whole units with decimals (`12.34` worked, echoed back as `12.34`). `currency` must be upper case: `usd` gave 422 "merchant does not have access to the specified currency" (misleading text). Send `USD`.
  - **Q3** `GET /payment-requests/{id}` returns `{transaction: {...}}`. Seen `status`: `pending` (invoice_status `unpaid`) and `success` (invoice_status `paid`, with `payment_id`, `payment_date`, `amount`, `currency`, `payment_type` `DB`). Expiry field is `expiration_date` (null so far). No expired, voided or failed request status seen yet, so `closed` stays unproven. An unknown id gives 422 `transaction_id is not valid` (not 404).
  - **Q4** A decline does not change the request: it stays `pending` and a NOK row appears in `GET /payments` (`status: NOK`, `status_label: failed`, `payment_id` empty, reason only in `result_description`, for example "Card Declined (Do not honor)", "Card Declined (Insufficient funds)", "invalid postal code: N"). `result_code` is absent on rows. The same request can be retried: request 1074083OO had a NOK row and later became `success`. So a decline means "client retries on the same link" and PayHub needs no `closed` for it. Code 253 is not explained by any row.
  - **Q5** Double payment still unproven (order 31 was paid twice through two links). Link expiry and reopening a paid link: not tested.
  - **Q6, Q7** Need a browser payment: what is appended to `redirect_url`, whether `cancel_url` works, callback retries, sender IPs. Open.
  - **Q8** Test connection ping: `GET /payments?limit=1` works with a valid key. A bad key returns 403 (not 401), so AC-1 messages must treat 401 and 403 from a bad key as "wrong key"; the plan problem is still the 422 about Invoicing. To tell a missing permission from a wrong key, treat 403 as "wrong key or missing permission".
  - **Q9** `GET /payments` is paged: keys `payments`, `page`, `limit`, `page_count`, `total_count`; `limit` max 50 (more gives 422). Rows carry `transaction_id`, `payment_id`, `amount`, `currency`, `payment_type`, `status` (`ACK` or `NOK`), `status_label` (`success` or `failed`), `reference_number`, `payment_date`. Matching on `transaction_id` works. `GET /payments/{payment_id}` also works and returns `{payment: {...}}`.
  - **Q10** No decline test cards found. Failed rows in the history came from earlier plugin tests (do not honor, insufficient funds, bad postal code). No rate limit headers were returned; 429 limits unknown.
  - **Q11** Live plan, PCI scan: still a business step, unchanged.
  - Changes to make in the build: AC-1 test connection treats 403 as wrong key or missing permission; AC-7 can read the payment from the request lookup (`status` `success`, `payment_id`, amount, currency, `payment_type` `DB`) and use `GET /payments/{payment_id}` instead of a keyword search when `payment_id` is present; AC-11 `declined` outcome comes from a NOK history row for the same `transaction_id`.
  - Still to do in a browser (a human card payment on the sandbox page): Q5, Q6, Q7, Q10 sandbox test cards.
- [ ] Get a Kurv plan that allows payment requests live, and settle the failed PCI scan, before any live payment (research: `docs/research/kurv.md`).
- [ ] Confirm PCI level (SAQ A) with Kurv or the acquirer in writing.
- [ ] Ask Kurv: signed callbacks or fixed sender IPs, official sandbox decline cards, what result code 253 means.
- [ ] Manual checklist for step 0 and step 12 (no Pest test covers the real Kurv service): create a request, pay it, decline one, close the tab, replay the callback, open a paid link again, and write each result here.
- [ ] After the 72 hour window a payment stays `pending` and counts as pending in `DashboardMetrics` and `CentralSummary`; the sweep only logs a count. Decide whether to expire such payments once the real failure values are known.
- [ ] Revisit setting `failed` once the real decline status and codes are known from the attempts table.
- [ ] Revisit an automatic refund of a `duplicate` payment only if double payments happen in practice.
- [ ] After the build, `/sync` updates `AGENTS.md` (nine providers, a Kurv bullet, the CSRF list) and `docs/agent.md`.
- [ ] Refresh the `provider-parity-reviewer` agent and skill text to cover nine providers.
- [ ] `phpunit.xml` hardcodes `APP_BASE_PATH` for another machine; override it locally before running tests.
