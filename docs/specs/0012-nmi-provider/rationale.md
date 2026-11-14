# 0012. NMI provider: rationale

## Context

Beyond Bancard (a merchant account reseller) gave the agency an NMI gateway account. To take those payments in PayHub, NMI must become a tenth provider next to the nine that exist. The findings are in `docs/research/nmi.md`. A sandbox merchant (gateway ID 1363510, Test 3DS on) and both keys already exist.

These are the forces at play:
- **Rules in `AGENTS.md`:** never trust the browser for status, never take an amount from the client, keep secrets encrypted, and build every surface for every provider.
- **NMI's own tools:** a current Payment Component with a v5 REST sale call, an older Collect.js with `transact.php`, a Three-Step Redirect, and a hosted Collect Checkout whose amount comes from product SKUs or from the customer.
- **NMI webhooks:** they are signed but carry no timestamp, and they retry for only about 18 minutes. The only documented status lookup is the Classic XML `query.php`.
- **Compliance:** PCI DSS scope, because this takes card payments.
- **Product asks:** 3D Secure per merchant, USD or GBP, and Google Pay.

If nothing is decided, Beyond Bancard merchants either can't be used, or get charged outside PayHub through a third party WooCommerce plugin, which breaks central reporting.

## Options considered

### Option 1: Payment Component and v5 sale, status from a resolver lookup (chosen)

NMI frames tokenize the card or Google Pay. PayHub's server charges the token with the server side amount, then confirms with `query.php` through one resolver. The webhook and the sweep trigger the same lookup.

**Pros**: It's NMI's recommended path for new builds. Card data stays in NMI frames. It fits the existing Clover, MPGS and Authorize.net pattern. Google Pay and 3DS come from the same package.
**Cons**: It mixes the JSON v5 API with XML `query.php`, adds a new npm dependency, and brings styling limits inside the frames.

### Option 2: Collect.js and Classic `transact.php`

The same embed idea with NMI's older library, and one form encoded API for both sale and query.

**Pros**: One API style. It has been around a long time.
**Cons**: NMI keeps it for existing integrations, so we'd be building new work on a legacy path. Its 3DS and wallet setup is separate and older.

### Option 3: Collect Checkout (hosted redirect)

PayHub sends the customer to NMI's hosted page.

**Pros**: No card UI on PayHub. Smallest PCI scope. No CSP change.
**Cons**: The amount comes from NMI product SKUs or from the customer typing it in. There's no documented way to send a fixed server side amount, which breaks the "never accept amount from client" rule.

### Option 4: Trust the signed webhook body for status

The same as Option 1, but the signed webhook writes the status directly.

**Pros**: Fewer lookup calls, and simpler code.
**Cons**: NMI's webhook has no timestamp window and stops after about 18 minutes of retries. It would also make NMI the only server charge provider whose body is trusted, which goes against MPGS, Authorize.net and Kurv.

## Rationale

Option 1 is the only one that meets both hard rules at once: the amount comes from the server's `Payment` record (which rules out Option 3), and no status is written from anything PayHub didn't read back itself (which rules out Option 4). Among the embed choices, NMI calls the Payment Component the path for new integrations, and it carries 3DS and Google Pay in one package. That matters because you asked for both. Collect.js would work, but it starts a new integration on a legacy path.

Reading back through `query.php` costs one extra call per charge. In return, a lost reply, a missed webhook (only about 18 minutes of retries) and a mismatched amount all become safe: the sweep and the resolver settle them. The per account currency follows from NMI taking its currencies from the processor linked to the account, the same reason Square has a single currency lock. The webhook is registered by hand because NMI's docs show no API to create one.

## References

**Project sources**:
- `AGENTS.md`: the critical rules, the nine provider parity rule, the Authorize.net, MPGS and Kurv exception pattern
- `docs/research/nmi.md` and `docs/research/beyond-bancard.md`
- Specs 0005 (MPGS), 0007 (Authorize.net own fields) and 0010 (Kurv)

**Practices & standards**:
- Idempotency and guarded single writer updates for money state
- HMAC webhook verification with constant time compare and event id dedupe
- PCI DSS SAQ levels for gateway hosted fields

**NMI docs used (by name)**: Payment Component guide, Integration Guide (frontend, 3D Secure), Payments API v5 sale, Query API (`query.php`), Webhooks (signature and retries), Collect.js (wallets), Collect Checkout.
