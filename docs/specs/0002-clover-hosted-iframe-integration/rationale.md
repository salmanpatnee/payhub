# 0002 · Rationale — Switch Clover to a Hosted Iframe integration

_Decision history for `index.md`. Not build input — see `index.md` for the spec `/develop` builds against._

## Context

> ⚠️ Premise note: spec 0001 chose Hosted Checkout partly *because* it avoided exactly the complexity this spec now adds back in: a second card collecting surface living on PayHub's own page, a new endpoint that accepts card tokens and calls a charge API directly, and a retry-and-decline model with its own edge cases. The stated benefit of switching is UX (the client stays on PayHub's own page instead of redirecting to Clover's), not a new business requirement, and the underlying premise from 0001 still holds unchanged: there is still no confirmed Clover merchant using this in production. That is a real cost worth naming plainly before building it: this again touches account credentials, the pay page, the webhook handler, the dashboard, and the parity surfaces, all still unexercised by a real transaction. The engineer directed this switch explicitly (a separate branch, made for this purpose); this spec designs it carefully, but the same go-live gate from 0001 (a real sandbox pass before any account is trusted with money) still applies, arguably more so now that PayHub's own server takes on more of the payment flow than a pure redirect ever did.

PayHub is a payment hub used by an agency to collect client payments across brands, currently through Stripe, Revolut, Square, Viva, and (as of spec 0001) Clover using Hosted Checkout, a redirect flow. Hosted Checkout works and is already committed on `feat/clover-payment-provider`, but it is the only provider in PayHub that sends the client away to a provider hosted page instead of collecting payment on PayHub's own page (matching Stripe Elements and Square's Web Payments SDK). This spec designs the alternative: Clover's Hosted Iframe integration, an embedded card form on PayHub's own pay page that tokenizes the card client side and charges the token server side.

Clover scopes one Ecommerce API credential to a single integration type at creation (Hosted Checkout, Hosted Iframe, or the raw Ecommerce API); the credential already generated for Hosted Checkout cannot be reused here; a new one, generated specifically for Hosted Iframe, is required.

Switching integration shape changes more than the pay page. Hosted Checkout's whole data model exists to manage a 15 minute checkout session (reuse it while valid, replace it once expired); Hosted Iframe has no session concept at all, so that machinery (session id, expiry tracking, the reuse logic in AC-4 of 0001) becomes dead weight. In its place, a new concern appears that Hosted Checkout never had: PayHub's own server now makes the charge call directly and gets a synchronous response, something no other redirect based provider in PayHub does. Whether and how to use that synchronous response, without breaking the project's standing rule that only a verified webhook ever writes a payment's paid status, is the central design question this spec resolves (see Decision and the Feature design's Key invariants in `index.md`).

A second new concern: unlike a redirect flow, a declined card here can be retried immediately on the same page, without a new payment link. PayHub already has this exact pattern for Stripe and Square (`HandleStripeWebhookJob`, `HandleSquareWebhookJob`): a decline moves a payment to `failed`, but `failed` is not terminal, a later approved attempt still completes it. Hosted Checkout's spec (0001) made `DECLINED` terminal, because a new session there really did mean a dead end requiring a fresh link; that reasoning does not carry over here.

## Options considered

### Option 1: Clover Hosted Iframe, embedded tokenize and charge

A Clover provided JavaScript SDK renders card fields inside an iframe on PayHub's own pay page. The client enters card details there (never touching PayHub's server), the SDK returns a token, and PayHub's server charges that token via Clover's `/v1/charges` endpoint. Status is still decided only by Clover's signed webhook.

**Pros**:
- Matches the exact shape PayHub already runs for Stripe Elements and Square's Web Payments SDK: client side tokenize, server side charge, webhook confirms. No new integration pattern to learn.
- Client never leaves PayHub's page, avoiding a redirect for what is otherwise a five minute checkout.
- Lighter PCI scope than the alternative (SAQ A-EP, matching Stripe Elements/Square): raw card data never reaches PayHub's server, only a token does.

