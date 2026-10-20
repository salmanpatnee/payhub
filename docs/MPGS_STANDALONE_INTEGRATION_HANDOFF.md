# MPGS Standalone PHP Integration: Research Handoff

**Audience:** the team or app that will integrate MasterCard Payment Gateway Services (MPGS) into a non-WordPress PHP application.
**Source:** analysis of the "MPGS Payment Gateway for WooCommerce" plugin (v3.1.1, `mpgs-payment-gateway.php`) plus Mastercard Gateway documentation (Context7 index and web search).
**Date:** 2026-10-17
**Status:** research only. No code was changed. Nothing here has been run against a real MPGS sandbox.

Legend: **[Verified in code]** read directly in this repo. **[Verified in docs]** found in Mastercard documentation. **[Unverified]** inferred or not confirmed. Test it in the sandbox first.

---

## 1. Summary

- The plugin is a thin wrapper around the MPGS REST API. The real gateway logic is about 150 lines. WordPress and WooCommerce only supply settings storage, URL routing, HTTP calls, nonces and order state.
- The same integration works in any PHP app. You need an MPGS merchant account, a Merchant ID, an Integration Authentication Password, the gateway host and an API version. You do not need WordPress, WooCommerce, or any MPGS PHP SDK.
- **Do not copy the plugin file into the other app.** `MPGS_Gateway` extends `WC_Payment_Gateway` and cannot run without WooCommerce. Reimplement the HTTP contract as a small library. Section 6 shows what to reuse and what to rewrite.
- Two integration styles are possible. Both are SAQ-A target per Mastercard:

| Style | Customer experience | 3DS | Effort |
|---|---|---|---|
| **A. Hosted Checkout** (what the plugin does) | Redirect to an MPGS page, or MPGS form embedded in a `<div>` | MPGS handles it | Lowest |
| **B. Hosted Session** (not in the plugin) | Your own payment page with gateway-hosted card fields (Stripe Elements feel) | **You implement it** (ThreeDS JS or REST) | Moderate |

---

## 2. How it works today in WordPress

### 2.1 Architecture [Verified in code]

One main class, `MPGS_Gateway extends WC_Payment_Gateway` (`mpgs-payment-gateway.php:437`). Everything else is add-on code:

| Piece | File | Role | Needed standalone? |
|---|---|---|---|
| Gateway core | `mpgs-payment-gateway.php` | Session create, callback verify, refund | Logic only |
| Blocks support | `includes/class-mpgs-blocks-support.php`, `assets/js/mpgs-blocks.js` | Block checkout UI | No |
| Onboarding, review and Pro notices | `includes/class-mpgs-{onboarding,pro-notice,review-notice}.php` | Admin UI | No |
| Pro: capture | `includes/class-mpgs-capture__premium_only.php` | AUTHORIZE then CAPTURE | Concept only |
| Pro: branding | `includes/class-mpgs-branding__premium_only.php` | `interaction.merchant.*` | Concept only |
| Pro: alerts, log viewer, surcharge | `includes/*__premium_only.php` | Extras | No |
| Freemius SDK | `freemius/` | Licensing | No |
| Browser JS | `assets/js/mpgs-payment.js` | `Checkout.configure` then `showPaymentPage` | Copy the idea (about 15 lines) |

Registration: the gateway is added through `woocommerce_payment_gateways` (L380) and defined on the `plugins_loaded` hook via `mpgs_init()` (L1893). The callback is `add_action('woocommerce_api_mpgs', …)` (L571), which maps `?wc-api=mpgs` to `process_response()`.

### 2.2 Payment flow (Hosted Checkout, redirect) [Verified in code]

