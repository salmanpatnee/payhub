---
name: security-patterns
description: Established safe vs unsafe patterns for PayHub codebase, updated from audit findings
metadata:
  type: project
---

## Safe Patterns (Verified)

- `new StripeClient($account->secret_key)` — correct per-account initialization
- `app()->make(StripeClient::class, ['config' => $key])` — testable pattern in ClientPaymentController
- `Webhook::constructEvent($payload, $sigHeader, $stripeAccount->webhook_secret)` — correct signature verification
- `$request->getContent()` for webhook raw body — preserves body for signature check
- `Payment::where(...)->whereIn('status', [...])` pattern for idempotent status updates
- `$data['amount'] = (int) round($data['amount'] * 100)` in `StorePaymentRequest::validated()` — cents conversion
- `'currency' => ['required', 'string', 'in:usd,gbp']` — whitelist currency validation
- `encrypted` cast on `secret_key` and `webhook_secret` in `StripeAccount` model
- `secret_key` excluded from `$fillable`, assigned explicitly
- `preventRequestForgery(except: ['webhook/stripe/*'])` in bootstrap/app.php — correct CSRF exclusion
- Agent `stripe_account_id` overridden from `$user->stripe_account_id` server-side — never trusted from request
- `Payment::getRouteKeyName() = 'uuid'` — prevents integer ID enumeration on public routes

## Unsafe Patterns to Watch

- `Stripe::setApiKey()` — NEVER use globally
- Trusting `confirmPayment()` result for DB writes — all status from webhooks only
- Passing `amount` or `currency` from client request to Stripe — always from DB record
- Logging `$event->data->object->toArray()` — may contain `client_secret`
- Float amounts — always integer cents
- SQL WHERE on `secret_key` or `webhook_secret` columns — encrypted values are not searchable

## Authorization Patterns

- Admin routes: protected by `role:admin` middleware at route group level
- Payment list: agent scope enforced by `where('user_id', $user->id)` unconditionally
- FormRequest `authorize()`: uses `hasRole('admin')` check
- MISSING: No PaymentPolicy — `PaymentController::show()` has no ownership check
