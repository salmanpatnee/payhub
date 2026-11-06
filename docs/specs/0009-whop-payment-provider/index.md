# 0009. Whop as the eighth payment provider, with Whop's Checkout embedded in PayHub's pay page

**Date**: 2026-11-02 (reworked 2026-11-03)
**Status**: Accepted

## Summary

This spec adds Whop as the eighth payment provider. PayHub's server creates a Whop checkout (a Whop object that fixes the amount and currency) from the amount on its own payment record. The pay page then shows Whop's checkout inside PayHub's page, or sends the client to Whop's hosted checkout page when the account is set to redirect. A payment becomes `completed` only when Whop's signed webhook says it succeeded. This replaces the first design (Whop card frames and a server charge), because Whop's sandbox does not support Whop Elements and the token charge call is not in Whop's public guide.

## Requirements

**User stories**:
- As an admin, I want to add, test, activate and deactivate a Whop account for a brand, choose embed or redirect for it, and register its webhook with one command, the same way I do for Revolut.
- As an agent, I want to create a USD or GBP payment link against an active Whop account like any other provider.
- As a client, I want to agree to the terms, pay by card on a page that shows the brand, and see a clear result.
- As the business, I want a payment marked paid only from Whop's signed webhook, never from what the browser says.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: An admin can create, edit, activate, deactivate and test a Whop account (account name, prefix, company ID, API key, webhook secret, environment of `sandbox` or `production`, checkout mode of `embed` or `redirect`, default `embed`), restricted to `role:admin`. `api_key` and `webhook_secret` are encrypted at rest, not mass assignable, and never returned to the browser (only `has_api_key` and `has_webhook_secret` flags). Test connection makes one read only Whop call with the stored key and company ID and reports success in plain words: 401 means "wrong key", 403 means "missing permission", 404 means "wrong company ID", anything else a generic message. Two active accounts cannot share one `company_id`. The webhook secret is optional on save (it is set by AC-12). **Built on the branch, except the checkout mode field.**
- **AC-2**: A Whop account has no currency lock. A payment against a Whop account may be `usd` or `gbp`; `StorePaymentRequest`, `UpdatePaymentRequest` and `CurrencySupportResolver` treat Whop like Stripe and Revolut. PKR is rejected for Whop, as for every provider except MPGS. **Built on the branch.**
- **AC-3**: Whop appears everywhere the seven providers already do: `PaymentProvider` enum, payment forms, agent account assignment (`user_payment_accounts`), `ProviderAccountTable`, CSV export, dashboard filters and "Accounts Today", provider badge, sidebar, `CentralSummary`, and the client Success, Failed and Unavailable pages. The client pay page route for a Whop payment never falls through to the Stripe page. A parity review with grep for two way and four way provider branches passes before merge.
- **AC-4**: The pay page of a Whop payment shows PayHub's brand layout and the existing consent check first, then the amount and a "Pay now" button that starts checkout. Cards only (no Apple Pay, Google Pay or PayPal). The client email comes from the payment, so the client never retypes it; `client_email` is required for Whop payments in `StorePaymentRequest` and `UpdatePaymentRequest`. Consent comes first on every page load (it is skipped only when a consent record already exists). Only after consent and a click on "Pay now" does the page call the checkout endpoint, and it never mounts Whop from a saved id in the page props. The mode in the endpoint's reply wins over the mode the page loaded with (an admin may flip it in between). In `embed` mode the page then mounts Whop's embedded checkout in a container on the page. In `redirect` mode the page sends the browser to Whop's hosted checkout URL. `ClientPaymentController::show` redirects a `completed` payment to the Success route before the Unavailable guard, so a webhook that lands first sends the client to Success. An inactive Whop account renders the Unavailable page (an already open Whop checkout can still be paid, and the webhook still completes it). Props sent to the page: payment, brand, policies, `mode`, Whop `environment`, `hasCheckout` and `returnStatus` (below); embed mode adds the script host. Props never include the API key, the webhook secret or `purchase_url`. Card values never touch PayHub code, Inertia form state, a URL, storage or a log (Whop collects them). The checkout container carries the `sentry-block` class.
- **AC-5**: The checkout endpoint `pay/{payment}/whop/checkout` takes no amount, currency or account from the request. It returns 422 unless the payment's provider is Whop, its status is `pending`, its account exists and is active, and a consent record exists for the payment (consent is enforced on the server, not only in the page). The client email is read from the payment on each call and passed to Whop for prefill; it is not stored in Whop beyond the checkout. It creates one Whop checkout configuration per payment: the first call sends `POST /checkout_configurations` with `mode: "payment"`, an inline one time plan (`plan_type: one_time`, `release_method: buy_now`, `company_id`, `currency`, `initial_price` converted from `Payment.amount` with integer math), `metadata` of `payment_id` (the payment UUID) and `reference` (the reference code), and `redirect_url` set to the payment's own https pay page URL. The returned `ch_` id is saved in `whop_checkout_configuration_id`, the full hosted checkout URL (the host from `config('services.whop.hosts.{environment}.checkout')` plus the path Whop returns) is saved in `whop_purchase_url`, and `whop_charge_started_at` is set (this is the moment amount, currency, account and brand lock, and cancelling is blocked). Later calls (reload, retry, a second tab) reuse the saved values and make no Whop call. The reply is JSON `{mode, checkout_configuration_id, purchase_url}`, where `mode` is the account's current `checkout_mode`; the client never gets a token or secret. The Whop HTTP timeout is 10 seconds to connect and 30 in total (config). A Whop 5xx, timeout or unreadable reply returns 502 and saves nothing.
- **AC-6**: One checkout creation runs at a time per payment (`Cache::lock('whop:checkout:{payment id}', 60)->block(5)`; a caller that gets the lock after waiting reuses the saved id; one that cannot get it within 5 seconds gets 409). The cache store must support locks (confirm in tests and on the server). A named limiter `whop-checkout` allows 10 requests per payment UUID per hour (key `whop-payment:{uuid}`, taken from the raw route string because throttle runs before route model binding) and 30 per IP per hour (key `whop-ip:{ip}`); reuse calls count toward the limit; the first limit hit returns a JSON 429 with "Too many attempts. Please try again later." and makes no Whop call. Once `whop_charge_started_at` is set, `UpdatePaymentRequest` rejects a change to the amount, currency, account or brand, and a payment cannot be cancelled while its Whop checkout configuration exists (an open Whop checkout would stay payable). **The amount, currency and account lock is built on the branch; the brand and cancel blocks are new.**
- **AC-7**: The client sees only PayHub's fixed messages, never Whop text from the API. Whop's own checkout shows its own card errors inside its box or page. `showWhop` reads `status` (and `payment`) from the query string on the server and passes a display only `returnStatus` prop; the page never reads `window.location` for it. When the browser comes back (after a redirect or a 3D Secure step) with `status` of `succeeded`, `processing` or any pending value, the page shows "We are confirming your payment. Please do not pay again." and starts polling. A return with `status` of `failed` or `canceled` shows the checkout again so the client can retry. `onComplete` and the return URL values are display only and never change PayHub status. The page removes `payment` and `status` from the address bar and history on load. Because the query is stripped, the confirming state is also remembered in `sessionStorage` under the payment UUID with its start time, so a reload keeps the waiting state instead of showing "Pay now" again. Fixed client messages (never Whop text): 409 "Your payment is being prepared. Please try again in a moment.", 422 "This payment link can no longer be used.", 429 "Too many attempts. Please try again later.", 502 "We could not start your payment. Please try again."; 409 and 502 show a retry button.
- **AC-8**: Waiting state: the page polls `pay/{payment}/whop/status` every 3 seconds while the tab is visible. The endpoint only reads `Payment.status` (no Whop call) and returns `state` only: `completed` maps to `completed`, `pending` to `waiting`, anything else to `unavailable`. It has a per minute throttle (60 per minute per IP). `completed` navigates to the Success route. The 10 minute counter starts from the start time saved in `sessionStorage`, counts visible time only, and survives a reload. After 10 minutes it shows "Still confirming, you will get a receipt by email". `ClientPaymentController::show` redirects a completed payment to Success (before the Unavailable guard).
- **AC-9**: The webhook route `webhook/whop/{whopAccount}` is excluded from CSRF, keeps the raw body, and verifies the Standard Webhooks signature (`webhook-id`, `webhook-timestamp`, `webhook-signature` header, HMAC-SHA256 with the account's `ws_` secret, compared with `hash_equals`; the header may hold several space separated `v1,` signatures, and any one matching passes) and rejects a timestamp more than 5 minutes from now. A bad or missing signature or a stale timestamp returns 401 with no queueing. A good one queues `HandleWhopWebhookJob` and returns 200 in well under 5 seconds. An account with no stored secret returns 403. An inactive account is still processed (a payment may be mid flight); 404 only for a missing account row. **Built on the branch.**
- **AC-10**: `HandleWhopWebhookJob` drops a repeated delivery by inserting the `webhook-id` into `processed_whop_events` (unique), inside the same database transaction as the status update, so a failed job leaves no event row and Whop's retry is processed. On `payment.succeeded` it finds the payment by `metadata.payment_id`, requires that it belongs to the route's account and that the event's price field equals `Payment.amount` and currency exactly (the field that equals the price PayHub set, before any tax or fee Whop adds; named in step 0), with amounts read as strings and compared in integer cents, then in one guarded update (`whereIn('status', ['pending','failed'])`) sets `completed`, `paid_at` and `whop_payment_id`, and dispatches `SendPaymentNotification` after the transaction commits, only when a row was updated. An event with no `metadata.payment_id` is logged at info level and ignored. A different amount, currency or account, or an unknown PayHub payment UUID: no status change, logged at error level. A success for a `cancelled` payment, or a second success with another Whop payment id for a `completed` payment: no change, logged at error level. **Built on the branch.**
- **AC-11**: `payment.failed` is logged at info level and does not change `Payment.status`, because the client may retry inside Whop's checkout and later succeed. Refund, dispute and every other event type return 200 after dedupe and are logged at info level with no status change. PayHub does not track Whop refunds or disputes. **Built on the branch.**
- **AC-12**: `php artisan whop:register-webhook {account?} --url=...` creates the webhook through the Whop API for the account (events `payment.succeeded` and `payment.failed`), stores the returned `ws_` secret encrypted on the account, and prints the URL. It refuses a missing or non `https` URL, and warns before replacing an existing secret. **Built on the branch.**
- **AC-13**: A Whop check (Build plan step 0) confirms or corrects every item under **Open questions** before the checkout code is built. Answers go in `## Follow-up`. Any field or event name that differs is fixed in `WhopClient` and `WhopWebhookPayload`, the only two places that know Whop field names.
- **AC-14**: On the pay page of a Whop payment only, the Content Security Policy adds exactly the Whop hosts found in step 0 for scripts, frames and connections (in `redirect` mode none are added). No other route changes, and no `unsafe-inline` or `unsafe-eval` is added.

## Decision

**Chosen option**: Option 1 with Option 3 as a per account fallback: Whop Checkout embedded on the pay page, a server created checkout configuration, and a `redirect` mode that sends the client to Whop's hosted checkout. Paid status comes from the signed webhook only.

PayHub's server creates the checkout configuration with the amount from the `Payment` record and the metadata `payment_id`, once per payment. The pay page shows Whop's checkout (embed) or opens it (redirect). Only the signed `payment.succeeded` webhook completes the payment. This keeps the rule "status comes from webhooks only" with no scoped exception, which is a different position from Clover, MPGS and Authorize.net. The first design (Option 2) is dropped because Whop Elements has no sandbox and the confirmation token charge is not in Whop's public guide.

**Implementation skills**: `laravel-best-practices` (`.claude/skills/laravel-best-practices/`) · `inertia-vue-development` (`.claude/skills/inertia-vue-development/`) · `wayfinder-development` (`.claude/skills/wayfinder-development/`) · `pest-testing` (`.claude/skills/pest-testing/`) · `provider-parity-check` (`.claude/skills/provider-parity-check/`)

## Rationale

Reasoning and options: see `rationale.md`.

## Feature design

**Data model sketch** (the three migrations already on the branch stay; one new migration adds two columns):

- `whop_accounts` (built): id, `account_name`, `prefix` nullable, `company_id`, `api_key` (encrypted, not mass assignable), `webhook_secret` nullable (encrypted, not mass assignable), `environment` (`sandbox` or `production`), `is_active`, timestamps. **New**: `checkout_mode` string default `embed` (`embed` or `redirect`).
- `payments` (built): `whop_account_id`, `whop_payment_id`, `whop_charge_started_at`. **New**: `whop_checkout_configuration_id` string nullable, indexed, and `whop_purchase_url` text nullable (full hosted checkout URL, never sent to the page props). `whop_charge_started_at` keeps its name and now means "the checkout configuration was created", which locks amount, currency, account and brand edits and blocks cancelling.
- `processed_whop_events` (built): id, `webhook_id` unique, `event_type`, timestamps.
- No attempts table and no pause key. PayHub makes no charge call, so there is no unclear charge to wait out.

Relationships: `whop_accounts` 1:N `payments`. Agents reach an account through `user_payment_accounts`, as for the other providers.

**State transitions**: `pending` to `completed` on a matching `payment.succeeded` (the job also accepts `failed` as a source, as the other providers do, but Whop never sets `failed` in v1, and the checkout endpoint requires `pending`). A decline inside Whop's checkout, a return with a pending status and `payment.failed` leave `pending` unchanged. `completed` is terminal. Whop never sets `failed` in v1.

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `whop-accounts` (resource, except `show`), `activate`, `deactivate`, `test-connection` (built) | GET/POST/PUT/DELETE/PATCH | account fields from AC-1 | rows with `has_api_key`, `has_webhook_secret` flags, never the secrets | `role:admin` | 403, 404, 422 |
| `pay/{payment}` and `pay/{payment}/{ref}` (existing, new `showWhop` branch handles both) | GET | payment UUID, optional return query `status`, `payment` | Inertia page with brand, policies, amount, currency, `mode`, `environment`, `hasCheckout`, `returnStatus` | public (UUID) | 404, redirect to Success when completed, Unavailable when not payable or account inactive |
| `pay/{payment}/whop/checkout` | POST | none (UUID in path) | JSON `{mode, checkout_configuration_id, purchase_url}` | public (UUID), limiter `whop-checkout` | 409 busy, 422 not payable or no consent, 429, 502 |
| `pay/{payment}/whop/status` | GET | payment UUID | JSON `{state}`, state is `waiting`, `completed` or `unavailable` | public (UUID), throttle 60 per minute | 404, 429 |
| `webhook/whop/{whopAccount}` (built) | POST | raw body and the three signature headers | 200 after queueing | signature, CSRF excluded | 401/403, 404 |
| `whop:register-webhook` (built) | Artisan | `{account?}`, `--url` | secret stored encrypted | operator | refuses non `https`, API errors |

**Value sourcing**:

| Action | Value produced / displayed | Source |
|---|---|---|
| Pay page | mode (`embed` or `redirect`), environment | the payment's `whop_accounts` row (`checkout_mode`, `environment`) |
| Pay page | button amount, currency, client email | `Payment.amount`, `Payment.currency`, `Payment.client_email` |
| Pay page | `hasCheckout` | `payments.whop_checkout_configuration_id` is not null (the id itself is not sent) |
| Pay page | `returnStatus` | `status` query value read server side in `showWhop`, display only |
| Pay page | Whop script host | `config('services.whop.hosts')`, values from step 0 |
| CSP | whether to add Whop hosts | the payment's account `checkout_mode` is `embed`, read in the CSP middleware on the Whop pay page only |
| Checkout reply | `purchase_url` | `payments.whop_purchase_url`, built when the configuration was created from `config('services.whop.hosts.{environment}.checkout')` plus Whop's returned path |
| Checkout reply | `mode` | the account's `checkout_mode` at call time |
| Checkout | consent check | the existing `payment_consents` record for the payment |
| Limiter | keys | `whop-payment:{uuid}` and `whop-ip:{ip}` |
| Checkout | API key and company ID | the payment's `whop_accounts` row, decrypted server side |
| Checkout | `plan.initial_price` | `Payment.amount` cents converted to Whop's decimal with integer math (never the request) |
| Checkout | `plan.currency` | `Payment.currency` (case per step 0) |
| Checkout | `plan.plan_type`, `plan.release_method` | constants `one_time`, `buy_now` |
| Checkout | `plan.product.external_identifier`, `plan.product.title` | `payhub-brand-{Payment.brand_id}` and the brand name (one Whop product per brand, reused) |
| Checkout | `plan.title` | `Payment::formattedReferenceCode()` |
| Checkout | `metadata.payment_id`, `metadata.reference` | `Payment.uuid`, `Payment::formattedReferenceCode()` |
| Checkout | `redirect_url` | `route('pay.show', $payment)`, must be `https` |
| Checkout | email prefill | `Payment.client_email` (how it is passed is a step 0 question) |
| Checkout | HTTP timeouts, lock length | `config('services.whop.connect_timeout')` (10), `timeout` (30), `lock_seconds` (60) |
| Status | state shown | `Payment.status` only |
| Webhook | which account and secret | route `{whopAccount}` row, `webhook_secret` decrypted |
| Webhook | which payment | `metadata.payment_id` in the event, checked against the route's account |
| Webhook | amount and currency checked | event fields converted with integer math, compared to `Payment` |
| Webhook | idempotency key | `webhook-id` header |
| Register command | webhook URL | `--url` option |

**Key invariants**:
- Only a verified, deduplicated `payment.succeeded` webhook with matching account, amount and currency ever sets `completed`. The browser, `onComplete`, the return URL and the checkout reply never do.
- A checkout never uses an amount, currency or account from the request.
- Each payment has at most one Whop checkout configuration, and its amount, currency and account never change after it exists.
- `completed` is terminal. A second success or a success for a `cancelled` payment changes nothing and logs at error level.
- The API key and webhook secret are never logged, stored in a URL or returned.
- Amounts are integer minor units everywhere in PayHub; Whop's decimal format exists only at the edge of `WhopClient` and `WhopWebhookPayload`.
- Any Whop value PayHub does not recognize is logged and ignored, and never fails a payment update.

**Security model**:
- Account management is `role:admin` only. Pay, checkout and status routes are public but scoped by the unguessable payment UUID. The webhook route is protected by the signature and a 5 minute replay window.
- **Compliance scope is PCI DSS.** Whop collects the card (in its embedded checkout or its own page), so the target is SAQ A, not SAQ A-EP. In `embed` mode the checkout sits inside PayHub's page, so the acquirer or an assessor makes the final call; `redirect` mode is the lightest case.
- Whop acts as merchant of record for these payments (see `docs/research/whop.md`): it settles to the company and can suspend or hold payouts. This is a business risk, not a code risk, and the engineer accepted it.
- Only the Whop hosts found in step 0 are added to the Content Security Policy, only on this pay page, only in `embed` mode (AC-14). Sentry session replay must not record the checkout (`sentry-block`).
- Checkout abuse is limited by the limiter and the lock (AC-6), and by the one configuration per payment rule, so repeated calls cannot create many Whop checkouts.
- Raw Whop text goes to the logs for operators only. Clients see the fixed strings in AC-7.
- Audit trail: `whop_checkout_configuration_id` and `whop_payment_id` on the payment plus `processed_whop_events`; every refused or mismatched event is logged with both ids.

**Configuration required**:
- `config/services.php` `whop` (built): `hosts` per environment (API base URL; step 0 adds the embed script and frame hosts for the CSP), `lock_seconds` (60), `connect_timeout` (10), `timeout` (30). `unclear_pause_seconds` is no longer used and is removed.
- No `.env` secrets: the API key and webhook secret live encrypted per account.
- Outside the code: a Whop API key for each brand's company with `checkout_configuration:create`, `plan:create`, `access_pass:create`, `access_pass:update` and `checkout_configuration:basic:read` (from Whop's reference), plus a permission to create webhooks (step 0). The Whop plan must allow it.
- The embed package pinned in `package.json` (name from step 0); `public/build` is committed, so rebuild with `npm run build`.