```
Browser            WooCommerce/plugin                     MPGS
   | checkout submit    |                                    |
   |------------------->| process_payment()  L1032           |
   |                    |-- POST .../session --------------->|  INITIATE_CHECKOUT (v63+) or CREATE_CHECKOUT_SESSION (<63)
   |                    |<-- session.id, successIndicator ---|
   |                    | store successIndicator on order meta (L1188)
   |<-- redirect to order-pay page ?sessionId=... (L1192)    |
   | load checkout.js from MPGS host (payment_scripts L832)  |
   | Checkout.configure({session:{id}}); Checkout.showPaymentPage()   (assets/js/mpgs-payment.js)
   |--------------- customer pays + 3DS on the MPGS page --->|
   |<-- 302 to returnUrl ?resultIndicator=...&order_id=...    |
   |------------------->| process_response()  L1261          |
   |                    | verify nonce; resultIndicator == stored successIndicator (L1309)
   |                    |-- GET .../order/{id} -------------->|
   |                    |<-- transaction[]                    |
   |                    | last txn result==SUCCESS && receipt -> payment_complete()
```

### 2.3 MPGS API calls the plugin makes [Verified in code]

Base path: `{host}api/rest/version/{v}/merchant/{MID}/`

| Operation | Method and path | Code |
|---|---|---|
| Create session | `POST session` | L1151 |
| Verify payment | `GET order/{orderId}` | L1318 |
| Full refund ("void") | `DELETE order/{id}/transaction/{txnId}` in the plugin (L1491). **Do not copy:** the gateway docs show `PUT` with `apiOperation: VOID` and `targetTransactionId` (spec 0005 research). Unconfirmed until the sandbox spike. | L1491 |
| Partial refund | `PUT order/{id}/transaction/refund-{time}` with `apiOperation: REFUND` | L1499 |
| Capture (Pro) | `PUT order/{id}/transaction/capture-{time}` with `apiOperation: CAPTURE` | `class-mpgs-capture__premium_only.php` |

### 2.4 Authentication [Verified in code]

HTTP Basic. Username is `merchant.<MID>`. Password is the Integration Authentication Password (L1154). The password is sent server-side only and never reaches the frontend. It is not the MPGS portal login. Generate it in Merchant Administration under Integration Settings.

### 2.5 Version branching in `process_payment` [Verified in code]

- v62 and above: `initiator.userId`. Older: top-level `userId`.
- v52 and above: send `interaction.operation` (PURCHASE or AUTHORIZE).
- v63 and above: `apiOperation = INITIATE_CHECKOUT`. Older: `CREATE_CHECKOUT_SESSION`.
- v62 and below: `displayControl.orderSummary = HIDE`.
- SDK URL (L849): v63 and above `{host}static/checkout/checkout.min.js`. Older `{host}checkout/version/{v}/checkout.js`.
- Lightbox mode is unsupported on v63 and above. Redirect (`showPaymentPage`) works on all versions.

### 2.6 Session request body the plugin sends (v66 shape) [Verified in code]

```json
{
  "apiOperation": "INITIATE_CHECKOUT",
  "initiator": { "userId": "<user>" },
  "order": { "id": "<orderId>", "amount": "100.00", "currency": "USD",
             "description": "Pay for order #<id> via <title>",
             "customerOrderDate": "YYYY-MM-DD", "customerReference": "<user>", "reference": "<orderId>" },
  "transaction": { "reference": "TRF <orderId>" },
  "customer": { "email": "...", "firstName": "...", "lastName": "...", "phone": "..." },
  "interaction": {
    "operation": "PURCHASE",
    "returnUrl": "https://site/?order_id=<id>&wc-api=mpgs&mpgs_nonce=<nonce>",
    "merchant": { "name": "..." },
    "displayControl": { "billingAddress": "HIDE", "customerEmail": "HIDE", "shipping": "HIDE" }
  }
}
```

The billing address block is commented out in the plugin (L1058–1068); the reason is unknown.

---

## 3. WordPress/WooCommerce dependencies to replace

