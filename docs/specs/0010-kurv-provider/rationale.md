# 0010. Rationale: Kurv as the ninth payment provider (hosted page)

## Context

> ⚠️ Premise note: the hosted page is the weaker technical fit. Kurv's Merchant API has an unsigned callback, no embed (the page sends `X-Frame-Options: sameorigin`), and live use needs a paid plan (the Start plan returns 422, "Invoicing"). The engineer chose it on 2026-11-05 after first choosing the Gateway embed. This spec accepts that choice and makes it safe with API lookups. It also assumes a Kurv plan that allows payment requests before any live payment (see Follow-up in `index.md`).

PayHub takes payments for several brands through eight providers. Each follows the same rules: a per account client, the amount read from PayHub's own payment record, integer minor units, and status set only from a trusted source. A ninth provider, Kurv, is wanted because DEEPSYNC can use a Kurv account and a brand needs it.

Kurv has two products. The Merchant API creates a hosted payment link, and the client pays on Kurv's page. The Gateway (Payment Enablement) has an embeddable card component and signed webhooks. The WooCommerce plugin work (version 1.0.1) showed how the Merchant API behaves in practice: the callback is a JSON POST with no signature, the payment request lookup returns a single `transaction` object, a paid request shows `status=success`, history rows show `ACK`, and old rows come back for a reused reference. One order was paid twice because each retry made a new link.

PayHub's existing rule is that status comes from webhooks only, with a scoped exception for gateways where PayHub reads the result from the gateway's own API (Clover, MPGS, Authorize.net, and Viva's unsigned events). Without a decision, a Kurv integration could trust the callback and mark a payment paid on a forged request.

## Options considered

### Option 1: Hosted page by redirect, status from a Kurv API lookup (chosen)

PayHub creates one payment request per payment, sends the client to Kurv, and treats the callback as a nudge. A resolver reads the result from Kurv's API after the callback, from a throttled lookup while the client waits, and from a 10 minute sweep.

**Pros**:
- Lightest PCI scope: card data never reaches PayHub.
- Works with the account DEEPSYNC already has and what the WooCommerce test proved.
- No trust in the unsigned callback, so a forged request can cost one lookup at most.
- Follows an existing pattern (Authorize.net and MPGS resolvers and sweeps).

**Cons**:
- The client leaves PayHub; the page cannot be styled and the ZIP and country fields cannot be hidden.
- Status depends on API lookups and unknown Kurv status values.
- Live needs a paid plan.

### Option 2: Gateway Payment Component embed

The Kurv Gateway tokenizes the card in the browser inside PayHub's page, and the server calls `POST /api/v5/payments/sale`. Signed webhooks (`transaction.sale.success|failure|unknown`) set status.

**Pros**:
- The client stays on PayHub's page.
- A signed webhook gives status with no scoped exception.
- A failure path is part of the documented response codes.

**Cons**:
- More to build (a browser component, tokenization, a server sale call, signing key handling).
- Unanswered: ZIP and country fields, GBP, PCI level (SAQ A is not certain), the production base URL.
- The engineer decided against it on 2026-11-05 (kept as a fallback).

### Option 3: Hosted page, trust the callback after a token check

Mark a payment paid when the callback arrives with a valid token in the URL.

**Pros**:
- The least code and the fastest result.

**Cons**:
- The body is unsigned and the token only proves the URL, not the payment. This breaks the rule that status comes from a trusted source and leaves a path to a paid payment with no real payment.

## Rationale

The engineer chose the hosted page, so the question was how to make it safe, not whether to use it. Option 1 is the only version that keeps the core rule: PayHub marks a payment paid only from data it fetched itself from Kurv's API for a transaction id it saved. The WooCommerce fix already proved this pattern against the live Kurv sandbox, and PayHub already runs the same shape for Authorize.net and MPGS (a single resolver that alone writes status, plus a 10 minute sweep).

The sweep, not delayed jobs, is the safety net because staging runs `QUEUE_CONNECTION=sync`, which ignores delays. The waiting page runs a throttled lookup so clients get a fast answer without depending on the sweep. Matching on the Kurv transaction id, one request per payment, and a lock on each lookup respond directly to what the sandbox showed: stale rows for a reused reference, a callback that arrives before the lookup sees the payment, and a double payment from two links.

The engineer chose to log a double payment only, to keep v1 small, and to leave a decline as `pending` so a client can retry. Both are safe because nothing is lost: the attempts table records every lookup, so the real decline values can be learned and `failed` added later. USD only for v1 avoids relying on GBP and amount handling that Kurv has not confirmed.

## Evidence

Research and sandbox findings live in `docs/research/kurv.md` (sections "Sandbox test results and plugin fix", "Gateway deep dive" and "Decision (2026-11-05, changed): hosted method"). The Whop spec (`docs/specs/0009-whop-payment-provider/`) is the model for the redirect pay page, the checkout lock and limiter, and the parity list. The Authorize.net spec (`docs/specs/0007-authorizenet-acceptjs-own-card-fields/`) is the model for the resolver, the attempts table and the sweep.

## References

**Project sources** (verifiable, in this repo):
- `AGENTS.md`: the critical rules (per account clients, amount from the server record, integer minor units, scoped lookup exceptions) and the eight provider parity rule
- Spec 0009 (Whop redirect mode) and spec 0007 (Authorize.net resolver and sweep)
- `docs/research/kurv.md`: the sandbox results, the live API shapes, the Gateway notes and the open questions
- `docs/kurv-woocommerce-main` (version 1.0.1): the working lookup and matching logic
- `routes/console.php`: the existing 10 minute sweeps for MPGS and Authorize.net

**Practices & standards**:
- Never trust an unsigned callback: use it as a hint and confirm with the provider's API
- Idempotent money operations: one request per payment, guarded status updates, a lock per lookup
- Least PCI scope: redirect to a hosted page (SAQ A)
