# Feature Landscape: PayHub — Multi-Brand Payment Hub

**Domain:** Centralized payment management portal for agency managing multiple brands via Stripe
**Researched:** 2026-05-02
**Context:** Internal tool (Admin + User roles), client-facing payment pages (no login), one-time payments only

---

## Table Stakes

Features users expect. Missing = product feels incomplete or broken.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Create payment (amount + currency + brand selection) | Core function — this is the entire point of the tool | Low | Admin and User roles both need this |
| Shareable payment link | How clients receive and complete payments; without this there is no product | Low | Single URL per payment; non-expiring per v1 decision |
| Branded client-facing payment page | Clients must see the correct brand, not a generic page — trust and professionalism require it | Med | Per-brand logo, color, accent; stored against each brand config |
| Stripe Elements payment form (card input) | Actual payment capture; chosen over Stripe Checkout for full control of UX | Med | Card only is acceptable for v1 (wallets can come later) |
| Payment success state for client | Client needs immediate confirmation payment was accepted; absence creates support load | Low | Post-payment "thank you" page with summary |
| Client email receipt on payment completion | Clients expect a record of what they paid and to whom | Low | Triggered via Stripe webhook on payment_intent.succeeded; branded per brand |
| Admin email notification on payment completion | Ops awareness; without this staff miss payments and follow-ups break down | Low | Single notification email to configured admin address |
| Payment status tracking (paid / unpaid / failed) | Staff need to know whether a client has paid without calling them | Low | Stored in DB, synced from Stripe webhook events |
| Payment history list per user | Users need to see the payments they created; basic accountability | Low | Filtered to own payments for User role; all payments for Admin |
| Admin: full payment history across all brands | Admin oversight requires cross-brand visibility | Low | Filter/search by brand, date range, status |
| Multi-brand management (configure N brands) | The entire agency use-case requires brand isolation | Med | Each brand maps to a distinct Stripe account |
| Per-brand Stripe account binding | Revenue must land in the correct Stripe account — routing errors mean financial misattribution | Med | Admin configures which Stripe secret key belongs to which brand |
| Invite-only user management | No public registration; agency controls who can create payments | Low | Admin invites staff by email; decided in v1 scope |
| Role distinction: Admin vs User | Admin configures, User operates — without this there is no access control | Low | Middleware/policy layer on routes |
| Multi-currency support (creator-selected per payment) | Clients are often in different countries; wrong currency breaks payments | Low | Currency selected at payment creation time, passed to Stripe PaymentIntent |
| Refund capability (admin) | Stripe charges can need reversing; without this admin must log in to Stripe directly | Med | Proxied through Stripe API per connected account |
| Webhook handling for Stripe events | Required to receive real-time payment status updates reliably; polling is not acceptable | Med | payment_intent.succeeded, payment_intent.payment_failed minimum |

---

## Differentiators

Features that set PayHub apart from "just use Stripe directly" and add genuine agency workflow value.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Per-brand visual branding on client payment page | Every client sees the brand they expect — not a generic Stripe page or the agency's own branding | Med | Custom logo, brand color, accent color stored per brand; applied at render time |
| Unified cross-brand reporting dashboard | Single view of all payment activity across every brand — not possible by logging into multiple Stripe accounts separately | Med | Filter by brand, date range, currency, status |
| Payment notes / internal reference field | Lets staff annotate what a payment is for (invoice number, project name) without cluttering the client-facing page | Low | Stored in PaymentIntent metadata on Stripe side |
| CSV export of transaction history | Finance teams need to reconcile against bank statements; export avoids manual copying from Stripe | Low | Date-range filter, per-brand or all-brands export |
| Payment description shown on client page | Contextualises the charge for the client ("Website redesign — Phase 1") rather than a naked amount | Low | Free-text field on payment creation, rendered on payment page |
| Audit trail: who created which payment | Accountability for internal staff; important if disputes arise about whether a payment was sent | Low | Created-by, created-at stored and visible in admin |
| Payment link copy-to-clipboard UX | Staff need to send the link to clients — friction here means links get shared incorrectly | Low | Single click copy in the payment detail view |
| Brand selector at payment creation with Stripe account preview | Reduces routing errors; staff can confirm they are charging to the right account before sending | Low | Show brand name + last 4 of Stripe key or display name |
| Admin-configurable brand details (name, logo, colors) | Agency adds new brands without code deploys | Med | CRUD for brands in admin settings |
| Payment amount visible in link preview / email subject | Client knows what to expect before clicking; reduces abandoned payment pages | Low | Include amount + brand name in email subject and link metadata (og:title) |

---

## Anti-Features

Things to deliberately NOT build in v1 (and reasoning).

