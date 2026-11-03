# 0009. Rationale: Whop as the eighth payment provider

## Context

> ⚠️ Premise note: This feature assumes the company's Whop plan allows creating checkout configurations through the API for any amount, and that its webhooks follow the Standard Webhooks format. Neither is confirmed; the research in `docs/research/whop.md` lists them as open items. Whop's sandbox does not support Whop Elements, and whether its embedded checkout works in the sandbox is not stated. Build step 0 settles these before the checkout code is written, and any difference is fixed in one class. Also, Whop is merchant of record, so unlike the other providers the funds and payouts sit with Whop rather than a merchant account the brand owns.

PayHub is a multi brand payment hub with seven providers behind the `PaymentProvider` enum. The team already uses Whop and sends invoices from its dashboard. They want Whop payments from PayHub pay pages so that agents can create a link, the client pays on the brand page, and the payment is recorded, exported and shown like every other provider.

The forces: PayHub never trusts the browser for status, never accepts an amount from the client, and keeps provider keys per account and encrypted. Whop gives no server side charge that returns a final status, so the only trustworthy source is its signed webhook. Whop keys are per company, and a payment can carry any amount through an inline price. Card entry can be a full Whop checkout or hosted card frames inside our own layout. The research recorded fees, merchant of record terms, and the fact that PayHub payments will not show as invoices in the Whop dashboard.

Without a decision the Whop work stays in the dashboard, and agents keep sending invoices outside PayHub, so those payments are missing from PayHub's exports, dashboard and central summary.

Workspace: single repo (Laravel 13, Inertia v3, Vue 3). No scope feature row exists, so this is a standalone spec. Compliance scope: PCI DSS.

## Options considered

### Option 1: Whop Checkout embed

PayHub creates a checkout configuration on the server with an inline price and mounts Whop's whole checkout on the pay page (or redirects to its purchase URL). Whop handles the card, wallets and 3D Secure.

**Pros**:
- Least code. Card data and 3D Secure stay entirely inside Whop's checkout.
- Wallets come with it once the domain is registered.
- Redirect to the purchase URL is a simple fallback.

**Cons**:
- The page looks like Whop's checkout, not the brand's, and the consent gate and amount button must sit around it.
- Less control over fixed messages and the waiting state.

### Option 2: Whop card frames in PayHub's layout, server charge, webhook only (first choice, dropped on 2026-11-03)

Dropped because Whop Elements has no sandbox and the token charge call is not in Whop's public guide. See Rationale.

The Whop frames collect the card on PayHub's page and return a confirmation token. The server charges it with the amount from the `Payment` record. The signed webhook alone completes the payment.

**Pros**:
- The page matches the other providers: consent gate, brand layout, fixed messages, polling.
- Card values never touch PayHub code.
- No new exception to "status comes from webhooks only".

**Cons**:
- More to build and test: the form, the 3D Secure step, the waiting state, the charge endpoint and its protections.
- Whop's frames sit inside our page, so the PCI level may be above the lightest one.
- Exact charge fields are undocumented and need a sandbox check.

### Option 3: Redirect to Whop's purchase page only (kept as the `redirect` mode fallback)

Create the checkout on the server and send the client away. Now built as one of the two modes of the chosen design, not as the only way.

**Pros**:
- Smallest build and the smallest PCI scope.
- No Whop script on our page.

**Cons**:
- The client leaves the brand page, and the return URL result is display only, so the client may land back before the webhook arrives.
- Weakest fit with the "own page" experience of the other providers.

## Rationale

**Reworked 2026-11-03.** The engineer first chose Option 2 because it keeps the brand page and fixed messages. The sandbox guide (https://docs.whop.com/developer/guides/sandbox, read 2026-11-03) then showed that "The sandbox environment isn't available for Whop Elements yet", and the token charge call is not in Whop's public guide. That is the switch rule this spec already carried: if the confirmation token charge cannot be shown to work in the sandbox, move to Option 1. The engineer confirmed the switch.

The decision is now Option 1 (Checkout embedded on the pay page) with Option 3 (redirect to Whop's hosted checkout) as a per account fallback, `checkout_mode` of `embed` or `redirect`. The reasons, tied to the forces above:
- PayHub still never trusts the browser for status and never takes an amount from the client: the server creates the checkout configuration from the `Payment` record, and only the signed webhook completes the payment. No new exception beside Clover, MPGS and Authorize.net.
- It removes the whole charge path (token, charge endpoint, 3D Secure step, pause key), which was the part with undocumented fields and no sandbox.
- Everything already built on the branch stays: accounts, webhook, completion job, payment form rules.
- The `redirect` mode uses Whop's hosted page on `sandbox.whop.com`, so the full flow can be tested before any real money moves. Whether the embed itself works in the sandbox is unknown (Open question 11), which is why the fallback is built in from the start.

Cost accepted: the client sees Whop's checkout rather than a PayHub card form.

The small calls made while writing (the first five are carried from the original design, the rest are new):
- **Webhook matching**: by `metadata.payment_id`, the route's account, exact amount and currency, not by the stored Whop payment id. A retry after a decline makes a new Whop payment, so an id check would ignore a late success from an earlier attempt. Runner up: match on the stored id only, which fails if the charge reply was lost.
- **`payment.failed` is logged only**: every decline fires it and the client may retry, so setting `failed` would close the pay page and block the retry. Runner up: set `failed` and allow completion from `failed`, as Authorize.net does. Revisit if staff want declines to show on the payment.
- **No lookup fallback**: the engineer chose webhook only. Whop retries failed deliveries, and the metadata match survives a lost charge reply. Runner up: a read only lookup after 10 minutes, as MPGS does.
- **No attempts table**: the checkout lock lives in the cache and failures go to Sentry. Runner up: `whop_charge_attempts` like Authorize.net, if support needs per attempt reasons.
- **Limiter numbers**: 10 per payment and 30 per IP per hour on the checkout endpoint, 60 second lock, a little looser than the 5 and 20 in spec 0007 because opening a checkout is not a charge attempt. The pause key is gone: PayHub makes no charge call, so no outcome is unclear.
- **No Whop email field**: the payment already holds `client_email`, so the checkout is prefilled with it. If step 0 shows Whop requires its own email field, show it prefilled.
- **One checkout configuration per payment, created on the "Pay now" click, not on page load**: a GET should not call a third party or write rows, and a consent click is a clear user action. The saved id lets reloads and retries reuse it, and keeps the number of unused Whop checkouts low. Runner up: a new configuration on every open, which is simpler but clutters Whop and makes a late success harder to trace.
- **Default `checkout_mode` is `embed`**: that is the engineer's choice. Step 0 and the staging run can flip a single account to `redirect` with no code change if the embed fails.
- **One Whop product per brand** (`external_identifier` of `payhub-brand-{brand_id}`): inline plans need a product, and a product per payment would clutter the Whop dashboard. Runner up: an existing `product_id` stored on the account, which needs one more field and a manual setup step. Step 0 confirms the inline shape (Open question 8).
- **Keep the column name `whop_charge_started_at`**: it now means "checkout configuration created". Renaming it would touch the built migration, request rules and tests for no behavior gain. Runner up: rename to `whop_checkout_created_at` in a new migration, if the name confuses people.
