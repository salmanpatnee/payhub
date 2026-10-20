# 0005. Add MPGS (Mastercard Gateway) Hosted Session as a payment provider

**Date**: 2026-10-17
**Status**: Built and working on staging; awaiting go-live details and the remaining sandbox checks

## Summary

This spec adds MPGS (Mastercard Payment Gateway Services) as a new PayHub payment provider. The client types their card into gateway hosted fields (small secure frames from the gateway) embedded on PayHub's own brand pay page, so card details never touch PayHub's server. It follows the Clover pattern (per account client, an attempts table, a resolver job), plus a webhook as a second source of truth, and it includes full 3D Secure (3DS, the bank's extra card check). Several MPGS details are not yet confirmed against a real sandbox, so the first build step is a sandbox spike that gates the rest of the work.

## Requirements

**User stories**:
- As an admin, I want to create and manage an MPGS account (merchant ID, integration password, webhook secret, API version) the same way I manage every other provider account.
- As an agent, I want to create a payment link against an MPGS account exactly like any other provider.
- As a client, I want to pay by typing my card on the brand page, complete my bank's 3DS check without leaving the page, and if my card is declined, try another card without a new link.
- As the business, I want a payment marked paid only from a result the gateway itself returned to PayHub's server or confirmed by a lookup, never from anything the browser says, and I want a double click or a slow connection to never charge a card twice.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: An admin can create, edit, activate, deactivate and test the connection of an MPGS account (account name, prefix, merchant ID, integration password, optional webhook secret, API version, optional gateway host), restricted to `role:admin`. The integration password and webhook secret are encrypted at rest, not mass assignable, and never returned to the browser (only `has_*` flags). Test connection makes one authenticated call with the stored credentials and reports success or failure without touching any payment.
- **AC-2**: An agent or admin can create a payment link against any active MPGS account in USD or GBP. MPGS accounts accept both currencies with no per account currency lock (like Stripe and Revolut), so `CurrencySupportResolver` treats `mpgs` as supported for USD and GBP. **Amended 2026-10-20:** PKR is also allowed, only on an MPGS account with `supports_pkr` on (the bank enables PKR per merchant), and only for admins (agents, Zelle and user account slots stay USD/GBP via `SupportedCurrency::standard()`). No other provider accepts PKR. A currency the bank has not enabled shows up only at pay time as a gateway rejection (see AC-7, hard error).
- **AC-3**: MPGS appears everywhere the other providers already do: payment create and edit forms, agent account assignment (`user_payment_accounts`), the `ProviderAccountTable` and `CurrencySupportResolver` maps, CSV export, the dashboard account filters and "Accounts Today", the provider badge, and the client facing Success, Failed and Unavailable pages. No surface is left behind (grep for the other providers' two way and three way `match`, ternary and `in_array` lists).
- **AC-4**: When a client opens an MPGS payment link, PayHub renders the card form on the pay page using the gateway's Hosted Session fields, loaded from the account's gateway host (falling back to the configured default) with the account's merchant ID and API version. The card fields are `readonly` inputs with no `name` attribute, which the gateway replaces with its own frames. No raw card data is ever sent to PayHub. The page declares the clickjacking mitigation it really enforces (`x-frame-options`, which PayHub already sets to `DENY`).
- **AC-5**: A prepare endpoint creates a gateway session on the server with the account's credentials (asking for `session.authenticationLimit` of 25, since 3DS retries count against it), writes the payment's amount and currency onto that session from the `Payment` record together with the 3DS fields (`authentication.channel` of `PAYER_BROWSER` and `authentication.redirectResponseUrl`, the PayHub return route), creates an `mpgs_charge_attempts` row (status `prepared`) holding a unique gateway order id (`{payment_id}-{sequence}`) and the 3DS and PAY transaction ids, and returns only what the browser needs (session id, order id, authentication transaction id). It rejects with 422 unless the payment's provider is MPGS, its status is `pending` or `failed`, its account exists and is active, and no PAY is currently in flight for it. The session id is encrypted at rest on the attempt, never logged, never placed in a URL, and cleared when the attempt reaches a final state.
- **AC-6**: The browser runs 3DS (initiate authentication, then authenticate payer) using the ids PayHub issued, shows any bank challenge inline in a container on the pay page, and then calls the pay endpoint. The CSP is widened (`frame-src https:` plus the account's gateway host in script, connect and frame sources) only on the pay page of an MPGS payment; every other page keeps the strict policy. The 3DS return route (`authentication.redirectResponseUrl`) is the one route that sends `X-Frame-Options: SAMEORIGIN` instead of `DENY`, because the bank's challenge frame redirects back to it inside the pay page; it takes no payment data and changes no status.
- **AC-7**: The pay endpoint is rate limited per payment, takes a lock so only one PAY is ever in flight per payment, requires an attempt in status `prepared` that belongs to the payment and is not expired, moves it to `pending`, and sends PAY to the gateway with the amount and currency read from `Payment` (never the request), the stored session id and the 3DS authentication transaction id. The response is classified into exactly one of four buckets: **approved** (gateway result is success AND the order is captured AND the amount and currency match `Payment` exactly, anything less is unknown), **declined**, **hard error** (bad credentials, malformed request, unsupported currency: logged, payment untouched), or **unknown** (timeout, network failure, 5xx, anything unclassifiable: resolved later, AC-10).
- **AC-8**: A given gateway order can move a payment to `completed` at most once, using the same two guards the Stripe, Square and Clover handlers use: `whereIn('status', ['pending', 'failed'])` for completion and `where('status', 'pending')` for a decline. An approved attempt sets `paid_at` and `mpgs_order_id` in that same guarded update and dispatches `SendPaymentNotification` only when the update affected a row. The synchronous pay path, the webhook job and the resolver job all apply results through one shared resolver class, never three copies.
- **AC-9**: The webhook route `webhook/mpgs/{mpgsAccount}` is excluded from CSRF, rejects any request whose `X-Notification-Secret` header does not match the account's stored secret (compared with `hash_equals`, rejected outright when the account has no secret set), and then only queues a job. The job looks the order up at the gateway and applies the lookup result through the shared resolver. It never trusts the webhook body. Duplicate deliveries are dropped through `processed_mpgs_events`. PayHub sets `order.notificationUrl` per PAY request so no global gateway configuration is needed.
- **AC-10**: An attempt classified unknown is resolved by a queued resolver job that only reads the gateway (retrieve order, then pick the PAY transaction by its id, not "the last one"), never re-sends PAY. After its retries it marks the attempt `abandoned` and logs it. A scheduled sweep every 10 minutes re-dispatches stale `pending` attempts and expires `prepared` attempts older than the configured session lifetime without calling the gateway.
- **AC-11**: The client experience matches Clover: an approved response goes straight to Success; a declined response shows a fixed PayHub message inline (never the gateway's raw text) and the client can retry with another card, which creates a new attempt and a new order id; an unknown response shows a processing state that polls the payment status every 3 seconds for up to 2 minutes, then a terminal "still confirming, you will get a receipt by email" message.
- **AC-12**: Security rules hold: amount, currency, account and payment are always resolved server side; the integration password and webhook secret are encrypted and never exposed; the prepare and pay endpoints each have a named rate limiter keyed on the payment UUID; raw gateway decline text is stored for operators only; secrets and session ids never appear in logs.
- **AC-13**: Before any build task beyond account management begins, a sandbox spike with a real test merchant confirms or corrects each item in this spec's **Open questions** and records the answers in `## Follow-up`. Where an answer changes a field name, endpoint or sequence in this spec, the spec is updated through `/architect` before the dependent task is built.

## Decision

**Chosen option**: Option 1: Hosted Session fields on PayHub's pay page, server side PAY, with a webhook and a read only resolver as backstops

Embed the gateway's Hosted Session card fields on the brand pay page and run 3DS with the gateway's JavaScript library. PayHub's server creates the session, writes the amount onto it, and sends the PAY request. The gateway's synchronous response to that server to server call is the primary result. A webhook (a signal only; the job re-reads the order) and a read only resolver job cover lost or late responses. This is the same scoped exception to "status comes from webhooks only" that spec 0002 made for Clover, for the same reason: the channel is server to server and the browser is never trusted.

**Implementation skills**: `laravel-best-practices` (`.claude/skills/laravel-best-practices/`) · `inertia-vue-development` (`.claude/skills/inertia-vue-development/`) · `pest-testing` (`.claude/skills/pest-testing/`) · `provider-parity-check` (`.claude/skills/provider-parity-check/`)

## Rationale

Reasoning and options: see `rationale.md`.

## Feature design

**Data model sketch**:

- `mpgs_accounts` (new)
  - `id`, `account_name` string, `prefix` string(10) nullable (reference code prefix, as other accounts)
  - `merchant_id` string, plain (not secret, needed in URLs and the browser)
  - `integration_password` text, `encrypted` cast, NOT mass assignable
  - `webhook_secret` text nullable, `encrypted` cast, NOT mass assignable (null means the webhook route rejects everything and PayHub relies on the sync response and resolver)
  - `api_version` unsigned small integer (default from config, 100)
  - `gateway_host` string nullable (the account's gateway host, e.g. the bank's test host; null means use `config('services.mpgs.host')`)
  - `is_active` boolean default true, timestamps
  - `supports_pkr` boolean default false (added 2026-10-20, migration `2026_10_20_000001`): whether this merchant accepts PKR payments.
  - No `currency` column and no `environment` column. Test versus live is a different merchant ID and password, and usually a different host, so the host is set per account with the config value as the fallback.
- `payments` (existing, additive migration)
  - Adds `mpgs_account_id` FK nullable (cascade on delete, like the others) and `mpgs_order_id` string nullable, indexed (the winning attempt's gateway order id, shown as the provider reference in CSV and dashboard)
  - The `provider` enum gains `mpgs`
- `mpgs_charge_attempts` (new, written before any PAY call)
  - `id`, `payment_id` FK (cascade), `sequence` unsigned int, unique (`payment_id`, `sequence`)
  - `order_id` string unique (`{payment_id}-{sequence}`, the gateway order id)
  - `auth_transaction_id` string (3DS), `transaction_id` string (the PAY transaction)
  - `session_id` text nullable, `encrypted`, set at prepare, nulled at any final state
  - `status` string default `prepared` (`prepared`, `pending`, `approved`, `declined`, `hard_error`, `abandoned`; a plain string, not a DB enum)
  - `auth_status` string nullable (3DS outcome), `gateway_code` string nullable, `decline_reason` string nullable (operators only), `receipt` string nullable
  - `attempts_count` unsigned int default 0, `last_checked_at` timestamp nullable, timestamps
- `processed_mpgs_events` (new): `id`, `event_key` string unique (`order_id:transaction_id:result`), timestamps
- `user_payment_accounts` (existing): `provider` column is a plain string, so `mpgs` needs no schema change, only the enum cast and `ProviderAccountTable` map

Relationships: `mpgs_accounts` 1:N `payments` · `payments` 1:N `mpgs_charge_attempts` · agents reach an account through `user_payment_accounts` (`provider = mpgs`, `account_id`).

**State transitions**:

Payment: `pending` → `failed` (a declined attempt) → `completed` (a later approved attempt), or `pending` → `completed` directly. `completed` is terminal. `failed` is not terminal. A payment can sit `pending` while its latest attempt is unresolved.

Attempt: `prepared` → `pending` (PAY sent) → `approved` | `declined` | `hard_error` | `abandoned`. `prepared` may also go straight to `abandoned` (expired, never paid). Final states are written once.

**Attempt classification** (applied to the PAY response, to a retrieve order lookup, and to the webhook triggered lookup; field names marked in Open questions):

| Bucket | Condition | Effect |
|---|---|---|
| **Approved** | gateway result is success AND the PAY transaction is approved AND the order is captured AND order amount and currency equal `Payment` exactly | Attempt `approved`; payment `completed` (AC-8) |
| **Declined** | a classifiable decline (issuer decline, failed 3DS, insufficient funds and similar gateway codes) | Attempt `declined`; payment `failed` if still `pending` |
| **Hard error** | request invalid or unauthorized (bad credentials, malformed, currency not enabled for the merchant, unknown merchant) | Attempt `hard_error`, logged; payment untouched; never retried |
| **Unknown** | transport failure, timeout, 5xx, a response matching none of the above, or success without a matching capture or amount | Attempt stays `pending`; payment untouched; resolver job (AC-10) |

`MpgsClient::pay()` must not `->throw()` on 4xx or 5xx. It returns status and body so the caller can classify.

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `mpgs-accounts` (resource, except `show`) | GET/POST/PUT/DELETE | account fields from AC-1 | rows with `has_integration_password`, `has_webhook_secret` flags, never the secrets | `role:admin` | 422 |
| `mpgs-accounts/{account}/activate`, `/deactivate`, `test-connection` (stored), `mpgs-accounts/test-connection` (unsaved keys) | PATCH/POST | credentials for the unsaved form | success or failure message | `role:admin` | 404, 422 |
| `pay/{payment}` (existing route, new MPGS branch `showMpgs`) | GET | payment UUID | Inertia page with gateway host, merchant id, api version, brand data (no session id) | public (UUID) | 404, redirect to Unavailable when not payable |
| `pay/{payment}/mpgs/session` | POST | none (payment from route) | JSON `{session_id, order_id, auth_transaction_id, attempt_id}` | public (UUID), limiter `mpgs-session` | 422 not payable, 409 PAY in flight, 429 |
| `pay/{payment}/mpgs/pay` | POST | `attempt_id` | JSON `{outcome: approved\|declined\|unknown, message}` with a fixed PayHub message | public (UUID), limiter `mpgs-pay` 10 per payment per hour | 422 bad attempt, 409 in flight, 429 |
| `pay/{payment}/mpgs/status` | GET | payment UUID | JSON `{status}` | public (UUID) | 404 |
| `pay/{payment}/mpgs/3ds-return` | GET/POST | what the bank's ACS posts back after a challenge (order id, transaction id, recommendation) | a tiny page that hands the result to the pay page and renders nothing sensitive; sends `X-Frame-Options: SAMEORIGIN` | public (UUID), CSRF excluded (cross site POST from the bank) | 404 |
| `webhook/mpgs/{mpgsAccount}` | POST | `X-Notification-Secret` header, gateway body (ignored beyond order and transaction ids) | 200 after queuing | secret header, CSRF excluded | 401/403 secret mismatch or none set, 404 unknown account |

**Value sourcing**:

| Action | Value produced / displayed | Source |
|---|---|---|
| Pay page | gateway host | `MpgsAccount.gateway_host`, else `config('services.mpgs.host')` (env `MPGS_GATEWAY_HOST`) |
| Prepare | `authentication.redirectResponseUrl` | absolute HTTPS URL of route `pay.mpgs.return` for this payment |
| Pay page | merchant id, api version | `MpgsAccount` columns, never request params |
| Prepare | session id | gateway response to server side session create; stored encrypted on the attempt |
| Prepare | session amount and currency | `Payment.amount` (integer cents) converted to the gateway's decimal string with integer math (`intdiv`, `sprintf('%d.%02d')`, no floats; USD and GBP both have 2 decimals) and `Payment.currency` |
| Prepare | `order_id` | `{payment_id}-{sequence}`, `sequence` = highest existing for the payment + 1, inside the lock |
| Prepare | `auth_transaction_id`, `transaction_id` | generated server side (short unique strings), stored before use |
| Pay | amount, currency sent on PAY | `Payment`, never the request |
| Pay | session id and 3DS transaction id | the attempt row, never the request (request carries only `attempt_id`) |
| Pay | `order.notificationUrl` | route `webhook.mpgs` for the payment's account (absolute HTTPS URL) |
| Pay | reference sent for operators | `Payment::formattedReferenceCode()` in the gateway order description or reference field (length limit in Open questions) |
| Pay | which bucket applies | gateway response body and HTTP status, classified server side |
| Pay | which payment to update | route `{payment}` plus attempt ownership check, not anything in the gateway response |
| Webhook | which attempt to resolve | `order_id` from the body, matched to `mpgs_charge_attempts.order_id` and checked to belong to the route's account, then re-read from the gateway |
| Resolver | which transaction is the PAY | `mpgs_charge_attempts.transaction_id` matched inside the retrieved order's transaction list |
| Status poll | status shown | `Payment.status`, read only |
| Customer details for 3DS | name, email | `Payment.client_name`, `Payment.client_email` (billing address deliberately not collected, see Open questions) |

**Key invariants**:
- Only the gateway's own response to PayHub's server (PAY response, or a retrieve order lookup the webhook or resolver triggered) ever sets `Payment.status = completed`. The browser and the webhook body never do.
- `completed` is terminal. `failed` is not terminal.
- At most one PAY is ever in flight per payment: a cache or row lock scoped to `payment_id` is taken before the attempt moves `prepared` to `pending` and held through the gateway call.
- An attempt row, holding its order id and transaction ids, exists before any gateway PAY call, and a gateway order id is never reused for a second try.
- Approved requires success, a captured order, and exact amount and currency match; anything less is unknown.
- The resolver, webhook job and sweep only read the gateway. They never send PAY.
- Any gateway outcome PayHub does not recognize is logged, treated as unknown or hard error, and never allowed to fail an insert or update.
- `integration_password`, `webhook_secret` and `session_id` are encrypted columns; the first two are not mass assignable; none is logged or sent to the browser (the session id is returned once to the browser that must use it, and is not stored client side).
- Amounts are integer minor units everywhere in PayHub; the decimal string exists only in the gateway request and is never stored.

**Security model**:
- Account management is `role:admin` only. Pay, session, status routes are public but scoped by the unguessable payment UUID. The webhook route is protected by the per account secret header.
- Compliance scope is PCI DSS. Hosted fields keep card data inside gateway frames, so PayHub's target is the lightest self assessment (SAQ A, Mastercard's stated target for hosted fields). The assessor or acquiring bank makes the final call.
- Gateway raw decline text and codes go to `decline_reason` and `gateway_code` for operators only. Clients always see fixed PayHub strings.
- CSP widening is limited to the MPGS pay page. `X-Frame-Options: DENY` stays everywhere and is the declared frame embedding mitigation.
- Audit trail: every attempt row is the durable record of a money operation; attempts are never deleted except with their payment.

**Configuration required**:
- `MPGS_GATEWAY_HOST`: the default gateway host, e.g. `https://ap-gateway.mastercard.com` (no trailing slash; read through `config/services.php`), used by any account whose own `gateway_host` is empty
- `MPGS_API_VERSION`: default API version for new accounts (100, the version the gateway documentation recommends; Hosted Session supports any version above 18)
- `MPGS_SESSION_LIFETIME_MINUTES`: how long a `prepared` attempt may wait before the sweep expires it (start at 30, spike confirms the real session lifetime)
- Prerequisite outside the code: a test merchant from the acquiring bank with Hosted Session and 3DS enabled, and a note of which currencies are enabled

**Open questions** (unverified; the sandbox spike, AC-13, must confirm each):
1. Exact `session.js` URL pattern and the API version range that supports Hosted Session plus the 3DS JavaScript library, and the library's current version and script URL.
2. Exact PAY request and response field names: success result, gateway code, transaction type and result, order status, captured amount, receipt location. The classification table uses these.
3. Whether the session needs amount and currency written onto it before PAY, or whether sending them on PAY alone is enough (the design sends both; confirm there is no conflict).
4. The 3DS JS sequence (initiate, authenticate), the callbacks, how to render the challenge inline, and whether name and email alone are enough for the bank's authentication (no billing address). If not, add the minimum billing fields and amend AC-2 and the form.
5. Whether `order.notificationUrl` on a PAY request is honored with the same account wide notification secret, the body format (require JSON), and that 3DS flows really send two notifications.
6. Whether creating and discarding a session is an acceptable credential check for test connection, or whether a lighter inquiry call exists.
7. Whether test and live use the same host (design assumes yes, with a different merchant id).
8. The retrieve order response shape: how to find the PAY transaction in the transaction list by id.
9. The gateway error shape when a currency is not enabled for the merchant (must classify as hard error, not decline).
10. CSP: the exact hosts for `session.js`, the 3DS library and any challenge frames beyond `https:`; whether `fullScreenRedirect` is needed for any bank.
11. Field length limits for order reference, transaction reference and description (to carry the PayHub reference code).
12. The full set of gateway codes to map into approved, declined, hard error and unknown, and the real session lifetime.

**Critical test scenarios** (all gateway calls use `Http::fake`; the real gateway is covered only by the spike):
- Happy path: admin creates an account, agent creates a USD link, client prepares, passes 3DS, pays, the PAY response is approved with matching amount and currency, payment is `completed`, `paid_at` and `mpgs_order_id` set, one notification dispatched. Verifies **AC-1, AC-2, AC-4, AC-5, AC-6, AC-7, AC-8**.
- GBP payment against the same account works with no currency lock. Verifies **AC-2**.
- Decline then retry: first PAY declined, payment `failed`, client prepares again (new order id `…-2`), second PAY approved, payment `completed`. Verifies **AC-7, AC-8, AC-11**.
- Concurrent double submit: two pay calls at once, exactly one reaches the gateway, the other gets 409. Verifies **AC-7**.
- Precondition guard: prepare or pay on a `completed` payment, an inactive account, a non MPGS payment, an attempt of another payment, an expired or non `prepared` attempt: all 422 with no gateway call. Verifies **AC-5, AC-7**.
- Amount mismatch or success without a captured order is classified unknown, never approved. Verifies **AC-7**.
- A decline delivered as an HTTP 4xx is still classified declined, not hard error. Verifies **AC-7**.
- Currency not enabled: the gateway rejection is a hard error, payment untouched, client sees a fixed message. Verifies **AC-7, AC-12**.
- Unknown then resolved: PAY times out after the attempt was written, resolver retrieves the order and finds it captured, payment completes once and no second PAY is ever sent. Verifies **AC-10, AC-8**.
- Webhook: wrong secret or no stored secret gets 401 or 403 and changes nothing; correct secret queues a job that re-reads the order; the same delivery twice completes the payment once; a webhook body claiming success for an order the gateway reports as declined changes nothing. Verifies **AC-9, AC-8**.
- Terminal guard: a `completed` payment is never changed by a later resolved decline. Verifies **AC-8**.
- Abandoned and expired attempts: resolver exhausts retries and marks `abandoned`; a stale `prepared` attempt expires with no gateway call. Verifies **AC-10**.
- Rate limits: the 11th pay for one payment in an hour gets 429 with no gateway call. Verifies **AC-12**.
- CSP: the MPGS pay page carries the widened frame-src and gateway host; a Stripe or Clover pay page and every other route keep the strict policy. Verifies **AC-6**.
- Secrets: serialized account props never contain the password or secret; logs contain no session id. Verifies **AC-1, AC-12**.
- Auth/permission: a non admin hitting any `mpgs-accounts.*` route gets 403. Verifies **AC-1**.

## Build plan

No build approach is recorded in `AGENTS.md` or a `docs/scope/` header (this project runs its own planning workflow), so this plan defaults to end to end Tracer Bullet slices and states that assumption. The first slice proves the riskiest thread (session, fields, 3DS, PAY, CSP) before any parity work is spent on it.

0. **Sandbox spike (gate).** With a real test merchant, prove in a throwaway script and a throwaway page: session create and update, `session.js` field render, 3DS (frictionless and challenge), PAY, retrieve order, a webhook delivery with the secret header, and the CSP hosts. Write every Open question answer into `## Follow-up`, and amend this spec through `/architect` where an answer changes it. Satisfies **AC-13**.
1. Migrations: `mpgs_accounts` (including nullable `gateway_host`), `payments` additions and provider enum widening, `mpgs_charge_attempts`, `processed_mpgs_events`. Models (`MpgsAccount` with encrypted casts and guarded password and secret, `MpgsChargeAttempt`, `ProcessedMpgsEvent`), factories, `PaymentProvider::Mpgs`, `Payment` fillable and relation, `ProviderAccountTable`, `CurrencySupportResolver` (`mpgs` supports all). Satisfies **AC-1, AC-2, AC-12**.
2. `App\Services\Mpgs\MpgsClient` built per account through `app()->make()`: `createSession`, `updateSession`, `pay`, `retrieveOrder`, Basic auth as `merchant.{MID}`, host from config, no `->throw()` on pay and retrieve. Plus `MpgsChargeClassifier` (pure, unit tested) and `MpgsChargeResolver` (the one place guarded status writes happen). Satisfies **AC-7, AC-8, AC-10**.
3. Admin slice: `StoreMpgsAccountRequest`, `UpdateMpgsAccountRequest`, `Admin\MpgsAccountController` with activate, deactivate, both test connection routes, routes, and the three admin Vue pages (webhook URL shown for copying; secret fields write only). Satisfies **AC-1**.
4. Thin end to end pay thread: `showMpgs`, `MpgsPaymentController::session` and `::pay`, `PayMpgs.vue` and `MpgsPaymentForm.vue` (loads `session.js`, mounts fields, runs 3DS, calls pay), named limiters `mpgs-session` and `mpgs-pay` in `AppServiceProvider`, the per payment lock, the MPGS only CSP widening in `SecurityHeaders`, and the 3DS return route with its `SAMEORIGIN` header. Satisfies **AC-4, AC-5, AC-6, AC-7, AC-8, AC-12**.
5. Client states: `mpgs/status` endpoint, processing state with polling, inline decline retry, Success redirect, fixed messages. Satisfies **AC-11**.
6. Webhook: `MpgsWebhookController`, `HandleMpgsWebhookJob`, route outside CSRF (add `webhook/mpgs/*` to the exclusion list in `bootstrap/app.php`), `order.notificationUrl` on PAY. Satisfies **AC-9**.
7. Resolver: `ResolveMpgsChargeAttempt` job with `$tries` and backoff and a `failed()` that marks `abandoned`; scheduled sweep in `routes/console.php` (every 10 minutes, `withoutOverlapping`) that re-dispatches stale `pending` attempts and expires stale `prepared` ones. Satisfies **AC-10**.
8. Parity sweep (use the `provider-parity-reviewer` agent): `StorePaymentRequest`, `UpdatePaymentRequest`, `PaymentController`, `Admin\UserController` and user requests, `PaymentsExport`, `DashboardController`, `DashboardMetrics`, `DashboardFilters.vue`, `PaymentProviderBadge.vue`, `AppSidebar.vue`, payments Create, Edit, Index, Show pages, `PaymentLayout.vue`, users Create and Edit pages, Success, Failed, Unavailable pages, `PaymentFactory`. Satisfies **AC-3**.
9. Pest coverage for every Critical test scenario, plus unit tests for the classifier and the amount conversion. Satisfies **AC-1 through AC-12**.
10. Close out: update `AGENTS.md` (via `/sync`) for the scoped server to server exception and the provider list. Satisfies **AC-8** (the rule it relaxes is documented).

## Consequences

**Positive**:
- MPGS clients get the same on page card experience as Stripe, Square and Clover, with card data staying inside gateway frames.
- Almost all scaffolding is reused from Clover and Square, so the extra risk is limited to the gateway specifics and 3DS.
- A webhook plus a read only resolver gives two independent ways to recover from a lost response, which Clover could not have.
- One shared resolver class keeps all status writes in one guarded place.

**Negative / tradeoffs**:
- PayHub owns 3DS in this design. A bug in the 3DS wiring or a CSP gap shows up as lost sales, not as a visible error, and needs real bank test cards to catch.
- Widening `frame-src` to `https:` on the MPGS pay page lowers CSP strength for that page. The page still blocks inline script, other origins for scripts, and framing of PayHub itself.
- No per account currency lock means a wrongly configured bank shows up only at pay time, as a fixed "could not be processed" message and a logged hard error. Revisit with a currencies column if this happens.
- A per account host means a wrong host on an account fails only at pay time. The admin form validates it as an `https://` host with no path, and the stored test connection exercises it.
- Several field names and the whole 3DS sequence rest on documentation PayHub could not fetch directly. The spike may force edits to this spec.
- Paid status for MPGS comes from a server to server response, a second exception to the "webhooks only" rule that `AGENTS.md` states.

**Neutral**:
- Refunds and voids are out of scope (PayHub has no refund feature today). The WooCommerce plugin voids with `DELETE`; the gateway documentation shows `PUT` with a VOID operation, so the integration handoff doc should not be copied for that.
- The provider list in `AGENTS.md` (three providers) is already stale; five exist before this spec.
- The first MPGS migration runs on `master` and is additive, so it can be layered normally.

## Follow-up

**Amendment (2026-10-17), from the bank's test gateway documentation** (Hosted Session "Making a Payment" and "3DS with Hosted Session" pages, read in a browser; not yet proven against a live sandbox). Answers to the Open questions:
- Q1: `session.js` is `{host}/form/version/{version}/merchant/{MID}/session.js`, and its version must equal the version used to create the session. The 3DS library is `/static/threeDS/1.3.0/three-ds.min.js` on the same host (inferred from a relative path in their sample). Hosted Session supports any API version above 18; 100 is recommended and is now the default.
- Q2: the PAY response matches the classifier's assumptions: `result: SUCCESS`, `response.gatewayCode: APPROVED`, `order.status: CAPTURED`, `order.totalCapturedAmount`, `order.currency`, `transaction.receipt`. Still unknown: the `error` block shape and the full gateway code list.
- Q3: fields sent on PAY override the session, so sending amount and currency on both is fine.
- Q4 (partial): the sequence is `ThreeDS.configure({merchantId, sessionId, containerId, callback, configuration: {wsVersion}})` once on load, then `initiateAuthentication(orderId, transactionId, cb)` as early as possible (the bank's ACS method call needs about 10 seconds), then `authenticatePayer(orderId, transactionId, cb, optionalParams)` on pay, then PAY with `authentication.transactionId`. The library renders any challenge into `containerId`. Still unknown: whether name and email are enough (the REST table calls billing, shipping and customer required for browser authentication, the JS page lists them as optional extras), and what the return page must contain for the library callback to fire.
- Q7: test and live use different hosts (the test host is `test-bankalfalah.gateway.mastercard.com`), so the host is now per account (`gateway_host`, config fallback).
- Still open: Q5, Q6, Q8 to Q12, and the Retrieve Order `transaction[]` shape.

Amended in this pass: `authenticationLimit` of 25 on create session, `authentication.channel` and `authentication.redirectResponseUrl` on update session, readonly input fields, `frameEmbeddingMitigation: ['x-frame-options']` (PayHub already sends `DENY`), the 3DS return route with `SAMEORIGIN`, the per account host, and API version 100. Built in the same pass (code, not yet run against a sandbox): the 3DS return route `pay.mpgs.return` with `public/mpgs-3ds-return.js`, the `gateway_host` column and admin field, `MpgsClient` body changes, API version 100, readonly inputs, and `gateway.ts` written from the documented calls with the open spots marked SPIKE. Decision: the gateway libraries (`PaymentSession.configure`, `ThreeDS.configure`) run once per page load, so a decline or failed 3DS reloads the pay page for a fresh session and order id (the message survives the reload through `sessionStorage`), instead of re-mounting the fields.

**Build status (2026-10-17).** Steps 1 to 3, 6, 7 and 8 are built. Steps 4 and 5 are built as a shell, pending the spike:
- Server: `MpgsPaymentController` (`session`, `pay`, `status`), `ClientPaymentController::showMpgs`, routes `pay.mpgs.session|pay|status`, limiters `mpgs-session` (20 per payment per hour) and `mpgs-pay` (10), the MPGS only CSP widening in `SecurityHeaders`. These routes stay behind CSRF (the page sends `X-XSRF-TOKEN`); only the webhook is excluded.
- Client: `ClientPayment/PayMpgs.vue` (consent, processing poll, timed out state), `MpgsPaymentForm.vue` (prepares a session on load because hosted fields need one first, 3DS, pay, decline retry on a fresh session), and `resources/js/lib/mpgs/gateway.ts`, the single file for `session.js` and the 3DS library (wired since the 2026-10-20 staging runs, see below).
- (Superseded 2026-10-20) `gateway.ts` was a stub that threw `MpgsNotWiredError`. It is now fully wired and proven on staging; the PHP field names for the approved path are confirmed by real PAY calls.
- A session is prepared on every pay page load (hosted fields need it first), so a reload creates and retires an attempt. The 20 per hour session limit and the sweep bound the cost. Revisit if the spike shows a lazier flow is possible.
- (Superseded 2026-10-20) Step 9 and step 10 are done, see the next build status. `phpunit.xml` still hardcodes `APP_BASE_PATH` for the original machine.

**Build status (2026-10-20).** Steps 1 to 10 are built. Step 9 is done: `tests/Feature/MpgsPaymentControllerTest.php` (35 tests) covers prepare, pay, decline then retry, HTTP 4xx decline, hard error, amount mismatch, timeout, unusable attempts, lock conflict, rate limits, status poll, the 3DS return route, the MPGS only CSP and secret leakage. Step 10 is done: `AGENTS.md` now lists all six providers and names the MPGS server to server exception. `gateway.ts` now registers `onValidityChange` with field names (`card.number` etc.) and still accepts selectors; not yet seen on staging. `phpunit.xml` now pins `PAYMENT_EMAIL_NOTIFICATIONS_ENABLED=true`, which fixes the red `NotificationTest` (the local `.env` had it off). Still open: the staging answers listed above (Open questions 5, 6, 8 to 12), live go-live details from the bank, and the two decisions (refunds out of scope, PKR admin only at launch).


**Staging results and fixes (2026-10-20).** The full flow works on a public staging URL: hosted fields, 3DS challenge, return page, PAY, USD at least. Answers and changes found while proving it:
- Q4 (partly answered): the 3DS challenge renders inline. `renderChallenge()` inserts the gateway's `htmlRedirectCode` into `#mpgs-3ds-challenge` and submits its form, then PAY waits for the `mpgs-3ds-return` postMessage from the return page. When there is no `htmlRedirectCode` (frictionless) PAY goes at once. The challenge container must sit OUTSIDE the payment `<form>` (a nested form is dropped when HTML is inserted). Whether name and email alone are enough for every bank is still unconfirmed.
- Q6 (answered): create-and-discard a session works as the credential check, but the body must be a JSON object (`new \stdClass`). An empty PHP array encodes as `[]` and the gateway returns 400.
- Hosted fields were untypeable because the gateway copies the input's measured width into its frame and a flex wrapper squeezed the input to 0px. The wrapper is now a plain block, the form always renders (no `v-show`), the Pay button waits until the fields are ready, and inputs carry placeholders (the gateway copies them).
- A hard error on PAY now shows "Your payment could not be processed...", not "could not reach".
- `gateway_host` accepts a trailing slash (stripped before validation).
- Local development cannot finish 3DS: the bank's public challenge page is blocked from posting back to a local address. Test 3DS on a public URL.
- Test card (public gateway): 5123450000000008, 01/39, CVV 100; the bank's challenge emulator offers "(Y) Successful" and other results.
- Tests: `tests/Feature/MpgsPaymentControllerTest.php` (35 tests) added; full suite green. `NotificationTest` was red only because the local `.env` turned payment emails off; `phpunit.xml` now pins them on.

**Still unanswered** (needs staging runs or the bank): Q5 webhook delivery and the two-notification behaviour with the secret header, Q8 retrieve-order transaction shape, Q9 error shape for a currency the merchant has not enabled, Q10 `fullScreenRedirect` for any bank, Q11 field length limits, Q12 full decline code list and real session lifetime, and whether PKR is enabled on the test and live merchants. Go-live also needs the live gateway host, merchant ID and the notification URL and secret setup.

**Pending decisions:** refunds and voids stay out of scope for the first release (proposed); PKR stays admin-only at launch (proposed).

- [ ] Finish the sandbox spike (Build plan step 0): the staging flow works, but the open questions listed above are still unanswered. Record each remaining answer here.
- [ ] Decide after the spike whether the 3DS challenge stays inline or needs `fullScreenRedirect` for some banks (needs a return route and page state restore if so).
- [ ] If the spike shows name and email are not enough for 3DS, amend AC-2, the form and the value sourcing table to collect the minimum billing fields.
- [ ] Revisit a `currencies` column on `mpgs_accounts` if wrong currency rejections happen in practice.
- [x] Correct `docs/MPGS_STANDALONE_INTEGRATION_HANDOFF.md`: void is `PUT` with `apiOperation: VOID`, not `DELETE`, and the line references point to the plugin repo. Done 2026-10-17.
- [x] Update `AGENTS.md` through `/sync` once this ships: add MPGS to the provider list and name the scoped server to server exception (Clover and MPGS) in the critical rules.
- [ ] Rate limits (10 pays and 20 sessions per payment per hour), the 30 minute prepared lifetime and the 10 minute sweep are starting defaults, not reviewed figures.
- [ ] An `abandoned` attempt is only logged today; consider an admin view of stuck MPGS payments if it happens often.
- [ ] Refunds and voids stay out of scope; revisit only if PayHub adds a refund feature for any provider.