| Anti-Feature | Why Avoid | What to Do Instead |
|--------------|-----------|-------------------|
| Per-brand subdomains (brand1.pay.agency.com) | DNS provisioning, SSL cert management, and routing complexity massively outweigh benefit for v1; already explicitly out of scope | Use a single domain with brand identified by URL path or payment record; serve branding from DB |
| Subscriptions / recurring payments | Requires billing plan management, dunning, cancellation flows, failed payment retries — a separate product surface entirely | Build one-time only; recurring is a v2 scope decision |
| Client login / client portal | Clients have no business need to manage their payments in this tool; adding auth for clients creates onboarding friction and support overhead | Stateless payment page accessed by unique link is the correct model |
| Public user registration | Agency controls who uses this; public registration creates security risk and compliance overhead | Invite-only flow only |
| Slack / webhook notification routing | Operational complexity with minimal v1 value; admin email notification covers the need | Email notification is sufficient for v1; add Slack in v2 if demanded |
| SSO / SAML / OAuth provider integration | Invite-only email auth is sufficient for an internal agency tool at v1 scale | Simple email+password with Laravel's built-in auth; SSO is a v2 or enterprise concern |
| Multi-PSP routing (fallback to non-Stripe processor) | Each brand is explicitly on Stripe; adding alternative processors means managing multiple webhook schemas, API differences, and reconciliation complexity | Stripe-only; if a brand later migrates, that is a configuration change |
| Chargeback / dispute management UI | Stripe's own dashboard handles disputes adequately; building a competing UI has no ROI at this stage | Link admin to Stripe dashboard for dispute handling |
| Customer-entered amount (open-ended payment links) | Introduces ambiguity about what is owed; misuse risk for internal tools | Staff always sets the amount at creation time |
| Advanced fraud scoring / custom rules engine | Stripe Radar handles this automatically on all PaymentIntents; duplicating it is wasted effort | Rely on Stripe Radar as-is |
| Invoice PDF generation | Scope creep; Stripe already generates receipts | Stripe's automatic receipts are sufficient; if PDF invoicing is needed it is a separate product decision |

---

## Feature Dependencies

```
Brand config (name, logo, colors, Stripe key)
  └── Payment creation (requires brand selection)
        └── Payment link generation (requires payment record)
              └── Branded client payment page (requires brand config at render time)
                    └── Stripe PaymentIntent creation (requires Stripe key from brand)
                          └── Stripe webhook (payment_intent.succeeded)
                                ├── Payment status update in DB
                                ├── Client email receipt (requires client email collected on form)
                                └── Admin notification email

User management (invite-only)
  └── Role assignment (Admin / User)
        ├── Payment creation (both roles)
        └── Admin-only: brand management, full reporting, refunds

Multi-currency
  └── Payment creation (currency field)
        └── Stripe PaymentIntent (currency param)

CSV export
  └── Payment history list (requires filterable payment records in DB)

Audit trail (created-by)
  └── User auth (requires authenticated session at creation time)
```

---

## MVP Recommendation

Prioritize this order based on dependency chain and user value:

1. **Brand management CRUD** — everything else depends on at least one brand existing
2. **User invite + auth + roles** — staff can't create payments without accounts
3. **Payment creation form** (amount, currency, brand, description, internal note)
4. **Branded client payment page** (Stripe Elements + per-brand visual config)
5. **Stripe PaymentIntent lifecycle + webhook handling** (status sync)
6. **Payment status in admin** (paid/unpaid/failed visible to creator and admin)
7. **Client email receipt + admin notification** (post-payment webhook-triggered)
8. **Payment history views** (per-user and all-brands admin view)

Defer to v1.1 (after launch validation):
- **CSV export** — useful but not blocking; admin can query Stripe directly initially
- **Refunds UI** — admin can process via Stripe dashboard initially; build UI once usage volume justifies it
- **Cross-brand reporting dashboard with aggregated analytics** — basic list view is enough for MVP; charts/aggregations add scope

---

## Sources

- [Payment Gateway Features 2025 — DECTA](https://www.decta.com/company/media/payment-gateway-features-in-2025-16-must-have-capabilities-and-selection-checklist)
- [Stripe Branding per Account — Stripe Docs](https://docs.stripe.com/get-started/account/branding)
- [Stripe Elements — Stripe Docs](https://docs.stripe.com/payments/elements)
- [Stripe Metadata — Stripe Docs](https://docs.stripe.com/api/metadata)
- [Stripe Connect — Multi-account management](https://stripe.com/connect)
- [Building a Payments Hub — Modern Treasury](https://www.moderntreasury.com/journal/building-a-payments-hub)
- [Payment Link UX Best Practices — ECS Payments](https://www.ecspayments.com/online-payment-links/)
- [Payment Form Anti-Patterns — Evil Martians](https://evilmartians.com/chronicles/payment-form-best-coding-practices-that-dont-drop-sales)
- [Payment UX Best Practices — GoCardless](https://gocardless.com/guides/posts/payment-ux-best-practices/)
- [Role-Based Access in Financial Systems — Lightspark](https://www.lightspark.com/glossary/role-based-access-control-)
- [Audit Trail Guide — Middleware.io](https://middleware.io/blog/audit-logs/)
- [Payment Confirmation Email Best Practices — Zoho ZeptoMail](https://www.zoho.com/zeptomail/articles/payment-confirmation-email.html)
