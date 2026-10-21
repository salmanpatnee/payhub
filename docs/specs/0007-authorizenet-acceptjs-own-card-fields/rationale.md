# 0007. Rationale: Authorize.net with Accept.js and PayHub's own card fields

## Context

> ⚠️ Premise note: this change trades PCI scope for looks. Spec 0006 picked the hosted iframe mainly because card data never touches PayHub's page, and it rejected Accept.js for that reason. The engineer has now chosen Accept.js so that Authorize.net matches the other providers' pay pages, and has accepted the heavier PCI level (SAQ A-EP). The risk is real: a script injected into the pay page could read card numbers before Accept.js tokenizes them. The design below shrinks that risk but cannot remove it.

PayHub is a multi brand payment hub with six live providers behind one `PaymentProvider` enum and a seventh, Authorize.net, partly built on the branch `feat/authorizenet-accept-hosted`. The merchant uses a US Authorize.net account in USD. The branch already has the account admin pages, the provider parity work, a classifier, a resolver, an invoice number lookup, a webhook and a sweep, all built for the hosted iframe (spec 0006).

The iframe has two problems. Its look is Authorize.net's own, not the brand page's. And in the sandbox its result message to PayHub's page never fired in four real payments, so 0006 had to be redesigned around polling. Accept.js is the other way Authorize.net offers to take a card on your own page: the browser sends the card to Authorize.net and gets back a one time code, and the server charges that code.

The project's rules still apply. Card data must not be stored or logged by PayHub. Amount comes only from the server side `Payment`. Paid status must never rest on a browser claim. The "webhooks only" rule has already been relaxed for Clover, MPGS and 0006 where a server to server read is available. This work is under PCI DSS.

Prerequisites with specs: 0005 (MPGS) and 0002 (Clover) set the pattern for a server side charge and a scoped exception. Spec 0006 is superseded by this one.

## Options considered

### Option 1: Keep the Accept Hosted iframe (spec 0006)

Authorize.net shows its own form in an iframe on the brand page. PayHub finds the result by polling its own status endpoint, which looks the transaction up by invoice number.

**Pros**:
- Lightest PCI scope (SAQ A); card data never touches PayHub's page scripts.
- No card form to build or maintain.
- Already built to the point of a working pay thread.

**Cons**:
- The form looks like Authorize.net, not the brand, and cannot be restyled to match the other providers.
- The result message never arrived in the sandbox, so every payment relies on polling and the form keeps showing "Processing..." until the page swaps it.
- Plain declines are invisible to PayHub.

### Option 2: Accept.js with PayHub's own card fields, paid status from the charge plus a read back

PayHub draws the card fields, Accept.js returns a one time code, the server charges it with `createTransactionRequest`, and then reads the transaction back with `getTransactionDetails` before changing status. The webhook, invoice lookup and sweep remain for charges whose outcome was unclear.

**Pros**:
- Same look and flow as the Stripe, Square and MPGS pages.
- The result is immediate and comes from PayHub's own server call, so there is no lost message problem.
- PayHub sees each decline and can show a clear message.
- Reuses most of the built classifier, resolver, lookup, webhook, sweep and admin work.

**Cons**:
- Card numbers pass through PayHub's page scripts: SAQ A-EP, with script inventory and tamper detection duties.
- PayHub must build and maintain the card form and its validation.
- A timed out charge can leave an unknown outcome that needs a lock, a pause and a lookup.

### Option 3: Accept.js with paid status from the charge reply alone

Same form and charge, but trust the `createTransactionRequest` reply and skip the read back.

**Pros**:
- One fewer gateway call per payment.
- Simplest code path.

**Cons**:
- The charge reply has no amount field to compare, so the exact cents check the project requires for every provider would be impossible.
- Breaks the one rule all earlier providers keep: status comes from a read that can be checked against the `Payment` record.

## Rationale

Option 2 wins on the forces in Context. The engineer wants one consistent pay page, and the hosted iframe cannot give that, so Option 1 fails the main goal. The sandbox failure of the iframe message also removes the main reason to keep it. Option 3 is rejected because the project requires an exact amount and invoice match before a payment is marked paid, and the read back is the only way to get that. The extra call costs little and reuses the classifier that is already built and tested.

PCI is the honest cost. 0006 said Accept.js "gives up the lightest PCI scope for control nobody asked for". The engineer has now asked for that control (a matching look) and accepted the heavier level with the risk stated, so this spec does not re argue it. It narrows the exposure with rules a builder can check: Accept.js loaded only from Authorize.net's host on the one pay page, a Content Security Policy with no `unsafe-inline` or `unsafe-eval`, no other third party scripts, card values held only in memory and cleared at once, and the card form blocked from Sentry replay (replay is on today with text masking off, though inputs are masked by default).

The attempts table from MPGS stays out, as in 0006. MPGS needs it because PayHub drives each step of the charge there. Here each charge is one call, so the durable facts are the final transaction id on the payment and the attempt timestamps. The engineer chose no table, which means plain declines are only logged; the cost is accepted in Consequences. A cache lock per payment stops double clicks, a 120 second pause stops a retry while the outcome is unknown, and Authorize.net's own `duplicateWindow` setting is a second safety net for the same card and amount. The existing invoice number lookup resolves the unclear case without any new lookup code.

The three fixed decline messages follow the project habit of never showing raw gateway text: a card detail problem tells the client to fix the card, a plain decline tells them to try another card, and an unclear outcome tells them not to pay again.

The Laravel Http client stays in place of the official SDK, as for 0006 and for Revolut and MPGS: a handful of JSON calls are easy to fake in Pest.

The engineer chose each of these: supersede 0006, same look as the main reason, SAQ A-EP accepted, charge reply plus a read back, no attempts table, card plus expiry plus CVV plus ZIP, client key read automatically from Authorize.net, a 2 minute pause with a lookup for unclear charges, 5 tries per payment and 20 per IP per hour, remove the iframe code, edit the branch migrations in place, three fixed decline messages, and no References section.

## Options for the unclear charge (weighed inside Option 2)

- **Pause retries for 2 minutes and look it up (chosen).** The client waits briefly, the lookup usually finds the answer, and the duplicate window guards the rare miss.
- **Allow an instant retry.** Rejected: a double charge would depend on one gateway setting working.
- **Block until the lookup resolves.** Rejected: a client could be stuck for a long time when the lookup finds nothing.
