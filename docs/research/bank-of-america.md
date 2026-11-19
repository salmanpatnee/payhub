# Bank of America Merchant Services: research

Date: 2026-11-17. Branch: `feature/bank-of-america-provider`.
Status: research only. Nothing has been tested against a real sandbox yet (the sandbox sign-up was down for maintenance).

## Short answer

Yes, we can add Bank of America as a new provider. Best fit: **Microform** (card fields in a Bank of America iframe) plus the **REST charge API**. Technical confidence is about 85%. Open items are listed at the end.

## What it is

- Bank of America Merchant Services sells a "Bank of America Gateway". The developer portal is CyberSource/Visa Acceptance based (same API paths, same Flex Microform product).
- Online rate on the marketing page: 2.99% + 30 cents. Same day funding for qualified accounts.
- A Bank of America business checking account is required. Funds settle into it. Merchant must be enrolled in Business Advantage 360 (BA360).
- The marketing page ([business.bankofamerica.com/en/merchant-services](https://business.bankofamerica.com/en/merchant-services)) gives no technical detail. The real docs are on the developer portal and on Merchant Help.

## Integration methods (official: Gateway Integrations Overview)

| Method | Type | Card data goes to | PCI | Key type |
|---|---|---|---|---|
| Hosted Payments Page (HPP) | Hosted by Bank of America | Bank of America directly | SAQ A | Secure Acceptance access key + secret key |
| Checkout API | Our own page posts to Bank of America | Bank of America directly (our page renders the form) | SAQ A-EP | Secure Acceptance access key + secret key |
| Microform | Embedded Bank of America iframe (card number and CVV) | Bank of America iframe | SAQ A | Shared Secret |
| CNP Toolkit (REST API) | Direct API | Our server | SAQ D | Shared Secret |

Notes:
- All four need a developer and a custom (non-third-party) cart.
- HPP and Checkout API: sale and authorization only. No capture, refund, void or reversal. Recurring is "TBD". Digital wallets not supported. The Secure Acceptance profile page says 3D Secure is "not yet available".
- Microform: supports capture, credit (refund), void and reversal as follow-up REST calls.
- The docs warn that an integration "should not be used with payment plugins". Plugins are not relevant to PayHub anyway.
- The developer portal also lists Unified Checkout, Click to Pay, Apple Pay, Google Pay, Samsung Pay, Pay by Link, Invoicing and Recurring Billing. We could not read those docs (404 errors).

## Chosen method: Microform + REST charge

Why two parts: Microform only collects the card safely and returns a one-time token. It cannot take the payment. The REST charge call is where our server uses the token to charge, and later refunds and looks up payments.

Why this one: card numbers stay in Bank of America's iframe (SAQ A) and refunds, voids and captures work. HPP cannot refund. Checkout API is SAQ A-EP. CNP puts card numbers on our server (SAQ D).

### Flow (Flex Microform Developer's Guide, v2)

1. Our server calls `POST /microform/v2/sessions` with the allowed card brands and the pay page origin (hard coded on the server). It gets a signed "capture context" token, valid 15 minutes.
2. The pay page loads the Microform script from Bank of America (`flex.merchant-services.bankofamerica.com`, or `testflex.` for test).
3. The page mounts two iframes: card number and CVV. Expiry, name and billing address are our own fields.
4. The customer pays. `createToken` returns a one-time "transient token" (a JWT).
5. Our server checks the token signature against the public key from `/flex/v2/public-keys/{kid}`.
6. Our server charges with `POST /pts/v2/payments` using `tokenInformation.transientTokenJwt`, the amount and currency from our own `Payment` record.
7. Follow-ups: `/pts/v2/payments/{id}/captures`, `/reversals`, `/refunds`. Lookup: `GET /pts/v2/payments/{id}`.
8. The token works for 15 minutes or one successful authorization.

### Required fields on the charge (from the guide)

Amount, currency, email, bill-to first name, last name, address, city, state, postal code, country, and ship-to first name, last name, address, city, state, postal code, country. Plus the token. Our pay pages do not collect all of this today (see open items).

### Hosts

| What | Test | Production |
|---|---|---|
| API | `apitest.merchant-services.bankofamerica.com` | `api.merchant-services.bankofamerica.com` |
| Microform script | `testflex.merchant-services.bankofamerica.com` | `flex.merchant-services.bankofamerica.com` |

## Credentials (what PayHub needs per account)

Unlike Stripe there is no public key. The browser only gets the one-time capture context from our server.

- Merchant ID (MID)
- Shared Secret Key ID
- Shared Secret (store encrypted, not mass assignable)

Plus settings, not credentials: environment (sandbox or production), and currency if the account is single currency.

### How to get them (official docs)

- **Shared Secret Key ID and Shared Secret**: log in, go to Key Management (menu is named "Payment Configuration" in the developer guide and "Payment Acceptance Configuration" in Merchant Help), click + Generate key, choose "REST - Shared Secret", click Generate key, then Download key. The guide says "The Key value is your key ID and the Shared Secret value is your shared secret key." The Merchant Help article says to keep the Key Generation window open to copy both values. The docs do not say whether the secret can be viewed again later, so copy both at generation time.
- **Merchant ID**: the docs say the MID identifies you and that "the organization ID is your MID", but no page names the screen where it is shown. Ask Bank of America, or check the sign-up email.
- Keys expire every 3 years.
- Microform uses a Shared Secret key (Security Keys page, and the Shared Secret article).
- Requests are signed with a short-lived JWT (`exp` within 2 minutes of `iat`). From Feb 2026 message-level encryption (MLE) is required, by Sept 2026. The older HTTP Signature method is being retired. The CyberSource REST client SDKs (PHP included) build the JWT and handle MLE. Use the SDK, not hand-rolled signing.

## Sandbox / testing

- Sign-up page: [developer.merchant.services.bankofamerica.com/hello-world/sandbox.html](https://developer.merchant.services.bankofamerica.com/hello-world/sandbox.html). Steps from the REST guide: fill the form, follow the "Merchant Registration Details" email to set a username and password (12 to 50 characters), then log in to BA360 with the passcode from the "Bank of America Identification Code" email.
- **On 2026-11-17 the page redirected to a "page under maintenance" notice.** It may also be blocked on the company network (proxy error). Fallback: ask the Bank of America rep for a test account, or call 855.225.9303.
- Test and live are separate environments with separate logins and keys. Test keys are made in the test environment ("DCE"), then new keys are made in the live account for go-live. We found no test-mode switch inside the live dashboard (not ruled out).
- Mixing sandbox and production keys returns a 401 error.
- Sandbox cannot process live payments. No load testing. No simulated gateways or processors. Test cards are in the [testing guide](https://developer.merchant.services.bankofamerica.com/hello-world/testing-guide.html).
- Going live: production Merchant ID, signed contract, then a MID Activation request in BA360 (Support Cases > MID Configuration Request, up to 3 business days). A sandbox MID can be reused as production if chosen.

## Fit with PayHub (draft design, to go in a spec)

Follow the NMI pattern (`docs/specs/0012-nmi-provider/`):

- New enum value, account table and model, admin CRUD, `BankOfAmericaClient` as the only class that knows Bank of America field names.
- Pay page component, charge endpoint with consent and a rate limiter, Bank of America hosts added to the CSP on this pay page only.
- Scoped exception to "webhooks only" status (same family as Clover, MPGS, Authorize.net, Kurv, NMI): a resolver is the only status writer, and `completed` needs a read-back (`GET /pts/v2/payments/{id}`) with exact amount and currency match. The charge reply alone never completes a payment.
- Double charge guard: per payment lock, a pause key set before the charge call and kept on an unclear outcome, no new charge while an attempt is approved or unclear.
- Attempt rows for admins: diagnostics only, never the token or card values.
- Parity work for all providers: CSV export, dashboard and filters, receipts.

## Open items (ask Bank of America sales)

1. Is 3D Secure available with Microform? (Not mentioned in the Microform guide. HPP and Checkout API say it is not yet available.)
2. Which currencies are supported? Is GBP supported? (Only USD appears in examples.)
3. Do webhooks exist for this gateway? (None found. We would rely on read-back lookups plus a sweep.)
4. Are Apple Pay and Google Pay possible with Microform? (Gateway docs say digital payments are not currently supported. The marketing page says wallets are accepted. Unified Checkout may be the route, but its docs returned 404.)
5. Can the ship-to fields be dropped? Our pay pages do not collect them. Billing address fields would also need to be added to the pay page.
6. Where is the Merchant ID shown, and can the Shared Secret be viewed again after generation?
7. Can test keys be made from a live account, or is a separate test account needed? When is the sandbox sign-up back?
8. Are the two Key Management menu names ("Payment Configuration" vs "Payment Acceptance Configuration") the same place?
9. Which JWT sign-in applies to Microform: shared secret only, or a certificate (.p12)?

## Sources

- [Gateway Integrations Overview](https://merchanthelp.bankofamerica.com/Bank_of_America_Gateway_Integrations_Overview)
- [Transaction Support by Integration Method](https://merchanthelp.bankofamerica.com/Bank_of_America_Gateway_Transaction_Support_by_Integration_Method)
- [Security Keys](https://merchanthelp.bankofamerica.com/security-keys)
- [Shared Secret key generation](https://merchanthelp.bankofamerica.com/Shared_Secret_security_key)
- [Gateway Setup](https://merchanthelp.bankofamerica.com/Bank-of-America-Gateway-Setup)
- [Secure Acceptance Profile](https://merchanthelp.bankofamerica.com/Secure-Acceptance-Profile)
- [Checkout API Developer's Guide](https://merchanthelp.bankofamerica.com/Checkout_API_Developer_s_Guide)
- [Flex Microform Developer's Guide (PDF)](https://merchanthelp.bankofamerica.com/kb/upload_files/fb452a4e2d764731a8e913786d238b06.pdf)
- [REST getting-started guide](https://developer.merchant.services.bankofamerica.com/docs/bofa/en-us/platform/developer/all/rest/rest-getting-started.md)
- [Developer portal docs hub](https://developer.merchant.services.bankofamerica.com/docs.html)
- [Testing guide](https://developer.merchant.services.bankofamerica.com/hello-world/testing-guide.html)

## Not read / unconfirmed

- Unified Checkout, Click to Pay, webhooks and Payer Authentication docs (404 or not fetched).
- The Checkout API PDF text and the CNP Toolkit guide.
- Parts of the REST guide were read only as a summary.
- Context7 had no Bank of America or CyberSource library, so everything comes from web pages and the Microform PDF.
- Docs are dated Dec 2025 to Feb 2026 and may have changed.
