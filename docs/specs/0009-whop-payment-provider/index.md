# 0009. Whop as the eighth payment provider, with PayHub's own layout and Whop card frames

**Date**: 2026-11-02
**Status**: Accepted

## Summary

This spec adds Whop as the eighth payment provider. The client types their card into card fields that Whop hosts inside PayHub's own pay page, so card numbers never touch PayHub. PayHub's server starts the charge using the amount from its own payment record, and a payment becomes `completed` only when Whop's signed webhook says it succeeded. Because Whop's exact charge fields and failure event are not fully documented, the first build step is a short sandbox check, and any difference is fixed in one class.

## Requirements

**User stories**:
- As an admin, I want to add, test, activate and deactivate a Whop account for a brand, and register its webhook with one command, the same way I do for Revolut.
- As an agent, I want to create a USD or GBP payment link against an active Whop account like any other provider.
- As a client, I want to pay by card on a page that looks like the rest of the brand page, see a clear result, and retry another card if mine is declined.
- As the business, I want a payment marked paid only from Whop's signed webhook, never from what the browser says.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: An admin can create, edit, activate, deactivate and test a Whop account (account name, prefix, company ID, API key, webhook secret, environment of `sandbox` or `production`), restricted to `role:admin`. `api_key` and `webhook_secret` are encrypted at rest, not mass assignable, and never returned to the browser (only `has_api_key` and `has_webhook_secret` flags). Test connection makes one read only Whop call with the stored key and company ID and reports success in plain words: 401 means "wrong key", 403 means "missing permission", 404 means "wrong company ID", anything else a generic message. Two active accounts cannot share one `company_id`. The webhook secret is optional on save (it is set by AC-12).
- **AC-2**: A Whop account has no currency lock. A payment against a Whop account may be `usd` or `gbp`; `StorePaymentRequest`, `UpdatePaymentRequest` and `CurrencySupportResolver` treat Whop like Stripe and Revolut. PKR is rejected for Whop, as for every provider except MPGS.
- **AC-3**: Whop appears everywhere the seven providers already do: `PaymentProvider` enum, payment forms, agent account assignment (`user_payment_accounts`), `ProviderAccountTable`, CSV export, dashboard filters and "Accounts Today", provider badge, sidebar, `CentralSummary`, and the client Success, Failed and Unavailable pages. A parity review with grep for two way and four way provider branches passes before merge.
- **AC-4**: The pay page of a Whop payment shows PayHub's own layout with Whop's hosted card frames (`@whop/elements`, bundled from npm at a pinned version). It shows the existing consent check first, the amount on the pay button, cards only (no Apple Pay, Google Pay or PayPal), and the Whop branding element Whop requires. The email comes from the payment, so the client never retypes it; `client_email` is therefore required for Whop payments in `StorePaymentRequest` and `UpdatePaymentRequest`. When the payment is in the confirming state (server prop `confirming`, true while the pause key exists on a `pending` payment), the page shows the waiting state and starts polling instead of the card form, including after a reload or a return from a 3D Secure redirect. Card values never touch PayHub code, Inertia form state, a URL, storage or a log. The frame container carries the `sentry-block` class.
- **AC-5**: The charge endpoint takes only `confirmation_token` (the single use token from the Whop frames; a string, at most 255 characters). It returns 422 unless the payment's provider is Whop, its status is `pending`, and its account exists and is active. The amount and currency always come from the `Payment` record (integer cents converted to Whop's decimal format with integer math). The request carries the account's company ID, an inline one time price for that amount and currency, the confirmation token, the pay page `return_url`, and `metadata` of `payment_id` (the payment UUID) and `reference` (the reference code). The token and Whop request body are never stored or logged. `whop_charge_started_at` is written just before the Whop call (every attempt). If step 0 shows Whop accepts an idempotency key, the request sends `{payment uuid}:{attempt number}` (attempt number = count of earlier charge starts, kept in the cache). The Whop HTTP timeout is 10 seconds to connect and 30 in total (config), shorter than the 60 second lock. On a reply, `whop_payment_id` is saved (latest attempt only).
- **AC-6**: One charge runs at a time per payment (`Cache::lock` of 60 seconds; a second caller gets 409). A named limiter `whop-charge` allows 5 charge requests per payment UUID per hour and 20 per IP per hour; the first one hit returns 429 with "Too many attempts. Please try again later." and makes no Whop call. After a `requires_action`, processing, unclear (timeout, connection error, 5xx) or unknown outcome, a cache pause key `whop:pause:{payment id}` blocks new charges for 600 seconds (409 with the confirming message) and is cleared by the completing webhook. A decline does not start the pause. Once `whop_charge_started_at` is set, `UpdatePaymentRequest` rejects a change to the amount, currency or account.
- **AC-7**: The client only ever sees these fixed messages, never Whop text. Declined: "Your card was declined. Please try a different card." (payment stays `pending`, form stays open). Needs a 3D Secure step: the page runs `handleNextAction` with the reply's `client_secret`, then treats the result as processing. Processing, unclear or timeout, and also a Whop reply that says paid: "We are confirming your payment. Please do not pay again." A 5xx, a timeout, a dropped connection or any Whop status PayHub does not know is also treated as confirming (Whop may already have created the payment) and starts the pause. Only a clear 4xx rejection shows: "Your payment could not be processed. Please try again or use a different card." The charge endpoint returns HTTP 200 for all four outcomes; 502 is used only for `error` caused by a Whop 4xx it cannot read. The frame's `onComplete` and the return URL values are display only and never change PayHub status. `client_secret` appears only in the charge reply JSON, is never stored or logged, and the page removes Whop's `payment`, `status` and `client_secret` return URL values from the address bar and history on load.
- **AC-8**: Waiting state: the page polls `pay/{payment}/whop/status` every 3 seconds while the tab is visible. The endpoint only reads `Payment.status` (no Whop call): `completed` maps to `completed`, `pending` to `waiting`, anything else to `unavailable`. `completed` navigates to the Success route. After 10 minutes of visible waiting it shows "Still confirming, you will get a receipt by email". `ClientPaymentController::show` redirects a completed payment to Success as it does for the other providers.
- **AC-9**: The webhook route `webhook/whop/{whopAccount}` is excluded from CSRF, keeps the raw body, and verifies the Standard Webhooks signature (`webhook-id`, `webhook-timestamp`, `webhook-signature` header, HMAC-SHA256 with the account's `ws_` secret, compared with `hash_equals`; the header may hold several space separated `v1,` signatures, and any one matching passes) and rejects a timestamp more than 5 minutes from now. A bad or missing signature or a stale timestamp returns 401 with no queueing. A good one queues `HandleWhopWebhookJob` and returns 200 in well under 5 seconds. An account with no stored secret returns 403. An inactive account is still processed (a payment may be mid flight); 404 only for a missing account row.
- **AC-10**: `HandleWhopWebhookJob` drops a repeated delivery by inserting the `webhook-id` into `processed_whop_events` (unique), inside the same database transaction as the status update, so a failed job leaves no event row and Whop's retry is processed. On `payment.succeeded` it finds the payment by `metadata.payment_id`, requires that it belongs to the route's account and that the event's price field equals `Payment.amount` and currency exactly (the field that equals the price PayHub set, before any tax or fee Whop adds; named in step 0, Open question 11), with amounts read and built as strings and compared in integer cents, then in one guarded update (`whereIn('status', ['pending','failed'])`) sets `completed`, `paid_at` (the event's paid time if present, else `now()`) and `whop_payment_id`, clears the pause, and dispatches `SendPaymentNotification` after the transaction commits, only when a row was updated. An event with no `metadata.payment_id` (for example a Whop dashboard invoice) is logged at info level and ignored. A different amount, currency or account, or an unknown PayHub payment UUID: no status change, logged at error level. A success for a `cancelled` payment, or a second success with another Whop payment id for a `completed` payment: no change, logged at error level (reaches Sentry).
- **AC-11**: `payment.failed` (if the sandbox check confirms it exists) is logged at info level and does not change `Payment.status`, because the client may retry and later succeed. Refund, dispute and every other event type return 200 after dedupe and are logged at info level with no status change. PayHub does not track Whop refunds or disputes.
- **AC-12**: `php artisan whop:register-webhook {account?} --url=...` creates the webhook through the Whop API for the account (events `payment.succeeded` and `payment.failed`), stores the returned `ws_` secret encrypted on the account, and prints the URL. It refuses a missing or non `https` URL, and warns before replacing an existing secret.
- **AC-13**: A sandbox check (Build plan step 0) confirms or corrects every item under **Open questions** before the charge thread is built. Answers go in `## Follow-up`. Any field or event name that differs is fixed in `WhopClient` and `WhopWebhookPayload`, the only two places that know Whop field names.
- **AC-14**: On the pay page of a Whop payment only, the Content Security Policy adds exactly the Whop hosts found in step 0 for scripts, frames and connections. No other route changes, and no `unsafe-inline` or `unsafe-eval` is added.

