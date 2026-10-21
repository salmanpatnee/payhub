# 0006. Rationale: Add Authorize.net (Accept Hosted iframe) as a payment provider

## Context

PayHub is a multi brand payment hub with six providers behind one `PaymentProvider` enum. A new merchant uses Authorize.net, a US card gateway, with a USD account. PayHub needs to take card payments for that merchant on the same brand pay page the other providers use, without holding card data.

Authorize.net offers two ways to take a card on your own page. Accept Hosted shows Authorize.net's own payment form (full page, popup or embedded in an iframe). Accept.js lets you build your own form and swaps the card details for a one time code in the browser. Both end with a server side charge or a lookup.

The project's rules shape the answer. Card data must stay out of PayHub's server. Amount comes only from the server side `Payment`. Paid status must never rest on a browser message. The webhooks only rule has already been relaxed twice (Clover, MPGS) where a server to server read is available. Each provider brings read side surfaces (exports, dashboard, receipts) that earlier providers missed on a first pass.

Prerequisites with no spec: none beyond the existing provider and pay page patterns (specs 0001, 0002, 0005).

## Options considered

### Option 1: Accept Hosted in an iframe, paid status from a server side lookup

The server asks for a one time token, the pay page posts it into an iframe, Authorize.net shows its form and takes the card. The iframe message, the webhook and a sweep only trigger a `getTransactionDetails` read. PayHub marks paid from that read.

**Pros**:
- Lightest PCI scope (SAQ A); card data never touches PayHub code or page scripts.
- No card field UI to build and no card charge code on the server.
- Retry after a decline lives inside Authorize.net's form, so PayHub needs no attempts table.
- Fits the established "server to server read is the source of truth" exception.

**Cons**:
- The iframe communicator has a history of brittle behavior and needs a same domain relay page and a special framing header.
- Form look and field set are limited to Authorize.net's settings and Merchant Interface configuration.
- Plain declines are invisible to PayHub (no webhook event for them).

### Option 2: Accept Hosted in an iframe, paid status from webhooks only

Same iframe, but PayHub changes status only when a webhook arrives, never from its own lookup.

**Pros**:
- Strictest reading of the project's "webhooks only" rule; no new exception.
- No confirm endpoint.

**Cons**:
- A late or lost webhook leaves the client in a long waiting state, and the fragile iframe callback gives no fast path.
- Needs the signature key set on every account or nothing ever completes.
- Held payments cannot be resolved if their event is missed.

### Option 3: Accept.js with a PayHub built card form

PayHub renders its own card fields, sends them to Authorize.net from the browser, receives a one time code, and the server charges with `createTransactionRequest`.

**Pros**:
- Full control of the look, same style as Stripe and Square forms.
- The charge response is immediate and synchronous, so decline handling and attempts are fully visible to PayHub.

**Cons**:
- Card numbers pass through PayHub's page JavaScript (SAQ A-EP, a heavier assessment) and the pay page needs a stricter script policy.
- PayHub must build the card form, validation, charge call, duplicate protection and decline mapping itself.
- More code and more risk for no feature the business has asked for.

## Rationale

Option 1 wins on the forces in Context. The business wants the smallest PCI burden and the same on page experience as the other hosted providers, and Option 3 gives up the first for control nobody asked for. Between Options 1 and 2, the iframe callback is known to be unreliable and the webhook is not guaranteed to be quick, so relying on webhooks alone would regularly leave a client staring at a waiting screen. A lookup that PayHub's own server makes, with an exact amount, invoice and status check, is as trustworthy as a webhook, and the project already accepted that pattern for Clover and MPGS.

The attempts table from MPGS is deliberately left out. MPGS needs it because PayHub drives each charge step. Here Authorize.net runs the whole form, including retries, under one token, so the only durable fact PayHub needs is the final transaction id on the payment. Not setting `failed` on a plain decline follows the same logic: the client keeps retrying in the form, and only a fraud review decline is a true final no.

The Laravel Http client over the official PHP SDK matches RevolutClient and MpgsClient, keeps calls easy to fake in Pest, and avoids an older dependency for what are three JSON calls. USD only per account follows the Square currency lock.

The engineer chose each of these: webhook plus server lookup, an environment column per account, the admin pastes the webhook URL, card only with name and email prefilled, held payments stay pending, thin Http client, `getMerchantDetails` for test connection, no attempts table, polling when the callback is lost, token requested on page load and refreshed on expiry, and no References section.

## Amendment 2026-10-21 (after the sandbox spike)

The iframe callback was expected to be fragile, and in the sandbox it never fired: four card payments were captured, and the form never requested our communicator page. So the design no longer treats the browser message as an input. The pay page polls PayHub's own status endpoint from the moment it opens, and that endpoint finds the transaction by the payment's invoice number (`getUnsettledTransactionListRequest` returns `invoiceNumber` and `transId`) and then reads it with `getTransactionDetails`. This keeps the rule that only a lookup PayHub's server makes can mark a payment paid, and it removes the long waiting state that Option 2 (webhooks only) was rejected for. The webhook and the sweep stay as two more independent ways to find the same transaction.

Options weighed for finding the transaction when the callback is lost:
- **Status lookup by invoice number plus webhook plus sweep (chosen).** Fast for the client, works with no callback, uses only calls proven in the spike.
- **Webhook only.** Rejected again: its signature format and delay are still unverified, and a slow webhook would leave the client waiting.
- **Switch to the non iframe Accept Hosted (redirect with a return URL).** Not chosen: gives up the on page experience, and was not asked for. Still the fallback if production also fails to relay messages and the polling delay proves unacceptable.
