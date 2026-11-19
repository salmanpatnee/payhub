# 0013. Rationale: Bank of America provider

## Context

> ⚠️ Premise note: this spec is written before any real sandbox call (the sign up page was under maintenance on 2026-11-17). Every Bank of America field name comes from CyberSource style docs. The biggest unknown is how requests are signed: the Microform guide asks for a Shared Secret, but newer docs say JWT signing and message level encryption (MLE) are required from Sept 2026, which normally needs a `.p12` certificate. Building the pay page or locking the account form before step 0 risks rework, so the build plan holds the browser half until the sandbox answers.

PayHub has ten parallel providers. A client using Bank of America Merchant Services (money settles into their Bank of America business checking account) wants payments to go through that gateway. The gateway is CyberSource based: same API paths, same Flex Microform product, Bank of America hosts.

Forces:
- **PCI scope**: PayHub should not see card numbers. SAQ A is the target.
- **Follow up actions**: the hosted page and Checkout API cannot refund, void or capture; only Microform plus REST and the direct API can.
- **Status rule**: PayHub never trusts the browser. A scoped exception lets server side read backs set status (Clover, MPGS, Authorize.net, Kurv, NMI).
- **No idempotency key and no known webhook**: a timed out charge must be found later by our own reference.
- **Required fields**: the charge guide lists billing and shipping address and email; our pay pages collect none of these today.
- **Unknowns**: 3D Secure, GBP, wallets and webhooks are all unconfirmed.

## Options considered

### Option 1: Microform plus REST charge (chosen)

Microform frames for card number and CVV, a one time token, server side sale, read back before status.

**Pros**: SAQ A likely; refunds and voids possible later; fits the NMI pattern exactly.
**Cons**: two moving parts (capture context plus charge); billing address must be added to the page; signing method unconfirmed.

### Option 2: Hosted Payments Page (HPP)

Redirect to Bank of America's page, like Kurv.

**Pros**: SAQ A, least front end work, no address fields on our side.
**Cons**: sale and authorization only, no refund, void or capture ever; 3DS "not yet available"; status still needs a lookup.

### Option 3: Checkout API

Our page posts card fields straight to Bank of America.

**Pros**: full control of the form.
**Cons**: SAQ A-EP (our page renders the card fields); same no refund limits as HPP.

### Option 4: CNP Toolkit (direct REST with card numbers)

**Pros**: simplest server flow.
**Cons**: card numbers on our server, SAQ D. Not acceptable.

## Rationale

Option 1 is the only path that keeps SAQ A and leaves refunds and voids open for later. It also reuses every NMI building block (resolver, attempts, pause key, sweep, card lock, page events), so the team builds a known shape instead of a new one. HPP was the closest rival: less work today, but a permanent ceiling on follow up actions.

Choices made in the interview (engineer picked each; the recommended pick in every case):
- **Charge only, sale with capture**: matches every other provider; refunds stay in the dashboard for now.
- **USD only**: only USD appears in the docs and the money settles into a US account. Runner up: one currency per account, like NMI.
- **Billing address, no shipping, never stored**: meets the guide's billing rule with the least personal data kept. If `shipTo` is truly required, step 0 records it and billing is copied over.
- **No 3DS, cards only, no webhook**: none of these are confirmed for Microform; each is a later spec update.
- **CyberSource PHP SDK**: it builds request signing and MLE, which the research warned not to hand write. Runner up: Laravel Http with our own signing (smaller, but risky crypto code).
- **Shared Secret now, `.p12` later if needed**: matches the Microform guide; the form changes only if step 0 proves a certificate is needed.
- **Search by our reference code after a timeout**: the only way to find a sale whose id we never received. Runner up: pause and let an admin check by hand.
- **No local token signature check**: the gateway validates the token when charging, and the amount never comes from the client.
- **Held for review stays pending, partial approval never completes, double charge guard as NMI, sweep 48 hours with a 30 minute give up**: same family rules as Authorize.net and NMI.
- **Backend first with a mocked client**: lets work start while the sandbox is down without guessing the browser side.

Decided while writing (not asked):
- **Session endpoint after consent**: no gateway call for visitors who never pay, and the 15 minute context can be refreshed. Runner up: page prop on load.
- **Test connection by creating a capture context**: proves the keys with no transaction. Runner up: a Transaction Search, which may lag or need extra permissions.
- **Read back by transaction details plus search by reference**: covers both a known id and a timeout. Runner up: only `GET /pts/v2/payments/{id}`, which cannot find a sale without its id.
- **Microform script URL checked against the configured host**: the capture context names the script, so the page refuses any host outside config.
- **Tests mock `BofaClient`**: the SDK uses its own HTTP stack, so `Http::fake` cannot intercept it.
- **Naming `bofa`**: short like `mpgs` and `nmi`; display name "Bank of America".

## References

**Project sources**:
- `docs/research/bank-of-america.md` (all Bank of America and CyberSource facts, with links to the Gateway Integrations Overview, Transaction Support by Integration Method, Security Keys, Shared Secret key generation, Flex Microform Developer's Guide, REST getting started guide and testing guide)
- `docs/specs/0012-nmi-provider/` (resolver, attempts, pause key, sweep, page events, CSP pattern)
- `docs/specs/0010-kurv-provider/` (USD only rule, required client email)
- `docs/specs/0007-authorizenet-acceptjs-own-card-fields/` (held charges stay pending)
- `AGENTS.md` (Critical rules: per account clients, encrypted secrets, amount from the server, scoped exception to webhooks only status)

**Practices & standards**:
- PCI DSS SAQ A for card data kept in provider hosted frames
- Read back before trusting a payment reply (no status from the client or the reply alone)
- Double charge guard without an idempotency key: lock, pause before the call, search by our own reference
- Data minimisation for personal data (address sent, not stored)
