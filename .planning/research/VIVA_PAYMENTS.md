# Viva.com Merchant Payments — Feasibility Research

**Date:** 2026-07-09
**Context:** Evaluated as a potential 4th payment provider for PayHub, alongside Stripe, Revolut, and Square. Merchant entity assumed UK-registered. Requirement: must support both USD and GBP.

**Recommendation: Do not adopt.** Fails the USD+GBP requirement outright, and the supported integration path is a UX regression from our current in-page checkout experience.

---

## 1. Integration Overview

| Option | What it is | Verdict for PayHub |
|---|---|---|
| **Smart Checkout** | Fully hosted, Viva-managed redirect payment page. Merchant creates an "order" via API, redirects customer to `checkout.viva.com`, customer pays, Viva redirects back. | Viva's flagship, recommended product. PCI-DSS/SCA/3DS compliance offloaded to Viva. |
| **Embedded/iframe card form** | Viva explicitly supports embedding Smart Checkout in an iframe with browser-side card tokenization, but its own docs **actively discourage this**: "iframe will disable certain features, result in poor user experience, and significantly reduce conversion rates" — no Apple Pay, no saved cards, no redirect-based methods (Klarna, BLIK, etc.), degraded 3DS flow. | Unlike Stripe Elements/Square Web Payments SDK (which *are* the recommended embedded path for those providers), Viva's own vendor guidance is "don't do this." A true in-page card field like Stripe Elements is not a first-class supported pattern. |
| **Native per-method APIs** | Separate "Native" API integrations exist for specific methods (Apple Pay, Google Pay, MB WAY/MB REF) with direct token submission, bypassing redirect for those specific wallets. No general-purpose "Native Checkout" for card-present in-page entry equivalent to Stripe Elements. | Method-specific, not a general embedded card solution. |
| **Card tokenization API** | Server-side (merchant creates token from stored card) and a customer-side JS tokenization flow, but tokens are still redeemed on the **redirect** Smart Checkout page, not rendered as an arbitrary custom UI field. | Confirms redirect is the intended endpoint of the flow even when tokenizing. |

**Bottom line:** Viva's realistic, supported integration for a Laravel + Vue app is a **hosted redirect (Smart Checkout)** — customer leaves the pay page, authenticates on `checkout.viva.com`, and is redirected back. This is a materially different UX from our current Stripe Elements / Revolut Card Field / Square Web Payments SDK in-page flows, all of which keep the customer on our pay page.

---

## 2. Integration Requirements

- **Merchant account**: Viva Business Account, KYC/business verification required, tied to country of incorporation.
- **Credentials**: Two credential types —
  - **Smart Checkout (Client) credentials**: `Client ID` + `Client Secret` (Basic Auth for order creation, OAuth2 Bearer for API calls)
  - **API Key / Merchant ID**: legacy Basic Auth pair used on some endpoints (`Authorization: Basic base64(MerchantID:APIKey)`)
- **Auth flow**: OAuth2 **client_credentials** grant — `POST https://demo-accounts.vivapayments.com/connect/token` (prod: `accounts.vivapayments.com`), Basic-Auth-encoded `client_id:client_secret`, returns Bearer token, **1-hour expiry** (must be cached/refreshed — a new "token refresh" concern vs. our current static-secret-per-account model for Stripe/Revolut/Square).
- **SDK/libraries**: **No official PHP/Node/Python SDK.** Official resources are REST API docs + a Postman collection (`postman.com/viva-com/developer-portal`) + raw code samples (cURL/PHP/JS snippets, not a packaged library). The only PHP option is a **community-maintained** package, [`sebdesign/laravel-viva-payments`](https://github.com/sebdesign/laravel-viva-payments) (not affiliated with Viva, PHP 8.1+/Laravel 10+, wraps Smart Checkout + ISV API + webhooks + OAuth2 token management). This is a meaningfully weaker SDK story than `stripe/stripe-php` or `square/square` — we'd either build our own thin client on Guzzle or take on a third-party dependency for a payment-critical integration.
- **Webhooks**: Subscribe to event types via API call (up to 10 URLs per event type at ISV level). Key events: `TransactionPaymentCreated` (EventTypeId 1796), `TransactionFailed`, `AccountTransactionCreated`, etc. **Verification handshake required**: before delivering events, Viva sends a GET to your endpoint expecting it to echo back a verification key (JSON response) over HTTPS/TLS 1.2 — an extra registration step our Stripe/Revolut/Square webhook setups don't have. Ongoing authenticity is via **HMAC signature in request headers** (exact header name not fully surfaced in public docs — would need registered sandbox access to confirm). **Retry policy**: expects HTTP 2xx; on failure, retries up to 23 times, hourly (~24h total), vs. Stripe's shorter/faster retry schedule.
- **Transaction retrieval**: `GET /checkout/v2/transactions/{transactionId}` (OAuth2 Bearer), returns status/amount/currency/timestamp — straightforward, comparable to Stripe's PaymentIntent retrieval.

---

## 3. Multi-Currency (USD + GBP) — the critical finding

**A UK Viva merchant account cannot natively accept USD.** This is confirmed by multiple official Viva sources cross-checked:

- **Settlement currency is fixed by incorporation country.** Viva's own Wallet API docs state a wallet's "currency is determined by the merchant's incorporation country," and bank-transfer/IBAN docs require the linked IBAN to be "in the same currency as your Viva merchant account." For a UK entity, that's **GBP**.
- **"Multicurrency" add-on** (lets a merchant *price and get paid* in a non-default currency) supports: **EUR, RON, PLN, CZK, HUF, SEK, DKK, GBP**. **USD is not on this list**, per both `developer.viva.com/smart-checkout/smart-checkout-multicurrency/` and the UK Help Center multicurrency article.
- **E-DCC (Dynamic Currency Conversion for Smart Checkout)** merchant currencies: **EUR, GBP, PLN, DKK** (CZK/RON/SEK/HUF "coming soon"). USD is absent here too. DCC's *cardholder*-side currency list does include USD, but that only means a USD-card customer can be shown a USD-equivalent price at checkout — **the merchant still settles in GBP**, converted at Viva's rate. That's the opposite of what we need (we need the `Payment` record's currency to be USD and have the provider process it as USD, matching how Stripe/Revolut/Square work today).

