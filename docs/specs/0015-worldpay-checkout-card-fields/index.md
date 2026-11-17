# 0015. Worldpay Checkout card fields on the PayHub pay page

**Date**: 2026-11-17
**Status**: Proposed

## Summary

This spec adds a second way to pay through Worldpay: the card fields sit on PayHub's own pay page (Worldpay's Checkout SDK, a script that draws the card inputs) instead of sending the client to Worldpay's hosted page from spec 0014. Each Worldpay account is set to `hosted` or `card_fields`, so nothing already built changes. PayHub's server charges the card with the amount from its own payment record, handles the bank's 3D Secure check inside frames on the page, and marks the payment paid only after reading a settled sale back from Worldpay, the same rule as 0014, NMI and Authorize.net. Google Pay is the last build step, switched off by default, and several Worldpay facts (the charge body, 3D Secure hosts, the PCI form) are not confirmed in public docs, so a real Try check (step 0) comes first.

## Requirements

**User stories**:
- As an admin, I want to set a Worldpay account to `hosted` or `card_fields`, enter its Checkout ID, and test it, so I choose the flow per brand.
- As a client, I want to enter my card on the PayHub page, pass any bank check without leaving it, and see a clear result.
- As a client whose card is refused, I want to try again a few times on the same link.
- As the business, I want a payment marked paid only after PayHub's server has read one settled sale for the exact amount in GBP from Worldpay.
- As an admin, I want to see each charge try (outcome, plain reason, 3D Secure step) on the payment page, so I can understand a refusal or a stuck payment.

**Acceptance criteria** (the contract, each one IDed and checkable on its own):

