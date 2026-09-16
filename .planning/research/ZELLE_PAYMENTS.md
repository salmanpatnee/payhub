# Zelle Payments — Feasibility Research

**Date:** 2026-09-16
**Context:** Evaluated as a potential additional payment provider for PayHub, alongside Stripe, Revolut, Square, and Viva. Requirement: PayHub payments must support programmatic checkout-session creation, webhook-driven status updates, and USD/GBP currency handling, the same shape Stripe/Revolut/Square/Viva already provide.

**Recommendation: Do not adopt.** Zelle has no public developer API for third-party businesses at all — unlike Clover or Viva, there is no `docs.zelle.com`, no hosted checkout endpoint, no OAuth flow, and no webhook product a company like PayHub can register for. Zelle cannot be integrated into PayHub's `PaymentProvider` abstraction in any form that resembles Stripe/Clover/Viva.

**Important caveat on sourcing**: Zelle (operated by Early Warning Services, LLC, jointly owned by several major US banks) publishes no technical developer documentation comparable to `docs.clover.com` or `stripe.com/docs`. Everything below is sourced from Zelle's own consumer/business marketing pages, banking-industry reporting, and general knowledge of how the Zelle network operates — **not from an API reference**, because none exists publicly. Any claim below that would normally cite a specific docs page is instead flagged as general/industry knowledge or explicitly marked **not confirmed**.

---

## 1. What Zelle Actually Is

