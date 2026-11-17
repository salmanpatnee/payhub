# 0015 rationale: Worldpay Checkout card fields

## Context

> ⚠️ Premise note: This spec builds on 0014, whose Worldpay code is built but has never taken a successful Try payment (every Try card attempt ended in `authorizationFailed` or the failure URL). The card fields path has the same Try dependency plus four more unknowns (charge body, 3D Secure hosts, Google Pay token type, PCI form). The design below is firm, but it is not proven. Build step 0 (a real Try check) is a hard gate before any dependent step ships, and Live use waits for written PCI confirmation.

PayHub already runs Worldpay through Hosted Payment Pages (spec 0014): the client leaves PayHub, pays on Worldpay's page and returns. The business now wants the card inputs on PayHub's own pay page, so the client stays on the brand's page and PayHub controls the look. Worldpay offers this through its Checkout SDK (a script that draws the card inputs and returns a one use session) plus a server side charge through the Payments API.

The forces: payment status must still come only from the provider's own answer, read back by the server (project rule, shared by Clover, MPGS, Authorize.net, Kurv and NMI). The amount must come from the `Payment` record. The Worldpay entity here is a small merchant account, so features that need an implementation manager (the signed webhook) cannot be assumed, but the Checkout ID is issued by one, and the business already holds one for Try. Card payments in the UK need 3D Secure, which Worldpay runs as a device data step and a challenge step driven by the server and two frames in the browser. A card form on the brand's page changes the PCI case and the CSP. Without a decision the Worldpay provider stays hosted only and clients keep leaving the page.

Workspace: single repo, Laravel 13 with Inertia and Vue, PCI DSS applies.

## Options considered

### Option 1: Replace the hosted page with card fields

Remove the 0014 hosted flow and run every Worldpay account on card fields.

**Pros**:
- One Worldpay path to maintain.
- No mode setting.

**Cons**:
- Throws away built and tested 0014 code, and the hosted page is the lightest PCI case.
- Every account would need a Checkout ID and a working 3D Secure flow, which are both unproven.

### Option 2: Per account `card_fields` mode beside the hosted page (chosen)

Add `mode` to the account. `hosted` keeps 0014. `card_fields` renders the SDK on the pay page, charges through the Payments API and reuses the same resolver, sweep and attempts panel.

**Pros**:
- Nothing already built changes; rollout is one account at a time, and rollback is one setting (before payments exist).
- Same pattern as Whop's embed or redirect mode.
- Reuses the 0014 resolver, lookup table, sweep and narrative rules.

**Cons**:
- Two Worldpay paths to test and keep in step.
- The mode lock means a brand that wants to switch needs a new account.

### Option 3: Choose the flow per payment link

The agent picks hosted or card fields on each payment.

**Pros**:
- Maximum flexibility.

**Cons**:
- The pay form, the account fields and the CSP would all depend on a per payment value, and an account without a Checkout ID could receive a card payment.
- Agents get a technical choice they cannot judge.

### Option 4: Embed the hosted payment page inside PayHub's page (not yet checked)

If Access Hosted Payment Pages allow an iframe or lightbox, the client stays on PayHub's page and 0014 is reused with no charge call, no 3D Secure code and no double charge guard.

**Pros**:
- Smallest code and lightest PCI case (likely SAQ A).
- Reuses the whole 0014 flow and read back.

**Cons**:
- Unconfirmed: Worldpay may forbid framing, and a frame still cannot be styled by PayHub.
- Bank 3D Secure pages inside a nested frame can misbehave on some browsers.

## Rationale

Option 4 is the cheapest path if it exists, so build step 0 checks it first (Open question 9) and the build waits on that answer and on the PCI answer. If framing is allowed and keeps SAQ A, this spec should be rewritten as that option. Otherwise Option 2 stands.

Option 2 fits the forces. The hosted flow is built and the card fields path has unproven parts (Try failures, charge body, PCI), so replacing it (Option 1) trades a working path for an unproven one, and a per account mode lets one brand test card fields on Try while the others stay hosted (basis: strangler pattern, your AGENTS.md Whop embed or redirect rule). A per payment choice (Option 3) puts a technical decision on agents and makes the CSP and form depend on a payment value, with no business gain.