**Open questions** (answered by the step 0 check; none were tested yet):
1. Which embed package takes a server made checkout configuration id: the older `@whop/checkout` (`sessionId`) or the `@whop/elements` Checkout (the docs show a `plan` id)? Does the chosen one also count as "Whop Elements", which the sandbox page says is unsupported?
2. The exact script, frame and connect hosts for the CSP, and whether it works with no `unsafe-inline`.
3. How to prefill the client email and hide the email box, both in the embed and in the hosted `purchase_url` (query parameter or option).
4. The `payment.succeeded` payload fields: which field equals the price PayHub set before tax or fees (`subtotal`, `total` or other), the currency case, the paid time field, and that `metadata` is copied from the checkout configuration. Does `payment.failed` carry the same fields?
5. Does Whop add tax, VAT or fees to the paid total?
6. **Answered 2026-11-03 (see Follow-up): a replay is accepted with a fresh timestamp, no longer a blocker.** Does Whop re-sign retried webhooks with a fresh timestamp? (Its guide does not say.) If not, the 5 minute window rejects every retry, and with no lookup fallback a paid payment would stay `pending`. Answer this before merge; a wider window or a lookup is needed if the answer is no.
7. The currency format the API wants (`usd` or `USD`), and that both USD and GBP work on the company's plan.
8. Is `plan.product` with `external_identifier` and `title` the right inline shape, and does a second checkout for the same brand reuse the product?
9. The `purchase_url` is a path (like `/checkout/ch_xxxx/`): which host to put in front of it for sandbox and for production (the `checkout` entry in `config('services.whop.hosts')`)?
10. Does the Whop plan allow creating checkout configurations by API, and which key permissions does webhook creation need? Does a read only call exist that is cheap for Test connection?
11. Does the embed work in the sandbox, or only the hosted page? If neither embed package works, `embed` stays off until the production check and `redirect` is the first mode used.
12. Can a paid checkout configuration be paid again (a second tab), and how does Whop behave if the client reopens it after paying? Can the plan be limited to one use (stock of 1)?
13. How are wallets (Apple Pay, Google Pay, PayPal) turned off so the checkout shows cards only?
14. The exact return query names Whop appends (`payment`, `status`) and their values, in both the embed and the redirect.
15. Can a checkout configuration or plan be archived or disabled by API, so a cancelled payment cannot still be paid? (Cancel is blocked in PayHub while a configuration exists, so this is only a nice to have.)

