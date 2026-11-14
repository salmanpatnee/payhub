# NMI research (gateway behind Beyond Bancard)

Researched 2026-11-11. Sources: NMI official docs only (docs.nmi.com through Context7 `/websites/nmi`, plus search limited to nmi.com, docs.nmi.com and support.nmi.com). Related: [beyond-bancard.md](beyond-bancard.md).

## Why NMI

On 2026-11-10 Beyond Bancard emailed a "Payment API" key. The key screen text (Payment API, Three Step Redirect, QuickClick, V4 API, Query APIs) is NMI's Security Keys page. Still to confirm with Beyond Bancard, along with the gateway's login URL (resellers often white label the NMI domain).

## Integration methods

| Method | Type | NMI status | Fit for PayHub |
|---|---|---|---|
| **Payment Component** (`@nmipayments/nmi-pay`, or Gateway.js) + **REST API v5** | Embed. NMI hosted card fields on our page, token sent to our server, server charges | **Recommended for new integrations** | **Best fit** |
| Collect.js + Classic `transact.php` | Embed. Same idea, older | Kept for existing integrations | Works, but legacy |
| Three-Step Redirect (Classic) | Our form posts the card straight to NMI, NMI sends the browser back with `token-id`, server completes | Classic | Card form on our page, so similar PCI to embed. No upside |
| **Collect Checkout** (hosted page) | Redirect. `CollectCheckout.redirectToCheckout(...)` | Current | **Poor fit**: line items are product SKUs from NMI's product list, or a "customPayment" item where **the customer types the amount**. No way found to send a fixed, server-side amount. Breaks our "never accept amount from client" rule |

Recommendation: **Embed** with the Payment Component and the v5 REST API. Redirect is not a good fit with what the docs show.

## How the embed flow works

1. The pay page loads the Payment Component with a **public tokenization key** (`mountNmiPayments('#el', { tokenizationKey, onPay })`). Card fields are NMI iframes.
2. The customer enters the card. The component returns a single use `payment_token` (valid 24 hours).
3. Optional 3DS: `mountNmiThreeDSecure(...).startThreeDSecure({amount, currency, ...})` returns `cavv`, `eci`, `xid`, `cardHolderAuth`, `threeDsVersion`, `directoryServerId`.
4. The browser sends only the token (and the 3DS result) to PayHub. PayHub reads the amount from the `Payment` record and calls `POST https://secure.nmi.com/api/v5/payments/sale` with header `Authorization: <private key>`:
   `{ "amount": "10.00", "payment_details": { "payment_token": "..." }, "order_id": "...", "cardholder_auth": { ... } }`
5. The reply has `id` (transaction id), `status`, `response`, `response_code` (100 = approved), and `auth_code`.

## Status and webhooks

- **Webhooks exist** and are signed. Header `Webhook-Signature: t=<nonce>,s=<sig>`, where `sig = HMAC-SHA256(nonce + "." + rawBody, signingKey)`. The signing key is on the Webhooks settings page. NMI publishes its sending IPs.
- Body: `event_id` (use it for dedupe), `event_type`, `event_body` (`transaction_id`, `order_id`, `condition`, `currency`, amounts and more).
- Events: `transaction.sale.success | failure | unknown`, plus auth, capture, void, refund, credit and validate.
- Retries: immediately, then after 10 s, 100 s and 1000 s (about 18 minutes in total), then delivery stops. So we still need a lookup fallback.
- Lookup: `query.php` (Classic, XML) by `transaction_id` or `order_id`. Conditions: `pending`, `pendingsettlement`, `in_progress`, `abandoned`, `failed`, `canceled`, `complete`, `unknown`.
- The webhook only has a signature and a nonce, and **no timestamp window is documented**. Rely on `event_id` dedupe.

Fit with PayHub rules: the server makes the charge call itself, so it is the same pattern as the **Authorize.net / MPGS / Clover** exception (status comes from the gateway's reply to our own server call, plus a lookup and a webhook), not webhook-only status. All status writes would go through one resolver class, like `AuthorizenetChargeResolver`.

## What we need from Beyond Bancard / the NMI portal

1. Confirm the gateway is NMI, and the exact API host (secure.nmi.com or a white label domain).
2. A **public tokenization key** (Settings > Security Keys, "Checkout"/tokenization permission). The emailed key is the **private** one and must never reach the browser.
3. Webhooks turned on, the signing key, and our URL added (`webhook/nmi/{account}`).
4. **Payer Authentication (3DS)** service turned on, if we want 3DS.
5. Currencies: these come from the processor linked to the account (the merchant profile lists them). Confirm USD, and GBP if needed.
6. A sandbox account (NMI's sandbox is `sandbox.nmi.com`). Test cards: Visa 4111111111111111, MC 5431111111111111, expiry 10/29, and an amount under 1.00 gives a decline.

## Sandbox setup (done 2026-11-11)

- Sign up is free at https://guide.nmi.com (sandbox accounts are always in test mode). The first try gave an F5 "403 Forbidden, URL rejected" firewall page; if that happens, use a US/UK VPN in a private window, or send the support ID to support@nmi.com.
- guide.nmi.com opens the **partner portal** (List Accounts, Add Merchant). Its Webhooks page is for partner events, not transactions.
- To reach the **merchant portal**: My Accounts > List Accounts > **Sign in** on "ACME Test Merchant" > in **Merchant Users**, click **Log in**.
- Test merchant: "ACME Test Merchant", gateway ID 1363510, status Test. Test CC Processors A and B are active, and **Test 3DS (Payer Authentication - Sandbox) is active**.
- Merchant portal menu: My Settings > Gateway Options.
  - **Security Keys**: created a private key (API permission) and a public tokenization key. Both are saved outside the repo; never commit them or paste them in chat.
  - **Webhooks**: Create an endpoint (https URL plus event types). The signing key appears on this page. Not set up yet; the PayHub route `webhook/nmi/{account}` does not exist yet, so use the staging URL once it is built.
  - **Country/Currency Configuration**: check here whether GBP is available as well as USD (not checked yet).
  - Also on this page: Test Mode, IP Restrictions, Address Verification, Card ID Verification, Payer Authentication Rejection Rules, API Configuration.

## PCI

NMI card fields sit in iframes on our page, the same model as Square and Stripe Elements. NMI's CSP example only allows `https://secure.nmi.com` for script, style, connect and frame. Confirm the SAQ level with the acquirer.

## WooCommerce

NMI does not publish its own WooCommerce plugin. Its integration portal points to third party plugins. Those charge outside PayHub. To keep payments in PayHub, WooCommerce should send customers to a PayHub pay link.

## Build size

A ninth provider: `NmiAccount` model and admin CRUD (encrypted private key and webhook signing key, plain public tokenization key), an `NmiClient`, a pay page with the Payment Component and 3DS, a charge endpoint, `NmiChargeResolver`, webhook route and job, a query lookup and sweep, CSV export, dashboard filters, receipts, CSP hosts on the NMI pay page only. Roughly the size of the Authorize.net work (spec 0007).