## Decision

**Chosen option**: Option 2: Whop hosted card frames in PayHub's layout, server charge with a confirmation token, paid status from the signed webhook only

PayHub renders its own page and Whop's frames collect the card and produce a confirmation token. The server then starts the charge with the amount from the `Payment` record and the metadata `payment_id`, and only the signed `payment.succeeded` webhook completes the payment. This keeps the rule "status comes from webhooks only" with no scoped exception, which is a different position from Clover, MPGS and Authorize.net.

**Implementation skills**: `laravel-best-practices` (`.claude/skills/laravel-best-practices/`) · `inertia-vue-development` (`.claude/skills/inertia-vue-development/`) · `wayfinder-development` (`.claude/skills/wayfinder-development/`) · `pest-testing` (`.claude/skills/pest-testing/`) · `provider-parity-check` (`.claude/skills/provider-parity-check/`)

## Rationale

Reasoning and options: see `rationale.md`.

## Feature design

**Data model sketch** (new migrations; names follow the existing provider tables):

- `whop_accounts`: id, `account_name` string, `prefix` string nullable, `company_id` string, `api_key` text (encrypted cast, not mass assignable), `webhook_secret` text nullable (encrypted cast, not mass assignable), `environment` string (`sandbox` or `production`), `is_active` boolean default true, timestamps. No currency column.
- `payments`: add `whop_account_id` FK nullable (to `whop_accounts`, null on delete restrict like the others), `whop_payment_id` string nullable, indexed, `whop_charge_started_at` timestamp nullable (set before every Whop call; locks amount, currency and account edits).
- `processed_whop_events`: id, `webhook_id` string unique, `event_type` string, timestamps.
- No attempts table. The charge lock and the pause live in the cache.

