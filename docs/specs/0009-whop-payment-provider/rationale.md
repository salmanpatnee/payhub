# 0009. Rationale: Whop as the eighth payment provider

## Context

> ⚠️ Premise note: This feature assumes the company's Whop plan allows creating payments through the API for any amount, and that its webhooks follow the Standard Webhooks format. Neither is confirmed; the research in `docs/research/whop.md` lists them as open items, and the exact charge fields are not in the public guide. Build step 0 is a sandbox check that settles them before the charge code is written, and any difference is fixed in one class. Also, Whop is merchant of record, so unlike the other providers the funds and payouts sit with Whop rather than a merchant account the brand owns.

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

### Option 2: Whop card frames in PayHub's layout, server charge, webhook only

The Whop frames collect the card on PayHub's page and return a confirmation token. The server charges it with the amount from the `Payment` record. The signed webhook alone completes the payment.

**Pros**:
- The page matches the other providers: consent gate, brand layout, fixed messages, polling.
- Card values never touch PayHub code.
- No new exception to "status comes from webhooks only".

**Cons**:
- More to build and test: the form, the 3D Secure step, the waiting state, the charge endpoint and its protections.
- Whop's frames sit inside our page, so the PCI level may be above the lightest one.
- Exact charge fields are undocumented and need a sandbox check.

### Option 3: Redirect to Whop's purchase page only

Create the checkout on the server and send the client away.

**Pros**:
- Smallest build and the smallest PCI scope.
- No Whop script on our page.

**Cons**:
- The client leaves the brand page, and the return URL result is display only, so the client may land back before the webhook arrives.
- Weakest fit with the "own page" experience of the other providers.

## Rationale

The engineer chose Option 2. It fits the forces: every other provider keeps the client on the brand page with the consent gate and fixed messages, and Option 2 is the only Whop route that lets PayHub own that experience while card numbers stay in Whop's frames. It also keeps the stricter rule that only a signed webhook sets paid, so Whop adds no exception beside Clover, MPGS and Authorize.net. Its cost is more build and an undocumented charge call, which the sandbox check in step 0 is there to settle. If step 0 shows the charge cannot be made with a confirmation token on the company's plan, fall back to Option 1; the account, webhook and resolver work stays the same.

The small calls made while writing:
- **Webhook matching**: by `metadata.payment_id`, the route's account, exact amount and currency, not by the stored Whop payment id. A retry after a decline makes a new Whop payment, so an id check would ignore a late success from an earlier attempt. Runner up: match on the stored id only, which fails if the charge reply was lost.
- **`payment.failed` is logged only**: every decline fires it and the client may retry, so setting `failed` would close the pay page and block the retry. Runner up: set `failed` and allow completion from `failed`, as Authorize.net does. Revisit if staff want declines to show on the payment.
- **No lookup fallback**: the engineer chose webhook only. Whop retries failed deliveries, and the metadata match survives a lost charge reply. Runner up: a read only lookup after 10 minutes, as MPGS does.
- **No attempts table**: locks and the pause live in the cache and failures go to Sentry. Runner up: `whop_charge_attempts` like Authorize.net, if support needs per attempt reasons.
- **Rate limit and pause numbers**: copied from spec 0007 (5 per payment and 20 per IP per hour, 60 second lock) because the same abuse and double charge risks apply. The pause is 600 seconds, longer than the 120 seconds in 0007, because Whop has no lookup to settle an unclear charge: the pause must outlast a slow webhook so a second attempt cannot succeed beside the first.
- **No Whop email field**: the payment already holds `client_email`. If step 0 shows Whop requires its own email field, show it prefilled.
