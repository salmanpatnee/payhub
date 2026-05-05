# Phase 4: Payment Creation + Link Generation - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-05
**Phase:** 04-payment-creation-link-generation
**Areas discussed:** Brand ↔ Stripe account pairing, Route structure + who can create, Amount input UX, Post-creation flow + payment list scope

---

## Brand ↔ Stripe Account Pairing

| Option | Description | Selected |
|--------|-------------|----------|
| Independent dropdowns | Two separate dropdowns: pick any brand, pick any active Stripe account. No coupling enforced. | ✓ |
| Cascade filter (brand → accounts) | Selecting brand filters stripe account dropdown. Requires pivot table or restoring brand_id. | |
| Stripe account only, brand auto-inferred | Brand derived from stripe account ownership. Requires re-linking. | |

**User's choice:** Independent dropdowns

---

| Option | Description | Selected |
|--------|-------------|----------|
| FK + active check only | Validate brand exists, stripe account exists and is_active=true. No cross-referencing. | ✓ |
| Explicit allow-list | Admin configures which stripe accounts are allowed per brand before payment creation. | |
| You decide | Claude picks validation — likely FK + active check. | |

**User's choice:** FK + active check only

---

## Route Structure + Who Can Create

| Option | Description | Selected |
|--------|-------------|----------|
| /payments (auth only) | Payment creation and list at /payments — auth+verified, accessible to all logged-in users. | ✓ |
| /admin/payments (admin-only) | Under /admin/ with role:admin. Contradicts PAY-01. | |
| Split routes | Admin at /admin/payments (full), User at /payments (own only). | |

**User's choice:** /payments (auth only)

---

| Option | Description | Selected |
|--------|-------------|----------|
| Role-scoped list | Admin sees all payments. User sees only their own (user_id = auth()->id()). | ✓ |
| Own payments only for everyone | Every user sees only their own on /payments. Admin full view deferred to Phase 7. | |
| You decide | Claude picks scoping — likely role-scoped. | |

**User's choice:** Role-scoped list

---

## Amount Input UX

| Option | Description | Selected |
|--------|-------------|----------|
| Decimal input, server converts | User types 25.00 or 10.50. Server multiplies by 100, stores as integer cents. | ✓ |
| Integer cents input | User types 2500 (raw cents). No conversion needed. | |
| Integer dollars only (no pence/cents) | Whole amounts only — can't express £10.50. | |

**User's choice:** Decimal input, server converts

---

| Option | Description | Selected |
|--------|-------------|----------|
| Minimum only — > 0 | Validate amount > 0 in dollars. No max. Stripe limits apply at payment time. | ✓ |
| Min + max range | Enforce a server-side maximum (e.g. £50,000) in addition to > 0. | |
| You decide | Claude picks appropriate validation bounds. | |

**User's choice:** Minimum only — > 0

---

## Post-Creation Flow + Payment List Scope

| Option | Description | Selected |
|--------|-------------|----------|
| Show page with link | Redirect to /payments/{uuid} — dedicated show page with prominent copy button. | ✓ |
| Redirect to list with flash | Redirect to /payments index with flash message containing the copyable link. | |
| Modal on list page | Redirect to index and auto-open modal with shareable link. | |

**User's choice:** Show page with link

---

| Option | Description | Selected |
|--------|-------------|----------|
| Simple list, no filters | Table: amount, currency, brand, status, created date, client email, copy-link. No filtering. | ✓ |
| List with basic status filter | Status filter (pending/completed/failed) in Phase 4. Other filters deferred. | |
| Full dashboard early | All filtering in Phase 4. Scope creep risk. | |

**User's choice:** Simple list, no filters

---

## Claude's Discretion

- Exact shadcn-vue components used in the payment form and table
- Route naming conventions (payments.index, payments.create, payments.store, payments.show)
- Status badge colors (pending = warning, completed = success, failed = destructive)
- Copy-to-clipboard implementation (navigator.clipboard.writeText or small utility)
- Amount formatting (server-side PHP number_format or client-side Intl.NumberFormat)

## Deferred Ideas

- Payment cancellation/void — v2 feature
- Editing a payment — payments are write-once
- Status filter on /payments index — Phase 7 dashboard (DASH-02)
- PaymentIntent creation — Phase 5 (when client opens payment page)
- Link expiry — expires_at = null; PAY-07 says links never expire
