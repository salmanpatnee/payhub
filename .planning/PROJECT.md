# PayHub

## What This Is

PayHub is a centralized payment infrastructure system for an agency managing multiple brands. It replaces a fragmented multi-site WooCommerce setup with a single Laravel 13 + Inertia.js application that generates branded payment links, processes payments via Stripe Elements, and provides unified reporting. Clients never see backend complexity — they see the brand they trust.

## Core Value

Clients always feel they are paying the same brand they interacted with, regardless of which Stripe account or backend system processes the payment.

## Requirements

### Validated

**Phase 1: Foundation** *(validated 2026-05-03)*
- Laravel 13 + Inertia v3 + Vue 3 + Tailwind CSS 4 + shadcn-vue scaffolded and running
- All D-03 packages installed at pinned versions (Fortify, spatie/permission, stripe-php, stripe-webhooks, Telescope)
- Schema correct: brands, stripe_accounts (TEXT encrypted cols), payments (unsignedBigInteger cents)
- AES-256 encryption round-trip verified for Stripe credentials (SEC-01)
- Integer cents enforced for amounts — no float drift
- Model relationships traversable (Brand → StripeAccounts → Payments)
- Idempotent seeder: 1 brand, 1 Stripe account, 1 admin user, 2 roles
- 49/49 tests passing

### Active

**Auth & Access**
- [ ] Admin can invite team members (no public registration)
- [ ] Admin and User can log in with email/password via Laravel Fortify
- [ ] Role-based access: Admin has full access, User can create payments and view own history

**Brand Management (Admin only)**
- [ ] Admin can create and manage brands (logo, colors, display name)
- [ ] v1: Single domain (pay.agency.com) renders all brands — no per-brand subdomains yet

**Stripe Account Management (Admin only)**
- [ ] Admin can add and configure Stripe accounts (publishable + secret key pairs)
- [ ] Secret keys encrypted at rest in DB (AES-256) — no external secret stores
- [ ] Admin can link Stripe accounts to brands
- [ ] Admin can test Stripe connection

**Payment Creation (Admin + User)**
- [ ] Admin/User can create a one-time payment (amount, description, brand, Stripe account, currency)
- [ ] Currency: USD ($) or GBP (£) per payment — fixed two-currency system
- [ ] System generates unique shareable payment link
- [ ] Payment links never expire (valid until paid or cancelled)

**Client Payment Experience**
- [ ] Client opens branded payment page (no login required)
- [ ] Inline Stripe Elements form styled to brand colors
- [ ] Payment processed under the selected Stripe account
- [ ] Client receives email receipt on successful payment

**Webhooks & Status Sync**
- [ ] Stripe webhook verifies and updates payment status (pending / completed / failed)
- [ ] Admin receives notification on payment completion (v1)

**Dashboard & Reporting**
- [ ] Admin: unified dashboard showing all payments across all brands
- [ ] Admin: filter by brand, Stripe account, status, date
- [ ] User: own payment history view

### Out of Scope

- Per-brand subdomains (pay.brandA.com) — deferred to v2, single domain sufficient for v1
- Subscriptions / recurring billing — one-time payments only by design
- External secret stores (AWS Secrets Manager, Vault) — encrypted DB sufficient
- Slack/webhook notifications — admin email only for v1
- SSO (Google/Microsoft) — invite-only email/password is sufficient
- Public registration — invite-only to control access
- Analytics / conversion tracking — deferred to optimization phase

## Context

This system solves a real operational problem: the agency runs multiple brands across separate WordPress/WooCommerce sites, each with its own Stripe account. Clients see different domains and inconsistent payment experiences, which erodes trust. PayHub centralizes this under one application.

**Technical environment:**
- Laravel 13 backend
- Vue 3 frontend via Inertia.js (not a standalone SPA — Inertia-rendered pages)
- Tailwind CSS 4 + shadcn/ui for styling
- Stripe Elements for embedded, brand-styled payment forms
- Multi-account Stripe setup: PaymentIntents created under the brand's specific Stripe account

**Stripe multi-account pattern:**
Each payment selects a brand → resolves linked Stripe account → creates PaymentIntent under that account → initializes Elements with that account's publishable key.

## Constraints

- **Tech Stack**: Laravel 13, Vue 3, Inertia.js v3, Tailwind 4, shadcn/ui, Stripe Elements — already decided
- **Security**: Stripe secret keys must be encrypted at rest (AES-256 in DB); no external secret management services
- **Payments**: One-time only — no subscriptions, no recurring billing
- **Auth**: Laravel Fortify, invite-only — no public registration, no SSO for v1
- **Multi-domain**: Single domain for v1 — per-brand subdomains are v2
- **Notifications**: Admin email only for v1 — client gets receipt, no Slack/webhook

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Inertia.js instead of standalone SPA | Simpler auth, SSR-friendly, less infra complexity than separate API + SPA | — Pending |
| Stripe Elements over Stripe Checkout | Full UI control, seamless brand experience, no Stripe-hosted redirect | — Pending |
| Single domain for v1 | Subdomains add DNS/SSL complexity — defer until brand count justifies it | — Pending |
| Encrypted keys in DB over external secrets | No external service dependency, AES-256 sufficient for internal tool | — Pending |
| Invite-only auth | Controlled internal tool — no public user growth needed | — Pending |
| Multi-currency support from v1 | Agency works across markets, retrofitting later is painful | — Pending |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-05-03 — Phase 1 complete*