- **AC-1**: `worldpay_accounts` gains `mode` (`hosted` default, or `card_fields`), `checkout_id` (nullable, a public id, not encrypted), `google_pay_enabled` (boolean, default false) and `google_pay_gateway_merchant_id` (nullable, public). The admin form (Create, Edit, Index) shows a Mode choice. `checkout_id` is required and must be a UUID when the mode is `card_fields` and ignored when `hosted`. Google Pay can only be turned on in `card_fields` mode and then needs the gateway merchant id. Help text says where the values come from ("The Checkout ID comes from your Worldpay implementation manager. Use the Try values for test mode and the Live values for production"). Existing 0014 behavior and fields are unchanged, and the credentials are still never returned to the browser.
- **AC-2**: The `mode` of an account cannot change while any payment uses it (422 "This account already has payments. Create a new account to change the mode."), the same rule as environment and entity in 0014 AC-1. `checkout_id` and the Google Pay fields stay editable. A `hosted` account keeps working exactly as in 0014. `pay/{payment}/worldpay/checkout` (the hosted route) answers 422 "This account takes card payments on the PayHub page." for a `card_fields` account, and the new card routes answer 422 for a `hosted` account. Neither route calls Worldpay in that case.
- **AC-3**: Test connection keeps the 0014 read only ping and its messages. For a `card_fields` account it also checks the saved `checkout_id` shape (a UUID) and, on success, shows "Connected. The Checkout ID is checked when the card fields load on a real payment page." It never creates a session or a payment. The admin form says plainly that Test connection cannot prove the Checkout ID or its domain allow listing, and that the first real pay page load does (an admin can open a Try payment link to check).
- **AC-4**: For a `card_fields` account `showWorldpay` renders a new page `PayWorldpayCard.vue` with the brand, policies, amount, currency, `hasConsent`, the account's `checkout_id`, the SDK script host for the environment, `googlePay` (null, or its public settings) and `cardState`, computed server side from the newest real attempt (rows with `step` of `event` are ignored): `ready` (show the form), `waiting` (an attempt is `approved` or `unclear`: no form, start polling), `retry` (last try refused, form shown with the fixed refusal message), `3ds_interrupted` (an unfinished check: form shown with "Your bank check did not finish. Please try again.") or `blocked` (retry limit or total cap reached: no form, "This payment link can no longer be used. Please contact the business."). A reload after a lost charge reply therefore never shows an empty form while a possible charge exists. The SDK script tag is added by the page with the request's CSP nonce (`Vite::cspNonce()`) passed as a prop, loaded from the environment's host only. The client must agree to the terms first (the existing `storeConsent` action) before the card fields mount. The form asks for the cardholder name (prefilled from the payment when known) plus Worldpay's card number, expiry and CVV fields, nothing else. Card values never touch Inertia form state, a URL, storage or logs, and the card form carries `sentry-block`. A completed payment redirects to Success. A `hosted` account still renders `PayWorldpay.vue`.
- **AC-5**: `POST pay/{payment}/worldpay/card/charge` (`worldpay-card-charge` limiter, consent required) takes only a `session` (Worldpay's one use card session href from the SDK), `method` (`card` or `google_pay`) and `cardholder_name`, or the Google Pay `token`. It never takes an amount, currency, account or reference from the request. The server charges the amount and currency from the `Payment` record, with `merchant.entity` from the account, a `transactionReference` of `{payment uuid}-c{sequence}`, a narrative from the brand name cleaned to the 0014 narrative rules, and automatic settlement requested. The `session` must be an `https` URL on a host in `config('services.worldpay.session_hosts.{environment}')`, start with the path prefix in `session_path_prefix` (step 0) and be at most 512 characters; anything else is a 422 and no call. `cardholder_name` is 2 to 60 letters, spaces, `.`, `'` and `-`, and is sent to Worldpay but never stored. The Google Pay `token` is at most 8192 characters and is never stored or logged. Sentry never receives these three values: the three card routes are scrubbed in `before_send` (request body removed). The attempt row is inserted inside the per payment lock before the Worldpay call, with `sequence` set to the highest existing real sequence plus 1 and `worldpay_charge_started_at` set in the same transaction (the unique index is only a backstop). The Worldpay charge call uses a `card_http_timeout_seconds` (30) timeout and no automatic retry; the lock (`card_lock_seconds`, 90) always outlasts it. Refused conditions return fixed plain messages and make no Worldpay call: payment not `pending`, wrong provider, account inactive or `hosted`, no consent, card lock active (the existing `CardLockGuard`, 423), pause key set, retry limit reached or the total cap reached (`card_max_attempts`, 10 real attempts of any outcome, so odd error replies cannot be used for card testing). The refusal limit and the cap are also checked when the page loads (`cardState` `blocked`), before the fields mount.
- **AC-6**: A charge reply never completes a payment. The charge reply is mapped to an attempt outcome and a client state: `authorized` or `sentForSettlement` gives `approved` and state `waiting` (the resolver, AC-9, decides paid); `refused`, `fraudHighRisk` and `3dsAuthenticationFailed` and `3dsUnavailable` give `refused` or `challenge_failed` and state `retry` with a fixed message; `3dsDeviceDataRequired` and `3dsChallenged` give state `3ds` (AC-7); a timeout, an unreadable reply, a 5xx or a duplicate answer gives `unclear` (AC-8); any other 4xx gives `error` with state `retry` and the code logged only. An unknown outcome name never fails a payment and is logged. Worldpay's own text is never shown to the client or stored in `reason`; PayHub writes the reason sentence.
- **AC-7**: 3D Secure runs inside frames on the pay page. When Worldpay asks for device data, the server returns only what the browser needs to load Worldpay's device data frame (field names confirmed in step 0), the browser reports completion to `POST pay/{payment}/worldpay/card/3ds` (`step` of `device_data` or `challenge`), and the server then makes the next Worldpay call using the next link it saved itself (in the attempt's encrypted `three_ds_state` column, never sent to or taken from the browser, cleared after one use, so a replay is refused). A challenge returns the challenge frame details the same way. The bank's result may come back as a POST inside the frame, so a separate route `POST|GET pay/{payment}/worldpay/card/3ds-return` (CSRF exempt, listed in `bootstrap/app.php`, `frame-ancestors 'self'` on this response only) renders a tiny page that tells the parent page "done" through `postMessage` to the same origin and carries no Worldpay value to the browser; the server then makes the next call itself. The same attempt row is updated through `step` (`charge`, `3ds_device_data`, `3ds_challenge`). The `3ds` endpoint rules: it picks the newest `challenge_required` attempt of the payment, refuses (422) when the `step` it was sent does not match the attempt's `step` or `three_ds_state` is empty, takes the same per payment lock, sets the pause key before the Worldpay call (device data and challenge calls can authorize), and treats a timeout as `unclear` like a charge. A failed or unavailable 3D Secure result is a refusal and counts toward the retry limit; the charge is never retried without 3D Secure. A new charge call marks any older `challenge_required` attempt of the payment `abandoned` and clears its `three_ds_state`; an abandoned check never blocks, because money only moves when PayHub's server makes the next call, which the lock already covers.
- **AC-8**: Double charge guard (the Worldpay Payments API has no confirmed idempotency key, step 0 asks): a per payment lock `worldpay:card:{payment id}` (`card_lock_seconds`, 90, `get()`, busy gives 409 "Your payment is being prepared. Please try again in a moment."), a 120 second pause key set before every charge or 3D Secure call and kept on an `unclear` outcome, and no new charge while any attempt of the payment is `approved` or `unclear` (`hasPossibleCharge`, whether or not it has a Worldpay payment id) until a lookup by that attempt's own reference settles it (the client sees "We are confirming your payment. Please do not pay again."). An `unclear` or `approved` attempt with no paid row becomes `not_found` (block lifted) only after `card_not_found_lookups` (3) lookups spread over at least `card_not_found_minutes` (10) since the attempt; a row in Payment Queries always wins over `not_found`, and a later paid row on a `not_found` attempt is still caught by the sweep (AC-10). An attempt stuck `approved` with no paid row after `card_stuck_hours` (24) sends a Sentry alert once, shows admins "This payment has a charge that Worldpay has not settled" on the payment page, and keeps new charges blocked until an admin resolves it in Worldpay. The pause key itself ends after its 120 seconds; the attempt based block above is the lasting one. Each attempt has its own unique `transactionReference`, so a repeated call can never reuse a reference. Once the first attempt row exists, `worldpay_charge_started_at` is set and amount, currency, account, brand and deletion are locked for good (0014 rule).
- **AC-9**: `WorldpayChargeResolver` stays the only status writer and the only writer of `worldpay_payment_id`. It now also looks up the `transactionReference` of the payment's `worldpay_charge_attempts` rows that are not final (`approved`, `unclear`, `challenge_required`) on every run, and of the final ones (`refused`, `challenge_failed`, `abandoned`, `not_found`) only once, from the sweep, near the end of its window (so a late paid row is still caught, without a lookup per refused try on every poll); event rows have no reference and are skipped. Lookups still cover every `worldpay_pages` row of a hosted payment, through Payment Queries, never a value from the request or webhook body. `completed` needs one settled row (`paid_events`) for the exact amount in `Payment.amount` minor units, currency GBP and a reference that belongs to this payment; a different amount, currency or reference gives `mismatch`; two paid rows or a second paid sale gives `duplicate`; both write an error log and change nothing, and `duplicate` also sends a Sentry alert once and shows admins a banner on the payment page ("Two charges were found for this payment. Refund one in Worldpay."). A resolver run writes the attempt's `outcome` forward only (`approved`, `unclear` and `not_found` can move to `refused` once Worldpay shows a refusal or to a paid state, never backward). Each lookup row records the attempt it searched through the nullable `charge_attempt_id`. The 0014 outcome table, locks, recheck key and deadline apply unchanged.
- **AC-10**: `pay/{payment}/worldpay/status` (existing) works for card payments: it returns `{state}` (`waiting`, `completed`, `retry`, `blocked`, `unavailable`) from `Payment.status`, the account's `is_active` and the newest real attempt (event rows ignored), and runs at most one lookup per `poll_lookup_seconds`. `WorldpayStalePaymentSweep` also picks `card_fields` payments with any attempt that is `approved`, `unclear`, `challenge_required` or `not_found` (and, once near the end of the window, final ones, AC-9) between `min_age_seconds` and `sweep_hours`, skipping ones checked in the last `lookup_gap_seconds`, and never makes a charge call.
- **AC-11**: Every charge, 3D Secure step and resolver outcome writes or updates a `worldpay_charge_attempts` row: `sequence`, `transaction_reference`, `method`, `outcome` (`approved`, `refused`, `challenge_required`, `challenge_failed`, `unclear`, `error`, `abandoned`, `not_found`), `step` (`charge`, `3ds_device_data`, `3ds_challenge`, `event`), `last_event`, `response_code`, a PayHub written `reason`, `worldpay_payment_id`, and a short browser label (`client_info`, `App\Support\ClientInfo`). Rows are diagnostic only and never hold a card value, the session href, a wallet token, a username, password, link or signature. Admins see them on the payment page through the existing `attemptHistory()` shape, with a summary of the Worldpay payment id and the attempt count.
- **AC-12**: `POST pay/{payment}/worldpay/card/event` (`WorldpayCardEventRequest`) accepts a fixed list of page reports (`fields_not_loaded`, `init_failed`, `session_failed`, `three_ds_frame_failed`, `timed_out`, `errored`) with Worldpay's own code and message as cleaned, length limited text. It is rate limited, writes one row with `step` `event` and outcome `error` at most once per 60 seconds per payment (`sequence` and `transaction_reference` are null on event rows), and never locks the payment or takes the card lock. Event rows are ignored by the poll, the sweep, the resolver, `hasPossibleCharge`, the retry limit and the total cap, and show in the admin panel only.
- **AC-13**: The CSP (`SecurityHeaders`) adds the Worldpay SDK, API and 3D Secure frame hosts (`services.worldpay.checkout_hosts.{environment}` and `services.worldpay.three_ds_hosts`, names fixed in step 0) only on the pay page of a `card_fields` account, and the Google Pay hosts (`services.wallet_hosts.google_pay`) only when that account has Google Pay on. A `hosted` account's pay page and every other provider's page get no new host. A test asserts both. Besides `script-src`, `frame-src` and `connect-src`, the card page adds `form-action` for the 3D Secure hosts (the device data frame posts a form into the frame) and keeps `frame-ancestors 'self'` only on the `3ds-return` response. The SDK script is allowed by the request nonce plus the host; step 0 checks that the existing CSP (nonce, possibly `strict-dynamic`) lets the SDK load its own sub scripts, and the host list is widened only as far as step 0 proves necessary.
- **AC-14**: GBP only and the rest of 0014 apply unchanged to `card_fields` payments (`StorePaymentRequest`, `UpdatePaymentRequest`, `CurrencySupportResolver`). No new parity surface is needed beyond the attempts panel: CSV export, dashboard, `CentralSummary`, receipts and agent assignment already treat Worldpay as one provider.
- **AC-15**: Google Pay (last build step, off by default) shows a Google Pay button on the `card_fields` pay page only when the account has it on and the payment is GBP. The Google Pay token goes to the same charge endpoint (`method` `google_pay`), which refuses to charge without a consent record, and follows AC-5 to AC-9 unchanged. The 3D Secure steps apply when Worldpay asks, with `allowedAuthMethods` set to what step 0 proves Worldpay accepts (PAN only tokens go through the 3D Secure flow, cryptogram tokens may skip it, per Worldpay's reply). The button follows the same consent gate and is hidden when `cardState` is `blocked` or `waiting`. Apple Pay is not offered. This step ships only after Try proves the wallet token type and the gateway merchant id (step 0).
- **AC-16**: A step 0 check against the Worldpay Try environment answers the items under **Open questions**. Answers go in `## Follow-up` and in the **Step 0 answers** block. Any field, event, host or code name that differs is fixed only in `WorldpayClient`, `WorldpayCardReply`, `WorldpayLookupReply` and config, the only places that know Worldpay field names. Build steps after step 0 that depend on an unproven item stay behind a config value until the staging run proves it.

## Decision

**Chosen option**: Option 2: Per account `card_fields` mode beside the hosted page, status by read back only.

Add Worldpay Checkout card fields as an account level mode next to 0014's hosted pages: the browser gets a one use card session from Worldpay's SDK, PayHub's server charges it with the server side amount and handles 3D Secure in frames, and `WorldpayChargeResolver` alone marks a payment paid after reading the sale back.

**Implementation skills**: `provider-parity-check` (`.claude/skills/provider-parity-check/`) · `laravel-best-practices` (`laravel/boost`, `.claude/skills/laravel-best-practices/`) · `inertia-vue-development` (`laravel/boost`, `.claude/skills/inertia-vue-development/`) · `wayfinder-development` (`laravel/boost`, `.claude/skills/wayfinder-development/`) · `pest-testing` (`laravel/boost`, `.claude/skills/pest-testing/`)

Reasoning and options: see [rationale.md](rationale.md).

## Feature design

**Data model** (confirmed; migrations are new, nothing built yet):

- `worldpay_accounts` (new columns): `mode` string default `hosted` (`hosted` or `card_fields`), `checkout_id` string nullable (a public id, not encrypted), `google_pay_enabled` boolean default false, `google_pay_gateway_merchant_id` string nullable (public).
- `worldpay_charge_attempts` (new): id, `payment_id` FK, `sequence` unsigned small integer nullable (unique with `payment_id`; null on event rows), `transaction_reference` string nullable unique (`{payment uuid}-c{sequence}`, 1 to 64 characters, no spaces; null on event rows), `method` (`card` or `google_pay`), `outcome` (`approved`, `refused`, `challenge_required`, `challenge_failed`, `unclear`, `error`, `abandoned`, `not_found`), `step` (`charge`, `3ds_device_data`, `3ds_challenge`, `event`), `three_ds_state` text nullable (encrypted, the next Worldpay link while a check is in progress; cleared after one use, on abandon and on a final outcome; never sent to the browser), `last_event` nullable, `response_code` nullable, `reason` nullable (PayHub's plain sentence), `worldpay_payment_id` nullable, `client_info` nullable, timestamps. Diagnostic only, except that the resolver reads `transaction_reference` and `outcome` to know which references to look up. The cardholder name, session and token are never columns.
- `payments`: no new column. `worldpay_payment_id`, `worldpay_charge_started_at` and `worldpay_last_lookup_at` from 0014 are reused.
- `worldpay_lookup_attempts` (existing): gains a nullable `charge_attempt_id` FK (null for hosted pages and for the card rows' page fields), so the admin panel can tie a lookup to the attempt it searched through.
- No processed events table.

Relationships: `payments` 1:N `worldpay_charge_attempts`; `worldpay_accounts` 1:N `payments` (unchanged). A payment on a `card_fields` account has attempts and no pages.

**State transitions**: `Payment` still goes `pending` to `completed` only through `WorldpayChargeResolver` (v1 never sets `failed`). An attempt goes `challenge_required` (3D Secure in progress) to `approved`, `refused`, `challenge_failed`, `unclear` or `abandoned` (a newer charge started); `approved`, `unclear` and `not_found` can only move forward by a lookup (to a paid state on the payment, or to `refused`), and `unclear` or `approved` becomes `not_found` only by the rule in AC-8. `refused`, `challenge_failed` and `abandoned` are final. A refusal, an error and an unclear reply leave the payment `pending`.

**Reply mapping** (names confirmed in step 0; lists live in config):

| Worldpay charge reply | Attempt outcome | Client state | Effect on payment |
|---|---|---|---|
| `authorized`, `sentForSettlement` | `approved` | `waiting` | none; queue the resolver |
| `3dsDeviceDataRequired` | `challenge_required`, step `3ds_device_data` | `3ds` | none |
| `3dsChallenged` | `challenge_required`, step `3ds_challenge` | `3ds` | none |
| `refused`, `fraudHighRisk` | `refused` | `retry` | none |
| `3dsAuthenticationFailed`, `3dsUnavailable` | `challenge_failed` | `retry` | none |
| timeout, unreadable reply, 5xx, duplicate answer | `unclear` | `waiting` | pause key kept; lookup queued |
| other 4xx, 429 | `error` | `retry` | none; code logged |
| any other name | `error` | `retry` | warning log |

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `worldpay-accounts` (existing resource, request classes and forms extended), test connection (existing, extended) | POST/PUT/PATCH | AC-1 fields | rows with `mode`, `checkout_id`, Google Pay settings, `has_*` flags | `role:admin` | 403, 404, 422 |
| `pay/{payment}` and `pay/{payment}/{ref}` (existing `showWorldpay`, new card branch) | GET | payment UUID | Inertia `PayWorldpayCard` props from AC-4 | public (UUID) | 404, redirect to Success when completed, Unavailable otherwise |
| `pay/{payment}/worldpay/card/charge` | POST | `session`, `method`, `cardholder_name` or `token` | JSON `{state, ...3DS frame details}` | public (UUID), limiter `worldpay-card-charge`, consent required | 409, 422, 423, 429, 502 |
| `pay/{payment}/worldpay/card/3ds` | POST | `step` | JSON `{state, ...}` | public (UUID), own limiter `worldpay-card-3ds` (15 per payment per 10 minutes) | 409, 422, 429, 502 |
| `pay/{payment}/worldpay/card/3ds-return` | POST, GET | none read | tiny page that posts "done" to the parent | public, CSRF exempt, `frame-ancestors 'self'` | none (always renders) |
| `pay/{payment}/worldpay/card/event` | POST | `event`, optional `code`, `message` | `204` | public (UUID), 60 second per payment gate | 422, 429 |
| `pay/{payment}/worldpay/status` (existing) | GET | payment UUID | JSON `{state}` | public (UUID), throttle 60 per minute | 404, 429 |
| `pay/{payment}/worldpay/checkout` (existing) | POST | none | JSON `{url}` or 422 on a `card_fields` account | public (UUID) | as 0014 plus AC-2 |
| `WorldpayStalePaymentSweep` (existing, extended) | scheduled every 10 min | none | lookups | system | none |
| `webhook/worldpay/{worldpayAccount}` (existing) | POST | signature, raw body | `200` always | signature | none |

**Value sourcing**:

| Action | Value produced / displayed | Source |
|---|---|---|
| Pay page | amount, currency | `Payment.amount`, `Payment.currency` |
| Pay page | `checkout_id`, SDK script host | the payment's `worldpay_accounts` row and `config('services.worldpay.checkout_hosts.{environment}')` |
| Pay page | cardholder name prefill | the payment's `client_name` column if present, else blank |
| Pay page | `hasConsent` | the existing `payment_consents` record |
| Pay page | `googlePay` | `google_pay_enabled` and the gateway merchant id, only when the payment is GBP |
| Charge | session href | the request, host checked against `config('services.worldpay.session_hosts.{environment}')`, used once, never stored |
| Charge | username, password, entity, API host | the account row (credentials decrypted; host `config('services.worldpay.hosts.{environment}')`) |
| Charge | `value.amount`, `value.currency` | `Payment.amount` (minor units), the constant `GBP` (payment already checked) |
| Charge | `transactionReference` | `{payment uuid}-c{next sequence}` |
| Charge | `narrative.line1` | the brand name cleaned and cut by the 0014 narrative rules |
| Charge | auto settlement | the constant "on", field name from step 0 |
| Charge | 3D Secure return URL | `route('pay.show', $payment)` (https) |
| 3D Secure | next link, challenge details | the previous Worldpay reply, saved in the attempt's encrypted `three_ds_state`, never from the browser |
| 3D Secure | which attempt and step | the newest `challenge_required` attempt of the payment; the request `step` must equal its `step` |
| Guard | retry limit | `config('services.worldpay.card_max_refusals')` (5) counted from this payment's `refused` and `challenge_failed` attempts |
| Guard | total cap | `config('services.worldpay.card_max_attempts')` (10) counted from this payment's real attempts (event rows excluded) |
| Guard | pause key | `Cache` key `worldpay:card:pause:{payment id}`, `config('services.worldpay.card_pause_seconds')` (120) |
| Guard | `not_found` rule | `card_not_found_lookups` (3) and `card_not_found_minutes` (10), counted from the attempt's lookup rows |
| Page | `cardState` | the newest real attempt and the two limits above, computed server side |
| Page | CSP nonce for the SDK script | `Vite::cspNonce()`, passed as a prop |
| Resolver | which references to look up | the payment's non final `worldpay_charge_attempts.transaction_reference` (final ones once from the sweep) and `worldpay_pages.transaction_reference` |
| Resolver | paid, amount, currency, payment id, `paid_at` | Worldpay lookup fields (0014 step 0 names) compared with `Payment` in minor units |
| Resolver | attempt `reason` | a fixed PayHub sentence per outcome plus the Worldpay event name, never Worldpay's text |
| Event report | `client_info` | `App\Support\ClientInfo` from the request user agent |
| Poll | state shown | `Payment.status`, the account's `is_active`, the newest attempt |
| Sweep | which payments | provider `worldpay`, account mode `card_fields`, newest attempt in a pending state, age and gap config as in 0014 |

**Key invariants**:
- A card fields charge never reads an amount, currency, account or reference from the request.
- A payment is `completed` only by `WorldpayChargeResolver`, from one settled row with the exact amount and GBP.
- The session href is used once and never stored; the wallet token and card values never reach a log, a URL, storage or the database.
- A new charge cannot start while an attempt is `approved` or `unclear` (until a lookup or the `not_found` rule settles it), while the pause key is set, or while the per payment lock is held. An abandoned 3D Secure check never blocks.
- A failed or unavailable 3D Secure result is a refusal; a charge is never retried without 3D Secure.
- Lock order is checkout or charge lock first, then the lookup lock (as 0014).
- Mode cannot change once any payment uses the account.

**Security model**:
- Pay routes are public by payment UUID and rate limited; the admin account routes are `role:admin`.
- Compliance scope: PCI DSS. Worldpay's SDK draws the card inputs, so card values should not reach PayHub, but loading a third party script on the payment page may mean SAQ A-EP rather than SAQ A. This is unconfirmed; written confirmation from Worldpay or the acquirer is a release gate for Live (see Follow-up).
- Sentry: the card form is `sentry-block`; reports hold no session, token or card value.
- Secrets: `api_username`, `api_password` and `webhook_secret` stay encrypted and hidden (0014). `checkout_id` and the Google Pay gateway merchant id are public identifiers, safe to show in the page props and the admin form.
- Audit: attempts rows give admins the history of each try; they are diagnostic only.

**Configuration required** (all in `config/services.php` under `worldpay`; no new `.env` secrets, credentials live per account):
- `checkout_hosts` per environment (SDK script, API and frame hosts; Try `try.access.worldpay.com`, Live `access.worldpay.com`, final list from step 0)
- `session_hosts` per environment (allowed host of the session href)
- `three_ds_hosts` (frame and connect hosts for the device data and challenge frames, step 0)
- `card_max_refusals` (5), `card_max_attempts` (10), `card_pause_seconds` (120), `card_lock_seconds` (90), `card_http_timeout_seconds` (30), `card_session_ttl_seconds` (60, the SDK's own limit, only for the page message), `session_path_prefix`
- `card_not_found_lookups` (3), `card_not_found_minutes` (10), `card_stuck_hours` (24)
- `card_refused_events`, `card_approved_events` (the outcome names in the Reply mapping)
- `services.wallet_hosts.google_pay` (already used by NMI)
- Rate limiters in `AppServiceProvider`: `worldpay-card-charge` (counts charge calls only: 5 per payment UUID per 10 minutes and 20 per IP per hour) and `worldpay-card-3ds` (15 per payment UUID per 10 minutes)
- `bootstrap/app.php`: CSRF exemption for `pay/*/worldpay/card/3ds-return`
- Sentry `before_send`: remove the request body on the three card routes
- Hosting: no shared cache is needed for the 3D Secure link (it lives in the database); the pause key and locks still use `Cache`, which must be a shared store (database or Redis) on any multi server deploy

**Open questions** (all answered at step 0 against the Try environment; each is a config value, a field name, or a retry rule):
1. The exact charge body for a Checkout session (the field that carries the session href, `paymentInstrument` type, the auto settlement field, `channel`, `WP-Api-Version`), and the reply fields (`outcome`, links, payment id).
2. Whether the Payments API has an idempotency key or header; if yes, use it and relax `hasPossibleCharge` only after the review.
3. The exact 3D Secure steps and fields: the device data frame inputs, the challenge frame inputs, how it returns to the page, and every host the browser must reach (script, frame, connect and image).
4. What the SDK renders (iframes or not), the name of the session field returned by `generateSessionState`, and whether the Checkout ID is tied to a domain or entity (a domain allow list in the Dashboard).
5. Why Try card payments on this entity end in `authorizationFailed` (0014 follow up), and which Try cards give an accepted payment, a refusal and a 3D Secure challenge.
6. The Google Pay wallet token type (`card/wallet+googlepay` or another), the gateway merchant id field, and whether PAN only tokens need the 3D Secure flow.
7. Payment Queries field names for a payment made through the Payments API (do they match 0014's step 0 row fields).
8. PCI: SAQ A or SAQ A-EP for this SDK, in writing.
9. Whether Access Hosted Payment Pages can be embedded in an iframe or lightbox on PayHub's page. If yes, that keeps the client on the PayHub page with no charge call, no 3D Secure code and no double charge guard, and the PCI case likely stays SAQ A, so it may replace this whole spec (see Option 4 in the rationale). Step 0 answers this first, and build steps 1 to 11 wait for it and for question 8.
10. Whether the 3D Secure bank result returns as a POST inside the frame (the spec assumes yes), and the frame details for `frame-ancestors`, `form-action` and the `3ds-return` page.
11. Whether the card session href is needed with `https` and a fixed path prefix, and its maximum length, for the session check.

**Step 0 answers**: none yet (the check runs as build step 0).

**Critical test scenarios** (all Worldpay calls use `Http::fake`; the real service is covered only by step 0 and the staging run):
- Happy path: card mode page, consent, session, charge `sentForSettlement`, resolver reads one paid row, payment `completed` once with one notification, poll returns `completed`, verifies **AC-4**, **AC-5**, **AC-6**, **AC-9**, **AC-10**
- The charge reply alone never completes a payment, even with `sentForSettlement`; only the lookup does, verifies **AC-6**, **AC-9**
- A request body carrying an amount, currency, account or reference is ignored; a `session` on another host is a 422 with no Worldpay call, verifies **AC-5**
- No consent, wrong provider, not `pending`, inactive account, `hosted` account, card lock, pause key set: refused with the fixed message and no Worldpay call, verifies **AC-2**, **AC-5**, **AC-8**
- Refusal, fraud and 3D Secure failed outcomes give state `retry`; the sixth refusal gives 422 "This payment link can no longer be used."; a successful try after four refusals still completes, verifies **AC-5**, **AC-6**
- 3D Secure: charge returns device data required, browser reports back, server uses its cached link (a link in the request body is ignored), challenge returns and completes; a failed challenge is a refusal; a closed tab leaves an attempt that the sweep settles, verifies **AC-7**, **AC-10**
- Unclear reply (timeout, 5xx): pause key stays, a new charge is blocked, a lookup is queued; two charge calls at once make one Worldpay call; an approved attempt without a paid row blocks a new charge until the lookup settles it, verifies **AC-8**
- Lookup shows a different amount, currency or reference: `mismatch`, error log, no change; two paid rows or a second paid sale: `duplicate`, no change; an unknown event never throws, verifies **AC-9**
- Sweep picks only `card_fields` payments with a pending attempt inside the window and makes no charge call; poll runs at most one lookup per window, verifies **AC-10**
- Attempt rows hold no card value, session, token, link or credential; the admin panel shows them in `attemptHistory()` shape, verifies **AC-11**
- Page event reports: only the fixed list, cleaned text, at most one row per 60 seconds, never a lock, ignored by the poll, sweep, retry limit and cap, verifies **AC-12**
- Timed out charge (no Worldpay id) keeps blocking after the pause key ends; a lookup row clears it; no row after 3 lookups over 10 minutes gives `not_found` and lifts the block; a later paid row is still caught, verifies **AC-8**, **AC-9**, **AC-10**
- An `approved` attempt with no paid row past `card_stuck_hours` alerts once and keeps blocking; a `duplicate` alerts once and shows the admin banner, verifies **AC-8**, **AC-9**
- Reload mid 3D Secure marks the old attempt `abandoned`, clears its stored link and does not block the new charge; a replayed `3ds` call, a wrong `step` or an empty link is refused, verifies **AC-7**
- The `3ds` endpoint takes the lock, sets the pause key first and treats a timeout as `unclear`; the charge limiter does not count `3ds` calls, verifies **AC-5**, **AC-7**
- The 10th real attempt of any outcome blocks the 11th; `cardState` is `waiting` after a lost reply and `blocked` at a limit, with the poll returning `blocked`, verifies **AC-4**, **AC-5**, **AC-10**
- Session on a path, scheme or length that fails the check is a 422; the cardholder name, session and token never appear in the database, logs or Sentry events, verifies **AC-5**, **AC-11**
- The `3ds-return` route accepts a POST without CSRF, renders the "done" page with `frame-ancestors 'self'` and carries no Worldpay value to the browser, verifies **AC-7**, **AC-13**
- The security headers test: card mode account gets the SDK and 3D Secure hosts, Google Pay hosts only when on; a `hosted` account and every other provider get none, verifies **AC-13**
- Account form: `checkout_id` required as a UUID for `card_fields`; Google Pay needs the gateway merchant id; mode change blocked once a payment exists; `hosted` routes and card routes refuse the wrong mode, verifies **AC-1**, **AC-2**, **AC-3**
- GBP only: USD and PKR still rejected for a card mode account, verifies **AC-14**
- Google Pay: button only with the switch on and GBP; token charge follows the same guard, consent and read back rules, verifies **AC-15**

## Build plan

Build approach: none recorded in `AGENTS.md`, so end to end Tracer Bullet slices are assumed. The first thread goes from the account mode to the card page to a charge to a lookup to a completed payment on Try, because that is the one path that proves the SDK, the charge body and the read back together.

0. Worldpay Try check for the **Open questions**, question 9 (embedding Hosted Payment Pages) and question 8 (PCI) first: if the hosted page can be embedded and keeps SAQ A, stop and rewrite this spec as that option; if A-EP makes card fields too costly, stop and decide again. The rest needs one Try payment that is accepted, which depends on Worldpay support (see Follow-up). Record answers in **Step 0 answers** and fix names only in `WorldpayClient`, `WorldpayCardReply`, `WorldpayLookupReply` and config, satisfies **AC-16**
1. Migrations (`worldpay_accounts` columns, `worldpay_charge_attempts`, `charge_attempt_id` on `worldpay_lookup_attempts`), models, factories, `config/services.php` keys, satisfies **AC-1**, **AC-11**
2. `WorldpayClient` additions (create card payment, continue 3D Secure device data, continue 3D Secure challenge) and `WorldpayCardReply`, satisfies **AC-5**, **AC-6**, **AC-7**
3. `WorldpayChargeResolver` extension to look up charge attempt references, forward only attempt outcomes, satisfies **AC-9**
4. Admin account form and requests (mode, Checkout ID, Google Pay fields, mode lock, test connection), satisfies **AC-1**, **AC-2**, **AC-3**
5. `WorldpayCardController` (`charge`, `3ds`, `event`), `WorldpayCardChargeRequest`, `WorldpayCardEventRequest`, guard, pause key, limiter, card lock, satisfies **AC-5**, **AC-6**, **AC-7**, **AC-8**, **AC-12**
6. `showWorldpay` card branch with `cardState`, `PayWorldpayCard.vue` and `WorldpayCardForm.vue` (SDK load with nonce, mount, consent gate, session, 3D Secure frames, the `3ds-return` page, fixed messages, polling, `sentry-block`), `SecurityHeaders` hosts, `form-action` and `frame-ancestors`, CSRF exemption, Sentry `before_send`, satisfies **AC-4**, **AC-7**, **AC-13**
7. Status endpoint and `WorldpayStalePaymentSweep` extension, satisfies **AC-10**
8. Admin attempts panel (charge attempts into `attemptHistory()`), mode checks on the hosted route, satisfies **AC-2**, **AC-11**, **AC-14**
9. Pest tests for the scenarios above (`php artisan test --parallel`), then `npm run build` and regenerate Wayfinder files, satisfies **AC-1** to **AC-14**
10. Staging run against Try (paid, refused, 3D Secure challenge, closed tab mid check, repeated clicks, expired session), then a small live payment and a refund by hand once PCI is confirmed and Live is enabled, satisfies **AC-16**
11. Google Pay slice: account fields already exist, add the Google Pay button and token charge, its CSP hosts and tests; ship only after Try proves the token type, satisfies **AC-15**, **AC-13**

## Consequences

**Positive**:
- The client stays on PayHub's page, and PayHub controls the look.
- One account setting, so every hosted payment and the 0014 code keep working untouched.
- The same safe status rule as NMI and Authorize.net: a reply from Worldpay alone never marks a payment paid.
- A refusal can be retried on the same link, which the hosted page may not allow.
- Reuses the 0014 resolver, sweep, lookup table, narrative rules and attempts panel.

**Negative / tradeoffs**:
- More code and more edge cases than 0014: the SDK session, 3D Secure frames, retry limit and the double charge guard.
- Own card fields on our page may mean SAQ A-EP, not SAQ A, and add CSP hosts; unconfirmed until Worldpay says in writing.
- The Worldpay session lives one minute and works once, so a slow client has to re enter the card.
- v1 never sets `failed`, so a refused payment stays `pending` until paid (same as 0014).
- Several Worldpay facts are still unproven, and Try card payments on this entity fail today; the build cannot be proven end to end until Worldpay fixes that.
- Without a confirmed idempotency key, a timeout on the charge call leaves a possible charge that only a lookup can settle, so new charges pause up to 120 seconds.

**Neutral**:
- Refunds, captures and chargebacks stay in the Worldpay dashboard.
- `AGENTS.md` and `docs/agent.md` need a card fields paragraph after the build (`/sync`).
- Apple Pay stays out because the Apple domain file belongs to Square on this deployment.

## Follow-up

- [x] Cross check on another model (2026-11-17): 25 findings, all recommended fixes applied (not found rule, abandoned and stuck attempts, 3D Secure endpoint rules and return route, CSP `form-action` and `frame-ancestors`, link kept in the database, limiter split, total cap, event rows, `cardState`, Sentry scrubbing, session and name checks, duplicate alert, Option 4 check).
- [ ] Ask Worldpay support why Try card payments on this entity end in `authorizationFailed` or the failure URL (carried over from 0014), and for Try test cards (accepted, refused, 3D Secure challenge). Step 0 is blocked without this.
- [ ] Ask the implementation manager for the Checkout ID per environment, whether it is tied to a domain or entity, the gateway merchant id for Google Pay, and the Payments API reference (body, idempotency, 3D Secure).
- [ ] Confirm the PCI form (SAQ A or SAQ A-EP) for the Checkout SDK in writing with Worldpay or the acquirer. Release gate for Live.
- [ ] Find out whether the SDK has an npm package (the docs read only show the CDN script); the CDN script is assumed.
- [ ] After step 0, record the **Step 0 answers** and the real hosts, and update the AC wording where a name or code changed.
- [ ] After the build, `/sync` updates `AGENTS.md` (Worldpay bullet, CSP note) and `docs/agent.md`, and the `provider-parity-check` skill and `provider-parity-reviewer` agent if their text needs it.
- [ ] Revisit setting `failed` once refusal events are known from the attempts table.
- [ ] Revisit Apple Pay only if a deployment gives Worldpay the Apple domain file.
- [ ] Spec 0013 (Bank of America) and 0014 are not merged yet; this spec edits Worldpay files only, so conflicts should be small. Whichever merges second resolves `AGENTS.md`.

## Rationale

Reasoning and options: see [rationale.md](rationale.md).
