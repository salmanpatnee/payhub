# 0014. Worldpay provider: rationale

## Context

> ⚠️ Premise note: "Worldpay" names several products (Access Worldpay REST, the older WPG gateway, cnpAPI, Express, eProtect). This spec covers Access Worldpay only, because that is the platform the user's account and credentials work against (confirmed by a real Try call on 2026-11-17). A merchant on another Worldpay platform needs its own spec. A second concern: the signed webhook is not self service, so a status design that depends on it could not ship on a small merchant account. This spec therefore makes status a read only lookup and treats the webhook as an optional extra.

PayHub already runs several payment providers behind one `PaymentProvider` enum, and each new one has to cover the same surfaces: admin accounts, pay page, status handling, export, dashboard and receipts. Worldpay is the next one the agency wants, with a GBP merchant account and credentials from the Worldpay Dashboard (API username and password, an entity reference and, for card fields, a Checkout ID).

Two project rules shape any design. Payment status must come from the provider's own server side answer, never from what the browser says, and the amount must come from PayHub's own `Payment` record. Worldpay's hosted payment page can send events by webhook, but signing is switched on only through a Worldpay Implementation Manager, and a small merchant account may not have one. The Payment Queries API, which answers "what happened to this reference", works with the same Basic Auth credentials and was proven reachable on the user's Try account.

Card data scope matters too. Taking cards on PayHub's own page moves PayHub into a heavier PCI scope than redirecting to a provider's page. A hosted page also expires (about an hour), while a PayHub payment link may be opened days later, so the design must cope with an expired page without losing a late payment on an old one.

If nothing is decided, Worldpay stays on the agency's old fragmented setup, which is the problem PayHub exists to remove.

## Options considered

### Option 1: Hosted Payment Pages by redirect, status by lookup (with an optional signed webhook)

PayHub creates a hosted page per payment, redirects the client, and reads the result from Payment Queries after a poll, a sweep or an optional webhook.

**Pros**:
- Lightest PCI case (SAQ A); no CSP change; no card values in PayHub.
- Works with credentials a small merchant can get from the Dashboard, no Implementation Manager needed.
- Same shape as Kurv, which is built and tested, so the resolver, sweep and attempts patterns are reused.

**Cons**:
- The client leaves PayHub and the page cannot be styled by PayHub.
- A paid payment can stay `pending` for minutes if the client closes the tab and no webhook is set.
- Pages expire, so PayHub needs the replace after expiry rules.

### Option 2: Hosted pages plus Checkout card fields on PayHub's page

Adds Worldpay's Checkout SDK (the Checkout ID) so the card inputs sit on PayHub's pay page, with a server charge through the Payments API.

**Pros**:
- The client stays on PayHub's page and the look is under PayHub's control.
- A direct charge reply is available at once.

**Cons**:
- Own card inputs on our page usually mean SAQ A-EP, not SAQ A, and add CSP hosts.
- Needs a separate 3D Secure flow and a double charge guard.
- Much more code and more provider specific edge cases for the first release.

### Option 3: Hosted pages with webhook only status

Same redirect, but status is set from the signed webhook alone, as Stripe, Revolut and Square do.

**Pros**:
- Matches the project's default rule that status comes from webhooks only.
- No polling load on Worldpay.

**Cons**:
- Signing needs an Implementation Manager, which a small account may not have.
- No fallback if a webhook is lost, so payments would hang as `pending` with nothing to recover them.

## Rationale

Option 1 fits the forces in Context. The webhook is not self service, so a design that needs it cannot ship on the account the user has, while the Payment Queries read works today with the same credentials (basis: a real Try call on 2026-11-17, spec 0010 for the pattern). It keeps card data off PayHub, which holds the PCI scope at SAQ A (basis: your AGENTS.md rules on trusting only the provider's answer). The cost is a slower worst case for status, which the poll, the 10 minute sweep and the optional webhook shrink.

The engineer's choices fit this: GBP only, hosted pages only in v1, lookup plus an optional signed webhook, and v1 never setting `failed`. The runner up for the webhook choice was a URL token, which Worldpay may not let a small merchant add, so a signed webhook is the only trigger that is safe to trust. The paid rule (sent for settlement or settled, never plain authorized) comes from the money rule that a payment is paid only when the funds are captured, and the exact amount check comes from the project rule that the amount is never taken from the client (basis: idempotency and verify before applying for money operations).

The replace after expiry design follows from the engineer's choice to make a new page rather than strand a client. Its risk, a late payment on an old page, is covered by looking up every page of the payment before completing, and by treating a second paid sale as an error log for staff to refund. Locking the payment for good once a page exists is the simplest way to keep the paid amount matching the payment record (basis: Whop's lock in `AGENTS.md`).

RECOMMEND items decided here: the page lifetime and grace (3600 seconds and 120 seconds, runner up: ask Worldpay for a longer expiry), at most 5 pages per payment (runner up: unlimited with the rate limit only), the transaction reference as `{payment uuid}-{sequence}` (runner up: the display reference code, rejected because it starts with `#` and repeats across installs), the host allow list on the returned URL (runner up: trust the reply), and one attempt row per page per lookup with a `checks` counter (runner up: a row per lookup, rejected because the sweep would write hundreds per payment).

## References

**Project sources** (verifiable, in this repo):
- `AGENTS.md`: the "Critical rules" (never trust client confirmation, amount from the `Payment` record), the Kurv, NMI and MPGS scoped exception paragraphs, the Whop edit lock, and the provider parity rule.
- Spec `0010-kurv-provider` (redirect, unsigned notice, resolver, sweep, attempts table), spec `0012-nmi-provider` (sweep throttle, status poll) and spec `0013-bank-of-america-provider` (parity list, same files touched).
- `docs/research/` and the `.env` test values added by the user for step 0.

**Practices & standards**:
- Idempotency and verify before applying for money operations.
- Allow listing redirect targets to prevent open redirects.
- HMAC signature check with a constant time compare, and never trusting a webhook body for a decision.
- Worldpay's own documentation, named here without links: Access Worldpay "API principles" (Basic Auth, separate Try and Live credentials), "Hosted Payment Pages: Setup a payment" and its create endpoint, "Payment Queries: query by transaction reference", "Events: signature" (the `Event-Signature` header), and the WooCommerce, Shopify and OpenCart plugin guides for where the credentials sit in the Dashboard.