Relationships: `whop_accounts` 1:N `payments`. Agents reach an account through `user_payment_accounts`, as for the other providers.

**State transitions**: `pending` to `completed` on a matching `payment.succeeded`. A decline, a processing state, a lost reply and `payment.failed` leave `pending` unchanged. `completed` is terminal. Whop never sets `failed` in v1 (see Follow-up).

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `whop-accounts` (resource, except `show`), `activate`, `deactivate`, `test-connection` | GET/POST/PUT/DELETE/PATCH | account fields from AC-1 | rows with `has_api_key`, `has_webhook_secret` flags, never the secrets | `role:admin` | 403, 404, 422 |
| `pay/{payment}` (existing, new `showWhop` branch) | GET | payment UUID | Inertia page with brand data, company ID, environment, amount, currency, client email | public (UUID) | 404, redirect to Unavailable when not payable |
| `pay/{payment}/whop/charge` | POST | `confirmation_token` | JSON `{outcome, message, client_secret?}`; outcome is `declined`, `requires_action`, `processing` or `error` | public (UUID), limiter `whop-charge` | 409 busy or paused, 422 not payable or bad input, 429, 502 |
| `pay/{payment}/whop/status` | GET | payment UUID | JSON `{status, state}`, state is `waiting`, `completed` or `unavailable` | public (UUID) | 404 |
| `webhook/whop/{whopAccount}` | POST | raw body and the three signature headers | 200 after queueing | signature, CSRF excluded | 401/403, 404 |
| `whop:register-webhook` | Artisan | `{account?}`, `--url` | secret stored encrypted | operator | refuses non `https`, API errors |

**Value sourcing**:

| Action | Value produced / displayed | Source |
|---|---|---|
| Pay page | company ID, environment | the payment's `whop_accounts` row (company ID is a public identifier; the API key and webhook secret are never sent) |
| Pay page | button amount, currency, client email | `Payment.amount`, `Payment.currency`, `Payment.client_email` |
| Pay page | `confirming` flag | `Cache::has('whop:pause:{payment id}')` on a `pending` payment |
| Pay page | Whop frames script | the bundled `@whop/elements` package (version in `package.json`) |
| Pay page | Whop API and frame environment | `whop_accounts.environment` mapped through `config('services.whop.hosts')` |
| Charge | API key and company ID | the payment's `whop_accounts` row, decrypted server side |
| Charge | amount and currency | `Payment.amount` cents converted with integer math, `Payment.currency` (never the request) |
| Charge | `return_url` | the payment's own pay page URL (`route('pay.show', $payment)`), must be `https` |
| Charge | `metadata.payment_id`, `metadata.reference` | `Payment.uuid`, `Payment::formattedReferenceCode()` |
| Charge | confirmation token | request `confirmation_token`, created by the Whop frames in the browser |
| Charge | client message | outcome mapped to the fixed strings in AC-7 |
| Charge | pause length, lock length, HTTP timeouts | `config('services.whop.unclear_pause_seconds')` (600), `lock_seconds` (60), `connect_timeout` (10) and `timeout` (30) |
| Charge | idempotency key (only if step 0 confirms support) | payment UUID plus attempt number |
| Webhook | `paid_at` | event paid time if present, else `now()` |
| Status | state shown | `Payment.status` only |
| Webhook | which account and secret | route `{whopAccount}` row, `webhook_secret` decrypted |
| Webhook | which payment | `metadata.payment_id` in the event, checked against the route's account |
| Webhook | amount and currency checked | event fields converted with integer math, compared to `Payment` |
| Webhook | idempotency key | `webhook-id` header |
| Register command | webhook URL | `--url` option |

**Key invariants**:
- Only a verified, deduplicated `payment.succeeded` webhook with matching account, amount and currency ever sets `completed`. The browser, the confirmation token, `onComplete`, the return URL and the charge reply never do.
- A charge never uses an amount, currency or account from the request.
- At most one charge request is in flight per payment, and none starts while the pause is active or the payment is not `pending`.
- `completed` is terminal. A second success or a success for a `cancelled` payment changes nothing and logs at error level.
- The API key, webhook secret, confirmation token and `client_secret` are never logged, stored, put in a URL or returned (except `client_secret` once in the charge reply, AC-7).
- Amounts are integer minor units everywhere in PayHub; Whop's decimal format exists only at the edge of `WhopClient` and `WhopWebhookPayload`.
- Any Whop value PayHub does not recognize is logged and ignored, and never fails a payment update.

