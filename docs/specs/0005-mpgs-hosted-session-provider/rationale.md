# 0005. Rationale: MPGS Hosted Session provider

This file holds the reasoning behind `index.md`. Builds do not need to read it.

## Context

> ⚠️ Premise note: the integration handoff (`docs/MPGS_STANDALONE_INTEGRATION_HANDOFF.md`) recommends Hosted Checkout (a redirect or an embedded gateway form) as the first step and Hosted Session as an optional extra. For PayHub the order is reversed, because the goal is card fields on the brand page like Stripe and Clover. Hosted Session means PayHub, not the gateway, owns the 3DS step, and that is where the real risk sits (a wrong 3DS wiring or a too strict content security policy loses sales without any visible error). The spec therefore puts a sandbox spike in front of the build instead of assuming the documentation is right.

PayHub is a multi brand payment hub. Each brand pays through links on PayHub's own pages, and each provider sits behind the `PaymentProvider` enum with per account credentials, a client side card form, and server side status handling. Five providers exist today (Stripe, Revolut, Square, Viva, Clover). A client of the agency wants to take payments through an acquirer that uses Mastercard Payment Gateway Services (MPGS), which PayHub cannot do yet.

Forces:
- The pay experience must match the existing providers: card typed on the brand page, no redirect, a declined card can be retried on the same link.
- Raw card data must never reach PayHub's server (PCI scope stays at the lightest level the other providers already have).
- Paid status must never come from the browser. Most providers use a signed webhook. Clover set the precedent that a server to server response can be authoritative when the browser is not involved.
- MPGS differs from Clover: it has webhooks, it needs a unique order id per attempt, a session that must carry the amount, and a 3DS step the merchant must drive.
- Some MPGS documentation pages could not be read directly when this spec was written (they render in the browser only), so several field names and the 3DS sequence come from the integration handoff and search results, not from a verified source.
- PayHub has no refund feature, so refunds and voids are not needed.
- The team already has a working template (Clover, spec 0002) for the attempt table, classifier, resolver job and lock.

If nothing is decided, MPGS clients cannot be onboarded, or someone builds a one off integration outside the provider pattern that skips the attempt table, the lock and the idempotency checks.

## Options considered

### Option 1: Hosted Session fields on PayHub's page, server side PAY, webhook and resolver backstops (chosen)

The card fields are gateway frames inside PayHub's own form. PayHub creates the session, writes the amount, drives 3DS with the gateway's JavaScript library, and sends PAY from its server.

**Pros**:
- Matches the Stripe, Square and Clover experience and branding.
- Card data stays in gateway frames, so PayHub targets the lightest PCI self assessment.
- Reuses the Clover design almost one to one.
- Full control of the retry and decline experience.

**Cons**:
- PayHub must implement 3DS and a CSP exception for bank challenge frames.
- More moving parts than a redirect (session, three ids per attempt, two lookups).
- Depends on documentation details not yet verified (hence the spike).

### Option 2: Hosted Checkout embedded in a container on PayHub's page

The gateway renders its whole payment form in a div. The gateway handles 3DS.

**Pros**:
- No 3DS code and the least work after the redirect option.
- Same server flow the WooCommerce plugin already proves.

**Cons**:
- Limited branding; it looks like the gateway's form, not PayHub's.
- The cancel callback does not work with the embedded page, per the handoff.
- Still needs frame and script allowances in the CSP, and the result comes back through a return URL and indicator check that the PayHub rules treat as browser input.

### Option 3: Hosted Checkout with a redirect to the gateway page (what the WooCommerce plugin does)

The customer leaves PayHub, pays on the gateway's page and returns.

**Pros**:
- Simplest and fastest; the gateway handles card entry and 3DS.

**Cons**:
- Breaks the on page experience every other provider gives.
- Return handling is browser driven, so status must still come from a webhook or lookup anyway.

### Option 4: Direct API with card fields on PayHub's own servers

**Pros**:
- Total control.

**Cons**:
- Card data would pass through PayHub's servers, which moves PCI scope to the heaviest level and contradicts the project's rules. Rejected without further analysis.

## Rationale

Option 1 is the only one that gives the brand page card form the engineer asked for while keeping card data out of PayHub. Options 2 and 3 are cheaper but give up the experience that makes the other providers consistent, and Option 4 breaks a core rule. The extra cost of Option 1 is concentrated in 3DS and CSP, which is why the spec starts with a sandbox spike rather than hiding the risk in the build.

The engineer chose full 3DS in v1. Without it, many banks and issuers decline, and fraud liability stays with the merchant. The engineer also chose inline challenge display with a CSP exception limited to the MPGS pay page. The alternative (a full screen redirect) keeps the CSP strict but needs a return route and page state recovery, so it stays as a fallback the spike can select.

Currency handling follows the engineer's choice: no currency column, every MPGS account takes both currencies, like Stripe and Revolut. The cost is that a bank which only enabled one currency is found at pay time, not at link creation. That is recorded as a tradeoff, with a future `currencies` column as the fix.

The gateway host is a single configuration value, again by the engineer's choice (one known host for now). It keeps the CSP static and the admin form short, and a second bank on another host will need a follow up.

Status confirmation uses the PAY response as the primary result and keeps a webhook and a resolver as backups. A webhook is useful here, but the gateway says delivery can be late, and 3DS flows send two notifications, so the webhook is treated only as a signal: the job re-reads the order from the gateway and applies that. This makes the design safe even if the webhook body format differs from what the documentation suggests. The resolver never sends PAY again, for the same reason Clover's does not replay a charge: a second PAY could charge the card twice.

A new order id per attempt (`{payment_id}-{sequence}`) fixes a weakness the handoff found in the plugin, which reuses the store order id and can collide on retry. Storing the session id encrypted and clearing it at a final state lets the server, not the browser, supply the session at PAY time, so a client cannot point a PAY at someone else's session.

The pay logic lives in a new `MpgsPaymentController` instead of growing `ClientPaymentController` (already about 750 lines). Runner up: add to `ClientPaymentController` like Clover did; rejected for size only.

## Handoff corrections recorded here

- Void: the gateway documentation shows `PUT` with `apiOperation: VOID` and `transaction.targetTransactionId`, not `DELETE` as the plugin does. Out of scope for this spec but must not be copied later. Which transaction id the URL carries is ambiguous in the documentation.
- The handoff's line references (`mpgs-payment-gateway.php:L1032` and so on) point to the WooCommerce plugin repository, not PayHub.

## Evidence notes

- Webhook facts verified from Mastercard documentation search results: the secret arrives in the `X-Notification-Secret` header (HTTPS only), a notification URL can be set per transaction with `order.notificationUrl`, delivery is not guaranteed to be timely and Mastercard itself recommends Retrieve Transaction for critical flows, and 3DS flows through Hosted Checkout produce two notifications.
- Not verified directly: the Hosted Session and 3DS JavaScript pages (the Open questions list in `index.md` is the complete set of what the spike must settle).
- Local precedent: `docs/specs/0002-clover-hosted-iframe-integration/` (attempt table, classifier, resolver, lock, rate limiter) and the Square and Stripe webhook jobs (guarded status writes, `$updated > 0` notification gate).