**Comparison:**

| Provider | USD + GBP on one account? | Model |
|---|---|---|
| Stripe | Yes | Single account, any supported currency per PaymentIntent |
| Revolut | Yes | Per-order currency via Merchant API |
| Square | Currency-locked per account (per CLAUDE.md) | One `SquareAccount` = one currency; we work around by having separate accounts per currency |
| **Viva (UK entity)** | **No — GBP only natively; USD not offered as merchant/settlement currency at all** | Would need a **second, USD-eligible legal entity/account** in a country Viva supports for USD, if one exists — unconfirmed, likely not since USD doesn't appear anywhere in Viva's currency lists (Viva is EU/UK-centric, not a US-market processor) |

This is worse than Square's per-account currency lock (Square at least *supports* USD, just requires a dedicated account); Viva appears not to support USD merchant settlement **at all**, in any configuration surfaced by official docs.

---

## 4. Sandbox vs Production

| | Sandbox (Demo) | Production |
|---|---|---|
| Base URL | `demo.vivapayments.com` / `demo-api.vivapayments.com` / `demo-accounts.vivapayments.com` | `vivapayments.com` / `api.vivapayments.com` / `accounts.vivapayments.com` |
| Account setup | Free self-serve demo merchant signup; 2FA code is always `111111` in demo | Requires full KYC/business verification (UK company docs, director ID, bank account) |
| Test cards | e.g. `5239290700000101`, exp any future date, CVV `111`; minimum test payment 30p/30¢ | Real cards |
| Webhooks | Same verification handshake + HMAC model as prod, tested against your public HTTPS endpoint (needs a tunnel like ngrok for local dev, no Viva-specific CLI equivalent to `stripe listen`) | Same mechanics, real events |
| Differences | Functionally a full replica; no real money moves | Live funds, live fraud/3DS checks apply |

**Go-live checklist (inferred from docs)**: business KYC approval, bank account/IBAN linking in GBP, webhook endpoint verified and subscribed, swap demo credentials for production Client ID/Secret, retest full order→webhook→transaction-retrieval loop against prod.

---

## 5. Practical Testing Guide (if proceeding)

1. Register a demo account at `developer.viva.com` → sandbox merchant.
2. Grab **Client ID/Secret** from account settings (`getting-started/find-your-account-credentials`).
3. Get a token: `POST https://demo-accounts.vivapayments.com/connect/token` with Basic Auth, `grant_type=client_credentials`.
4. Create an order: `POST https://demo.vivapayments.com/api/orders` (Basic Auth Merchant ID/API key) with `amount` (cents), `currencyCode` (ISO 4217 numeric, e.g. `978`=EUR, `826`=GBP), `customerTrns`, `sourceCode`.
5. Redirect customer to `https://demo.vivapayments.com/web/checkout?ref={orderCode}`.
6. Pay with a test card (e.g. `5239290700000101`, CVV `111`).
7. Retrieve result: `GET https://demo-api.vivapayments.com/checkout/v2/transactions/{transactionId}` with Bearer token.
8. Register a webhook URL (tunnel local dev via ngrok/similar), respond to Viva's GET verification challenge with the verification key JSON, then subscribe to `TransactionPaymentCreated`.
9. Confirm webhook POST arrives, verify signature, mark `Payment` as paid — mirroring our existing webhook-only-writes rule.

