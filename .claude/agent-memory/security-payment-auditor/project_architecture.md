---
name: project-architecture
description: Core security architecture decisions for PayHub — Stripe integration patterns, auth, RBAC, webhook design
metadata:
  type: project
---

## Stripe Integration Architecture

- Per-account StripeClient instantiated as `new StripeClient($account->secret_key)` — NEVER global `Stripe::setApiKey()`
- `ClientPaymentController` uses `app()->make(StripeClient::class, ['config' => $key])` to support test mocking
- PaymentIntents created only from server-side DB record (amount, currency) — never from client input
- `client_secret` passed only as Inertia prop on the Pay page, never logged or stored
- Stripe webhook route: `POST /webhook/stripe/{stripeAccount}` — excluded from CSRF via `preventRequestForgery(except: ['webhook/stripe/*'])`
- Webhook signature verification via `Webhook::constructEvent()` in `StripeWebhookController`
- Idempotency implemented via status guards in `HandleStripeWebhookJob` (match statement with `whereIn('status', ...)`)
- No Stripe event ID deduplication table — relies on payment status guards instead
- No idempotency keys on `paymentIntents->create()` calls

## Encryption

- `secret_key` and `webhook_secret` on `StripeAccount` model: `'encrypted'` cast (TEXT columns)
- `secret_key` excluded from `$fillable` — assigned explicitly only
- `publishable_key` stored plaintext (correct — it is public)

## Authentication & Sessions

- Laravel Fortify — invite-only (Features::registration() commented out)
- 2FA enabled with confirmation and password re-confirmation
- Login rate limit: 5/minute per email+IP combination
- 2FA rate limit: 5/minute per session login.id
- Session serialization: JSON (safe — no PHP object deserialization gadgets)
- `SESSION_SECURE_COOKIE` defaults to null (not forced true in config — deployment concern)

## RBAC

- Two roles: `admin` and `agent` — validated in all FormRequests with `in:admin,agent`
- Admin routes under `role:admin` middleware prefix `/admin`
- Agent scope: `stripe_account_id` forced from `$user->stripe_account_id` in both `create()` and `store()` in PaymentController
- `PaymentController::show()` has NO explicit authorization check — relies on implicit route model binding

## Known Gaps (2026-05-21 audit)

- No Payment policy — `PaymentController::show()` does not verify ownership (any auth user can view any payment by UUID)
- No idempotency keys on Stripe API calls
- No rate limiting on payment creation (`POST /payments`)
- No rate limiting on public webhook endpoint
- Telescope has no custom gate guard (uses default Authorize middleware — only allows local env access)
- `StorePaymentRequest::note` has no `max:` constraint
- `StorePaymentRequest::amount` has no upper bound (max:) validation
- SVG files allowed in brand logo upload — potential XSS vector in some browsers
- `SESSION_SECURE_COOKIE` not enforced to `true` in config defaults
- Welcome.vue has `canRegister: true` as default prop value (cosmetic — backend registration is disabled)
- Telescope enabled by default (`env('TELESCOPE_ENABLED', true)`) — requires `TELESCOPE_ENABLED=false` in production .env

**Why:** recorded from May 2026 security audit to track regression prevention
**How to apply:** verify these gaps are addressed before production deployment; check on subsequent audits
