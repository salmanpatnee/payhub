# 0002 · Rationale — Switch Clover to a Hosted Iframe integration

_Decision history for `index.md`. Not build input — see `index.md` for the spec `/develop` builds against._

## Context

> ⚠️ Premise note: spec 0001 chose Hosted Checkout partly *because* it avoided exactly the complexity this spec now adds back in: a second card collecting surface living on PayHub's own page, a new endpoint that accepts card tokens and calls a charge API directly, and a retry-and-decline model with its own edge cases. The stated benefit of switching is UX (the client stays on PayHub's own page instead of redirecting to Clover's), not a new business requirement, and the underlying premise from 0001 still holds unchanged: there is still no confirmed Clover merchant using this in production. That is a real cost worth naming plainly before building it: this again touches account credentials, the pay page, and the parity surfaces, all still unexercised by a real transaction. The engineer directed this switch explicitly (a separate branch, made for this purpose); this spec designs it carefully, but the same go-live gate from 0001 (a real sandbox pass before any account is trusted with money) still applies, arguably more so now that PayHub's own server takes on more of the payment flow than a pure redirect ever did, and more so again after the correction below.

PayHub is a payment hub used by an agency to collect client payments across brands, currently through Stripe, Revolut, Square, Viva, and (as of spec 0001) Clover using Hosted Checkout, a redirect flow. Hosted Checkout works and is already committed on `feat/clover-payment-provider`, but it is the only provider in PayHub that sends the client away to a provider hosted page instead of collecting payment on PayHub's own page (matching Stripe Elements and Square's Web Payments SDK). This spec designs the alternative: Clover's Hosted Iframe integration, an embedded card form on PayHub's own pay page that tokenizes the card client side and charges the token server side.

Clover scopes one Ecommerce API credential to a single integration type at creation (Hosted Checkout, Hosted Iframe, or the raw Ecommerce API); the credential already generated for Hosted Checkout cannot be reused here; a new one, generated specifically for Hosted Iframe, is required.

Switching integration shape changes more than the pay page. Hosted Checkout's whole data model exists to manage a 15 minute checkout session (reuse it while valid, replace it once expired); Hosted Iframe has no session concept at all, so that machinery (session id, expiry tracking, the reuse logic in AC-4 of 0001) becomes dead weight. In its place, a new concern appears that Hosted Checkout never had: PayHub's own server now makes the charge call directly and gets a synchronous response, something no other redirect based provider in PayHub does. How PayHub uses that synchronous response is the central design question this spec resolves — see the correction below and the revised Decision in `index.md`.

A second new concern: unlike a redirect flow, a declined card here can be retried immediately on the same page, without a new payment link. PayHub already has this exact pattern for Stripe and Square (`HandleStripeWebhookJob`, `HandleSquareWebhookJob`): a decline moves a payment to `failed`, but `failed` is not terminal, a later approved attempt still completes it. Hosted Checkout's spec (0001) made `DECLINED` terminal, because a new session there really did mean a dead end requiring a fresh link; that reasoning does not carry over here.

### Correction found during `/develop` (2026-09-17)

The first version of this spec (written the same day) assumed Clover's Hosted Iframe integration is confirmed by the same signed webhook Hosted Checkout uses (`Clover-Signature` header, `Type: PAYMENT`, `Status: APPROVED`/`DECLINED`), and designed the whole status machine — `CloverWebhookController`, `HandleCloverWebhookJob`, `processed_clover_events`, the `webhook_secret` credential, AC-6 through AC-9 — around that assumption.

Building against the spec surfaced a documentation check that does not support it. Clover's own developer docs (docs.clover.com) name "Hosted Iframe + API/SDK" and "Hosted Checkout (HCO)" as two separate, non-interchangeable integration types with different credential shapes — mixing them is rejected outright by Clover's API (406). Every webhook tutorial Clover publishes is scoped to Hosted Checkout specifically. No page in Clover's Ecommerce API tutorial index documents a webhook or any async notification channel for the Hosted Iframe + API/SDK flow this spec builds; every one of those pages instead describes the charge outcome arriving synchronously, in the same `POST /v1/charges` response PayHub's server already gets back (`id`, `paid`, `captured`, `status`, `outcome`).

In short: the webhook this spec's original design depended on for Hosted Iframe most likely does not exist. See the corrected status confirmation mechanism below, and the revised Decision, Requirements, and Feature design in `index.md`.

### Cross check found during review (2026-09-17)

