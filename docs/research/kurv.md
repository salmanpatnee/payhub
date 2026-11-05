# Kurv (KurvPay) research

Researched 2026-10-20. Sources: https://docs.kurv.app (read in browser and via fetch), kurvpay.com pages (the developers page and pricing page returned 403 to fetch), web search, and the `Paysley/kurv-woocommerce` plugin source. Context7 has no Kurv payment docs (its only "kurv" entry is an unrelated Rust process manager).

## What Kurv is

- US-focused payments platform: hosted checkout, payment links, QR codes, recurring billing, virtual terminal, tap-to-pay, ACH/eCheck.
- REST API; docs list cards, PayPal, Apple Pay, Google Pay and "Direct Debit". No Direct Debit product was found in search; closest is ACH.
- Capterra lists the US only. No public fee table in the docs.

## API summary

- Auth: `Authorization: Bearer <key>`. Keys: `kp_test_...` (sandbox) and `kp_live_...` (live). The URL plus key decide the mode.
- Sandbox: `https://api-sandbox.kurv.app/`. Production: `https://api.kurv.app/`.
- Request bodies are described as form-encoded in the intro, but the WooCommerce plugin sends JSON and it works.
- Going live needs the merchant connected to the app (invite from either side, or an application token) and the app set to Published.
- Create payment: `POST /payment-requests/` with `request_methods` (EMAIL/SMS/WEB), `amount`, `currency`, `fixed_amount`, `customer_first_name`, and `email` or `mobile_number`. Optional: `reference_number`, `response_url`, `redirect_url`, `cancel_url`, `payment_type` (DB default, PA = pre-auth), `payment_methods`, recurring fields, `cart_items`.
- Response: `transaction_id`, `short_url`, `long_url`, `qrcode_link`. The customer pays on Kurv's hosted page (redirect flow, no embedded form).
- Other endpoints: `GET /payments`, `GET /payments/{payment_id}`, `POST /refunds`, `POST /captures/{payment_id}`, `DELETE /payments/subscription/{id}`, POS links (`/pos`, `/pos/generate-link`), customers, customer groups, products, categories.
- Errors: 400, 401, 402, 403, 404, 409, 422, 429 (use exponential backoff), 5xx.

## Payment Response (the only callback)

- Kurv POSTs a form field `response` (JSON string) to `response_url`.
- Fields: `status`, `result` (`ACK` success / `NOK` failure), `payment_id`, `transaction_id`, `reference_number`, `payment_type` (e.g. `CC.DB`), `currency`, `amount` (sample `15`, whole units), `result_code` (100 = success), `result_description`, `payment_frequency`, `email`, `mobile_no`.
- **No webhooks section, no signature, no secret, no IP allowlist** anywhere on the page (confirmed by a keyword search of the whole rendered page).
- History status values seen: `success`, `refund`, `capture`, `end_subscription`. No full status list. No documented failed or pending states.

## Fit with PayHub rules

| Area | Kurv | Note |
|---|---|---|
| Checkout | Hosted redirect | No embedded form like Stripe Elements |
| Credentials | One `kp_` key per merchant plus app connection | Works with per-account clients |
| Status source | Callback is unsigned | Treat as a hint, then `GET /payments/{payment_id}` |
| Dedupe | No event id | Key on `payment_id` plus status |
| Amount | Plain number, probably whole units | Convert to integer cents; confirm in sandbox |
| Currency | USD, ZAR, EUR in examples | GBP and PKR unconfirmed |
| Refund / capture | Supported | Doc inconsistency: `/refunds` vs `/refunds/{unique_id}` |

Verdict: feasible only as a "re-fetch to verify" provider, same approach as unsigned Viva webhooks.

## Doc problems noticed

- Typos in code samples (missing quotes, commas, bad Authorization headers).
- Create response sample shows ZAR for a USD request.
- Live-format keys appear in examples. Do not reuse.
- `PUT /pos` vs `POST /pos/generate-link` mismatch; `/refunds` path mismatch.
- `expiry_date` is documented as `YYYY-MM-DD HH:MM` but the plugin author reports every format is rejected as "not in ISO 8601 format".