| Plugin call | Where | Standalone replacement |
|---|---|---|
| `wc_get_order()`, `$order->get_total()/get_currency()/get_user_id()/get_billing_*()` | L1034 and others | Your own order model or table |
| `update_meta_data()` / `get_meta()` (`mpgs_successIndicator`, `_mpgs_transaction_id`, `_mpgs_transaction_receipt`, …) | L1188, L1303, L1339 | Columns or a `payments` table |
| `payment_complete()`, `add_order_note()`, `update_status()` | L1368–1370 | Your order state machine and audit log |
| `wp_remote_post/get/request`, `is_wp_error`, `wp_remote_retrieve_*` | L1151, L1318, L1491 | cURL or Guzzle |
| `wp_create_nonce` / `wp_verify_nonce` | L1085, L1266 | Order-bound HMAC token (see §8) |
| `add_query_arg`, `home_url`, `get_return_url`, `wc_get_checkout_url`, `wp_redirect`, `wp_die` | L1086, L1385 | Your router and responses |
| `woocommerce_api_mpgs` (`?wc-api=mpgs`) | L571 | A route like `/payment/mpgs/return` |
| `WC_Payment_Gateway`, `init_form_fields`, `get_option` | L437, L906 | Config file or env vars |
| `wp_enqueue_script`, `wp_localize_script`, `plugins_url` | L856–883 | A `<script>` tag in your template |
| `__()`, `esc_*`, `sanitize_text_field`, `absint` | everywhere | `htmlspecialchars`, `(int)`, your i18n |
| `wc_get_logger`, `do_action('mpgs_*')`, `apply_filters('mpgs_*')` | L589, L1141 | PSR-3 logger, events or callbacks |
| `$woocommerce->cart->empty_cart()` | L1369 | Your cart service |
| HPOS and Blocks declarations, admin metabox | L315–318, L637+ | Delete |

---

## 4. Prerequisites and configuration

1. **MPGS merchant account** from your acquiring bank. Test and live are separate, with separate Merchant IDs.
2. **Gateway host** with trailing slash, e.g. `https://ap-gateway.mastercard.com/`. It depends on the bank's region.
3. **Merchant ID** and **Integration Authentication Password** (see §2.4).
4. **API version**, 49 to 66 in the plugin (66 recommended). Docs examples go up to 100. Session creation and `session.js` versions must match (Hosted Session).
5. **Currency match.** The store currency must be enabled on the merchant account. CLAUDE.md calls this the most common failure.
6. **HTTPS** with TLS 1.2 or higher and a public return URL. No client certificates and no MPGS PHP SDK are needed.
7. **Enable features at the bank:** Hosted Session, 3DS and its JS library, AUTHORIZE, and webhooks if you want them. Ask which are on for your account.
8. **Store credentials in env or a secret store.** Never in frontend code, version control, or logs.

---

## 5. Integration styles for the standalone app

### 5.A Hosted Checkout (redirect or embedded) [Verified in docs for embedded]

Same server flow as the plugin. Two display options:
- `Checkout.showPaymentPage()` redirects to a gateway page.
- `Checkout.showEmbeddedPage('#embed-target')` renders the MPGS form in a div on your page. Docs note the cancel callback works only with the payment page, not the embedded page. Merchant branding can be added around the embedded payment section.

Server steps:
1. `POST …/session` with the body in §2.6. Store `successIndicator` (and `session.version`) against your order.
2. Render a page that loads the SDK (URL per version, §2.5), calls `Checkout.configure({session:{id}})`, then shows the page.
3. Return handler: check the token, then `resultIndicator === successIndicator` (use `hash_equals`).
4. `GET …/order/{id}`, then verify the items in §8.
5. Mark the order paid in one DB transaction.

### 5.B Hosted Session [Verified in docs]

Card fields are gateway-hosted iframes inside your own form. Card data never reaches your server. Mastercard states the target PCI scope is **SAQ-A** when you use hosted fields.

Sequence:

```
1 server  POST  /api/rest/version/V/merchant/MID/session            {"session":{"authenticationLimit":25}}
          -> session.id, version, updateStatus
2 server  PUT   .../session/{sessionId}                             {"order":{"amount":100.00,"currency":"USD"}}
3 page    load session.js + three-ds.min.js; PaymentSession.configure(...)
4 payer   pay(): PaymentSession.updateSessionFromForm('card')
          -> formSessionUpdate: ok | fields_in_error | request_timeout | system_error
5 page    ThreeDS.configure({merchantId, sessionId, containerId:"3DSUI", callback, configuration:{wsVersion}})
6 page    ThreeDS.initiateAuthentication(orderId, transactionId, cb)
            gatewayRecommendation: PROCEED -> next
                                   RESUBMIT_WITH_ALTERNATIVE_PAYMENT_DETAILS -> ask for another card
7 page    ThreeDS.authenticatePayer(orderId, transactionId, cb, {fullScreenRedirect, billing})
8 server  PUT   .../order/{orderId}/transaction/{newTxnId}
          {"apiOperation":"PAY","authentication":{"transactionId":"<same as 6-7>"},
           "session":{"id":"<sessionId>"},"transaction":{"reference":"<orderId>"}}
9 server  verify result, then GET .../order/{orderId} and check status/amount/currency
```