**Critical test scenarios** (all Whop calls use `Http::fake`; the real service is covered only by step 0 and a staging run):
- Happy path: checkout endpoint creates one configuration, webhook `payment.succeeded` completes the payment once, notification sent once, status poll then returns `completed`, verifies **AC-5**, **AC-8**, **AC-10**
- Second call to the checkout endpoint reuses the saved id and makes no Whop call, verifies **AC-5**
- Two checkout calls at once: one Whop call only; a caller that cannot get the lock within 5 seconds gets 409, verifies **AC-6**
- The eleventh call in an hour (reuse calls count) gets a JSON 429 with no Whop call, verifies **AC-6**
- No consent record: the checkout endpoint returns 422 and makes no Whop call, verifies **AC-5**
- Cancel and brand change are rejected once a checkout configuration exists, verifies **AC-6**
- Inactive account: the pay page shows Unavailable, the webhook still completes a mid flight payment, verifies **AC-4**, **AC-9**
- Completed payment: `show` redirects to Success before the Unavailable guard, verifies **AC-4**, **AC-8**
- Reload while confirming keeps the waiting state and the 10 minute counter, verifies **AC-7**, **AC-8**
- Client messages for 409, 422, 429 and 502 are the fixed strings and never contain Whop text, verifies **AC-7**
- Status endpoint returns `state` only and is throttled, verifies **AC-8**
- Mode flip between page load and endpoint call: the reply's mode wins, verifies **AC-4**
- CSP adds Whop hosts only for an `embed` account on the Whop pay page, verifies **AC-14**
- Whop 5xx or timeout on create: 502, nothing saved, the amount edit lock is not set, verifies **AC-5**
- Not pending, wrong provider or inactive account: 422, verifies **AC-5**
- Request body with an amount or account is ignored, verifies **AC-5**
- `redirect` mode returns `purchase_url`; `embed` mode returns the configuration id, verifies **AC-4**, **AC-5**
- A Whop payment page never renders the Stripe page, verifies **AC-3**
- Return with `status=succeeded` shows the waiting state and strips the query values; `status=failed` shows the checkout again, verifies **AC-7**
- Webhook before the browser returns: still matches by `metadata.payment_id` and completes, verifies **AC-10**
- Replay, mismatch, bad signature, cancelled payment, second success, `payment.failed`, refunds (all built and tested on the branch), verifies **AC-9** to **AC-11**
- Amounts: GBP and odd cents (12.34, 0.10) convert exactly in both directions, verifies **AC-5**, **AC-10**
- CSV export, dashboard filter and "Accounts Today" include a Whop payment, verifies **AC-3**
- Amount, currency or account change blocked after the checkout exists (built), verifies **AC-6**
- Account form saves and shows `checkout_mode`; an invalid value is rejected, verifies **AC-1**