## WooCommerce plugin troubleshooting (Paysley/kurv-woocommerce)

Symptom: sandbox works; in production the customer lands back on the WooCommerce checkout page instead of the hosted page. Cause: `process_payment()` returns `failure` when the API call throws, so WooCommerce reloads checkout and shows the error notice.

Found in order:
1. **Wrong live URL.** `includes/class-kurv-api.php` had `$api_live_url = 'https://live.kurv.app'`, which does not resolve. Correct value: `https://api.kurv.app`.
2. **Empty billing email.** Log showed `"email":""` and `"mobile_number":"+1"`, giving 422 "email or mobile number is required." The staging checkout was not collecting the billing email.
3. **Bad phone prefix.** The plugin adds a country code when the phone is 10 characters or fewer, so an empty phone becomes `"+1"`. Guard with a non-empty check.
4. **Account plan.** After the above, live returned 422: "Your tier does not give you permission to access this resource 'Invoicing'. Please upgrade your tier plan." `POST /payment-requests` counts as Invoicing. This is the current blocker. It is a plan issue on the merchant account, not a code bug.

Other plugin notes:
- `cart_items` was empty in the logs. Check that the order has line items.
- The gateway can be set to `PA` (pre-auth); orders then sit on-hold until captured.
- The plugin trusts the browser return (its own HMAC token plus the `response` parameter). It never confirms with `GET /payments/{payment_id}`, so a customer who closes the tab on Kurv's page never marks the order paid.
- Log lines `GET /payment-requests/1074013OO -> 404` came from code not in the repo version we read. Possibly a sandbox ID looked up in live.

## Plans and pricing (from kurvpay.com pages and reviews; unverified at source)

- START $0/month, BUILD $9.95, SCALE $29.95, PERFORM $59.95 (plus per-transaction fees on paid tiers).
- Invoicing is not in START. BUILD adds Customer Management, Invoicing, Recurring Billing, Analytics, Terminal.
- Upgrade from the Merchant Portal at any time, or add individual modules à la carte ($5-$25/month).
- Processing: Swiped 2.50% + $0.10; Keyed and Online 2.90% + $0.25; Zero Cost Processing 0% merchant with a 3.95% cardholder fee. Final rates set at underwriting.
- Reviews conflict on contract length (24 months vs "no lock-in") and on whether rates are public. Confirm in writing.

## Resolving the "Invoicing" tier error (checked 2026-11-04)

- **Confirmed from the merchant dashboard (screenshot, `kurv.app/en/merchant/dashboard`, My Account):** merchant DEEPSYNC TECHNOLOGIES LLC is on the **Start** plan ($0/month). The only active tool is Customer Management ($5.00). No Invoicing module. Banner says "Upgrade to Build". Gateway is Active. Monthly cap $10.0K. PCI status shows "SAQ: Incomplete, SCN: FAIL" (ask Kurv if it blocks live payments).
- **Confirmed from the Developer Portal (`developer.kurv.app/my_app`):** app **DemoTech** is **published**, with 1 connection. The Production access key page shows the merchant **DEEPSYNC TECHNOLOGIES LLC** with status **Connected** (2026-10-20). The webhook URL field is blank. So the app and connection are fine. The access key is in that page's URL: treat it as a secret and never paste it into notes or tickets.
- Most likely cause of the production failure: the Start plan. Sandbox does not check plans, so it works there. Still unproven: no Kurv page says outright that API payment requests need Invoicing. That link comes only from the error text and our own test.
- Kurv's docs say a 403 means the key "doesn't have permissions" and explain nothing more. Context7 has no Kurv payment docs. Some `kurvpay.com` pages return 403 to a plain fetch (they load in a real browser), and the third-party reviews may be out of date.
- Likely fix: the merchant upgrades from START to BUILD or higher with **Change Plan** (no contract, per the official page), or buys the Invoicing module à la carte ($5-$25/month, possibly cheaper). PayHub cannot do this for them.
- Steps:
  1. Ask Kurv support (800-726-2117) which plan or add-on unlocks `POST /payment-requests` through the API, and whether BUILD is enough or only SCALE/PERFORM.
  2. The merchant upgrades or adds the module.
  3. Re-run the same live call that returned the 422. If it still fails, record the HTTP status and body (key removed).

