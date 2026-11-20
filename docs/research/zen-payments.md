# Zen (ZEN.COM) integration research

Researched 2026-10-19. Sources: docs.zen.com (via Context7 and web fetch) and the open-source ZEN WooCommerce plugin (`plugins.svn.wordpress.org/zen-com-payments/trunk`). zenpayments.com itself returned HTTP 429 and was not read.

Status: research only. Nothing verified against a real Zen account.

## Integration types

| Type | What it is | Fits PayHub? |
|---|---|---|
| Hosted checkout | Server calls `POST /api/checkouts`, gets `redirectUrl`, customer pays on Zen's page | Yes, safest first step |
| Card widget | Iframe for card payments, for merchants who are not PCI compliant. Public docs are empty (see open questions) | Maybe, unconfirmed |
| Full API | `POST https://api.zen.com/v1/transactions` with raw card data, 3DS, tokenizing, one-click, recurring | No. Card data would touch our server (full PCI scope) |
| Plugins | WooCommerce, PrestaShop, Magento | No |

Sources: [API introduction](https://docs.zen.com/payments/api-reference/introduction), [About checkout](https://docs.zen.com/payments/checkout-integration/about-zen-checkout), [Card widget](https://docs.zen.com/payments/checkout-integration/card-widget), [About API](https://docs.zen.com/payments/api-integration/about-api), [Create purchase](https://docs.zen.com/payments/api-reference/create-purchase-transaction), [Plugins](https://docs.zen.com/payments/plugins/about-plugins).

## Hosted checkout contract

- Create: `POST https://secure.zen.com/api/checkouts` (test: `https://secure.zen-test.com/api/checkouts`).
- Body: `terminalUuid`, `amount`, `currency`, `merchantTransactionId`, optional `customer`, `items`, `billingAddress`, `shippingAddress`, `urlSuccess`, `urlFailure`, `urlRedirect`, `customIpnUrl`, `language`, and `signature`.
- Response: `{ "redirectUrl": "https://secure.zen.com/<id>" }`.
- Credentials: Terminal UUID + Paywall Secret.
- Amounts in the checkout request look like plain numbers. IPN and refund amounts are decimal strings (`"400.00"`).

Source: [Checkout purchase](https://docs.zen.com/payments/checkout-integration/purchase).

### Request signature

1. Flatten the JSON to `name=value` pairs (`billingAddress.firstName=John`, `items[0].code=x`). Skip empty values. Leave out `signature`.
2. Lowercase every pair.
3. Sort the pairs alphabetically.
4. Join with `&`.
5. Append the `paywallSecret` with no separator.
6. Hash (SHA-256) and format as `<hexdigest>;sha256`.

The docs page says "only the values are concatenated" but its examples include field names. Follow the examples and check against the sample hash before going live.

Source: [Generating a signature](https://docs.zen.com/payments/checkout-integration/signature).

## IPN (webhook)

Payload fields: `type` (`TRT_PURCHASE` / `TRT_REFUND`), `transactionId`, `merchantTransactionId`, `amount` (string), `currency`, `status`, `paymentMethod`, `customer`, `hash`.

Hash rule (found in the official WooCommerce plugin, `includes/ApiLibrary/Ipn/Ipn.php`, not in the docs):

```
hash = strtoupper( sha256( merchantTransactionId . currency . amount . status . ipnSecret ) )
```

- No separators.
- Use `amount` exactly as received (`"10.00"` and `"10"` hash differently).
- Compare with `hash_equals()`.
- The plugin rejects hashes that are not 64 uppercase hex characters.
- This formula uses the IPN Secret (API/plugin flow). Not confirmed for the hosted-checkout IPN.

Plugin behavior (`includes/Webhook/IpnWebhook.php`):
- Compares the IPN status with the status fetched from Zen's API and keeps the more advanced one (`PENDING` -> `AUTHORIZED` -> `ACCEPTED`).
- Handles `PENDING`, `AUTHORIZED`, `ACCEPTED`, `REJECTED`, `CANCELED`.
- Replies `{"status":"ok"}`. Returns 503 on processing errors so Zen retries.

## Other API endpoints

- Transaction details: `GET /v1/transactions/{id}` and `GET /v1/transactions/merchant/{merchantTransactionId}`. Docs say a transaction may stay `PENDING`, so re-request the final status.
- Refund: `POST /v1/transactions/refund` (header `request-id` 38-1024 chars, amount as decimal string).
- Payment methods: `GET https://api.zen.com/v3/terminals`.
- Full API auth: Terminal API Key in the `Authorization` header, plus `request-id`.
- Currencies include GBP and USD.

## Card widget: what is known

- Docs pages [checkout](https://docs.zen.com/payments/checkout-integration/card-widget) and [api](https://docs.zen.com/payments/api-integration/card-widget) say it is an iframe and that Zen manages all PCI data. Sections "How does it work?", "Appearance", "How to integrate?" and "Language management" are empty.
- The WooCommerce plugin's "card widget" is named `zen_payments_card_payment_in_redirect_to_zen_mode`. It creates a transaction on the `type=widget` endpoint (`includes/ApiLibrary/Transactions/WidgetTransactions.php`, key `apiKey`, channel `PCL_CARD`) and then redirects to a Zen card page with `order_id=<transactionId>`, `url_success`, `url_cancel`, `url_failure`.
- A search summary mentioned `https://cdn-secure.zen.com/zen-checkout.1.0.0.js` for an embedded flow. Not seen in code and not verified.
- Conclusion: a true embedded iframe contract is not confirmed.

## Getting a test account

No self-service sign-up was found. Access comes from Zen staff.

1. Apply as a merchant via the contact form on zen.com. Zen emails a link to a registration form. ([Registration](https://docs.zen.com/payments/checkout-integration/registration))
2. Get an account manager and ask for a test account. ([Checklist](https://docs.zen.com/payments/checkout-integration/checklist))
3. Email the account manager or integration team with: company name, address, tax ID, business representative's name, test store URL, integration type, **IP address to whitelist**, and how long you need access. ([Access to the sandbox](https://docs.zen.com/payments/sandbox/access-to-the-sandbox-1741576m0))
4. Zen replies with a phone number, 6-digit OTP and 4-digit PIN for `https://my.zen-test.com/`.
5. Collect keys in the test panel (checkout: Terminal UUID + Paywall Secret; API: Terminal API Key + IPN Secret).

Notes: sandbox is for "a selected group of merchants". Only Card and Blik work in test, refunds only on Card. Test and production credentials differ. Production stays inactive until business verification is finished.

## Fit with PayHub rules

- Per-account client: store `terminal_uuid`, `paywall_secret`, API key, IPN secret per account, all with the `encrypted` cast.
- Amount comes from the server `Payment` record, never the client.
- Status comes only from the IPN (plus an API re-fetch), never from `urlSuccess`.
- Keep amounts as integer cents. Convert once at the Zen boundary (decimal strings in IPN/refunds).
- Idempotency: a `ProcessedZenEvent` table keyed on `transactionId:status`.

## Suggested build

1. Add `zen` to the `PaymentProvider` enum.
2. `zen_accounts` table (encrypted secrets) and `payments.zen_transaction_id`.
3. `ZenClient`: sign requests, create checkout, fetch transaction, refund.
4. Route `webhook/zen/{zenAccount}`, excluded from CSRF. Verify the IPN hash, then re-fetch the transaction and trust that status.
5. Pay page that redirects to `redirectUrl`. Admin account CRUD, CSV export, dashboard and filters, receipt pages.
6. Run the provider parity check across all providers.
7. Build against mocked Zen responses until a test account exists.

## Open questions for Zen

1. Which secret signs the hosted-checkout IPN, and is the hash the same formula as above?
2. Is there a real embedded card widget, and where are its docs?
3. What is the full list of transaction statuses?
4. Are USD and GBP both enabled on our merchant account?
5. What response does the IPN endpoint need to return?
