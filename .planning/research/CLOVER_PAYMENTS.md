# Clover Payments — Feasibility Research

**Date:** 2026-09-15
**Context:** Evaluated as a potential 5th payment provider for PayHub, alongside Stripe, Revolut, Square, and Viva. Requirement: PayHub payments currently must support USD and GBP.
**Sources:** Official Clover developer docs (`docs.clover.com/dev/docs/*`), one Clover Community thread, and a review of PayHub's existing provider code (`StripeClient`, `RevolutClient`, `VivaClient`, `SquareWebhookController`, `Payment` model). Context7 MCP was not connected in this session — official docs were fetched directly instead.

**Recommendation: Adoptable only as a USD-only, US/Canada-scoped provider — not a GBP alternative.** Clover has no documented GBP support, so it cannot cover PayHub's GBP requirement. If ever added, it should sit alongside the existing four providers as a narrow, USD-only option, following the Viva-style redirect pattern rather than Stripe's client-confirmed in-page flow.

---

## 1. Integration Overview

| Option | What it is | Verdict for PayHub |
|---|---|---|
| **Hosted Checkout** | Merchant calls `POST .../invoicingcheckoutservice/v1/checkouts` to create a session, gets back a `href` (checkout URL), redirects the customer to Clover's hosted page, Clover redirects back with a status and fires a webhook. Session expires **15 minutes** after creation. | Clover's recommended path for online/card-not-present payments without handling card data. Matches the redirect pattern PayHub already uses for Viva Smart Checkout. |
| **Direct Ecommerce API (tokenize + charge)** | Collect card data via Clover's SDK/iframe on your own page, tokenize it (`POST /v1/tokens`) with the public "pakms" key, then charge the token (`POST /v1/charges`) with the private token/OAuth access token. | Keeps the payment page on our own domain, but pulls Clover's card-collection iframe into the PCI conversation — a materially different posture from Hosted Checkout. Not recommended as the first integration. |

**Bottom line:** Hosted Checkout is the right fit — it is architecturally closest to what PayHub already built for Viva (dashboard-configured webhook, redirect-based session, short-lived checkout URL), not like Stripe's client-confirmed PaymentIntent flow.

Docs: [Clover Ecommerce](https://docs.clover.com/dev/docs/clover-ecommerce-homepage), [Hosted Checkout integration](https://docs.clover.com/dev/docs/hosted-checkout-api), [Create a Hosted Checkout session request](https://docs.clover.com/dev/docs/creating-a-hosted-checkout-session), [Ecommerce API—Accept payments flow](https://docs.clover.com/dev/docs/ecommerce-api-payments-flow).

---

## 2. Integration Requirements (single merchant, Hosted Checkout)

- **Merchant ID** — assigned when the Clover merchant account is created, visible in the Merchant Dashboard. Used as a path/header parameter (`X-Clover-Merchant-Id`) on API calls.
- **Ecommerce API private token** — generated per merchant via Merchant Dashboard → Settings → Ecommerce → Ecommerce API Tokens → "Create Token," selecting the "Hosted Checkout" integration type. Used as `Authorization: Bearer {private_token}` on Hosted Checkout / Ecommerce API calls. **No OAuth required for a single, fixed merchant** — this is simpler than the multi-merchant OAuth flow described below.
- **Webhook signing secret** — generated in Merchant Dashboard → Settings → Ecommerce → Hosted Checkout, alongside the webhook URL field. Used to verify the `Clover-Signature` header (HMAC-SHA256 over `{timestamp}.{raw_body}`).
- **OAuth (App ID / App Secret / access & refresh tokens)** — only needed for a self-service, multi-merchant model where each merchant connects their own Clover account (Stripe Connect-style). Not needed for PayHub's current admin-provisions-the-account pattern. If ever built: PayHub registers one App ID/App Secret on the Clover App Market; each merchant authorizes via OAuth 2.0 authorization-code flow (v2/OAuth — high-trust or PKCE/low-trust depending on Clover's classification of the app); PayHub stores that merchant's `access_token`/`refresh_token` pair; refresh via `/oauth/v2/refresh`; recovery via `/oauth/v2/recovery` if a refresh token is lost (high-trust apps only).
- **`.env` vs database**: none of the above is a global platform secret — every credential is merchant-specific. Following the existing `RevolutAccount`/`SquareAccount`/`VivaAccount` pattern, a new `clover_accounts` table should hold `merchant_id` (plain), `private_token` and `webhook_secret` (Laravel `encrypted` cast, not mass-assignable), plus `refresh_token` (encrypted) only if OAuth is later adopted. `.env` only needs `CLOVER_ENVIRONMENT=sandbox|production`.