## Kurv has more than one product (checked 2026-11-04 in a browser)

`kurvpay.com/developers/` lists four products. We have only tested the first.

| Product | Best for | Docs |
|---|---|---|
| Merchant | Invoice/MOTO merchants, in person, hosted payment pages | developer.kurv.app, docs.kurv.app |
| Gateway | E-commerce, mobile apps | docs.paymentenablement.com |
| Onboarding and Transaction Data API | Software companies, agents, ISOs | apidocs.emscorporate.com |
| Embedded Payments Platform | Software companies | kurvpay.paymentplatform.dev |

- **Gateway (Payment Enablement)** looks like a better fit for PayHub. The "NMI-style" label is my inference, not stated by Kurv. It has an embedded Payment Component (`@pmt-nblmt/pe-pay`; cards, ACH, Apple Pay, Google Pay; card data never touches our server), a server charge call `POST /api/v5/payments/sale` with a private key (docs include a Laravel example), 3D Secure, a Customer Vault and recurring billing.
- Gateway hosts: sandbox `sandbox.paymentenablement.com`, production `secure.paymentenablement.com`. Keys come from the gateway's own Merchant Portal (Settings > Security Keys): a public tokenization key and a private key. This is a different login from `kurv.app`.
- The Gateway docs mention no plan tiers and no Invoicing. Not yet checked: webhooks or status re-check, GBP/PKR support, fees beyond online 2.90% + $0.25 (IC+ and volume discounts available), and whether DEEPSYNC can get gateway access (may need a separate application).
- Marketing pages (`/products/payment-gateways/`, `/solutions/online/`, the digital wallet blog post) add no API detail. They say the gateway keeps users in a "native checkout", offers recurring billing, a card vault, 3DS and Apple/Google Pay, and approval can take as little as 1 day. Apple Pay online needs domain verification. Refunds and chargebacks work like normal card payments.

## Embed options checked (2026-11-05)

**Merchant API hosted page: no embed.** The docs describe a redirect flow only. The sandbox payment page answers with `X-Frame-Options: sameorigin`, so browsers refuse to show it in an iframe on another site. The docs mention a "payment widget" (under `moto_enabled`) but only as part of the hosted page.

