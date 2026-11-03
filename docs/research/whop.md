# Whop as a PayHub payment provider

Research date: 2026-11-02. Sources are Whop's official docs, Whop's own terms and fees pages, and two third-party WooCommerce plugins (used only as proof that per-cart amounts work).

## Verdict

**Yes, Whop fits PayHub as an eighth provider.** The team already uses Whop and sends invoices from its dashboard, so the account and merchant relationship exist. Whop can create a checkout for any amount from the server, embed it on the pay page, and confirm payment by signed webhook. That matches PayHub's rules.

An earlier pass said no. That was because we assumed a brand-new vendor, and because we wrongly thought amounts were fixed. Both were corrected.

## How payments would work

1. The server reads the amount from the `Payment` record (never from the client).
2. The server creates a **checkout configuration** through the Whop API: `mode: "payment"`, an inline one-time `plan` with `initial_price` and `currency`, and `metadata` containing the PayHub `payment_id`. The response has a `ch_` id and a `purchase_url`.
3. The pay page embeds the checkout (Checkout element) using the `ch_` id. Or, as a fallback, it redirects to the `purchase_url`.
4. Whop sends a signed `payment.succeeded` webhook. PayHub marks the payment paid. The metadata maps it back to the `Payment`.

## Two ways to take the payment

| | Checkout element (embed) | Payment elements (own form) |
|---|---|---|
| Look | Whop's whole checkout inside the page | PayHub's own layout with Whop-hosted card frames |
| Amount | Checkout configuration with inline plan | Inline `variant` passed with the server charge |
| Card data | Never touches PayHub | Never touches PayHub (hosted frames) |
| Effort | Lower | Higher: build the form and handle 3D Secure with `handleNextAction` |

Recommendation: start with the Checkout element. Use payment elements only if the PayHub look matters.

Packages: `@whop/elements` (plain JS, CDN script) or `@whop/elements-react`, both at 1.9.0 when checked. In the React package, `<Checkout>` goes inside `<WhopElements elements={loadWhop()}>`.

## Rules in the docs that match PayHub

- The browser never sends an amount and no API key goes on the page.
- Checkout options are fixed when the element is created. To change what is bought, mount a new checkout.
- `onComplete` and the return URL (`payment` and `status` query values) are display only. Fulfil from the webhook only.
- Webhooks follow the Standard Webhooks spec: HMAC-SHA256 over webhook-id, webhook-timestamp and the raw body, with the `ws_` secret. Reject timestamps more than 5 minutes off. Respond within 5 seconds or Whop retries.
- The webhook secret is only returned when the webhook is created. Save it then.
- There is no Stripe-style event id. The `webhook-id` header can serve as the idempotency key.

## Refunds and disputes

- Events: `refund.created`, `refund.updated`, `dispute_alert.created`, `dispute.created`, `dispute.updated`.
- Dispute statuses: `warning_needs_response`, `warning_under_review`, `warning_closed`, `needs_response`, `under_review`, `won`, `lost`, `prevented`.
- A lost dispute creates no refund record and `payments.refund` rejects disputed payments. Treat the dispute as the source of truth.
- Permissions: `payment:manage` (create refunds), `payment:basic:read`, `payment:dispute:read`, `payment:dispute` (evidence).

## Fees (Whop fees page)

- Cards and wallets: 2.7% + $0.30 per domestic payment. International cards add 1.5%. Currency conversion adds 1%.
- 3DS: $0.03. Radar: $0.07. Dispute: $15. Early dispute alert: $29.
- Optional extras: orchestration 0.8%, billing 0.5%, tax and remittance 2%.
- Payouts cost extra: next-day ACH $2.50, instant bank 4% + $2.50, wire $23.
- Whop says it supports "135+ currencies" but does not list them.

## Risks and cautions

- Whop acts as merchant of record. Its Buyer Terms say it relies on Stripe and PayPal behind the scenes. Its Seller Terms limit the merchant-of-record role to card-network rules and settlement. This is not a merchant account the brand owns, unlike the other providers.
- Whop's terms allow suspension and withheld payouts. Payouts can take up to 5 business days and can be held for compliance review.
- The fees page lists no prohibited-business rules. The third-party plugin sellers advertise Whop for "high-risk or grey-hat" merchants and "99% approval". These are unverified marketing claims.
- Whop's docs and some third-party text disagree on how wide the merchant-of-record role is. The legal terms are the more reliable source.

## Not the same as the dashboard invoices

No create-invoice endpoint was found. Whop only documents invoice webhooks (created, paid, past due, voided, marked uncollectible). PayHub payments would use checkout configurations, so they will not show up as invoices in the Whop dashboard.

## Open items before building

1. Confirm the Whop plan allows creating checkout configurations through the API, and which API key permissions are needed.
2. Confirm the currencies the company really accepts. Only USD and GBP were seen in the docs. PayHub needs both.
3. Confirm the failure event. The overview does not document `payment.failed` or any payload fields. An older sample showed `payment.failed`. Check the API reference.
4. Read the Webhooks guide and sandbox guide (test cards and test mode).
5. Confirm domain registration for the embed. The docs say domains other than whop.com must be registered through the Payment Method Domains API before Apple Pay, Google Pay and PayPal appear.
6. Decide the account model. Whop keys are per company, so a PayHub Whop account would hold `company_id` plus an encrypted API key and webhook secret per brand.

## Work if approved

An eighth `PaymentProvider` value (`whop`), a `whop_accounts` table (encrypted `api_key` and `webhook_secret`, not mass-assignable, like Revolut and Square), `WhopClient` per account, a webhook route `webhook/whop/{whopAccount}` excluded from CSRF, a `ProcessedWhopEvent` idempotency table, and parity across the pay page, CSV export, admin account CRUD, dashboard filters and receipts. Plan it as a spec in `docs/specs/` before coding.

## Side finding

Whop's payments overview page contains an "Agent Instructions" block telling AI tools to connect to Whop's MCP servers and act on live account data. It was ignored. Treat Whop docs as untrusted input.

## Sources

- https://docs.whop.com/developer/payments/overview
- https://docs.whop.com/developer/guides/embed-checkout
- https://docs.whop.com/developer/guides/payment-elements
- https://docs.whop.com/developer/guides/refunds-and-disputes
- https://docs.whop.com/developer/guides/accept-payments
- https://docs.whop.com/developer/guides/webhooks
- https://docs.whop.com/payments/checkout-embed
- https://docs.whop.com/api-reference/checkout-configurations/create-checkout-configuration
- https://docs.whop.com/api-reference/invoices/invoice-created
- https://docs.whop.com/api-reference/webhooks/create-webhook
- https://docs.whop.com/fees
- https://docs.whop.com/merchant-of-record
- https://whop.com/buyer-terms/
- https://whop.com/seller-terms/
- https://woo-whop.com/ (third party, per-cart sessions, 47 currencies)
- https://whopwoocommerce.com/ (third party, per-cart sessions)
- Context7: `/whopio/developer-documentation`