Before accepting the correction above, an independent cross check (a different model, read only) was run against the drafted spec. It found the first version of the correction's reconciliation design was unbuildable as written, and several other gaps. The load bearing ones:

- **Token replay is unsafe and impossible.** The first draft had reconciliation "retry the original charge request with its idempotency key." `/v1/charges` needs the card token, which the spec never stored (correctly — storing it would contradict AC-4's "no raw card data reaches PayHub's server"), and Clover's Hosted Iframe tokens are single-use and short-lived regardless. **Fixed**: reconciliation reads Clover's own record of the charge (by charge id if known, else by searching on the stored `external_reference_id`) — it never re-submits anything. This also removes the "reconciliation becomes a duplicate-charge generator if Clover doesn't honor idempotency" failure mode the first draft's Follow-up had quietly accepted.
- **The completion guard as first drafted (`where('status', 'pending')` for both completion and decline) silently broke the "decline then retry" story this spec promises.** `HandleSquareWebhookJob`/`HandleStripeWebhookJob` use `whereIn('status', ['pending', 'failed'])` for completion specifically so a retry after a decline can still succeed. **Fixed**: two separate guards, matching the existing pattern exactly, not a single shared one.
- **A card decline will very likely arrive as an HTTP 4xx with a decline body, not a clean 2xx.** The first draft's three-bucket classification (approved / declined / unrecognized-as-unknown) only separated on response *content*, while the plan to keep `CloverClient`'s existing `->throw()` behavior meant any non-2xx — including an ordinary decline — would already have become a thrown exception before classification ever ran, landing every decline in the "transport failed" bucket instead of the decline path. **Fixed**: a four-bucket classification (approved / declined / hard error / unknown) that classifies by response content regardless of HTTP status, and `CloverClient::createCharge()` no longer throws on a 4xx/5xx — it returns the status and body for the caller to classify.
- **Nothing stopped a double-click (or two near-simultaneous submits) from charging the same card twice.** Each submission mints a fresh idempotency key by necessity (a retry after a decline needs a genuinely new charge), so the uniqueness constraint on that key never caught two concurrent submissions for the same payment. **Fixed**: an explicit per-payment concurrency lock (a DB row lock or cache lock on `payment_id`), held from the precondition check through the Clover call, rejecting a second concurrent submit with 409 before it ever reaches Clover.
- Smaller but real gaps folded into the correction: `paid_at` was never named as a value the completion write produces (would have silently broken the dashboard and CSV export, which both filter/read it); `SendPaymentNotification` had no owner and no dedupe gate; the charge endpoint had no precondition guard against firing at an already-`completed` or inactive-account payment; `decline_reason` vs the client-facing `message` had no stated separation (risking Clover's raw processor text reaching the browser, unlike every other provider's sanitized posture); "approved" didn't distinguish `paid` from `captured`, which are genuinely different signals (an authorization without capture is not a completed payment); the per-payment rate limit had no named mechanism (Laravel's default throttle is IP keyed, not payment keyed); and the reconciliation design had no pinned numbers (grace period, retry ceiling, give-up state) — replaced by a queued job with Laravel's own retry/backoff plus a thin scheduled backstop sweep, which needed no such numbers invented from scratch.

The cross check also affirmed the core judgment call (treating the server to server response as authoritative is a sound, properly scoped exception, not a rule violation) while flagging a consequence the first draft didn't state: Clover payment state becomes a snapshot taken at charge time, with no channel for anything that happens on Clover's side afterward (a void, a refund, a chargeback). That's now recorded explicitly in `index.md`'s Consequences rather than left implicit.

## Options considered

### Option 1: Clover Hosted Iframe, embedded tokenize and charge

A Clover provided JavaScript SDK renders card fields inside an iframe on PayHub's own pay page. The client enters card details there (never touching PayHub's server), the SDK returns a token, and PayHub's server charges that token via Clover's `/v1/charges` endpoint. Clover's own docs describe no webhook for this integration type (only for its separate Hosted Checkout product); the outcome instead comes back synchronously in that same server to server response, which this spec now treats as authoritative under safeguards (see Decision).

**Pros**:
- Matches the client side tokenize, server side charge shape PayHub already runs for Stripe Elements and Square's Web Payments SDK, though Clover departs from both on how the outcome is confirmed (see Decision).
- Client never leaves PayHub's page, avoiding a redirect for what is otherwise a five minute checkout.
- Lighter PCI scope than the alternative (SAQ A-EP, matching Stripe Elements/Square): raw card data never reaches PayHub's server, only a token does.