**Cons**:
- Introduces a new PayHub hosted endpoint that accepts a token and triggers a real charge, a risk surface none of PayHub's other webhook only providers have; needs its own rate limiting (see AC-11 in `index.md`).
- The synchronous `/v1/charges` response has to be used carefully: useful for fast UX feedback, but must never be allowed to write the payment's status itself, or PayHub gains a second, inconsistent source of truth alongside the webhook.

### Option 2: Clover Direct Ecommerce API (server side raw card handling)

PayHub's own server RSA encrypts the raw card number (retrieved from a form PayHub builds itself, not a Clover provided iframe), sends it to Clover's `/v1/tokens` endpoint, then charges the resulting token.

**Pros**:
- No dependency on Clover's iframe SDK loading correctly in the client's browser.

**Cons**:
- Materially broader PCI scope (SAQ D): PayHub's own server handles the raw card number, even encrypted, which none of PayHub's other four providers do today.
- No SDK to build against, only a REST API with manual RSA key handling PayHub would own and maintain itself.
- Not Clover's own recommended path for this shape of integration.

### Option 3: Keep Hosted Checkout (do not switch)

Leave the already built and committed Hosted Checkout integration from spec 0001 as is.

**Pros**:
- Zero additional surface area; the redirect flow is already built, tested, and matches Viva's proven shape.

**Cons**:
- Does not satisfy the engineer's explicit decision to move to an on page checkout experience for Clover; if that is genuinely wanted, deferring just delays the same work.

## Rationale

Option 1 wins on fit: it is the same client side tokenize, server side charge, webhook confirms shape PayHub already runs for Stripe and Square, so the engineering risk is confined to Clover specific details (its SDK, its synchronous response, its field names) rather than a new integration pattern. It also keeps PCI scope at the same light level as those two providers, unlike Option 2.

Option 2 is rejected for the same reason 0001 rejected it: broader PCI scope, no SDK, and not Clover's recommended path, with no upside over Option 1 for what PayHub actually needs (an embedded card form).

Option 3, keeping Hosted Checkout, is the cheapest option and the one that best matches the Premise note above (no confirmed merchant yet). The engineer weighed that and explicitly directed the switch anyway, on a dedicated branch; this spec proceeds on that basis, with the tradeoff restated plainly in the Premise note and the same go-live gate carried into Follow-up.

## References

**Project sources**:
- `CLAUDE.md`'s critical rules: never trust client side confirmation for DB writes, amounts always server sourced, per-account client instantiation
- `docs/specs/0001-add-clover-payment-provider` (superseded by this spec) — the Hosted Checkout design and its rejected Option 2
- `app/Jobs/HandleStripeWebhookJob.php` and `app/Jobs/HandleSquareWebhookJob.php` — the existing `pending → failed → completed` retry pattern this spec's Decision follows for Clover
- `app/Models/CloverAccount.php`, `app/Services/Clover/CloverClient.php`, `database/migrations/2026_09_17_*` — the existing Hosted Checkout code this spec modifies

**Practices & standards**:
- Idempotency keys for webhook driven money operations
- PCI DSS scope reduction via client side tokenization (SAQ A-EP), the same posture as Stripe Elements and Square's Web Payments SDK already in this codebase

**Links** (web verified during this design conversation):
- Clover Ecommerce home: https://docs.clover.com/dev/docs/clover-ecommerce-homepage
- Using the Clover Hosted Iframe: https://docs.clover.com/dev/docs/using-the-clover-hosted-iframe
- Ecommerce API payments flow: https://docs.clover.com/dev/docs/ecommerce-api-payments-flow
- Create a card token: https://docs.clover.com/dev/docs/create-a-card-token
- Webhooks: https://docs.clover.com/dev/docs/webhooks
