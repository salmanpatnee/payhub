# PayHub

## What This Is

PayHub is a centralized payment infrastructure system for an agency managing multiple brands. It replaces a fragmented multi-site WooCommerce setup with a single Laravel 13 + Inertia.js application that generates branded payment links, processes payments across four parallel providers (Stripe, Revolut, Square, Viva — see `PaymentProvider` enum), and provides unified reporting. Clients never see backend complexity — they see the brand they trust.

## Core Value

Clients always feel they are paying the same brand they interacted with, regardless of which payment provider or account processes the payment.

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

### Validated

**Phase 2: Auth + User Management** *(validated 2026-05-03)*
- Invite-only auth — registration disabled; admin creates accounts manually
- Admin and User login/logout via Laravel Fortify; session persistence with remember token
- Role-based access: role:admin middleware guards all /admin/* routes; User role gets 403
- Admin CRUD for team members (create, edit role, delete; self-delete blocked)
- AppSidebar role-aware nav: Users link admin-only; all other nav visible to both roles
- /pay/{uuid} stub accessible without session
- 51/51 tests passing

### Validated

**Phase 3: Brand + Stripe Account Management** *(validated 2026-05-05)*
- Admin CRUD for brands (name, slug, logo, primary/secondary color, website_url)
- Admin CRUD for Stripe accounts (publishable + secret key pairs, webhook_secret)
- Secret keys encrypted at rest via Laravel `encrypted` cast (AES-256)
- Stripe accounts decoupled from brands — top-level /admin/stripe-accounts resource
- Per-account StripeClient instantiation (never global setApiKey)
- Test connection button verifies live key validity against Stripe API
- ConfirmDeleteDialog extracted as reusable component

### Validated

**Phase 4: Payment Creation + Link Generation** *(validated 2026-05-09)*
- Admin/User can create one-time payments (amount, service, package, note, brand, Stripe account, currency)
- Currency: USD ($) or GBP (£) — fixed two-currency system; stored as integer cents
- System generates UUID-based shareable payment link (/pay/{uuid})
- Amount never accepted from client request — always read from server-side Payment record
- Payment history views for admin (all payments) and user (own payments)
- Payment links have no expiry in v1

### Validated

**Phase 5: Client Payment Page** *(validated 2026-05-09)*
- Branded unauthenticated payment page — client sees brand logo and colors
- Inline Stripe Elements form initialized with brand's publishable key
- PaymentIntent created server-side under correct Stripe account; client_secret scoped to page load only
- 3DS challenge handling — payment completes after authentication
- Branded success and failure pages
- PaymentIntent client_secret never logged, stored in URLs, or exposed beyond page load

### Validated

**Phase 6: Webhooks + Status Sync** *(validated 2026-05-13)*
- Per-account webhook endpoint: POST /webhook/stripe/{stripeAccountId}
- Signature verified via Stripe constructEvent with per-account webhook_secret
- Webhook routes excluded from CSRF; raw body preserved for signature verification
- HandleStripeWebhookJob dispatched immediately; status writes are async via queue
- payment_intent.succeeded → status=completed, paid_at set
- payment_intent.payment_failed → status=failed, paid_at null
- Idempotent: already-completed payments are not re-processed on duplicate delivery
- Deactivated accounts return 200 without processing
- webhook_secret stored encrypted; admin UI exposes has_webhook_secret bool only (never raw value)
- blank-means-preserve pattern: empty webhook_secret field on update preserves existing secret
- 14/14 webhook tests passing; live E2E verified via Stripe CLI

### Validated

**Phase 7: Notifications + Dashboard** *(validated 2026-05-14 — v1.0 milestone complete)*
- Admin receives email notification on payment completion
- Admin dashboard: unified payment list across all brands (amount, currency, brand, status, date, client email)
- Admin can filter payments by brand, Stripe account, status, date range
- User can view own payment history

### Validated (post-v1.0 — delivered via ad-hoc feature branches, not phase-plan waves)

**Phase 8: Revolut Payment Provider** *(delivered, exact date not tracked)*
- Revolut Merchant API as a second parallel provider — full parity with Stripe (account CRUD, pay page, webhook, export, dashboard)
- Per-account `RevolutClient`; webhook idempotency via `ProcessedRevolutEvent` (`order_id:event_type`, since Revolut sends no event id)

**Phase 9: Square Payment Provider** *(validated 2026-07-02, merged `feat/square-payment-processor-v2` → `staging`)*
- Square Payments API as a third parallel provider
- Per-account single-currency lock (`square_accounts.currency`) — rejects mismatched-currency payments
- CSV export Provider Reference bug fixed post-merge (`2aa7451`)

**Phase 10: Viva Payments Provider** *(validated 2026-07-11, merged `feat/viva-payment-provider` → `staging` as `4f35859`)*
- Viva Smart Checkout as a fourth parallel provider, GBP-only as a flat platform rule (not per-account like Square)
- Two ids scoped to the account: `viva_order_code` (set at pay-page order creation) and `viva_transaction_id` (set by webhook) — CSV export and payment detail page Provider Reference now fall back to `viva_order_code` when the transaction id isn't set yet
- `PaymentController::clearStaleProviderTransactionIds()` extended to clear both Viva ids on account change
- **Open**: webhook signature header/algorithm and the `TransactionFailed` event type id are unconfirmed against a live sandbox (VIVA-07) — see `.planning/research/VIVA_PAYMENTS.md`

### Out of Scope

- Per-brand subdomains (pay.brandA.com) — deferred to v2, single domain sufficient for v1
- Client email receipt on payment success — deferred to v2 (per-brand email sender identity unresolved)
- Subscriptions / recurring billing — one-time payments only by design
- External secret stores (AWS Secrets Manager, Vault) — encrypted DB sufficient
- Slack/webhook notifications — admin email only for v1
- SSO (Google/Microsoft) — invite-only email/password is sufficient
- Public registration — invite-only to control access
- Analytics / conversion tracking — deferred to optimization phase
- Invite-only registration flow (signed URLs) — admin creates accounts manually for v1
- Cancel/void a payment link — deferred to v2
- 2FA settings UI — TwoFactorAuthenticatable on model but no UI in v1

(CSV export of payment history was listed here as deferred but has since been delivered — see Phase 9/10 validated entries above.)

## Context

This system solves a real operational problem: the agency runs multiple brands across separate WordPress/WooCommerce sites, each with its own Stripe account. Clients see different domains and inconsistent payment experiences, which erodes trust. PayHub centralizes this under one application.

**Technical environment:**
- Laravel 13 backend
- Vue 3 frontend via Inertia.js v3 (not a standalone SPA — Inertia-rendered pages)
- Tailwind CSS 4 + shadcn-vue for styling
- Stripe Elements for embedded, brand-styled payment forms
- Multi-account Stripe setup: PaymentIntents created under the brand's specific Stripe account

**Stripe multi-account pattern:**
Each payment selects a brand → resolves linked Stripe account → creates PaymentIntent under that account → initializes Elements with that account's publishable key → webhook fires on that account → signature verified with that account's webhook_secret → status written authoritatively.

## Constraints

- **Tech Stack**: Laravel 13, Vue 3, Inertia.js v3, Tailwind 4, shadcn-vue, Stripe Elements — decided
- **Security**: Stripe secret keys must be encrypted at rest (AES-256 in DB); no external secret management services
- **Payments**: One-time only — no subscriptions, no recurring billing
- **Auth**: Laravel Fortify, invite-only — no public registration, no SSO for v1
- **Multi-domain**: Single domain for v1 — per-brand subdomains are v2
- **Notifications**: Admin email only for v1

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Inertia.js instead of standalone SPA | Simpler auth, SSR-friendly, less infra complexity than separate API + SPA | Implemented — no separate API layer needed |
| Stripe Elements over Stripe Checkout | Full UI control, seamless brand experience, no Stripe-hosted redirect | Implemented — brand colors applied to Elements |
| Single domain for v1 | Subdomains add DNS/SSL complexity — defer until brand count justifies it | Implemented — all brands on same domain |
| Encrypted keys in DB over external secrets | No external service dependency, AES-256 sufficient for internal tool | Implemented — `encrypted` cast on secret_key + webhook_secret |
| Invite-only auth | Controlled internal tool — no public user growth needed | Implemented — registration feature disabled in Fortify |
| Multi-currency support from v1 | Agency works across markets, retrofitting later is painful | Implemented — USD and GBP, stored as integer cents |
| Stripe accounts decoupled from brands | Brands and accounts are independent resources — one account can serve multiple brands | Implemented in Phase 3 |
| Per-account StripeClient (never global setApiKey) | Prevents key cross-contamination across accounts in same request | Enforced — critical rule in CLAUDE.md |
| All DB status writes via webhooks only | Client-side confirmPayment() cannot be trusted; webhook is authoritative | Enforced — HandleStripeWebhookJob is sole writer |
| has_webhook_secret bool pattern | Raw encrypted value must never reach frontend | Implemented in Phase 6 — boolean only in Inertia response |
| Queue driver: sync on shared hosting | HandleStripeWebhookJob is a fast DB write — no reason to defer on shared hosting | Documented in CLAUDE.md deployment section |
| Revolut, Square, Viva added as parallel providers (not a Stripe replacement) | Agency needed non-Stripe rails per brand/market; each provider gets its own account FK + transaction id columns on `Payment`, resolved by branching on `$payment->provider` | Implemented in Phases 8–10 — see CLAUDE.md "Payment providers" section for the per-provider architecture |
| Square: per-account single-currency lock | A Square merchant account is provisioned for one currency; mixing currencies on one account isn't supported by Square | Implemented Phase 9 — `square_accounts.currency`, enforced in Store/UpdatePaymentRequest |
| Viva: flat GBP-only rule (not per-account) | Simpler than Square's per-account lock — Viva accounts have no `currency` column at all | Implemented Phase 10 — enforced in Store/UpdatePaymentRequest |

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
*Last updated: 2026-07-11 — v1.0 milestone (Phases 1–7) complete; Phases 8–10 (Revolut, Square, Viva payment providers) delivered post-milestone via ad-hoc feature branches, backfilled into this doc 2026-07-11*