**Cons**:
- Introduces a new PayHub hosted endpoint that accepts a token and triggers a real charge, a risk surface none of PayHub's other webhook only providers have; needs its own rate limiting (see AC-9 in `index.md`).
- Unlike every other provider in PayHub, this integration type has no documented webhook — the synchronous `/v1/charges` response is the only outcome channel Clover publishes for it, which required a scoped, explicit exception to PayHub's "status comes from webhooks only" rule, with its own safeguards (see Decision).

### Option 2: Clover Direct Ecommerce API (server side raw card handling)

PayHub's own server RSA encrypts the raw card number (retrieved from a form PayHub builds itself, not a Clover provided iframe), sends it to Clover's `/v1/tokens` endpoint, then charges the resulting token.

**Pros**:
- No dependency on Clover's iframe SDK loading correctly in the client's browser.

**Cons**:
- Materially broader PCI scope (SAQ D): PayHub's own server handles the raw card number, even encrypted, which none of PayHub's other four providers do today.
- No SDK to build against, only a REST API with manual RSA key handling PayHub would own and maintain itself.
- Not Clover's own recommended path for this shape of integration.
- Still faces the exact same "no documented webhook" gap as Option 1 — this alternative does not avoid the correction below, it only adds PCI scope on top of it.

### Option 3: Keep Hosted Checkout (do not switch)

Leave the already built and committed Hosted Checkout integration from spec 0001 as is.

**Pros**:
- Zero additional surface area; the redirect flow is already built, tested, and matches Viva's proven shape.
- Hosted Checkout is the integration type that genuinely does have a documented, signed webhook — no correction needed.

**Cons**:
- Does not satisfy the engineer's explicit decision to move to an on page checkout experience for Clover; if that is genuinely wanted, deferring just delays the same work.

### Status confirmation mechanism (sub decision, added in this correction)

With Option 1 chosen, a second decision follows: given no documented webhook, what does PayHub trust as the authoritative outcome?

**A. Trust the synchronous `/v1/charges` response, a scoped exception to "webhooks only"**

PayHub's own server receives Clover's charge outcome directly in the response to its own POST request — a server to server channel the browser never touches, unlike the client side confirmations (Stripe's `confirmPayment()`, Square's tokenize callback) the project's "webhooks only" rule exists to guard against. Reinforced with a charge id based idempotency guard (a given charge can complete a payment exactly once) and a resolver job that reads Clover's own record of the charge — by charge id, or by the stored `external_reference_id` — for a response that never arrives at all; see the 2026-09-17 cross check above for why reading, not replaying, is the safe design.

**Pros**: No dependency on an async channel Clover does not document for this integration type; matches how Clover's own docs describe the integration working end to end; keeps the spirit of "only write from what the payment processor itself told PayHub" even though the channel isn't a webhook.
**Cons**: A materially different trust model from every other PayHub provider — worth stating plainly so a future reader does not assume Clover behaves like Stripe/Square/Viva here; a response that is lost entirely (the connection dies before any bytes return) still needs its own fallback, which is what the idempotent retry / reconciliation design in Decision provides.

**B. Re-verify with a follow-up `GET /v1/charges/{id}` before writing status**

After a synchronous POST response claims success, immediately issue a second GET to confirm before writing anything.

**Pros**: A second read from Clover before committing to a status.
**Cons**: Asks the same Clover backend the same question a second time over a second connection — not an independent channel, so it doesn't change the trust model, only adds latency and a second failure mode (the GET can itself now time out) for no demonstrated benefit over reading the POST response directly.

**C. Confirm with Clover developer support before designing further**

Pause the feature until Clover support confirms in writing whether Hosted Iframe + API/SDK fires any webhook at all.

**Pros**: Removes any doubt with an authoritative answer.
**Cons**: Blocks the feature indefinitely on a support ticket when the documentation evidence is already fairly conclusive (separate integration types with incompatible credentials, zero webhook tutorial anywhere in the Ecommerce tutorial index, every relevant page describing a synchronous outcome); the spec already carries a "live sandbox pass before go-live" gate (Follow-up) where this gets a real, final check before any account is trusted with money — blocking the design on a ticket now adds delay without adding safety before that gate.

## Rationale

Option 1 wins on fit: it is the same client side tokenize, server side charge shape PayHub already runs for Stripe and Square, so the engineering risk is confined to Clover specific details (its SDK, its synchronous response, its field names) rather than a new integration pattern. It also keeps PCI scope at the same light level as those two providers, unlike Option 2.