**Security model**:
- Account management is `role:admin` only. Pay, charge and status routes are public but scoped by the unguessable payment UUID. The webhook route is protected by the signature and a 5 minute replay window.
- **Compliance scope is PCI DSS.** Card fields are Whop hosted frames, so the target is SAQ A, not SAQ A-EP. That is not confirmed (the frames sit inside PayHub's page); the acquirer or an assessor makes the final call.
- Whop acts as merchant of record for these payments (per the research in `docs/research/whop.md`): it settles to the company and can suspend or hold payouts. This is a business risk, not a code risk, and the engineer accepted it.
- Only the Whop script and frame hosts are added to the Content Security Policy, only on this pay page (AC-14). Sentry session replay must not record the frames (`sentry-block`).
- Card testing abuse is limited by the limiter, the one charge at a time lock and the pause (AC-6).
- Raw Whop text goes to the logs for operators only. Clients see the fixed strings in AC-7.
- Audit trail: `whop_payment_id` on the payment plus `processed_whop_events`; every refused or mismatched event is logged with both ids.

**Configuration required**:
- `config/services.php` `whop`: `hosts` per environment (`api` base URL and the frame and script hosts for the CSP, values confirmed in step 0), `unclear_pause_seconds` (default 600), `lock_seconds` (default 60), `connect_timeout` (10), `timeout` (30). These are constants, not secrets.
- No `.env` secrets: the API key and webhook secret live encrypted per account, as for the other providers.
- Outside the code: a Whop API key with payment create and read permission for each brand's company, and the plan must allow creating checkouts and charges through the API (Open questions 1 and 2).
- `npm install @whop/elements` at a pinned version; `public/build` is committed, so rebuild with `npm run build`.

**Open questions** (answered by the sandbox check in step 0; none were tested):
1. Does the Whop plan allow creating payments through the API, and which API key permissions are needed?
2. The raw HTTP path, method and exact field names for creating a payment with a confirmation token and an inline price (amount unit, currency format, `metadata`, `return_url`). Does it accept an idempotency key?
3. The reply shape: `id`, `status` values (`paid`, `processing`, `requires_action`, declined) and where the decline reason sits.
4. The `payment.succeeded` and `payment.failed` payload fields: amount (decimal or cents), currency case, `metadata` location, payment id. Does `payment.failed` exist?
5. The `webhook-signature` format (`v1,` prefix, base64) and whether the `ws_` secret is used as is or base64 decoded after the prefix.
6. Whether the frames work with cards only and an email passed in, so no Whop email field shows.
7. The exact script, frame and connect hosts for the Content Security Policy, and whether it works with no `unsafe-inline`.
8. Whether USD and GBP both work on the company's plan, and the sandbox test cards (success, decline, 3D Secure).
9. Which read only call is the cheapest for Test connection, and the permission it needs.
10. The webhook create API: path, events list, and whether the secret is returned only once.
11. Does Whop add tax, VAT or fees to the paid total? Which webhook field equals the price PayHub set (the amount to compare), and which field holds the paid time?
12. Does Whop re-sign retried webhooks with a fresh timestamp? If not, the 5 minute window rejects every retry and a wider window is needed.
13. Do the frames need only the company ID, or a server made session or checkout object? If a session is needed, create it once per payment and reuse it.
14. **Switch rule**: if the confirmation token charge is not in Whop's public API reference or does not work in the sandbox, stop and switch to Option 1 (Checkout embed) through `/architect` (update this spec). The account, webhook and completion work stays the same.

**Critical test scenarios** (all Whop calls use `Http::fake`; the real service is covered only by step 0 and a staging run):
- Happy path: charge returns processing, webhook `payment.succeeded` completes the payment once, notification sent once, status poll then returns `completed`, verifies **AC-5**, **AC-8**, **AC-10**
- Webhook before the charge reply is saved: still matches by `metadata.payment_id` and completes, verifies **AC-10**
- Replay: the same `webhook-id` twice changes nothing the second time, verifies **AC-10**
- Mismatch: wrong amount, wrong currency or another account's payment: no change, error logged, verifies **AC-10**
- Bad signature, missing headers, old timestamp, account with no secret: 401/403 and nothing queued, verifies **AC-9**
- Decline: fixed message, payment stays `pending`, a second charge with another token is allowed, verifies **AC-7**
- Lost reply (timeout), 5xx, unknown status or `requires_action`: confirming message, pause active, next charge gets 409, webhook later completes and clears the pause, verifies **AC-6**, **AC-7**
- Reload during confirming: the page shows the waiting state, not the card form, verifies **AC-4**
- Webhook with no `payment_id`: info log only, no error; webhook job failing leaves no `processed_whop_events` row, verifies **AC-10**
- Amounts: GBP and odd cents (for example 12.34, 0.10) convert exactly in both directions, verifies **AC-5**, **AC-10**
- Webhook signature header with two signatures where only one matches: accepted, verifies **AC-9**
- Two charges at once: second gets 409; sixth in an hour gets 429 with no Whop call, verifies **AC-6**
- Cancelled payment or second success with another Whop id: no change, error logged, verifies **AC-10**
- Amount, currency or account change blocked after the first charge, verifies **AC-6**
- Currency: USD and GBP accepted, PKR rejected, verifies **AC-2**
- Permission: agent and account roles get 403 on account routes; secrets never in props, verifies **AC-1**
- Register command: non `https` URL refused, secret stored encrypted, verifies **AC-12**

## Build plan

Build approach: none recorded in `AGENTS.md`, so end to end Tracer Bullet slices are assumed. The first thread is webhook to completed payment, because that is the only path allowed to change status.

0. Sandbox check against a Whop sandbox company: answer every Open question, record answers in Follow-up, pin the `@whop/elements` version, fix field names, satisfies **AC-13**
1. Migrations (`whop_accounts`, payments columns, `processed_whop_events`), `PaymentProvider::Whop`, models with encrypted casts and factories, `config/services.php` entries, satisfies **AC-1**, **AC-10**
2. `WhopClient` (per account, never global), `WhopWebhookPayload`, webhook route with signature check, `HandleWhopWebhookJob` and the completion rules, with Pest tests; this is the first full thread, satisfies **AC-9**, **AC-10**, **AC-11**
3. Admin account CRUD, activate and deactivate, test connection, and `whop:register-webhook`, satisfies **AC-1**, **AC-12**
4. Payment create and update requests, `CurrencySupportResolver`, immutability after the first charge, `user_payment_accounts` assignment, satisfies **AC-2**, **AC-6**
5. Charge endpoint, status endpoint, `whop-charge` limiter, lock and pause, outcome mapping, satisfies **AC-5**, **AC-6**, **AC-7**, **AC-8**
6. Pay page: Whop frames component, consent gate, 3D Secure step, fixed messages, polling, return URL clean up, `sentry-block`, and the Content Security Policy addition; regenerate Wayfinder files, satisfies **AC-4**, **AC-7**, **AC-8**, **AC-14**
7. Parity sweep over every provider surface (enum, forms, export, dashboard, `CentralSummary`, receipt pages) with the `provider-parity-reviewer`, satisfies **AC-3**
8. Pest suite for the scenarios above, run with `php artisan test --parallel`, then `npm run build`, satisfies **AC-1** to **AC-14**
9. Staging end to end run with the Whop sandbox (success, decline, 3D Secure, repeated webhook), then production key and webhook registration

## Consequences

**Positive**:
- One more provider that follows the existing rules, with no new exception to "status comes from webhooks only".
- Card numbers never touch PayHub code, so the PCI scope stays light.
- The team already has a Whop relationship, so brands can start without a new vendor.

**Negative / tradeoffs**:
- Own layout means building the form, the 3D Secure step and the waiting state ourselves; the Checkout embed would have been less work.
- Without a fallback lookup, a lost webhook leaves a paid payment `pending` until Whop retries or staff check the Whop dashboard.
- Whop is merchant of record and relies on other processors behind the scenes, so payouts can be held or suspended outside PayHub's control.
- Whop fees are higher than most direct providers (2.7% + $0.30 domestic, extra for international cards and currency conversion).
- Payments made this way will not appear as invoices in the Whop dashboard.
- The pay page needs one more third party script host in its security policy.

**Neutral**:
- `payment.failed` is logged only, so a Whop payment never reaches the `failed` status in v1 and the Failed page is not used for it.
- Refunds and disputes stay in the Whop dashboard.
- `AGENTS.md` still says "seven providers" until `/sync` updates it.

## Follow-up

- [ ] Write a short refund note for the "second success" error log (two Whop payments succeeded for one PayHub payment): staff refund the extra one in the Whop dashboard.
- [ ] Update `docs/integrations/central-dashboard-api.md` for the new `whop` provider value in `CentralSummary` and confirm the receiving side accepts an unknown provider.
- [ ] Cross check 2026-11-02 raised a read only lookup (MPGS style sweep) for lost webhooks; left out of v1 by the engineer's choice. Revisit if staging or production shows lost webhooks.
- [ ] Pull `origin/master` before building (this branch was 3 commits behind when the spec was written).
- [ ] Step 0 answers (the 10 Open questions) to be recorded here.
- [ ] Confirm the PCI level (SAQ A) with Whop or the acquirer before production, since the frames run inside PayHub's page.
- [ ] Confirm the company's Whop plan allows API payments, and USD and GBP.
- [ ] Decide later whether `payment.failed` should ever set `failed` (runner up in the rationale), and whether to add a read only lookup like the MPGS sweep if lost webhooks happen.
- [ ] Wallets (Apple Pay, Google Pay, PayPal) need each brand's domain registered with Whop; a separate spec if wanted.
- [ ] After the build, `/sync` updates `AGENTS.md` (eight providers, Whop bullet, webhook and CSRF lists) and `docs/agent.md`.
- [ ] The `provider-parity-reviewer` agent and skill text still say four providers; refresh them so they check all eight.