Docs: [Generate Ecommerce API tokens (public and private keys)](https://docs.clover.com/dev/docs/create-ecommerce-api-tokens), [Clover OAuth flow overview](https://docs.clover.com/dev/docs/oauth-flows-in-clover), [High-trust apps—Auth code flow](https://docs.clover.com/dev/docs/high-trust-app-auth-flow), [Low-trust apps—Auth code flow with PKCE](https://docs.clover.com/dev/docs/oauth-flow-for-low-trust-apps-pkce), [Configure Hosted Checkout webhooks](https://docs.clover.com/dev/docs/ecomm-hosted-checkout-webhook).

---

## 3. Currency — the critical finding

**Clover does not support GBP.** This is the same category of dealbreaker that ruled out Viva for USD.

- Clover's Ecommerce platform is explicitly scoped to **"North America — United States and Canada"** ([Ecommerce FAQs](https://docs.clover.com/dev/docs/ecommerce-faqs)). Settlement currency is USD (or CAD for a Canadian-based merchant).
- The legacy `/v1/charges` API takes a required `currency` field (ISO 4217, lowercase, e.g. `usd`), but non-home-currency **pricing** is only possible through an add-on called **Multi-Currency Pricing (MCP)** — and MCP is **Visa/Mastercard only** (other networks are forced to USD), and still **settles to the merchant's own currency** at the authorization-time exchange rate. It does not let a merchant receive funds in a customer-chosen currency. ([Create a charge](https://docs.clover.com/dev/docs/create-a-charge))
- **GBP is not mentioned anywhere** in Clover's currency, FAQ, or charge documentation. Corroborated by [Clover Community: "Can an app support more than one currency?"](https://community.clover.com/questions/1206/can-an-app-support-more-than-one-currency.html).
- The Hosted Checkout session-creation payload itself showed **no currency field at all** in the docs retrieved — likely because it inherits the merchant account's home currency rather than accepting one per session. **Not confirmed** either way without sandbox testing.

**Comparison:**

| Provider | USD + GBP on one account? | Model |
|---|---|---|
| Stripe | Yes | Single account, any supported currency per PaymentIntent |
| Revolut | Yes | Per-order currency via Merchant API |
| Square | Currency-locked per account (per CLAUDE.md) | One `SquareAccount` = one currency; separate accounts per currency |
| Viva (UK entity) | No — GBP only, USD not supported at all | Settlement currency fixed by incorporation country |
| **Clover** | **No — USD (or CAD) only, GBP not supported at all** | Settlement currency fixed to US/Canada merchant's home currency; MCP add-on doesn't change this |

**Recommendation**: if Clover is ever added, apply a hard currency lock the same way Square already has one — reject GBP payments against a Clover account at the `StorePaymentRequest`/`UpdatePaymentRequest` layer with a message like `"This Clover account only accepts USD payments."`

---

## 4. Sandbox vs Production

| | Sandbox | Production |
|---|---|---|
| Base URLs | `apisandbox.dev.clover.com` (Hosted Checkout), `scl-sandbox.dev.clover.com` (Ecommerce/charges API) | Corresponding production hosts (not fully enumerated in the pages retrieved — verify per-endpoint before go-live) |
| Credentials | Sandbox merchant + sandbox Ecommerce API tokens, generated the same way as production | Live merchant account, live tokens |
| Webhooks | Same `Clover-Signature` HMAC model, configured against your sandbox merchant's Hosted Checkout settings | Same mechanics, real events |

Docs: [Use test API tokens in sandbox](https://docs.clover.com/dev/docs/using-api-tokens), [Create a Hosted Checkout session request](https://docs.clover.com/dev/docs/creating-a-hosted-checkout-session), [Get a charge](https://docs.clover.com/dev/docs/get-a-charge).

---

## 5. Payment Lifecycle & Webhooks

1. PayHub calls `POST .../invoicingcheckoutservice/v1/checkouts` → gets `href`, `checkoutSessionId`, `expirationTime` (created + 15 min).
2. Browser redirected to `href`; customer pays on Clover's hosted page.
3. Clover redirects back to the merchant site with a status indicator (exact query-param shape not confirmed from the pages retrieved — verify against "Customize a Hosted Checkout page" before building).
4. Clover fires a webhook: a single event type, `PAYMENT`, distinguished only by `status: APPROVED` or `DECLINED` inside the payload (no separate event names per outcome) — includes Payment UUID, Merchant UUID, Checkout Session UUID.
5. Verified via `Clover-Signature` header: HMAC-SHA256 over `{timestamp}.{raw_body}` using the merchant's webhook signing secret, compared against the `v1=` value.
6. `GET /v1/charges/{chargeId}` can be polled as a reconciliation backstop; response includes a `status` field (`succeeded` shown in Clover's example — full enum **not confirmed** from the docs retrieved).

**Source of truth**: per PayHub's existing rule for every other provider ("never trust client-side confirmation... all payment status comes from webhooks only"), the webhook should be authoritative, with the charge-GET endpoint as a reconciliation/polling backstop — the same role `retrieveTransaction()` plays for Viva. The post-payment redirect should only drive which page the browser lands on, never write to the `Payment` row.

**Idempotency**: Clover's docs don't describe a stable event ID field or a retry/redelivery policy. PayHub should apply its existing pattern — a `ProcessedCloverEvent` table, keyed on the Payment UUID + Checkout Session UUID pair (best documented candidate key), mirroring `ProcessedSquareEvent`/`ProcessedRevolutEvent`.

**Cancelled/abandoned sessions**: no distinct webhook event is documented for an expired, never-attempted session — only `PAYMENT` events fire, and only on an actual approve/decline. PayHub would need its own scheduled expiry for stale `Payment` rows rather than relying on a Clover notification.

Docs: [Hosted Checkout integration](https://docs.clover.com/dev/docs/hosted-checkout-api), [Configure Hosted Checkout webhooks](https://docs.clover.com/dev/docs/ecomm-hosted-checkout-webhook), [Get a charge](https://docs.clover.com/dev/docs/get-a-charge), [Refund payments and void transactions](https://docs.clover.com/dev/docs/ecommerce-refunding-payments).

---

## 6. Refunds

- `POST /v1/refunds` (charges) — does **not** support partial refunds for charges with taxes, tips, or more than one line item.
- `POST /v1/orders/{orderId}/returns` (orders) — supports partial refunds.
- **Voids**: apply only within 25 minutes of the original transaction; after that, Clover processes a void request as a refund instead.
- Both require `Authorization: Bearer {access_token}`.

Docs: [Refund payments and void transactions](https://docs.clover.com/dev/docs/ecommerce-refunding-payments).

---

## 7. PCI / Security

Hosted Checkout keeps raw card data entirely on Clover's page — the same posture as PayHub's existing Stripe Elements / Revolut Card Field / Square Web Payments SDK integrations, none of which let a PAN, CVV, or expiry touch PayHub's servers. What PayHub must still implement, unchanged from every existing provider: encrypt `private_token`/`webhook_secret` at rest (`encrypted` cast, non-mass-assignable), verify webhook signatures before trusting any payload, exclude the Clover webhook route from CSRF, throttle the public webhook/pay-page endpoints, and never log or expose the checkout `href` beyond the single redirect response.

---

## 8. Comparison for Our Use Case

| Dimension | Stripe | Revolut | Square | Viva (UK entity) | Clover |
|---|---|---|---|---|---|
| In-page embedded pay | Yes (Elements) | Yes (Card Field) | Yes (Web Payments SDK) | Discouraged by vendor | Not the recommended path (Hosted Checkout is redirect-based) |
| USD + GBP, one account | Yes | Yes | No (per-account lock, but USD supported) | No — USD not available at all | **No — GBP not available at all** |
| Session/link expiration | Checkout Session ~24h, configurable | N/A (order-based) | N/A | Order-based, no fixed short TTL surfaced | **15 minutes**, not documented as configurable |
| OAuth needed for single merchant | No | No | No | No | No — private token + Merchant ID suffices |
| Official PHP SDK | Yes, mature | No (raw API) | Yes, official | No — community package only | Not evaluated here; REST-only integration assumed (`Http` client, matching Revolut/Viva pattern) |
| Webhook model | Mature, fast retries, signed | Custom HMAC, no event id | HMAC via SDK helper | HMAC + verification handshake, hourly retries | HMAC (`Clover-Signature`), single `PAYMENT` event type, dashboard-configured only, retry policy not documented |

---

## 9. Recommended Integration Approach (if we proceed)

- Product: **Hosted Checkout**, redirect flow only — do not build the tokenize+charge flow as a first pass.
- Scope: **USD-only** provider, gated the same way Square's currency lock works today (`SquareAccount.currency` → reject mismatched payments in `StorePaymentRequest`/`UpdatePaymentRequest`).
- Auth: per-account **Merchant ID + private token**, no OAuth — matches the simplicity of Stripe/Revolut/Square's static-secret-per-account model, unlike Viva's OAuth2 client-credentials dance.
- Client: `App\Services\Clover\CloverClient`, mirroring `VivaClient`'s shape (per-account instantiation via `app()->make()`, `createCheckoutSession()`, `getCharge()`, `refund()`), not a global client.
- Webhooks: new `webhook/clover/{account}` route excluded from CSRF (matching Stripe/Revolut/Square/Viva pattern), HMAC-verify inbound POSTs against the merchant's signing secret, idempotency via a `ProcessedCloverEvent` table.
- Data model: `clover_accounts` table (`merchant_id`, `private_token` encrypted, `webhook_secret` encrypted, `prefix`, `account_name`, `is_active`, `currency` locked to `usd`); `clover_account_id`, `clover_checkout_session_id`, `clover_payment_id` columns on `payments`; `PaymentProvider::Clover = 'clover'` enum case; every exhaustive `match()` over `PaymentProvider` (Payment model, CSV export, dashboard filters, admin CRUD) needs a `Clover` arm added.
- Architecture: don't introduce a `PaymentProviderInterface` abstraction as a prerequisite. PayHub's current codebase doesn't have one (Stripe/Revolut/Square/Viva are each a standalone client class with exhaustive `match()` branching at call sites). A shared interface would mainly pay off once a second redirect-style provider (Viva + Clover) makes the duplication annoying enough to justify it — not before.

---

## 10. Risks, Limitations, Final Recommendation

**Risks/limitations:**
1. **GBP is not supported in any documented mode** — confirmed against Clover's Ecommerce FAQs, charge currency docs, and a community thread. This alone fails one of PayHub's two hard currency requirements, the same way Viva failed on USD.
2. **15-minute Hosted Checkout session TTL** is short — PayHub must regenerate the session on every page load/retry rather than caching one URL, unlike Stripe's longer-lived Checkout Sessions.
3. Several implementation details are **not confirmed** from the docs retrieved and need direct sandbox verification before building: the post-payment redirect's exact query-param shape, the full `GET /v1/charges` status enum, whether Hosted Checkout sessions accept a currency override at all, and webhook retry/redelivery behavior.
4. No official Laravel/PHP SDK found in the pages reviewed — a raw `Http`-client-based service (matching the Revolut/Viva pattern) is the fallback, not a vendor package.
5. Adding Clover would mean PayHub's `PaymentProvider` enum gains a provider that **cannot serve one of our two supported currencies**, requiring the same kind of explicit currency-eligibility gating already used for Square.

**Final recommendation**: adoptable only as a **USD-only, fifth provider** for US/Canada-based merchants — not a general-purpose addition and not a GBP alternative. If there's a concrete need for a US/Canada brand on Clover specifically (e.g., a Clover POS-using merchant who also wants online payment links), build it as described in Section 9. Otherwise, there's no requirement Clover uniquely satisfies that Stripe (already USD+GBP capable) doesn't already cover.
