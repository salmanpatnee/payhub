# Revolut Merchant Integration — Research Report

**For:** PayHub (Laravel 13 + Inertia/Vue 3, multi-brand payment hub)
**Context:** UK entity (GB IBAN); no-redirect embedded card entry is a **hard requirement**
**Date:** 2026-06-15

---

## 1. Executive Summary

**Revolut is a viable second provider for PayHub, and it maps onto your existing Stripe architecture more cleanly than expected.** Revolut's Merchant Web SDK offers a **Card Field** (`createCardField`) that embeds card inputs directly in your own checkout page with no redirect — the direct analog of Stripe's **Payment Element / Elements**. Your hard "pay inside the app, no redirect" requirement is satisfiable.

The mental model is almost 1:1 with Stripe:

| Stripe | Revolut |
|---|---|
| Create **PaymentIntent** (server, secret key) | Create **Order** (server, secret key, Merchant API) |
| `client_secret` | order **`token` / `public_id`** |
| **Elements / Payment Element** (embedded card field) | **Card Field** (`createCardField`, embedded) |
| `confirmPayment()` (client) | `cardField.submit()` (client) |
| Webhook `payment_intent.succeeded` (source of truth) | Webhook `ORDER_COMPLETED` (source of truth) |
| 3DS challenge modal (auto) | 3DS challenge / ACS rendered automatically by SDK |

Because the order-based, webhook-confirmed, secret-key-server-side pattern matches your existing PayHub critical rules almost exactly, **most of your current payment architecture and security posture transfers directly.**

The main caveats are **not technical** — they're (a) merchant account **application + approval/underwriting** (slower than Stripe's instant onboarding), (b) a **smaller ecosystem and less mature AI/MCP tooling** than Stripe, and (c) your **multi-brand model** maps differently (no true Stripe Connect equivalent — see Risks §5).

**Recommendation: Proceed to a sandbox proof-of-concept using the Card Field + Merchant API + `ORDER_COMPLETED` webhook.** This is the only option that meets your no-redirect requirement, and it slots into your existing per-account, webhook-driven design.

---

## 2. Research Objectives — Answers

### 2.1 Revolut Integration

**Integration options available (web):**

1. **Card Field** (`createCardField`, Merchant Web SDK) — **embedded card input fields rendered into a DOM element on your own page. No redirect.** ✅ *This is the Stripe Elements equivalent.*
2. **Card pop-up** (`payWithPopup`) — card entry in a Revolut-controlled popup overlay.
3. **Revolut Pay** — a button; customer pays from their Revolut balance/account, typically via popup and/or app hand-off (1-click). Involves popup/redirect.
4. **Hosted Checkout Page / Payment Links** — fully Revolut-hosted; redirect away from your site.
5. **No-code plugins** — WooCommerce, Shopify, Magento, etc. (irrelevant to a custom Laravel app).

**Closest to your Stripe Elements implementation:** **Card Field** (`createCardField`). It mounts embedded card inputs into your checkout, you call `cardField.submit()`, and the SDK tokenizes and handles 3DS. Conceptually identical to Payment Element + `confirmPayment()`.

**Can customers pay directly inside your application?** **Yes** — with Card Field, card details are entered and submitted inline on your page. No full-page redirect.

**Is any redirect required?** **No redirect for the card entry/payment itself.** The one nuance (same as Stripe): when the card issuer demands a **full 3-D Secure challenge**, the SDK renders the bank's ACS challenge (as a modal/iframe) — this is identical to Stripe's 3DS challenge behavior and is unavoidable for any compliant card processor. Frictionless 3DS happens silently. So your "no redirect" requirement is met in the same sense Stripe meets it today.

> ⚠️ The other options (Revolut Pay, pop-up, hosted page) all involve a popup or redirect and are **ruled out** by your hard requirement. Revolut Pay's docs explicitly describe popup completion and mobile redirect URLs.

### 2.2 Integration Requirements