**Gateway (docs.paymentenablement.com) has embedded and hosted options.** Read with WebFetch; the browser tool was refused on that domain.
- Sections: Payment Component, Customer Vault, Hosted Checkout ("Collect Checkout", no code), Custom Checkout, Collect.js, Gateway.js, Payer Authentication (3DS), Kount fraud checks, Appearance API, API Reference (payments, invoicing, subscriptions, customers, reporting, users, API keys, webhooks).
- **Payment Component** (`/docs/payment-component`): embedded in our page, it turns card or wallet details into a token in the browser. Our server then charges the token with `POST /api/v5/payments/sale` (`amount`, `payment_details.payment_token`) using the private key; `response === '1'` is success and `id` is the transaction ID. Packages: `@pmt-nblmt/pe-pay` (plain JS) and `@pmt-nblmt/pe-pay-react`. Methods: cards, ACH, Apple Pay, Google Pay. The docs say "Cardholder data never passes through your servers" (Kurv's claim, not tested). The tokenization key is public; the private key stays on the server. Styling through Component Studio and the Appearance object. Embedded components also use a short-lived session token from "Create a Session" (`/reference/create-embedded-component-session`), called from the backend.
- **Webhooks** (`/reference/overview`): created in the Merchant Portal (Settings > Webhooks), HTTPS only. Event types: transactions (success, failure, unknown), check status, recurring, settlement, chargebacks, automatic card updater. Payload: `event_id`, `event_type`, `event_body`. **Signed**: header `Webhook-Signature: t=<nonce>,s=<signature>`, HMAC-SHA256 of `nonce + "." + raw body` with the signing key from the Webhooks page. Delivered only from 104.192.32.81 to .87 and 104.192.36.81 to .87 (the page advises allowing only these). Retry behaviour is not described.
- **Sandbox:** the code sample uses `https://sandbox.paymentenablement.com`. The test page (`/docs/testing-sandbox`) lists only 3D Secure cards: not enrolled Visa 4111111111111111; frictionless Visa 4100000000000100; challenge Visa 4100000000005000 (code 12345 passes, any other code fails). Mastercard, Amex and Discover numbers are also listed there. No general success or decline cards, and no way to trigger a decline.
- **Keys:** sandbox keys and Merchant Portal access come from guide.paymentenablement.com. Different login from `kurv.app`.
- **Not stated:** whether the component asks for ZIP, country or other billing fields (or lets us hide them), supported currencies (GBP unknown), retries, the sale response beyond `response` and `id`, and any status lookup endpoint (not read yet).
- Other page URLs (add `.md` to read as text): `/docs/hosted-checkout`, `/docs/custom-checkout`, `/reference/authentication`, `/reference/create-sale-v5`, `/reference/transaction-events`, `/reference/check-status`, `/reference/recurring-events`, `/reference/settlement-events`, `/reference/chargeback-events`, `/reference/acu-events`.

**Embedded Payments Platform (`kurvpay.com/solutions/embedded-payments/`)** is marketing for software platforms and marketplaces that resell payments: white label merchant portals, merchant onboarding, no code widgets, hosted pages and embeddable forms, "two to three weeks" for a fully embedded setup. It is a partner program, not a checkout form we can drop into PayHub. WebFetch got a 403; the page loaded in the browser.

**What this means for PayHub**
- Gateway fits better than the hosted page: it has an embed and signed webhooks (the Merchant API callback is unsigned), and the flow matches Authorize.net in PayHub (our pay page, token, server charge).
- Open risks: separate product and keys (can DEEPSYNC get access, and at what cost), GBP unconfirmed, PCI level unconfirmed (ask Kurv whether the Payment Component counts as SAQ A), and no sandbox decline cards.
- Still to read before a spec: Hosted Checkout, Create a Session, `create-sale-v5` response and errors, Check Status, transaction events payload, refunds.

## Gateway deep dive (2026-11-05, read with WebFetch)

Pages read: sale, get payment, refund, create session, authentication, Payment Component, frontend guide, 3DS (Gateway.js page), hosted checkout, custom checkout, transaction events, check status, retry logic, response codes, test and deploy, testing sandbox, `llms.txt` index. Several API pages were cut off after about 100,000 characters (sale, get payment, refund), so full field lists (which fields are required, order id on the request, idempotency) are not confirmed.

**Flow for PayHub (Payment Component)**
1. Pay page mounts the component with the public tokenization key (safe in the browser). It needs either `onPay` (component shows its own pay button) or `onChange` (gives `token` once `complete` is true). `onExpressCheckout` covers Apple Pay and Google Pay. `layout` and `paymentMethods` props exist but are not described. After a failed try without `onPay`, call `resetFields()`.
2. Browser sends the token to PayHub. The amount and currency must come from the server side `Payment` record, never from the browser (the component itself takes no amount).
3. Server calls `POST /api/v5/payments/sale` with the private key in `Authorization` (no `Bearer`). Sandbox base: `https://sandbox.paymentenablement.com/api`. Production base URL is not documented in the pages we read. Request fields seen: `amount`, `currency`, `payment_details` (`payment_token`, or raw card fields), `billing_address` (`first_name`, `last_name`, `address1`, `city`, `state`, `zip`, `country`, `email`), `customer_vault`, `industry`. Sale authorizes and captures in one step.
4. Response: `response` 1 approved / 2 declined / 3 error, `response_code` (100 approved, 200 to 264 declined, 300 to 461 errors, 430 duplicate transaction), `response_text`, `id`, `amount`, `currency`, `avs_response`, `cvv_response`, `status`, `order_details.order_id`.
5. Signed webhook `transaction.sale.success` / `.failure` / `.unknown` confirms it. Event body: `event_body.transaction_id`, `action.amount`, `currency`, `order_id`, `action.response_code`, `action.response_text`, `action.success` ("1" or "0"), `condition`, `features.is_test_mode`. Signature header `Webhook-Signature: t=<nonce>,s=<signature>` (HMAC-SHA256 of `nonce.rawBody`). Sender IPs 104.192.32.81 to .87 and 104.192.36.81 to .87.
6. **Webhook retries:** up to 20 tries over about 3 days (about 15 s, 3 x 60 s, 5 min, 15 min, 30 min, 10 x hourly, 2 x 12 h, one at 24 h). Any answer other than HTTP 200 counts as a failure. Docs do not say if events can arrive twice or out of order: be idempotent (`event_id`; the sample page reuses the same `event_id` across samples, so do not trust it alone).
7. **Lookup:** `GET /v5/payments/{payment_id}` (fields `status`, `response`, `response_code`, `response_text`, `amount`, `currency`, `order_details.order_id`). It cannot look up by our order id, so save the Gateway payment `id` when the sale returns. Unknown outcome (timeout) therefore has no search by order id in what we read.
8. **Refunds:** `POST /v5/payments/{payment_id}/refund`, body `amount` (0.00 means full refund; partial allowed); only a settled transaction can be refunded. Also void (`/void`) and capture (`/capture`) pages exist (not read in full). Events `transaction.refund.*` and `transaction.void.*`.
9. **3D Secure** is opt in and must be built: mount `PeThreeDSecure`, call `startThreeDSecure({ paymentToken, currency, amount, firstName, lastName, ... })`, then send `cardHolderAuth`, `cavv`, `eci`, `xid`, `threeDsVersion`, `directoryServerId` with the token to the sale call. `modal` defaults to true. The Gateway.js 3DS page also lists `email`, `address1`, `city`, `state`, `country`, `postalCode` as required when available.
10. **Keys:** public and private keys from Settings > Security Keys in the Merchant Portal; keys inherit the permissions of the user they are assigned to, so make separate keys per environment and app. The Payment Component needs no session (`POST /sessions` under `/api/v5/embedded-reporting` is only for reporting style embedded components; token lasts 15 minutes).
11. **Sandbox testing:** sandbox accounts are already test mode; card expiry 10/29 works. Decline: amount below 1.00. Fatal error: invalid card number. AVS match: `888` in address1 and `77777` in zip. CVV match: `999`. 3DS cards listed on the sandbox page. Test cards for Visa, Mastercard, Discover, Amex, Diners, JCB, Maestro are listed on Test and Deploy (numbers not copied here).
12. **Hosted Checkout ("Collect Checkout"):** runs on Kurv's servers, limited customization, returns to our site; the docs do not say how the link is made, which fields it collects, or webhooks. Custom Checkout is just "build it yourself with the API".

**Sale request details (from the cut off part of `create-sale-v5`)**
- Required: only `amount`, plus exactly one payment source: `payment_details` or `customer_vault` (`id`, optional `billing_id`, `shipping_id`; never both).
- `payment_details` variants: card (`card_number` and `card_exp` MMYY required, `card_cvv` optional), **`payment_token`** (Payment Component or Collect.js tokens; not for Apple Pay or Google Pay), ACH, wallets (`applepay_payment_data`, `googlepay_payment_data`), card present.
- Optional: `currency` (3 letter ISO 4217; no default stated, so always send it), `billing_address`, `shipping_address`, `order_details`, `payment_descriptor`, `cardholder_auth`, `merchant_defined_fields`, `cit_mit`, `processor_id`, `tip`, `surcharge`, `convenience_fee`, `misc_fee`, `customer_receipt` (true sends the customer a receipt).
- **Our reference:** `order_details.id` (max 50 characters) is where the PayHub payment id would go; it comes back as `order_details.order_id` and as `event_body.order_id` in the webhook. `order_details.po_number` (max 50), `order_details.description` (max 100), `order_description` (max 255) also exist.
- **Duplicates:** there is no idempotency key. `dup_seconds` sets a duplicate check window (0 to 7862400 seconds; 0 turns it off), and a repeat shows as response code 430. This is the guard against paying twice; set it deliberately.
- **3DS (`cardholder_auth`):** `status` ("verified" or "attempted") and `cavv` required; `xid`, `three_ds_version`, `directory_server_id` optional. The ECI value is derived from `status`.
- **Errors:** `type` (for example `validationError`), `error_code` (`E_VALIDATION`, `E_INTERNAL`), `message`, optional `ref_id` and `details[]` (`fieldName`, `message`).
- `TransactionAction`: `response_text` APPROVED, SUCCESS or DECLINE; `success` "1" or "0"; `action_type` auth, capture, sale, void, refund, credit, return, validate.
- The `get-payment-v5` and `refund-payment-v5` excerpts after 100,000 characters held no status values and no refund enum (the pages are one large OpenAPI file; those parts sit elsewhere). Status values for `GET /v5/payments/{id}` and the refund `payment` enum are still unconfirmed.

**Still unknown (ask Kurv or read the cut off parts)**
- Billing fields: does the Payment Component show or collect ZIP and country, and can we hide or prefill them? The sale call accepts `billing_address`, and AVS needs ZIP; the notes on Authorize.net (AVS response B must be Allow) may apply.
- Currencies: `currency` is a request field (example USD). GBP depends on the merchant account and processor; no page in the index covers it.
- PCI: no page. The component says card data never passes through our servers; confirm SAQ A in writing.
- Production base URL, webhook ordering or duplicates, and the status values returned by `GET /v5/payments/{id}`. (Order id field and duplicate handling are answered above.)
- Access: separate Merchant Portal and keys from guide.paymentenablement.com; can DEEPSYNC get an account?
- Vue support: packages are vanilla JS (`@pmt-nblmt/pe-pay`) and React (`@pmt-nblmt/pe-pay-react`). The docs show React examples; PayHub uses Vue, so we would use the vanilla package.
- CSP and allowed domains for the component: not documented in what we read (PayHub must add the hosts only on this pay page, as with Authorize.net and Whop).
- Docs tools: a Developer Docs MCP (`/docs/mcp`) and a Postman collection exist; either would answer the cut off parts.

## Decision (2026-11-05, changed): hosted method

Decision: go with the **hosted payment page** (Merchant API, `POST /payment-requests`, redirect flow). This replaces the earlier choice of the Gateway Payment Component. The Gateway notes above stay as the fallback if the hosted page proves too limited. Same pattern as Whop in redirect mode: the customer leaves PayHub, pays on Kurv, comes back.

How it would fit PayHub rules:
- One payment request per PayHub payment, created on the server from the `Payment` amount and currency. Reuse it on retry (the WooCommerce test paid order 31 twice because every retry made a new link). Save the Kurv transaction ID on the payment.
- The `response_url` callback is unsigned, so it never decides status. It only triggers a re-fetch (`GET /payment-requests/{id}`), like Viva. Status changes only after the API says paid, the amount and currency match, and the transaction ID is ours. Add the same scheduled re-checks as the plugin (2 min, 10 min, 30 min, 2 h) because the first callback can arrive before the lookup shows the payment.
- The return page only reads `Payment.status` and polls. It never changes status.
- Live and sandbox hosts differ (`api.kurv.app` and `api-sandbox.kurv.app`). Keys are `kp_live_` and `kp_test_` and go in the encrypted account form.
- A second successful Kurv payment for an already paid PayHub payment must be flagged for refund, not ignored.
- No embed, so no control of the page: ZIP and country cannot be hidden (open question 12). PCI scope is the lightest option, since card details never touch PayHub.

Get or confirm before building:
- A Kurv merchant plan that allows payment requests live (Start plan returns 422, "Invoicing" tier). See "Resolving the Invoicing tier error". Sandbox already works.
- Sandbox and live API keys per brand (never paste them in chat, notes or tickets).
- Whether `amount` is whole units or cents, and GBP support (question 4).
- Failed and declined status values and result codes such as 253 (questions 5, 10, 11). The failure path is still untested.
- Whether callbacks can be signed or sent from fixed IPs (question 3).

Decisions for us: one Kurv account per brand like the other providers; USD only or USD and GBP; what the customer sees when the return page is still waiting.

Then: `/architect` spec at `docs/specs/0010-kurv-provider/`, built for every provider surface (pay page, CSV export, admin account CRUD, dashboard filters, receipts, callback route excluded from CSRF).

### Earlier decision (superseded): Gateway Payment Component

Kept for reference. It would have needed Merchant Portal keys (public and private), a signed webhook, and answers on ZIP and country, GBP, PCI level and the production base URL (questions 6 and 14).

## WordPress / WooCommerce

- `kurvpay.com/solutions/integrations/` lists a WooCommerce integration ("Open Source") but gives no plugin name, link, setup steps or plan. It is probably the `Paysley/kurv-woocommerce` plugin we read, but this is unconfirmed. The online solutions page names Shopify, 3DCart and ZenCart, not WooCommerce.
- To make the plugin work: upgrade the merchant plan (above); change the plugin's live URL from `https://live.kurv.app` to `https://api.kurv.app`; use the `kp_live_` key; make checkout collect the billing email; guard the phone prefix so an empty phone does not become `+1`; test a small live order and a refund.
- Risk: the plugin trusts the browser return and never re-checks the payment, so a closed tab never marks the order paid. Kurv's callback is unsigned.

## Sandbox test results and plugin fix (2026-11-04)

Tested on `deepsynctechnologies.com/kurv` with the plugin copy in `docs/kurv-woocommerce-main` (version 1.0.1, Test Mode, `sandbox-pay.kurv.app`). Orders were made in wp-admin with the amount as a fee.

**Why orders stayed "Pending payment" with "token mismatch"**
- `response_page()` rebuilt the token with the currency from Kurv's reply. An empty reply gave an empty currency, so it never matched. It was a plugin bug, not fraud.
- `redirect_url` and `response_url` were the same URL, so the plain browser return and Kurv's server call both ran the same code. The plugin never asked Kurv for the real status.
- Each retry made a new secret, so links from earlier attempts stopped matching. Kurv's own `transaction_id` was not saved.
- Several `WC_Kurv` objects registered the same hooks, so every handler ran twice (full refunds could have been sent twice).
- The full `kurv_token` was written to the log inside `redirect_url`.

**What the live API really does** (differs from docs.kurv.app)
- The `response_url` callback is a POST with `Content-Type: application/json` and the result as the raw body. There is no `response` form field, so `$_POST` is empty. Body keys: `status`, `result`, `payment_id`, `transaction_id`, `reference_number`, `payment_type`, `currency`, `amount`, `result_code`, `result_description`, `payment_frequency`, `email`, `mobile_no`. Success was `result=ACK`, `result_code=100`.
- `GET /payment-requests/{transaction_id}` answers with one `transaction` object (the docs show a `transactions` list). A paid request has `status=success` and a `payment_id`.
- `GET /payments?keywords=<reference>` lists the payment history. An accepted payment shows `status=ACK`, and `result_code` is empty. The same reference (for example `wc-31`) also returned rows from older tests (amounts 0.1 and 0.01), so rows must be matched on the Kurv transaction ID, not just the reference.
- The first callback of a payment can arrive before the lookup shows it. The plugin re-checks at 2 min, 10 min, 30 min and 2 h with Action Scheduler.

**What the plugin does now**
- `response_url` is `/?wc-api=wc_kurv&order_id=N&kurv_token=T` (official WooCommerce WC-API callback). `redirect_url` is the plain return URL plus `kurv_return=1`.
- The browser return never changes a status. Only the Kurv API answer does, through one method (`verify_order()`) with a per order lock. Success uses `payment_complete()`. Pre-auth and amount mismatches go on-hold.
- One secret per order across retries; Kurv transaction IDs saved in `_kurv_request_ids`; hooks registered once; token hidden in logs.

**Results**
- Order 31 ($10): paid after the lookup fix, then Completed. A later scheduled re-check logged "already final" and did nothing.
- Order 32 ($123): the callback arrived, the lookup confirmed it, and the order was marked paid within about one second.
- No "token mismatch" warnings after the fix.

**Still open**
1. **Double payment.** Order 31 was paid twice (two successful Kurv payments, 1074085OO and 1074086OO, for one order) because each retry makes a new link. The plugin marks the order paid once but does not flag the second charge. In live mode that is a real double charge. Idea: add an order note when a second successful payment arrives.
2. **Failed payments are untested.** We have not seen Kurv's status or fields for a decline. The code assumes `failed`, `declined`, `rejected`, `error` or `nok`. Kurv's dashboard showed wc-29 as FAILED with result code 253 (that was on the live host `pay.kurv.app`, not the sandbox). Code 253 is not explained anywhere we looked.
3. **No official sandbox test cards found.** The card 4242 4242 4242 4242 worked on the sandbox.
4. **ZIP and country fields.** Nothing in the docs we read lets the merchant hide or show billing ZIP or country on the hosted page. Ask Kurv.
5. **Live mode.** Do not enable until the plan issue above is solved and a real small payment plus a refund are tested.

## Verdict

Decision (2026-11-05): use the hosted payment page. It is feasible only as a "re-fetch to verify" provider, because the callback is unsigned and the hosted page cannot be embedded. It needs a plan that allows payment requests live, and the failed payment path must be tested first. The Gateway Payment Component stays as the fallback.

## Open questions for Kurv support

1. Move the live merchant account to BUILD or higher, or enable the Invoicing add-on.
2. Does the plan allow `POST /pos/generate-link`? (The plugin does not use it.)
3. Can callbacks be signed or sent from fixed IPs?
4. Is `amount` in whole units or cents? Is GBP supported?
5. Full list of payment statuses, including failed and pending.
6. Can DEEPSYNC get Gateway (Payment Enablement) access, and at what cost? Does the Gateway have signed webhooks and GBP support?
7. Is `Paysley/kurv-woocommerce` the official WooCommerce plugin, and which plan does it need?
8. Is the blank webhook URL on the developer app connection used at all?
9. Does the failed PCI scan (SAQ incomplete) block live payments?
10. What do result codes like 253 mean, and what are the status values for a failed or declined payment (payment history and `GET /payment-requests/{id}`)?
11. Official sandbox test cards for success and for decline.
12. Can the hosted payment page hide or make optional the billing ZIP and country fields on the Start plan?
13. The docs say the result is a form field named `response`; the live callback is a JSON body. Which is correct, and can the callback be signed?
14. Gateway: does the Payment Component ask for billing ZIP or country (and can they be hidden)? Which currencies (GBP)? Does it count as PCI SAQ A? Are webhooks retried? Which sandbox host and decline test cards?

## Sources

- https://docs.kurv.app/
- https://docs.kurv.app/page/how-to-connect-a-merchant-account
- https://github.com/Paysley/kurv-woocommerce
- https://kurvpay.com/products/merchant-portal/
- https://kurvpay.com/industries/field-services/
- https://kurvpay.com/solutions/mobile/
- https://www.top10.com/merchant-services/reviews/kurv
- https://www.comparisun.com/credit-card-processing/reviews/kurv-merchant-services-review-pricing-features-is-it-worth-it/
- https://www.capterra.co.uk/software/1111077/Kurv
- https://kurvpay.com/developers/
- https://kurvpay.com/solutions/integrations/
- https://kurvpay.com/products/payment-gateways/
- https://kurvpay.com/solutions/online/
- https://kurvpay.com/products/invoicing/
- https://kurvpay.com/blog/how-to-accept-digital-wallet-payments/
- https://docs.paymentenablement.com/ (Gateway: quick-start, getting-your-api-keys, payment-component)
- https://developer.kurv.app/my_app