Zelle is a **bank-to-bank money transfer network**, not a payment processor. It moves money directly between two parties' bank/credit union accounts in the US, in real time, without a card network, a merchant acquirer, or a checkout page in the Stripe/Clover sense. Over 2,200 US banks and credit unions participate, either through Zelle's own consumer app (discontinued April 2025 — see [Payments Dive: "EWS drops Zelle standalone app"](https://www.paymentsdive.com/news/early-warning-services-drops-zelle-app-bank-payment-transfers/744073/)) or, for the vast majority of users, directly inside their own bank's mobile/online banking app.

There is no Zelle-hosted checkout page, no Zelle-issued payment link, and no Zelle account independent of a bank account. A "Zelle payment" is really just an instant ACH-like transfer that happens to be routed through the Zelle network your bank participates in.

---

## 2. Can Zelle Be Integrated Like Clover/Viva/Stripe? — No

| What PayHub needs | Stripe / Clover / Viva | Zelle |
|---|---|---|
| Public developer API to create a checkout session | Yes | **No public API for merchants/software platforms exists** |
| Programmatic payment link generation | Yes | **No** — a Zelle "payment" is initiated by the payer manually entering an amount + the recipient's registered email/phone inside their own bank's app |
| Webhook on payment completion | Yes | **No official webhook product.** Some third-party services (e.g. `poof.io`, WHMCS Zelle plugins) advertise "Zelle webhooks," but these operate by parsing bank-sent notification emails/SMS or similar indirect signals, not an official Early Warning Services API — mechanism undisclosed publicly, not confirmed to be sanctioned by Zelle, and not something PayHub should build a payment-critical flow on top of |
| OAuth / merchant-authorizes-app flow | Yes (Stripe Connect, Clover App Market, Viva ISV) | **No such concept.** There is no "Zelle App Market" a SaaS platform can register on |
| Status/reconciliation API (`GET charge`) | Yes | **No.** The only way to confirm a Zelle payment arrived is to look at the receiving bank account's own statement/transaction feed — via that bank's *own* proprietary business-banking API (if it has one), which is unrelated to Zelle itself |

Sources: [Zelle Integration API overview — ApiX-Drive](https://apix-drive.com/en/blog/other/zelle-integration-api) (confirms integration happens through bank partnerships/toolkits, not a direct public API); industry search results consistently describing "no direct public API exists for Zelle as it is a bank-to-bank transaction" and integrations working "through partnerships with financial institutions and specialized toolkit providers."

**What does exist** is developer tooling built **for banks and credit unions**, not for merchants like PayHub:
- Core-banking vendors (Jack Henry, FIS, Fiserv, Apiture, Alacriti/Orbipay) sell "Zelle toolkits" that a *bank* embeds into its own online/mobile banking product. These are bank-side integrations — PayHub cannot sign up for one as an independent business.
- A handful of bank-specific business/treasury APIs (e.g., a "Disbursements via Zelle" product on a specific bank's developer portal) let *that bank's own commercial customers* send Zelle payouts programmatically through that bank's proprietary API — again, tied to a specific bank relationship, not a general Zelle API PayHub could use across any merchant's bank.

---

## 3. The Only Real-World "Integration": Manual, Bank-Enrolled Zelle for Business

The actual way a business accepts Zelle today:

1. The business enrolls its bank account as a **"Zelle for Business" / small-business profile** directly with its own bank (not with Zelle) — some banks charge a per-transaction fee for business use even though consumer-to-consumer Zelle is typically free.
2. The business shares its Zelle-registered **email address or phone number** with customers (e.g., displayed on an invoice or a page).
3. The customer opens **their own bank's app**, manually enters that email/phone and an amount, and sends the transfer.
4. The money lands in the business's bank account, usually within minutes.
5. The business (or its bookkeeper) manually checks its own bank statement/online banking to confirm the payment arrived, and manually matches it to an invoice/reference number the customer was asked to put in the memo field — Zelle has no structured "reference/order ID" field guaranteed to survive as structured data end-to-end.

This is fundamentally a **manual, human-reconciled flow** — there is no programmatic session, no redirect, no webhook, and no guaranteed way to auto-match a payment to a specific `Payment` row in PayHub without a human checking a bank statement.

---

## 4. Currency, Limits, Fees

- **USD only, US bank/credit union accounts only.** Zelle has no international reach and no multi-currency support of any kind — it cannot serve GBP at all, the same dealbreaker that ruled out Viva (for USD) and Clover (for GBP).
- **Transaction limits are set by each participating bank, not by Zelle uniformly** — commonly in the range of a few hundred to a few thousand dollars per day/transaction for consumer accounts, sometimes higher for enrolled business accounts, but this varies bank to bank and is **not confirmed** at a network-wide level from public documentation.
- **Fees**: Zelle itself doesn't charge, but individual banks may charge for business-tier Zelle usage.

---

## 5. Disputes, Refunds, and Fraud Risk — a structural problem for a payment product

- Zelle transfers are designed to behave like **cash/instant bank transfers**: once sent, they are very difficult to reverse and there is **no chargeback or formal buyer-protection dispute process** comparable to card networks (Stripe/Clover/Square) or even Revolut/Viva's card-rail dispute handling.
- There is no "refund API" — reversing a Zelle payment means the business manually sending a new Zelle transfer back to the customer, with no built-in linkage to the original transaction.
- Because of this irreversibility, Zelle is widely reported (banking-industry press) as a common vector for payment scams, and several banks' business terms restrict or discourage using Zelle for arm's-length commercial transactions with strangers — a materially different risk profile than a PCI-compliant hosted checkout page.

---

## 6. Comparison for Our Use Case

| Dimension | Stripe | Revolut | Square | Viva (UK entity) | Clover | **Zelle** |
|---|---|---|---|---|---|---|
| Public API to create a payment/checkout | Yes | Yes | Yes | Yes | Yes | **No** |
| Currency | USD + GBP | USD + GBP | Per-account lock, USD supported | GBP only | USD (CAD) only | **USD only, no international** |
| Webhook-driven status updates | Yes | Yes | Yes | Yes | Yes | **No official webhook** |
| Refund/dispute mechanism | Yes | Yes | Yes | Yes | Yes (limited) | **No — manual reverse-transfer only** |
| Fits PayHub's `PaymentProvider` abstraction | Yes | Yes | Yes | Yes | Yes | **No — nothing to abstract; there's no session/webhook/status API to wrap** |

---

## 7. Risks, Limitations, Final Recommendation

**Risks/limitations:**
1. **No public API exists.** This isn't a currency or credentials gap like Clover/Viva — it's a complete absence of the integration surface PayHub's architecture depends on (create session → redirect → webhook → status). There is nothing to build a `ZelleClient`/`ZelleWebhookController` against.
2. **No webhook, no reconciliation API** — any "PayHub knows a Zelle payment arrived" signal would have to come from a human checking a bank statement, or from an unofficial third-party service of undisclosed and unverified mechanism (email parsing, likely against bank/Zelle terms of service, and a fraud/reliability risk for a payments-critical flow).
3. **USD-only, no GBP support at all** — same class of failure as Clover, on top of the API gap.
4. **No refund/dispute mechanism** — materially higher fraud and reversal risk than every other provider already in PayHub.
5. **Third-party "Zelle API" products found in research (e.g. `poof.io`, WHMCS plugins) could not be confirmed as official, Zelle/EWS-sanctioned integrations** — their actual detection mechanism is undisclosed on their marketing pages. Building on top of one would mean depending on an unverified, likely-unofficial workaround for money-moving/reconciliation logic, which is not appropriate for PayHub's payment-critical rules (CLAUDE.md: status must come from an authoritative source, never inferred).

**Final recommendation: Do not pursue Zelle as a PayHub payment provider.** There is no API to integrate against — this isn't a matter of a narrower scope (like Clover's USD-only lock or Viva's GBP-only lock), but a fundamental "there is nothing here to build" finding. If a business genuinely wants to accept Zelle payments, the only path is a manual, bank-enrolled "share your Zelle email/phone and reconcile by hand" workflow entirely outside PayHub's automated payment-link/webhook model — not something worth encoding into the `PaymentProvider` enum or the payment-link product.