**Accounts & credentials:**
- A **Revolut Business account** + a **Merchant account** (you apply for the Merchant account; it goes through underwriting/approval).
- **Sandbox** account (separate from production — they share nothing) for development.
- **API keys per environment:** a **Secret key** (server-side only, used in the `Authorization` header for Merchant API calls) and a **Public key** (used client-side at checkout). Generated in the Merchant API settings of the Sandbox/Production dashboard.

**APIs / SDKs / libraries:**
- **Server:** Revolut **Merchant API** (REST) — call directly from Laravel via HTTP (Guzzle / `Http::` facade). Core endpoint: **Create an order** → returns the order `token`/`public_id`.
- **Client:** **`@revolut/checkout`** (official npm package; TypeScript-first). Initialize with `RevolutCheckout(token, 'sandbox'|'prod')`, then `createCardField(...)`. Official examples: `revolut-engineering/revolut-checkout` and `revolut-engineering/revolut-checkout-example` on GitHub.
- No official Laravel SDK — you integrate the REST API yourself (you already do effectively this with Stripe's per-account `StripeClient`).

**Difficulty vs Stripe:** **Comparable, marginally higher.** The flow is the same shape you've already built, so your team's Stripe experience transfers. The extra friction is: less mature/harder-to-navigate docs, smaller community for edge-case troubleshooting, the merchant-account approval step, and you write the server REST calls by hand (no polished first-party PHP SDK). Net: a moderate lift, not a from-scratch learning curve.

### 2.3 Sandbox & Live Payments

- **Sandbox?** **Yes.** Fully separate environment with its own dashboard and keys. Only test cards work there.
- **How to test:** Generate Sandbox keys → create an order server-side with the Sandbox Secret key → render Card Field with the order token in `'sandbox'` mode → pay with a **test card** (any future expiry MM/YY, any 3-digit CVV). Revolut provides **test cards for success and for specific error/decline codes**, plus 3DS test scenarios.
- **Test cards / accounts / webhooks?** **Yes to all.** Test cards documented; webhooks testable in Sandbox by registering a webhook URL (Sandbox Secret key in header) and verifying your backend receives **`ORDER_COMPLETED`** (and other lifecycle events) when you complete a test order.
- **Moving to production:** Apply for / activate the **Merchant account**; Revolut **underwrites** the business — may request proof of website ownership, financial statements, invoices, licences, etc. A live site + clear business description speeds approval. On approval you get Production keys (entirely separate from Sandbox). Restricted/prohibited business categories require extra documentation.

### 2.4 Testing Guide (practical workflow for PayHub)

**Local dev setup**
1. `npm i @revolut/checkout`; create a Revolut **Sandbox** account; generate Sandbox **Public + Secret** keys.
2. Store the Secret key encrypted (mirror your existing Stripe `encrypted` cast rule for `secret_key`/`webhook_secret`); never expose it client-side.
3. Build a Laravel endpoint `POST /revolut/orders` that calls Merchant API **Create an order** (amount in **minor units / cents**, currency GBP or USD) and returns **only the order `token`** to the frontend — never the secret key, never the amount from the client (read amount from the server-side `Payment` record, per your existing rule).

**End-to-end payment test**
4. Vue page: `RevolutCheckout(token, 'sandbox')` → `instance.createCardField({ target, onSuccess, onError })` → mount into a `<div ref>` → call `cardField.submit()` on pay.
5. Pay with a **success test card**; then repeat with **error test cards** to exercise declines and 3DS challenge flows.
6. **Do not** treat the client `onSuccess` as truth — mirror your Stripe rule: **DB state changes only on the `ORDER_COMPLETED` webhook.**

**Webhook testing**
7. Register a Sandbox webhook (e.g. via the API/dashboard) pointing at a tunnel (ngrok/Expose/Cloudflare Tunnel) → `https://<tunnel>/webhook/revolut/{accountId}`, mirroring your Stripe webhook route convention.
8. Exclude the route from CSRF and verify the webhook **signature** (Revolut sends a signature header; verify with the webhook signing secret) before processing — same discipline as your Stripe `constructEvent()` raw-body handling.
9. Complete a test order; assert your backend receives `ORDER_COMPLETED` with `order_id` and `merchant_order_ext_ref`, and that your job moves the `Payment` to paid **idempotently**.

### 2.5 MCP & AI Tooling

**Honest finding: Revolut's AI/MCP tooling is thin and mostly unofficial for *merchant payments* — well behind Stripe.**

- **Official Revolut MCP for merchant payments:** **None.** Revolut has **no first-party MCP server for the Merchant API.**
- **Official-ish Revolut MCP that exists:** **Revolut X** (crypto/trading) exposes an MCP API in **beta** — this is trading, **not relevant to merchant card acceptance**. (The widely-shared "Revolut built a trading desk in 30 min" story refers to this.)
- **Third-party Revolut *Business* (banking) MCP servers:** Zapier MCP, viasocket, Vinkius — these wrap **Revolut Business banking ops** (accounts, transfers), **not** card acceptance, and are not official.
- **Community Revolut MCP servers:** `jeff-nasseri/revolut-mcp` (GitHub); an "Revolut Merchant MCP Server & Skill (3 tools)" on mcpbundles; Apify "Revolut Pulse" MCP servers. **All unofficial — treat as experimental; do not connect to anything holding production payment credentials without review.**
- **Claude Code integration:** Any of the above can be added as an MCP server, but for *building* the integration you'll get more value from **Context7 / official docs** + the **`@revolut/checkout` SDK + `revolut-engineering` GitHub examples** than from a payments MCP.
- **Contrast:** Stripe ships an **official MCP server** (already available in this environment) plus a mature CLI (`stripe listen`). Revolut has **no equivalent official CLI/MCP for merchants** — expect to lean on tunneling + dashboard for webhook testing.

**Practical takeaway:** Don't plan around a Revolut MCP. Use the official npm SDK, the REST Merchant API, and official GitHub examples; keep AI assistance at the docs/codegen layer.

---

## 3. Stripe vs Revolut — Focused on PayHub

| Dimension | **Stripe (current)** | **Revolut Merchant** |
|---|---|---|
| Embedded no-redirect card entry | Payment Element / Elements ✅ | **Card Field (`createCardField`)** ✅ |
| Server payment object | PaymentIntent | **Order** (Merchant API) |
| Client secret handoff | `client_secret` | order **`token` / `public_id`** |
| Source of truth | webhook `payment_intent.succeeded` | webhook **`ORDER_COMPLETED`** |
| 3DS | auto modal | **auto (frictionless or ACS challenge)** ✅ |
| Server SDK | official PHP SDK (`stripe-php`) | **no official PHP SDK** — call REST directly |
| Client SDK | `@stripe/stripe-js` | **`@revolut/checkout`** (TS-first) ✅ |
| Onboarding | instant | **merchant account application + underwriting** ⚠️ |
| Multi-brand / sub-accounts | Stripe Connect (you use per-account keys) | **no Connect equivalent** ⚠️ (see Risks) |
| Webhook test CLI | `stripe listen` ✅ | **none** — use tunnel + dashboard ⚠️ |
| Official MCP / AI tooling | yes ✅ | **none for merchant** ⚠️ |
| Docs/community maturity | very high | moderate / smaller ⚠️ |
| Currency fit (GBP + USD) | ✅ | ✅ (UK entity → GBP native; USD as presentment currency) |
| Amounts | integer minor units | **integer minor units** ✅ (matches your "cents only" rule) |

**Bottom line:** Architecturally, Revolut drops into your existing pattern with minimal conceptual change. The gaps are ecosystem maturity, onboarding speed, tooling, and the multi-brand account model — not the core payment flow or your no-redirect requirement.

---

## 4. Recommended Integration Approach

**Adopt the order-based Card Field flow as a parallel provider behind your existing payment abstraction.**

1. **Provider abstraction** — introduce a payment-provider interface so `Payment`/`*Account` records select Stripe *or* Revolut. Keep the per-account, server-side-credentials pattern you already use for `StripeClient` (one set of Revolut keys per merchant account, encrypted).
2. **Server:** `CreateRevolutOrder` action → Merchant API *Create an order* (amount from server-side `Payment` record, currency GBP/USD, `merchant_order_ext_ref` = your reference code) → return only the `token`.
3. **Client (Vue):** reuse your Stripe Elements page structure; swap in `RevolutCheckout(token, mode).createCardField({ target, onSuccess, onError })` + a submit handler.
4. **Webhooks:** add `/webhook/revolut/{accountId}`, CSRF-excluded, signature-verified, raw body preserved; handle `ORDER_COMPLETED` (and failure events) via a queued, **idempotent** job that updates the `Payment`. **This — not client `onSuccess` — is the only thing that writes paid state.** (Directly satisfies your existing "all payment status from webhooks only" rule.)
5. **Test** with Pest feature tests covering: order creation (amount taken from server, never client), webhook signature verification, idempotency, and decline handling — mirroring your Stripe test suite.
6. **Reuse your conventions:** encrypted key casts, integer cents, reference-code format (`#` + 6-digit zero-pad), never log the order token beyond the page-load response.

**Sequence:** Sandbox POC (Card Field + webhook) → internal test matrix → merchant account approval → production keys → limited live rollout on one brand → expand.

---

## 5. Risks & Limitations

- **Merchant account underwriting (timing/approval risk).** Unlike Stripe's instant onboarding, Revolut reviews the business and may request website-ownership proof, financials, licences. Start the application **early**, in parallel with the POC.
- **Multi-brand architecture mismatch (biggest design risk).** You run multiple brands; with Stripe you use per-account keys (Connect-style). Revolut Merchant has **no Stripe Connect equivalent** — a Merchant account is tied to one Revolut Business entity. Brands under one legal entity likely share one merchant account (descriptor/statement naming and per-brand reporting/settlement separation differ from Stripe). **Validate per-brand settlement, statement descriptors, and reporting requirements before committing.** If brands are separate legal entities, each may need its own Revolut Business + Merchant account.
- **No official PHP SDK** — you maintain the REST integration yourself (manageable; you already do equivalent work).
- **No `stripe listen` equivalent** — webhook testing relies on tunnels + dashboard; slightly more setup.
- **Immature/unofficial AI & MCP tooling** — no official merchant MCP; don't architect around it; vet any community MCP before exposing credentials.
- **3DS challenge UX** — a full 3DS challenge still renders a modal/iframe (true of Stripe too); confirm this satisfies stakeholders' interpretation of "no redirect."
- **Smaller community** — fewer Stack Overflow answers / battle-tested edge cases than Stripe; budget extra time for unknowns.
- **Currency confirmation** — UK entity settles GBP natively; **confirm USD acceptance/settlement terms** for your specific merchant account during onboarding (presentment vs settlement currency, conversion).

---

## 6. Final Recommendation

**Proceed — but as a staged, validated addition, not a Stripe replacement.**

Revolut's **Card Field + Merchant API + `ORDER_COMPLETED` webhook** is the right (and only) path that meets your hard no-redirect requirement, and it reuses your existing order→webhook→idempotent-write architecture and security rules almost verbatim. The technical integration is a **moderate, well-scoped lift**.

Gate the decision on **two non-technical checks** before investing heavily:
1. **Merchant account approval** for your UK entity (start now).
2. **Multi-brand account model** — confirm Revolut can give you acceptable per-brand separation (settlement, descriptors, reporting) without a Connect equivalent.

**Suggested next step:** spin up a Sandbox POC (one Vue checkout page + Laravel order endpoint + signature-verified `ORDER_COMPLETED` webhook) to prove the embedded flow end-to-end, while the merchant account application runs in parallel. If both the POC and the multi-brand validation pass, roll out Revolut to a single brand first, then expand.

---

## Sources

- [Merchant introduction](https://developer.revolut.com/docs/guides/merchant/introduction) · [Merchant API](https://developer.revolut.com/docs/merchant/merchant-api)
- [Merchant Web SDK introduction](https://developer.revolut.com/docs/sdks/merchant-web-sdk/introduction) · [Get started](https://developer.revolut.com/docs/sdks/merchant-web-sdk/get-started) · [Install the widget](https://developer.revolut.com/docs/sdks/merchant-web-sdk/install-widget)
- [Card field (SDK)](https://developer.revolut.com/docs/sdks/merchant-web-sdk/payment-methods/card-field) · [Instance.createCardField](https://developer.revolut.com/docs/sdks/merchant-web-sdk/initialize-widget/instance/instance-create-card-field) · [Card field guide](https://developer.revolut.com/docs/guides/accept-payments/online-payments/card-payments/web/card-field) · [Card pop-up](https://developer.revolut.com/docs/guides/accept-payments/online-payments/card-payments/web/pop-up)
- [3D Secure overview](https://developer.revolut.com/docs/guides/accept-payments/other-resources/3d-secure-overview) · [3D Secure (SDK)](https://developer.revolut.com/docs/sdks/merchant-web-sdk/3d-secure)
- [Revolut Pay - Web](https://developer.revolut.com/docs/guides/accept-payments/online-payments/revolut-pay/web) · [Instance.payWithPopup](https://developer.revolut.com/docs/sdks/merchant-web-sdk/initialize-widget/instance/instance-pay-with-popup) · [Hosted checkout / payment link](https://developer.revolut.com/docs/guides/accept-payments/online-payments/hosted-checkout-page/payment-link)
- [Set up Sandbox](https://developer.revolut.com/docs/guides/accept-payments/get-started/set-up-sandbox) · [Generate API keys](https://developer.revolut.com/docs/guides/accept-payments/get-started/generate-the-api-key) · [Test cards](https://developer.revolut.com/docs/guides/accept-payments/get-started/test-implementation/test-cards) · [Test flows](https://developer.revolut.com/docs/guides/accept-payments/get-started/test-implementation/test-flows) · [Implementation checklist](https://developer.revolut.com/docs/guides/accept-payments/get-started/test-implementation/implementation-checklists)
- [Using webhooks](https://developer.revolut.com/docs/guides/accept-payments/tutorials/work-with-webhooks/using-webhooks) · [Apply for a Merchant account](https://developer.revolut.com/docs/guides/accept-payments/get-started/apply-for-a-merchant-account) · [Merchant account eligibility](https://help.revolut.com/business/help/merchant-accounts/setting-up-a-merchant-account/who-can-apply-for-a-merchant-account/)
- [`@revolut/checkout` (npm)](https://www.npmjs.com/package/@revolut/checkout) · [revolut-engineering/revolut-checkout](https://github.com/revolut-engineering/revolut-checkout) · [revolut-checkout-example](https://github.com/revolut-engineering/revolut-checkout-example)
- MCP/AI tooling (mostly unofficial): [Revolut X built a trading desk in 30 min](https://www.tradingview.com/news/financemagnates:b138c94ee094b:0-revolut-built-a-trading-desk-with-ai-in-30-minutes-will-prompts-replace-broker-platforms/) · [jeff-nasseri/revolut-mcp](https://github.com/jeff-nasseri/revolut-mcp) · [Revolut Business MCP (Zapier)](https://zapier.com/mcp/revolut-for-business) · [Revolut Merchant MCP & Skill (mcpbundles)](https://www.mcpbundles.com/skills/revolut-merchant) · [Connect Claude Code via MCP](https://code.claude.com/docs/en/mcp)