Page-side snippets (from Mastercard's guide):

```html
<script src="https://<host>/form/version/<v>/merchant/<MID>/session.js"></script>
<style id="antiClickjack">body{display:none !important;}</style>
<script>
if (self === top) { var a = document.getElementById("antiClickjack"); a.parentNode.removeChild(a); }
else { top.location = self.location; }

PaymentSession.configure({
  session: "<your_session_ID>",
  fields: { card: { number: "#card-number", securityCode: "#security-code",
                    expiryMonth: "#expiry-month", expiryYear: "#expiry-year",
                    nameOnCard: "#cardholder-name" } },
  frameEmbeddingMitigation: ["javascript"],
  callbacks: {
    initialized: function (r) { /* r.status */ },
    formSessionUpdate: function (r) { /* ok | fields_in_error | request_timeout | system_error */ }
  },
  interaction: { displayControl: { formatCard: "EMBOSSED", invalidFieldCharacters: "REJECT" } }
});
function pay() { PaymentSession.updateSessionFromForm('card'); }
</script>
```

Key rules:
- **Clickjacking defence is mandatory.** Declare what you use in `frameEmbeddingMitigation`: `javascript` (frame-breaker script), `x-frame-options` (header), `csp` (`frame-ancestors`).
- Session update statuses: `ok` (masked card details only), `fields_in_error` (`errors.cardNumber`, `expiryMonth`, `expiryYear`, `securityCode`), `request_timeout` and `system_error` (`errors.message`). Only `session.updateStatus === SUCCESS` lets you proceed.
- Set the amount and currency on the session **from the server**, never from the browser.
- `orderId` and `transactionId` are generated by you. Use the same pair in `initiateAuthentication`, `authenticatePayer` and the final PAY.
- The final PAY must not repeat 3DS fields. The gateway looks up the stored result via `authentication.transactionId`.
- Hosted-session helpers: `validate()`, `setFocusStyle()`, `setHoverStyle()`, `setPlaceholderStyle()`, callbacks `onChange`, `onValidityChange`, `onCardBINChange`, `onCardTypeChange`. Multiple card blocks use a `scope` argument.
- 3DS JS library: `…/static/threeDS/1.3.0/three-ds.min.js` with `data-error` and `data-cancel` callbacks. Default accepted version is 3DS2.

### 5.C Choosing

| | Hosted Checkout (A) | Hosted Session (B) |
|---|---|---|
| PCI target | SAQ-A | SAQ-A (hosted fields only) |
| UI control | Low (embedded mode allows branding around it) | Full |
| 3DS | Gateway | You implement |
| Plugin code you can reuse | Most of the server flow | Session creation, auth header, refund/capture |
| Effort | Lowest | About double |

Recommendation: ship A first. Add B only if the client needs a native-looking card form.

---

## 6. Reuse vs rewrite

| Component | Verdict | Detail |
|---|---|---|
| Version branching and session payload shape (L1042–1112) | **Reuse logic** | Port to a plain array builder. |
| Endpoint URLs and Basic auth recipe (L1146–1154, L1315) | **Reuse logic** | Facts of the MPGS API. |
| SDK URL by version (L849–853) and `mpgs-payment.js` | **Reuse logic** | Trivial. |
| `mpgs_get_error_message` map (L737) | **Reuse** | Strip `__()`. |
| `mpgs_convert_country_code` (L1630) | **Reuse** | Pure data. Needed only if you send billing addresses. |
| `mpgs_get_credentials_for_currency` idea (L777) | **Reuse as design** | Per-currency credential routing. |
| Refund and capture request bodies | **Reuse, verify first** | See the void caveat in §9. |
| `process_payment`, `process_response`, `process_refund` bodies | **Rewrite** | Interleaved with `WC_Order`, notices and redirects. |
| Nonce/CSRF, order notes, cart, hooks, settings, admin metabox, Blocks, onboarding, Freemius | **Drop** | WordPress-only. |
| Pro files `*__premium_only.php` | **Do not copy** | Commercial Freemius code. Reimplement the idea from MPGS docs. |

Suggested library layout:

```
src/
  MpgsConfig            host, merchantId, password, apiVersion      (from env)
  MpgsClient            createSession(), updateSession(), retrieveOrder(), pay(), authorize(),
                        capture(), refund(), void()                 (cURL/Guzzle, injectable)
  SessionRequestBuilder version branching
  CheckoutAssets        sdkUrl by version, JS snippet
  ResultVerifier        indicator + amount + currency + status + transaction selection
  ErrorMap              gateway code -> customer message
  Contracts/OrderRepositoryInterface, LoggerInterface
```

The host app implements the repository: save the indicator, mark paid, record transaction id and receipt.

---

## 7. Workflow details for the standalone app

- **Initialise:** build `MpgsConfig` from env. Never expose the password or Merchant secrets to the browser.
- **Authenticate requests:** `Authorization: Basic base64("merchant.<MID>:<password>")`, `Content-Type: application/json`.
- **Initiate a transaction:** create the session server-side, store the indicator, return the session id to the page.
- **Callbacks:** a return route for the customer, plus a webhook endpoint and a reconciliation job (see below).
- **Process responses:** check HTTP status and the `result` field, then business checks (§8).
- **Errors:** map `response.gatewayCode` to customer messages (`mpgs_get_error_message`). Log `error.explanation` server-side only. Use short timeouts. Do not blindly retry session creation, capture or refund.
- **Webhooks:** the plugin has none. It depends on the customer's browser returning. A customer who closes the tab after paying leaves a paid but unrecorded order. Add a webhook (enable "Webhook notifications" in Merchant Admin, validate its secret header) **and** a cron job that polls `GET order/{id}` for orders pending longer than N minutes.

---

## 8. Hardening: do not copy these weaknesses from the plugin [Verified in code]

1. **No amount or currency check on verify.** L1318–1334 checks only `result == SUCCESS` and a receipt. Check status, amount and currency against your own order.
2. **No transport error handling on the verify GET.** `$response_json['body']` at L1325 fatals on a `WP_Error`.
3. **Transaction picked as "last in array"** (L1328). Select by type and target.
4. **No idempotency guard.** A repeated callback re-runs `payment_complete`. Lock the order row and check if already paid.
5. **Weak comparison.** `==` at L1309. Use `hash_equals`.
6. **Nonce is global, not order-bound** (L1085). Use an HMAC of the order id with a secret.
7. **Order id reused as the MPGS `order.id`** (L1048). A retry after a failed attempt can collide. Use a unique attempt id such as `<orderId>-<n>`, and keep the mapping.
8. **Never log** passwords or card data. Do not show raw gateway errors to customers.

---

## 9. Limitations, licensing and open items

**Licensing**
- The plugin is **GPL v3** or later. Reusing its code in an app you distribute makes that code GPL. If the app is internal only, adaptation is fine. To avoid the question, write a clean-room implementation from Mastercard documentation. The API contract is not copyrightable.
- Pro files are separately licensed Freemius code. Do not copy them.
- The plugin credits Ali Basheer for v1.x.
- Trademark: the WordPress.org rename was for trademark compliance. Do not name your library "MasterCard …".

**Technical and provider risks**
- **Void implementation is wrong in the plugin.** The plugin voids with `DELETE …/transaction/{id}` (L1491), which its e2e mock suite cannot validate. The gateway documentation shows `PUT` with `apiOperation: VOID` and `targetTransactionId`; what the URL id means is still ambiguous. Confirm against the sandbox before porting. PayHub spec 0005 keeps refunds and voids out of scope. Line references point to the plugin repo (`D:\code\woo-mpgs`), not this file. [Partly verified]
- **Doc URL discrepancy.** A Mastercard sample shows the AUTHORIZE call under `/form/version/{v}/merchant/{MID}/order/{id}/transaction/{id}`. Treat that as a likely doc typo and use the REST path `/api/rest/version/{v}/merchant/{MID}/order/{id}/transaction/{id}`. Confirm in the sandbox. [Unverified]
- **Hosted Session session-update step.** The "update the session with order amount and currency" call was seen in Mastercard guides for 3DS JS and related flows. Verify the exact fields against your API version. [Unverified]
- **Not testable end-to-end locally.** The plugin's mock suite covers about 90% of PHP logic. Real 3DS, SDK rendering and live card authorisation need the bank sandbox. Plan one real test before launch.
- Lightbox is unsupported on API v63 and above.
- Host, enabled currencies, features (AUTHORIZE, Hosted Session, webhooks) and API version are per-bank. Sandbox behaviour can differ from production.
- PCI: SAQ-A is Mastercard's stated target for both styles when you use gateway-hosted fields and never handle card data yourself. Your assessor or bank makes the final call.

---

## 10. Effort estimate

| Scope | Effort |
|---|---|
| Hosted Checkout MVP: session, redirect, verify, mark paid | 2–3 days |
| Production-grade: idempotency, amount/currency checks, webhook plus reconciliation cron, refund/void, logging, tests with an HTTP mock | about 1.5–2 weeks |
| Add Hosted Session with 3DS JS | +4–6 days |
| Per-currency accounts, AUTHORIZE/CAPTURE, branding | +3–5 days |

---

## 11. Suggested next steps for the integrating team

1. Get sandbox credentials and the API version from the bank. Ask which features are enabled (Hosted Session, 3DS JS, AUTHORIZE, webhooks).
2. Decide the stack and order model, and which style (A or B) the client wants at launch.
3. Build `MpgsClient` with an injectable HTTP layer so you can test with a mock, as the plugin does with `pre_http_request`.
4. Implement the Hosted Checkout flow end to end and test against the sandbox.
5. Add webhook plus reconciliation, refunds and logging.
6. Add Hosted Session only if required. Test 3DS with the bank's 3DS test cards.

---

## 12. References

**In this repo**
- `mpgs-payment-gateway.php`: `process_payment` L1032, `process_response` L1261, `process_refund` L1454, `payment_scripts` L832, `mpgs_get_error_message` L737, `mpgs_get_credentials_for_currency` L777, `mpgs_get_payment_action` L807, `mpgs_convert_country_code` L1630
- `assets/js/mpgs-payment.js`
- `includes/class-mpgs-capture__premium_only.php`, `includes/class-mpgs-branding__premium_only.php`
- `docs/MPGS_COMPLIANCE_REQUIREMENTS.md`, `docs/TESTING_WITHOUT_MPGS.md`, `tests/e2e-mpgs.sh`

**Mastercard Gateway documentation**
- Choosing an Integration Method: https://ap-gateway.mastercard.com/api/documentation/integrationGuidelines/gettingStarted/choosingIntegrationMethod.html
- Hosted Session, Making a Payment: https://ap-gateway.mastercard.com/api/documentation/integrationGuidelines/hostedSession/makingAPayment.html
- Hosted Session, Session basics: https://ap-gateway.mastercard.com/api/documentation/integrationGuidelines/hostedSession/sessionBasics.html
- 3DS JS integration model: https://ap-gateway.mastercard.com/api/documentation/integrationGuidelines/supportedFeatures/pickAdditionalFunctionality/authentication/3DS/integrationModelJS.html
- Session SDK (v70): https://qa03.gateway.mastercard.com/api/documentation/apiDocumentation/session/version/70/api.html
- Session configure() (v73): https://qa06.gateway.mastercard.com/api/documentation/apiDocumentation/session/version/73/hs-function/configure.html
- Checkout SDK (v66): https://qa03.gateway.mastercard.com/api/documentation/apiDocumentation/checkout/version/66/api.html
- Implementing the Hosted Payment Page: https://developer.mastercard.com/mastercard-gateway/documentation/build/hosted-checkout/integrate-hosted-checkout/implementing-the-hosted-payment-page/