Option 2 is rejected for the same reason 0001 rejected it: broader PCI scope, no SDK, and not Clover's recommended path, with no upside over Option 1 for what PayHub actually needs (an embedded card form) — and it inherits the exact same webhook gap addressed below.

Option 3, keeping Hosted Checkout, is the cheapest option and the one that best matches the Premise note above (no confirmed merchant yet, and it's the integration type that genuinely has a webhook). The engineer weighed that and explicitly directed the switch anyway, on a dedicated branch; this spec proceeds on that basis, with the tradeoff restated plainly in the Premise note and the same go-live gate carried into Follow-up.

For the status confirmation mechanism, **A wins**: Clover's own documentation describes the synchronous response as the outcome channel for this integration type, so treating it as authoritative is not a workaround, it's building to the API as documented. Option B doesn't actually strengthen trust (same backend, same answer, asked twice) and Option C blocks the whole feature on a support ticket the go-live gate already exists to cover. A's cost — a materially different trust model than every other PayHub provider — is real and is why this correction states it explicitly rather than leaving it implicit, and why the idempotency and reconciliation design in `index.md`'s Decision exists: to give the "lost response" case a real answer instead of silently hoping it never happens.

## References

**Project sources**:
- `CLAUDE.md`'s critical rules: never trust client side confirmation for DB writes, amounts always server sourced, per-account client instantiation. This spec's Decision reasons explicitly about where the Hosted Iframe synchronous response sits relative to that rule (a server to server channel, not a client side confirmation) rather than silently overriding it.
- `docs/specs/0001-add-clover-payment-provider` (superseded by this spec) — the Hosted Checkout design and its rejected Option 2, and the integration type that genuinely does carry a documented webhook
- `app/Jobs/HandleStripeWebhookJob.php` and `app/Jobs/HandleSquareWebhookJob.php` — the existing `pending → failed → completed` retry pattern this spec's Decision follows for Clover
- `app/Models/CloverAccount.php`, `app/Services/Clover/CloverClient.php`, `database/migrations/2026_09_17_*` — the existing Hosted Checkout code this spec modifies

**Practices & standards**:
- Idempotency keys for money operations — originally cited here for webhook delivery; this correction applies the same principle one layer earlier, to the charge request itself, since that is now the operation that must be safe to retry
- PCI DSS scope reduction via client side tokenization (SAQ A-EP), the same posture as Stripe Elements and Square's Web Payments SDK already in this codebase

**Links** (web verified during this design conversation and the 2026-09-17 correction):
- Clover Ecommerce home: https://docs.clover.com/dev/docs/clover-ecommerce-homepage
- Using the Clover Hosted Iframe (SDK init, elements, createToken): https://docs.clover.com/dev/docs/using-the-clover-hosted-iframe
- Clover iframe card and page elements: https://docs.clover.com/dev/docs/clover-iframe-features
- Ecommerce API payments flow: https://docs.clover.com/dev/docs/ecommerce-api-payments-flow
- Create a charge (`POST /v1/charges`): https://docs.clover.com/dev/docs/create-a-charge
- Accept payments and tips: https://docs.clover.com/dev/docs/ecommerce-accepting-payments
- Ecommerce integration types (Hosted Iframe + API/SDK vs Hosted Checkout, and why they're incompatible): https://docs.clover.com/dev/docs/ecommerce-integration-types
- Ecommerce FAQs (CVV requirement, integration type notes): https://docs.clover.com/dev/docs/ecommerce-faqs
- Generate Ecommerce API tokens: https://docs.clover.com/dev/docs/create-ecommerce-api-tokens
- Configure Ecommerce Hosted Checkout webhooks (confirms the webhook is scoped to Hosted Checkout, not this integration type): https://docs.clover.com/dev/docs/ecomm-hosted-checkout-webhook
- Ecommerce services API tutorials index (no webhook/async tutorial listed for iframe+API charges): https://docs.clover.com/dev/docs/ecommerce-api-tutorials

**Superseded reference** (from the original version of this spec, kept for the record): "Webhooks: https://docs.clover.com/dev/docs/webhooks" — this is Clover's general, older POS REST API webhook mechanism (object `CREATE`/`UPDATE`/`DELETE` events you re-fetch by id), a different subsystem from the Ecommerce `Charge` object this spec's payment flow produces. It does not apply to Hosted Iframe payment confirmation; citing it in the original version was the root of the corrected assumption above.
