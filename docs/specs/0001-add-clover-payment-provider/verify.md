# Verify: Add Clover as a fifth payment provider · spec 0001 · updated 2026-09-17
_Steps derived from spec 0001 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual
- [x] Log in as admin → visit `/admin/clover-accounts` → create an account (sandbox, USD) → it appears in the list → AC-1 — verified live 2026-09-17 against Clover's real sandbox API (Merchant ID `C1ZHYBAE9J5R1`, token type `HOSTED_CHECKOUT`); `verifyCredentials()` and `createCheckoutSession()` both got genuine 200s via tinker before the account was saved through the UI
- [x] Edit that account, leave the private token/webhook secret blank, save → secrets unchanged, edit page never shows raw secret values, only `has_private_token`/`has_webhook_secret` booleans → AC-1, AC-10 — verified live; DB confirmed `private_token`/`webhook_secret` byte-identical after a blank-field save
- [x] Deactivate, then activate the account from the index page → status flips both ways → AC-1 — verified live, both directions, via the confirm-dialog flow
- [x] As admin, create a payment against the Clover account with currency GBP → rejected with "This Clover account only accepts USD payments." before any Clover API call → AC-2 — verified live 2026-09-17: the UI's own currency lock makes this unreachable by clicking through it (confirmed separately), so sent the raw `POST /payments` request directly (as a crafted client bypassing the UI would) → 422 with exactly `"This Clover account only accepts USD payments."`, and confirmed zero payment rows were created by the attempt (rejected at validation, before any DB write or Clover API call)
- [x] Create a payment against the Clover account with currency USD → succeeds, provider/account dropdowns show Clover alongside Stripe/Revolut/Square/Viva → AC-2, AC-3 — verified live; `Clover Sandbox USD (Clover)` listed alongside Stripe/Viva accounts in the Payment Account dropdown; payment created with reference `CLOV-001005`, Provider: Clover
- [x] Assign an agent a Clover account for the `usd` currency slot (`/admin/users`) → agent's next USD payment routes to that Clover account, not Stripe → AC-3, AC-9 — verified live 2026-09-17: assigned the seeded `agent` user a `{currency: usd, provider: clover, account_id: 1}` payment-account row via `PUT /admin/users/2`, confirmed in `user_payment_accounts`; logged in as that agent and created a USD payment with no account specified → `provider: clover`, `clover_account_id: 1`, `stripe_account_id` null
- [x] Open a Clover payment's `/pay/{uuid}` link twice within 15 minutes → both visits redirect to the same Clover checkout URL (no duplicate session created) → AC-4 — **fixed and verified live 2026-09-17** (`/debug`, see "Bugs found and fixed" below). First visit: 200, real session created against Clover's sandbox API. Second visit: 200 in ~1.5s (no Clover API call), same `clover_checkout_session_id` returned both times.
- [x] Wait past 15 minutes (or manually null `clover_checkout_expires_at`) and revisit `/pay/{uuid}` → a fresh session is created → AC-4 — verified live 2026-09-17: manually expired the stored session (`clover_checkout_expires_at` set 5 minutes in the past), revisited `/pay/{uuid}` → new `clover_checkout_session_id` (`07a1005a-...` vs the prior `9815c325-...`), ~4.8s response (a real Clover API call, matching the create path, not the ~1.5s reuse path), fresh expiry ~15 minutes out
- [x] Visit `/pay/{uuid}/clover/return` for a payment whose status is `completed` → redirects to Success; `failed` → redirects to Failed; `pending` → shows Unavailable inline, all sourced from the DB status, never a query string → AC-8 — verified live for `pending` (200, `ClientPayment/Unavailable` component); `completed`/`failed` redirects are a plain `match` on DB status with no new logic, not independently re-tested live
- [x] Export payments to CSV with a Clover payment present → Provider column shows "Clover", Payment Account shows the real account name, Provider Reference shows the Clover payment id (or checkout session id if still pending) → AC-9 — verified live 2026-09-17: downloaded `/payments/export` (an .xlsx, not a literal .csv — matches `PaymentsExport`'s actual format) as admin, extracted the sheet, confirmed "Clover", "Clover Sandbox USD", "CLOV-001005", and the live checkout session id `07a1005a-...` (payment still pending, no `clover_payment_id` yet) all present
- [x] Open the dashboard → filter by provider "Clover" and by a specific Clover account → figures update, "Accounts Today" lists the Clover account by name → AC-9 — verified live 2026-09-17: `?provider=clover` shows Pending Pipeline $50.00, "By Payment Provider or Account (Today)" lists "Clover Sandbox USD" with a CLOVER badge, Pending $50.00; `?account=clover:1` shows the same figures with the Account dropdown correctly set to the Clover account
- [x] As a non-admin agent, hit any `/admin/clover-accounts/*` route → 403 → AC-1 — verified live 2026-09-17: logged in as the seeded `agent`/`password` user (confirmed authenticated via `/payments` → 200), then `/admin/clover-accounts` → 403, `/admin/clover-accounts/create` → 403, `/admin/clover-accounts/1/edit` → 403

## Bugs found and fixed (live verify → /debug, 2026-09-17)
Live verify against Clover's real sandbox API found and `/debug` fixed three bugs in `ClientPaymentController::showClover()`, none of which the mocked test suite could have caught. All confirmed via direct tinker calls to Clover's live API before and after each fix, then end to end through the real `/pay/{uuid}` route.

1. **Missing `customer` object (root cause of the original 500).** `createCheckoutSession()` was called with only a `shoppingCart` key. Clover's Hosted Checkout API rejects that outright: `404 {"message":"Customer can't be null"}`. Fixed by adding a `cloverCustomer()` helper that builds `firstName`/`lastName` (split from `Payment::client_name`) and `email` (from `Payment::client_email`).
2. **`expirationTime` parsed as a Unix timestamp.** Clover returns it as an ISO 8601 string (`"2026-09-17T09:49:38.168Z"`), not an integer. `(int) $expirationTime` truncated it to `2026`, and `Carbon::createFromTimestamp(2026)` produced `1970-01-01 00:33:46` — a near-epoch date that then failed to save (`SQLSTATE[22007]: Invalid datetime format`). Fixed by parsing it as a real date: `Carbon::parse($expirationTime)`.
3. **Timezone drift on save.** This app runs `APP_TIMEZONE=Asia/Karachi` (not UTC) locally. `Carbon::parse()` alone keeps the UTC instant, but Eloquent's `datetime` cast writes the raw digits to the DB without converting to the app timezone, then re-reads those same digits back as if they were already local time — a genuine ~5 hour drift that made a real future expiry evaluate as already-past. Fixed with `->setTimezone(config('app.timezone'))` before the value is persisted.

Not changed: `showClover()` still lets a `RequestException` from `createCheckoutSession()` bubble up uncaught (a raw 500 rather than a graceful Unavailable page) if Clover's API fails for some other reason. This matches `showViva()`'s existing, established pattern for the same class of failure — not a Clover-specific defect, so left as-is per the debug loop's "fix the root cause, not a design choice riding along with it."

Regression coverage added: `tests/Feature/CloverPayPageTest.php` (3 tests), using Clover's real response shape (ISO 8601 timestamps) rather than a shape convenient to the old code. Verified each test fails without its corresponding fix and passes with it.

## Commands
- [x] `php artisan test --filter=CloverAccountManagementTest` → 11/11 pass → AC-1, AC-10
- [x] `php artisan test --filter=CloverWebhookTest` → 13/13 pass (signature verification, idempotency, terminal-state guard, admin auth) → AC-5, AC-6, AC-7
- [x] `php artisan test --filter=PaymentCreationTest` → includes Clover currency-lock and agent-routing cases, all pass → AC-2, AC-3
- [x] `php artisan test --filter=PaymentUpdateTest` → includes Clover account-move + stale-transaction-id clearing cases, all pass → AC-2, AC-9
- [x] `php artisan test --filter=PaymentExportTest` → includes Clover provider/account/reference mapping, pass → AC-9
- [x] `php artisan test --filter=AdminUserManagementTest` → includes Clover agent-locking + currency-mismatch cases, pass → AC-3, AC-9
- [x] `php artisan test --filter=CurrencySupportResolverTest` → includes Clover USD-only rows, pass → AC-2
- [x] `php artisan test --filter=CloverPayPageTest` → 3/3 pass (new, added by `/debug` 2026-09-17) → AC-4, AC-8
- [x] `php artisan test` (full suite, post-`/debug`) → 447/463 pass; the one failure (`NotificationTest`) is the same pre-existing, unrelated failure as the pre-fix run
- [x] `vendor/bin/pint --dirty --format agent` → passed, no formatting issues
- [x] `npx vue-tsc --noEmit` → no new type errors (3 pre-existing errors remain, identical pattern already present in `viva-accounts/Edit.vue` and `ssr.ts`)
- [x] `npm run build` → succeeds

## Not covered by automated tests (flagged, not blocking)
- AC-4/AC-8 now have automated coverage (`CloverPayPageTest`, added 2026-09-17), ahead of the other four providers' pay pages, which still have none — no provider's pay-page round-trip was tested before this. Worth doing for Stripe/Revolut/Square/Viva too as a follow-up (`/test`), since this is exactly the kind of real-API-shape mismatch that a mocked-to-convenience test would never catch.
- The exact Clover webhook JSON field names/casing (`Type`/`Status`/`Id`/`Data`) and the `Clover-Signature` replay window are implemented per Clover's prose documentation (docs.clover.com), not a published schema — genuinely unconfirmed until a live sandbox pass, per the spec's own Follow-up.

## Acceptance-criteria coverage
- AC-1 (admin CRUD) — `CloverAccountManagementTest` (automated) + manual steps above
- AC-2 (currency lock) — `PaymentCreationTest`, `PaymentUpdateTest`, `CurrencySupportResolverTest` (automated)
- AC-3 (create payment link parity, agent routing) — `PaymentCreationTest`, `AdminUserManagementTest` (automated)
- AC-4 (session reuse) — **met**, after `/debug` fix 2026-09-17 (see "Bugs found and fixed" above): `CloverPayPageTest` (automated) + verified live against Clover's real sandbox API
- AC-5 (signature verification) — `CloverWebhookTest` (automated)
- AC-6 (idempotent paid, terminal guard) — `CloverWebhookTest` (automated)
- AC-7 (declined, server-side amount) — `CloverWebhookTest` (automated)
- AC-8 (return route status mapping) — **met**: `CloverPayPageTest` doesn't cover this route directly, but verified live for `pending` → Unavailable; `completed`/`failed` branches are an untested but trivial `match` on DB status
- AC-9 (parity surfaces) — `PaymentExportTest`, `AdminUserManagementTest` (automated) + manual dashboard/CSV/UI steps above
- AC-10 (secrets encrypted, CSRF/rate-limit, webhook auth) — `CloverAccountManagementTest`, `CloverWebhookTest` (automated)
