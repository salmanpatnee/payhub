# 0001 · Rationale — Add Clover as a fifth payment provider

_Decision history for `index.md`. Not build input — see `index.md` for the spec `/develop` builds against._

## Context

> ⚠️ Premise note: the research this spec is based on (`.planning/research/CLOVER_PAYMENTS.md`) concludes there is "no requirement Clover uniquely satisfies that Stripe doesn't already cover" unless a specific US or Canada merchant needs Clover's point of sale system specifically. The engineer confirmed there is no such merchant yet; this is being built proactively, ahead of demand. That is a real cost: roughly fifteen files across the backend and frontend gain a fifth branch (account model, webhook, currency rules, dashboard, CSV export, admin forms, pay page), all of it unexercised by a real transaction until a Clover merchant actually signs up. The engineer chose to proceed anyway with that tradeoff understood; this spec designs it as carefully as the four providers already in production, but flags in Follow-up that go live needs a real Clover sandbox pass before this is trusted with money.

PayHub is a payment hub used by an agency to collect client payments across brands, currently through Stripe, Revolut, Square, and Viva. Each provider is wired in as its own standalone client class with explicit branching at every call site (there is no shared `PaymentProviderInterface`); adding a provider means finding and extending every one of those branch points, not implementing one interface.

Clover's Ecommerce platform is scoped to US and Canada merchants only and settles in USD (or CAD); it has no documented GBP support in any mode, including its Multi Currency Pricing add on, which changes the price shown at checkout but still settles in the merchant's own currency. PayHub currently only accepts USD and GBP payments, so a Clover account can never be the right home for a GBP payment; the currency boundary has to be enforced the same hard way it already is for Square (a Square account is locked to one currency; PayHub already rejects a currency mismatched payment before it reaches Stripe or the database).

Clover's Hosted Checkout, the integration path this spec uses, is a redirect flow: PayHub asks Clover for a checkout session and gets back a URL plus an `expirationTime` (15 minutes after creation), the browser goes there to collect card details on Clover's own page (so no card number ever touches PayHub), and Clover calls back with a webhook once the payment is approved or declined. Clover's webhook does carry a real signature (unlike Viva's, which has none), so once that signature checks out the payload can be trusted directly, the same way Stripe's, Revolut's, and Square's already are.

A short lived session creates a matching problem worth naming up front: if PayHub created a brand new session on every page visit, an old, still valid session could be paid (a second tab, browser back, a slow client) after a newer session had already replaced it in the database, leaving a real Clover payment with nothing to match it to. This spec avoids that by reusing the current session until Clover's own `expirationTime` has actually passed, rather than replacing it on every visit; see AC-4 and the Key invariants in `index.md`.

## Options considered

### Option 1: Clover Hosted Checkout, redirect flow, USD only fifth provider

PayHub creates a Clover checkout session per visit and redirects the client to Clover's own hosted page, following the same shape already built for Viva's Smart Checkout. No card data ever reaches PayHub's servers.

**Pros**:
- Closest match to a pattern PayHub has already built and operated (Viva), so the risk is well understood.
- Keeps all PCI scope on Clover's page, same posture as every existing provider.
- Clover's own recommended integration path for this kind of payment.

**Cons**:
- A second redirect based provider (after Viva) means two places carry "session lifetime" logic instead of one; a shared helper is worth revisiting once a third redirect provider shows up.
- The 15 minute session TTL means a session still needs recreating whenever it has genuinely expired, an extra Clover API call most other providers don't need (though reusing a still valid session, per AC-4, keeps this to roughly once per 15 minutes of a client dawdling, not once per page load).

### Option 2: Clover Direct Ecommerce API (tokenize and charge)

Collect card details in an iframe Clover provides embedded in PayHub's own pay page, tokenize them client side, then charge the token server side.

**Pros**:
- Keeps the payment page visually on PayHub's own domain instead of redirecting away.

**Cons**:
- Not Clover's recommended first integration path, and materially different in shape from every other provider PayHub has built, so there is no existing pattern to lean on.
- Still requires PayHub to host Clover's card collecting iframe directly inside its own pay page markup, a different PCI conversation than a clean redirect.

### Option 3: Do not build Clover now

Leave PayHub at four providers; revisit if a real Clover merchant shows up.

**Pros**:
- Zero added surface area or maintenance burden for a provider with no confirmed demand today, which is exactly what the research itself recommends absent a concrete need.

**Cons**:
- Does not satisfy the engineer's decision to build the capability ahead of need; if a Clover merchant does show up later, this work has to happen anyway, just under time pressure instead of ahead of it.

## Rationale

Option 1 wins because PayHub already has a working, understood example of this exact integration shape in Viva: a redirect to a provider hosted page, a webhook that is the only source of truth, and an idempotency table for a webhook with no reliable event ID. Reusing that shape means the engineering risk is in Clover specific details (its 15 minute session TTL, its real HMAC signature, its USD only currency scope), not in inventing a new integration pattern from scratch.

Option 2 is rejected because Clover itself does not recommend it as a first integration, and it would be the only provider in PayHub whose card collection surface lives inside PayHub's own page markup rather than a full redirect, which is a PCI posture the rest of the codebase does not have to reason about today.

Option 3, deferring entirely, is the technically cheapest option and the one the research leans toward absent a concrete merchant. The engineer weighed that and chose to build ahead of demand anyway; this spec proceeds on that basis, with the tradeoff made explicit in the Premise note above and a go-live gate captured in Follow-up.