The status rule follows the engineer's choice and the project rule: the charge reply never completes a payment, and `WorldpayChargeResolver` reads one settled sale for the exact amount and GBP through Payment Queries, the same as NMI and Authorize.net (basis: your AGENTS.md rule that payment status never comes from the client or an unverified reply; verify before applying for money operations). The double charge guard follows NMI (a per payment lock, a pause key set before the call, and no new charge while a possible charge exists), because Worldpay's Payments API has no confirmed idempotency key (basis: idempotency keys for money operations, spec 0012). 3D Secure runs in frames on the page so the client keeps their card entry, and a failed or unavailable check is a refusal, never a retry without 3D Secure, because dropping 3D Secure moves liability to the business and may break UK strong customer authentication rules. The runner up for 3D Secure was a full page redirect, which has a smaller CSP but loses the page state.

Runner ups for the small calls: the 3D Secure link is kept in an encrypted attempt column (runner up: `Cache`, rejected after the cross check because a file cache, several servers or a deploy `cache:clear` would lose it mid check); the retry limit is a config count of 5 (runner up: no limit, rejected because it invites card testing); Google Pay is the last build step and switched off by default because its token type and merchant id are unconfirmed.

The engineer chose cardholder name only for billing data, GBP only, a retry limit, frames for 3D Secure, and the mode lock while payments exist. The References and the PCI wording follow the engineer's choice to flag the PCI form as unconfirmed and make written confirmation a Live gate.

## Step 0 research notes (2026-11-17, public docs only, no Try call yet)

Confirmed from official pages:
- SDK script: Try `https://try.access.worldpay.com/access-checkout/v2/checkout.js`, Live `https://access.worldpay.com/access-checkout/v2/checkout.js`. Initialised with `Worldpay.checkout.init({id, form, fields:{pan, expiry, cvv}}, callback)`; `checkout.generateSessionState(callback)` returns the session. A session lasts one minute and works once. The page must be https. The Checkout ID is a UUID from the implementation manager.
- Payments API: `POST /api/payments` with a `WP-Api-Version` header (a `2024-06-01` spec was seen in a search snippet). Outcome names include `authorized`, `sentForSettlement`, `refused`, `3dsDeviceDataRequired`, `3dsChallenged`, `3dsAuthenticationFailed`, `3dsUnavailable`, `fraudHighRisk`.
- 3D Secure: the device data step is `POST /api/payments/{linkData}/3dsDeviceData` and the challenge verify step is `POST /api/payments/{linkData}/3dsChallenges`.
- Google Pay: built on Google's own Pay API; token type shown as `card/wallet+googlepay` with a `walletToken` on one page and a different spelling in a search snippet.

Not confirmed (the Open questions in `index.md`): the charge body field names, idempotency, 3D Secure frame details and hosts, the session field name, test cards, the Google Pay merchant id field, CSP hosts, domain allow listing and the PCI form.

## References

**Project sources**:
- `AGENTS.md`: scoped exception to webhooks only status, per account clients, never trust client confirmation, amount from the `Payment` record, CSP hosts only on the provider's pay page, `sentry-block` card forms
- Spec 0014 (Worldpay hosted pages): resolver, lookup table, sweep, narrative rules, GBP only
- Spec 0012 (NMI) and spec 0007 (Authorize.net): card fields, double charge guard, attempts table, event reports
- Spec 0009 (Whop): per account embed or redirect mode and its lock

**Practices & standards**:
- Strangler pattern for adding a new path beside a live one
- Idempotency and read back verification for money operations
- Server side amount, never from the client
- PCI DSS SAQ A versus SAQ A-EP for a third party script on the payment page (unconfirmed for this SDK)

**Links** (web verified in the research check on 2026-11-17):
- Worldpay Checkout card only (web): https://docs.worldpay.com/access/products/checkout/web/card-only
- Worldpay components (Checkout ID): https://docs.worldpay.com/access/access/products/components
- Worldpay Checkout web changelog: https://developer.worldpay.com/products/checkout/web/changelog
- Worldpay Payments 3D Secure actions: https://developer.worldpay.com/products/access/payments/openapi/3ds-actions
- Worldpay Google Pay: https://docs.worldpay.com/access/products/wallets/googlepay
- Worldpay Payments overview: https://docs.worldpay.com/access/products/payments