---

## 6. MCP & AI Tooling

- **No official Viva MCP server.** No results for a Viva-specific Model Context Protocol server (the only "Viva" MCP hits are unrelated — Microsoft Viva Engage).
- **No community MCP server found** for Viva payments.
- **No Claude Code-specific integration or skill.**
- Only dev-tooling of note: the official **Postman workspace** (`postman.com/viva-com/developer-portal`) with request collections (Cloud REST API, ISV API, Wallet), and **Context7** does index `developer.viva.com` docs (useful for future lookups), but there's no SDK-level or agentic tooling comparable to Stripe's ecosystem (Stripe has an official MCP server, agent toolkit, etc.).

---

## 7. Comparison for Our Use Case

| Dimension | Stripe | Revolut | Square | Viva (UK entity) |
|---|---|---|---|---|
| In-page embedded pay | Yes (Elements) | Yes (Card Field) | Yes (Web Payments SDK) | Discouraged by vendor; redirect is the supported path |
| USD + GBP, one account | Yes | Yes | No (locked per account, but USD *is* supported via separate account) | **No — USD not available at all for UK-settled accounts** |
| Official PHP SDK | Yes, mature | No (raw API, per our existing service) | Yes, official | **No — community package only** |
| Webhook model | Mature, fast retries, signed | Custom HMAC, no event id (we built idempotency) | HMAC via SDK helper | HMAC + upfront verification handshake, slow retry cadence (hourly) |
| MCP/AI tooling | Official MCP + agent toolkit | None | None | None |
| UK/US eligibility | Global | UK/EU | UK/US/etc. | EU/UK only, no US currency support surfaced |

---

## 8. Recommended Integration Approach (if we proceeded anyway)

- Product: **Smart Checkout**, redirect flow only — do not attempt iframe embedding (against vendor guidance, worse UX, feature loss).
- Auth: OAuth2 client-credentials, token cached server-side with ~1hr TTL refresh (new pattern vs. our current static-secret-per-account model — would need a `viva_access_token`/`expires_at` cache column or Laravel cache key per `VivaAccount`).
- Client: build a thin Guzzle-based service (`App\Services\Viva\VivaClient`) rather than depend on the unofficial `sebdesign/laravel-viva-payments` package for a payments-critical path, consistent with our "no global client, per-account instantiation" rule.
- Webhooks: new `webhook/viva/{account}` route excluded from CSRF (matching Stripe/Revolut/Square pattern), implement the GET verification-key handshake once per account setup, HMAC-verify inbound POSTs, track idempotency via a `ProcessedVivaEvent` table (matching `ProcessedSquareEvent`/`ProcessedRevolutEvent`).
- Currency: **only GBP** — Viva could only be used for GBP-only brands, not as a USD-capable provider, which breaks the "provider handles both our currencies" assumption baked into Stripe/Revolut and (per-account) Square.

---

## 9. Risks, Limitations, Final Recommendation

**Risks/limitations:**
1. **USD is not supported for a UK-incorporated Viva account, in any mode** (not multicurrency, not E-DCC, not settlement) — confirmed against three independent official Viva pages. This alone fails one of our two hard currency requirements.
2. Redirect-only UX is a regression from our current in-page checkout experience across all three existing providers.
3. No official SDK — added maintenance burden or dependency on an unofficial, small-community Laravel package for money-moving code.
4. Slower webhook retry cadence (hourly vs. near-real-time) and an unusual verification-handshake step add operational complexity.
5. Adding Viva would mean PayHub's `PaymentProvider` enum gains a provider that **cannot serve one of our two supported currencies**, breaking the implicit assumption that any provider can be selected for any brand/currency — this would need explicit currency-eligibility gating in `StorePaymentRequest`/`UpdatePaymentRequest`, similar to (but stricter than) the existing Square currency lock.

**Final recommendation: Do not adopt Viva**, at least not for USD-currency use cases, and likely not at all given the redirect-only UX regression and weak SDK support. If there's a GBP-only UK brand where Viva's UK market rates/features are specifically attractive, it could be considered as a **GBP-only fifth provider** later, but it does not meet the stated USD+GBP requirement as a general-purpose addition alongside Stripe/Revolut/Square. Recommend re-confirming the USD finding directly with a Viva UK sales rep before fully closing the door, since some processors offer off-docs custom multi-currency arrangements for larger merchants — but nothing in official self-serve documentation supports USD today.