## Migration plan

**Strategy**: no migration of live data. The branch is not deployed and no Whop payment exists, so the change adds to what is built.
**Phases**:
1. Keep the built work: account CRUD, webhook, completion job, payment form rules, enum, factories (steps 1 to 4 of the old plan).
2. Add the new migration, the checkout endpoint, the status endpoint, the pay page and the CSP (below).
3. Remove nothing from the branch except the unused `unclear_pause_seconds` config key; no charge code was ever written.
**Rollback**: revert the new migration and the new controller and page; the branch's built parts do not depend on them.
**Risks**: Whop's embed may not work in the sandbox (Open question 11), so the first full test may use `redirect` mode, then production with a small real payment.

## Build plan

Build approach: none recorded in `AGENTS.md`, so end to end Tracer Bullet slices are assumed. Steps 1 to 4 are already on the branch; the first new thread is checkout to webhook to completed payment in `redirect` mode, because it is the one path testable end to end in the sandbox.

0. Whop check against the sandbox and Whop's docs: answer every Open question, record answers in Follow-up, fix field names in `WhopClient` and `WhopWebhookPayload`, pin the embed package, satisfies **AC-13**
1. (Done on branch) Migrations for `whop_accounts`, payments columns, `processed_whop_events`; enum, models, factories, config
2. (Done on branch) `WhopClient`, `WhopWebhookPayload`, webhook route, signature check, `HandleWhopWebhookJob`, tests, satisfies **AC-9**, **AC-10**, **AC-11**
3. (Done on branch) Admin account CRUD, test connection, `whop:register-webhook`, satisfies **AC-1**, **AC-12**
4. (Done on branch) Payment create and update requests, currency rules, edit lock, agent assignment, satisfies **AC-2**, **AC-6**
5. New migration (`whop_checkout_configuration_id`, `whop_purchase_url`, `checkout_mode`); `checkout_mode` on the account form, request and page; block cancel and brand change once `whop_charge_started_at` is set, satisfies **AC-1**, **AC-6**
6. `WhopClient::createCheckoutConfiguration`, the checkout endpoint with server side consent check, lock, saved value reuse and the `whop-checkout` limiter, satisfies **AC-5**, **AC-6**
7. `showWhop` in `ClientPaymentController` (handles both pay routes, completed redirects first, inactive account shows Unavailable, no fall through to Stripe), the status endpoint with its throttle, and the pay page in `redirect` mode: consent, amount, button, redirect, `returnStatus`, waiting state kept in `sessionStorage`, polling, query clean up, fixed messages, satisfies **AC-3**, **AC-4**, **AC-7**, **AC-8**
8. Embed mode in the pay page (`sentry-block` container, mounts only after the endpoint answers), the CSP addition for the hosts from step 0 (the middleware reads the account's `checkout_mode`), `npm run build`, regenerate Wayfinder files, satisfies **AC-4**, **AC-14**
9. Parity sweep: CSV export match arm, `DashboardMetrics` (filter whitelist, account names, "Accounts Today", totals), `CentralSummary`, receipt pages, with the `provider-parity-reviewer`, satisfies **AC-3**
10. Pest tests for the scenarios above, `php artisan test --parallel` (set `APP_BASE_PATH` for your machine), then `npm run build`, satisfies **AC-1** to **AC-14**
11. Staging run with the Whop sandbox in `redirect` mode (success, decline, 3D Secure, repeated webhook), then try `embed`, then production key and webhook registration

## Consequences

**Positive**:
- One more provider that follows the existing rules, with no new exception to "status comes from webhooks only".
- Card numbers never touch PayHub code, and PayHub makes no charge call, so there is no unclear charge state, no pause key and no token handling to build.
- Everything built on the branch so far stays. The redirect mode can be tested end to end in the sandbox.

**Negative / tradeoffs**:
- The client sees Whop's checkout, not a PayHub card form, so the look is less like the other providers.
- Whop's field names, hosts and embed package are unverified until step 0.
- Without a lookup fallback, a lost webhook leaves a paid payment `pending` until Whop retries (about 12 times over 71 hours) or staff check the Whop dashboard.
- Whop is merchant of record and relies on other processors behind the scenes, so payouts can be held or suspended outside PayHub's control.
- Whop fees are higher than most direct providers (2.7% + $0.30 domestic, extra for international cards and currency conversion).
- Payments made this way will not appear as invoices in the Whop dashboard.
- One Whop checkout configuration is created per payment, so abandoned payments leave unused ones in Whop.

**Neutral**:
- `payment.failed` is logged only, so a Whop payment never reaches the `failed` status in v1.
- Refunds and disputes stay in the Whop dashboard.
- `AGENTS.md` still says "seven providers" until `/sync` updates it.

## Follow-up

- [x] Step 0 doc check done 2026-11-03 (Whop docs, `@whop/elements` 1.9.0 typings). Answered from docs: **1** `@whop/elements` Checkout takes `checkoutConfiguration: "ch_..."` (and `returnUrl`, `onComplete`, `buyerEmail`, `lockBuyerEmail`); **2** CSP is `https://cdn.whop.com` for script and frame, API origin for connect (from the package's `contentSecurityPolicy()`); **3** embed prefill is `buyerEmail` + `lockBuyerEmail` (not wired yet: the page props do not carry the email), hosted URL prefill undocumented; **4** payment object has `total`, `subtotal`, `usd_total`, lowercase `currency`, `paid_at`, `metadata` (kept `subtotal` as `PRICE_FIELD`, confirm in sandbox); **7** currency lowercase; **8** inline `plan.product` with `external_identifier` + `title` updates or creates (docs); **9** hosts: sandbox `https://sandbox.whop.com`, production `https://whop.com` (frontend host, confirm); **11** Whop's sandbox guide says Elements is not available in sandbox, so `redirect` is the first mode to test; **13** no option found to disable wallets; the full Checkout element is used; **14** return query is `payment` and `status` (`succeeded`, `failed`, `canceled`). **Still open, must be tested in the sandbox/production:** 5, 6 (**merge blocker**), 10, 12, 15, plus the `subtotal` vs tax/fee check and the hosted `purchase_url` host.
- [x] Sandbox finding 2026-11-03: the live API refuses the inline `plan` shape ("variant_id is required for payment mode"). It takes `account_id` (the `biz_` company id) plus an inline `variant` (`billing_type`, `release_method`, `visibility: hidden`, `currency`, `initial_price`, `title`), no product needed, and returns a full `purchase_url` (`https://sandbox.whop.com/checkout/ch_.../`). `WhopClient` now sends that shape. This replaces the plan and brand product rows in the Value sourcing table (no Whop product per brand). The request is not pinned with an `Api-Version-Date` header; consider pinning once a valid date is confirmed.
- [x] Sandbox payments 2026-11-03: two payments paid at Whop (one through the redirect page, one through the embed, so **the embed works in the sandbox**, answering Open question 11). Whop's payment object has money as an object, `subtotal`/`total`/`tax_amount`/`usd_total` = `{"currency":"usd","amount":"111.00","decimals":2,...}`, lowercase top level `currency`, `paid_at` ISO string, `status: paid`, `substatus: succeeded`, `metadata` copied from the checkout (`payment_id`, `reference`), tax 0.00 here. `WhopWebhookPayload` now reads the object form. The webhook body itself is still unseen (staging).
- [x] Staging run 2026-11-03 (embed mode, Whop sandbox, `QUEUE_CONNECTION=sync`, webhook `https://stagingpayhub.bestsolutionadvisor.com/webhook/whop/1` with `payment.succeeded` and `payment.failed`): success (`4242`), decline (`4000 0000 0000 0002`, then a retry on the same link), 3D Secure (`5385 3083 6013 5181`, password `Checkout1!`), reload while confirming and a GBP payment all worked; the success page shows the reference code. Real `payment.succeeded` body: `subtotal`, `total`, `presentment_total` and `usd_total` are `{amount, currency, decimals}` objects, all `100.00` `usd` for a $100 payment paid from Pakistan; `tax_amount` `0.00`; `currency` lowercase; `metadata` has `payment_id` (the payment uuid) and `reference`; `payment_method_type` is `card`; `customer_email` and `billing_address.name` carry the prefilled values; the envelope has `id` (`msg_...`), `timestamp`, `type`, `api_version` `v1` and `api_version_date` `2026-11-02-1` (a valid date to pin with `Api-Version-Date`). **`PRICE_FIELD` `subtotal` confirmed.** The reply body is empty, so Whop's log shows "Invalid JSON response" next to a 200 OK; harmless.
- [x] **Open question 6 answered 2026-11-03:** replaying an 11 minute old delivery from Whop's dashboard ("Replay delivery") came back 200 OK. The signature and 5 minute window run before dedupe, so Whop re-signs a replay with a fresh `webhook-timestamp`. Not a merge blocker. Automatic retry after a 5xx was not exercised separately; it is expected to use the same signing path.
- [x] Checkout shape sent by `WhopClient::createCheckoutConfiguration` (variant): `adaptive_pricing_enabled: false` (otherwise Whop showed a local currency price, for example PKR, in the embed) and `payment_method_configuration` `{enabled: WhopClient::PAYMENT_METHODS, disabled: [], include_platform_defaults: false}` (was card only; now card, apple_pay, google_pay, paypal and us_bank_account, crypto and buy now pay later stay off). Both are accepted inside `variant` and read back in the created configuration.
- [x] Embed page display (`PayWhop.vue`, commits `73c096c7`, `6a8dff81`, `f125293b`): the embed mounts on load (no Pay now step; redirect mode keeps the button); Whop's summary (company name, price) and email field are hidden with `appearance.classes` (`whop-CheckoutSummary`, `whop-Email`, `display: none`; Whop does not document `display: none`, so a Whop update could bring them back); the email is prefilled and locked with `buyerEmail` and `lockBuyerEmail` (element options, not group options), and the billing name is prefilled with `defaultValues.billingDetails.name`. Billing details cannot be hidden: Whop blocks payment with "Complete your billing details before continuing" (country and address stay for the client). `clientEmail` and `clientName` are page props in embed mode only.
- [x] Build progress 2026-11-03: plan steps 5 to 11 built and run on staging (migration applied, `WhopPaymentController`, `PayWhop.vue`, CSP, parity sweep, `WhopCheckoutTest`, `npm run build`).
- [ ] A brand rename leaves the Whop product with the old title; accept, or update the product when the brand changes.
- [ ] Unused checkout configurations pile up in Whop; accept for v1, revisit if it becomes noise.
- [ ] A lost webhook leaves a paid payment `pending` and the client may pay again; webhook only was chosen (no lookup). Revisit with a read only lookup or an admin "check Whop" action if it happens.
- [ ] A later spec can move `embed` out of v1 if step 0 shows the embed does not work in the sandbox; `redirect` stays the first mode used.
- [x] Fix the CSV export `match` (no Whop arm, throws `UnhandledMatchError`) and the dashboard gaps in `DashboardMetrics`; done in Build plan step 9 (`PaymentsExport`, `DashboardMetrics`).
- [x] `docs/integrations/central-dashboard-api.md` needs no change: `CentralSummary` returns counts and amounts only, with no provider field or list, so the `whop` value does not reach the receiving side.
- [ ] Write a short refund note for the "second success" error log: staff refund the extra payment in the Whop dashboard.
- [ ] Confirm the PCI level (SAQ A) with Whop or the acquirer before production, since the embed runs inside PayHub's page.
- [ ] Confirm the company's Whop plan allows API checkouts, and USD and GBP.
- [ ] `phpunit.xml` hardcodes `APP_BASE_PATH` for another machine (already on `master`); tests fail locally until it is overridden or removed.
- [ ] Revisit a read only lookup (MPGS style sweep) only if staging or production shows lost webhooks, and whether `payment.failed` should ever set `failed`.
- [ ] Wallets (Apple Pay, Google Pay, PayPal) need each brand's domain registered with Whop; a separate spec if wanted.
- [ ] After the build, `/sync` updates `AGENTS.md` (eight providers, Whop bullet, webhook and CSRF lists) and `docs/agent.md`.
- [ ] The `provider-parity-reviewer` agent and skill text still say four providers; refresh them so they check all eight.
