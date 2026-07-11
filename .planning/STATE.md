---
gsd_state_version: 1.0
milestone: v1.0
milestone_name: milestone
status: complete
stopped_at: Phase 10 complete 2026-07-11 — v1.0 (Phases 1-7) plus post-milestone Phases 8-10 (Revolut, Square, Viva payment providers) all delivered
last_updated: "2026-07-11"
last_activity: 2026-07-11
progress:
  total_phases: 10
  completed_phases: 10
  total_plans: 31
  completed_plans: 29
  percent: 100
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-07-11)

**Core value:** Clients always feel they are paying the same brand they interacted with, regardless of which payment provider or account processes the payment.
**Current focus:** v1.0 complete; Phases 8-10 (Revolut, Square, Viva payment providers) delivered post-milestone. VIVA-07 (failed-payment webhook transition) still open pending live sandbox verification.

## Current Position

Phase: 10 of 10 (Viva Payments Provider) — complete, one open item (VIVA-07)
Plan: N/A — Phases 8-10 delivered via ad-hoc feature branches, not phase-plan waves
Status: v1.0 milestone (Phases 1-7) delivered 2026-05-14. Phases 8-10 backfilled into .planning 2026-07-11 to reflect already-shipped work.
Last activity: 2026-07-11

Progress: [██████████] 100% (10/10 phases; Phases 8-10 have no plan-level tracking since they bypassed the phase-plan workflow)

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- [Roadmap]: 7 phases derived from dependency chain — schema/encryption must be correct before any other phase begins
- [Roadmap]: SEC requirements distributed across phases: SEC-01 → Phase 1, SEC-02 → Phase 4, SEC-03 → Phase 6, SEC-04 → Phase 5
- [Roadmap]: Client email receipt deferred to v2 (per-brand email sender identity unresolved)
- [Phase 2]: Registration disabled via config/fortify.php — Features::registration() removed (D-01)
- [Phase 2]: Email verification disabled — Features::emailVerification() removed to simplify test setup (open question resolved)
- [Phase 2]: Hard-delete chosen for user deletion — no soft deletes in Phase 2 (open question resolved)
- [Phase 2]: Computed spread pattern chosen for admin-only nav items in AppSidebar (no NavItem type extension needed)
- [Phase 02]: Wave 0 stubs use PHPUnit class-style with forgetCachedPermissions() + Role::firstOrCreate() in setUp() to prevent spatie permission cache contamination across tests
- [Phase 03]: Stripe accounts decoupled from brands — moved to top-level /admin/stripe-accounts route; brand_id dropped from stripe_accounts table
- [Phase 03]: website_url added to brands table (nullable)
- [Phase 03]: webhook_secret made nullable on stripe_accounts — not required at account creation time
- [Phase 03]: ConfirmDeleteDialog extracted as reusable component
- [Phase 05]: ClientPayment/ pages use app.ts resolver returning null + direct PaymentLayout import (brand props can't flow through resolver)
- [Phase 05]: loadStripe() called in onMounted; StripeElements conditionally rendered after stripeLoaded=true (prevents window.Stripe undefined error)
- [Phase 05]: getComputedStyle for --brand-primary must be called after layout root mounts (not in setup()); computed reads after mount resolves timing
- [Phase 05]: Mockery-based StripeClient stub pattern used in tests — $this->app->bind(StripeClient::class, fn() => $mockService)
- [Phase 06]: fakeStripeSignature() uses hash_hmac sha256 with t=,v1= format matching Stripe constructEvent requirement
- [Phase 06]: phpunit.xml APP_BASE_PATH corrected to salmanabdul.ghani user path (pre-existing path mismatch fixed)
- [Phase 06]: HTTP_STRIPE_SIGNATURE server var used in tests (not withHeaders()) — Laravel call() bypasses defaultHeaders; raw body must be preserved for Webhook::constructEvent()
- [Phase 06]: HandleStripeWebhookJob stub created with typed constructor args — Wave 2 (06-02) implements handle()
- [Phase 06]: eventData['id'] used for PI lookup in HandleStripeWebhookJob — controller passes toArray() of flat PaymentIntent, not nested wrapper
- [Phase 06]: has_webhook_secret (bool) pattern — raw encrypted value never in Inertia response; webhook_secret excluded from fill() (T-06-03, T-06-11)
- [Phase 06]: blank-means-preserve pattern applied to webhook_secret in update() — same as secret_key (D-04)
- [Phase 08]: Revolut added as a second parallel provider (not a Stripe replacement) — own account FK + `revolut_order_id`, webhook idempotency via `ProcessedRevolutEvent` since Revolut sends no event id
- [Phase 09]: Square added as a third parallel provider — per-account single-currency lock (`square_accounts.currency`), unlike Stripe/Revolut which have no currency restriction
- [Phase 09]: CSV export Provider Reference column had a `default => stripe_payment_intent_id` match-arm bug that left Square rows blank — fixed 2026-07-02 (`2aa7451`)
- [Phase 10]: Viva added as a fourth parallel provider — flat GBP-only rule (not per-account like Square); two ids scoped to the account (`viva_order_code` set at pay-page order creation, `viva_transaction_id` set by webhook)
- [Phase 10]: Same class of Provider-Reference blank-cell bug recurred for Viva (export + payment detail page) — fixed 2026-07-11 by falling back to `viva_order_code` when `viva_transaction_id` isn't set yet
- [Phase 10]: Merging Viva into `staging` surfaced that `clearStaleProviderTransactionIds()` (added by a concurrent Stripe stale-PaymentIntent fix) had no `viva_account_id` mapping — fixed in the same merge

### Pending Todos

- [Phase 10] VIVA-07: confirm the `TransactionFailed` webhook event type id and the `Viva-Signature` header/algorithm against a live Viva sandbox — currently unconfirmed, see `.planning/research/VIVA_PAYMENTS.md`. A failed Viva payment stays `pending` indefinitely until this is fixed.
- Read-side stale-id recovery (`reusableStripePaymentIntent()`-style) is Stripe-only — Revolut/Square/Viva pay pages likely still throw or reuse a drifted-amount order when the stored id is stale. Never audited, only inferred. See memory `project-stale-provider-txn-ids`.

### Blockers/Concerns

- [Phase 1]: APP_KEY rotation plan should be documented before first real Stripe secret is stored in the database
- [Phase 7 resolved]: Per-brand email From address deferred to v2 — notification emails use MAIL_FROM_ADDRESS (single sender)
- [Phase 10]: VIVA-07 open — see Pending Todos above

## Deferred Items

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| v2 | Client email receipt on payment success | Deferred — per-brand email sender unresolved | Roadmap |
| v2 | Invite-only registration flow (signed URLs) | Deferred — Admin creates accounts manually for v1 | Roadmap |
| v2 | Cancel/void a payment link | Deferred | Roadmap |
| v2 | 2FA settings UI | Deferred — TwoFactorAuthenticatable on model but no UI in Phase 2 | Phase 2 CONTEXT.md D-11 |
| v2 | User soft-delete / is_active flag | Deferred — hard-delete used in Phase 2 | Phase 2 CONTEXT.md |

CSV export of payment history was listed here as deferred but has since been delivered (Phase 9/10) — covers all four providers.

## Session Continuity

Last session: 2026-07-11
Stopped at: Phase 10 (Viva Payments Provider) complete and merged to staging. VIVA-07 (failed-payment webhook transition) still open.
Resume file: None (no active phase-plan in progress; VIVA-07 needs a live sandbox test, not more planning)
