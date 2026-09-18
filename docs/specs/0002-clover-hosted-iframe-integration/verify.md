# Verify: Clover Hosted Iframe integration · spec 0002 · updated 2026-09-17
_Steps derived from spec 0002 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual
- [x] As admin, open Clover Accounts → Add account → fill account name, merchant id, public access key, private token, sandbox → Save → account appears in the list → AC-1
- [x] Edit that account → confirm the form shows the public access key as plain text and the private token only as a "has token" indicator, never the raw value → AC-1, AC-11
- [X] As admin/agent, create a payment link against an active Clover account (USD) → link created like any other provider → AC-3
- [X] Attempt to create/update a payment against a Clover account with currency=GBP → rejected with the Clover currency error, before any Clover API call → AC-2
- [x] Open a Clover payment link as a client → the card form (number/date/CVV/postal code) renders directly on the page, no redirect to clover.com → AC-4
- [x] Submit a valid sandbox test card → client is sent straight to the Success page (no intermediate spinner) → AC-5, AC-7, AC-9
- [x] Submit a card that Clover's sandbox declines → inline decline message appears immediately (not Clover's raw text) and the same page lets you retry with a different card without a new link → AC-5, AC-8, AC-9, AC-11
- [x] Retry with an approving card after a decline → payment completes → AC-6, AC-8
- [x] Double-click "Pay" (or submit twice quickly) → only one real charge reaches Clover; the second click errors instead of double-charging → AC-5, Key invariants
- [x] Submit 11 charge attempts for the same payment within an hour → the 11th is rejected (429) before reaching Clover → AC-11 (verified live, 2026-09-18: attempts 1-10 processed, attempt 11 returned 429 "Too Many Attempts")
- [x] Open a payment that's already `completed` via its pay link → shows the Unavailable page, not the card form → D-03/D-12 guard (existing pattern)
- [x] Check the CSV export, dashboard account filters, "Accounts Today" figures, and a client-facing receipt page for a Clover payment → Clover appears everywhere the other four providers do → AC-10 (verified live, 2026-09-18, against a real completed Clover payment: CSV row shows provider "Clover", account name, and the order id as Provider Reference; the account appears in the dashboard's Clover account filter and in "Accounts Today" with correct accepted/pending totals; the client Success page renders with `provider: 'clover'` and the correct reference code)

## Commands
- [x] `php artisan test --compact --filter=Clover` → 66 passed → AC-1, AC-2, AC-5, AC-6, AC-7, AC-8, AC-9, AC-11 (2026-09-18)
- [x] `php artisan test --compact --filter="PaymentExport|PaymentUpdate|PaymentController"` → 24 passed (Clover parity surfaces unaffected) → AC-10 (2026-09-18)
- [x] `vendor/bin/pint --dirty --format agent` → passed (2026-09-18)
- [x] `npm run types:check` → no errors in any Clover file (2 pre-existing, unrelated errors in viva-accounts/Edit.vue and ssr.ts) (2026-09-18)
- [x] `npm run build` → built in 1m 29s, `PayClover-*.js` and `CloverPaymentForm-*.js` chunks built cleanly (2026-09-18)

## Acceptance-criteria coverage
- AC-1 (admin CRUD, api_access_key, no webhook_secret) — covered by the account-management manual steps and `CloverAccountManagementTest.php`
- AC-2 (currency lock, unchanged) — covered by the currency-lock manual step and existing `StorePaymentRequest`/`UpdatePaymentRequest` tests
- AC-3 (payment link creation parity) — covered by the manual step; no code changed here, so no new automated test
- AC-4 (embedded card form, no raw card data to PayHub) — covered by the manual step and `CloverPayPageTest.php`
- AC-5 (charge endpoint: rate limit, precondition guard, idempotency key, 4-bucket classification) — covered by `CloverChargeTest.php` and `CloverChargeClassifierTest.php`
- AC-6 (at-most-once completion, guarded update) — covered by `CloverChargeTest.php`'s decline-then-retry and `ResolveCloverChargeAttemptTest.php`'s idempotent-resolution cases
- AC-7 (approved requires paid && captured && amount/currency match) — covered by `CloverChargeClassifierTest.php` and the "captured false" case in `CloverChargeTest.php`
- AC-8 (declined not terminal; resolver job reads, never re-posts; abandoned after retries) — covered by `CloverChargeTest.php` and `ResolveCloverChargeAttemptTest.php`
- AC-9 (client UX: approved→Success, declined→inline retry, unknown→poll→terminal states) — the poll/timeout UI states in `PayClover.vue` have no automated coverage (no browser test in this pass); verify manually
- AC-10 (parity surfaces) — covered by the manual step and the existing `PaymentExportTest.php`/`DashboardMetrics` tests, plus the `PaymentController.php`/`PaymentsExport.php` fixes this build made
- AC-11 (security: private_token never exposed, decline_reason sanitized, per-payment rate limit, amount server-sourced) — covered by `CloverChargeTest.php` and `CloverPayPageTest.php`

## Live sandbox verification (2026-09-18)

Everything above was re-verified against a real Clover sandbox account and a real merchant dashboard, not just docs or mocks. This surfaced and fixed several real bugs the doc-only build had gotten wrong:
- `external_reference_id` silently has a 12-character cap Clover's docs state but the original build missed — a full UUID was rejected outright.
- Clover's real decline/error response shape differs from its own field-reference docs in places (a genuine decline carries no top-level `paid` field and a camelCase `declineCode`, not the documented snake_case `decline_code`) — the classifier now matches the real shape, confirmed against live approved and declined charges.
- The CSV export's "Provider Reference" now stores Clover's `order` id (confirmed against the real merchant dashboard to be the value that's actually searchable there), not the API charge id.
- The rate limiter was silently IP-keyed instead of payment-UUID-keyed (a route-model-binding timing issue) — confirmed and fixed; re-verified live that a fresh payment isn't affected by another payment's exhausted limit.
- Clover's sandbox itself enforces undocumented velocity limits (a "sale count per card" and a separate "sale count per IP address" limit) that produce ordinary decline responses unrelated to card validity — worth knowing before assuming a decline during testing is a PayHub bug.

## Remaining open item

- `findChargeByReference()` (the resolver job's fallback when no charge id was ever captured for an attempt) has never been exercised against a genuine unresolvable response from Clover — every "unknown" classification hit during this verification pass either had a captured charge id (resolved via `getCharge()`) or was a simulated transport failure. Clover's `GET /v1/charges` still has no documented filter by `external_reference_id`; `findChargeByReference()` lists and filters client-side. This is the one piece of spec 0002's design that remains genuinely unconfirmed — worth a dedicated check (or a production incident review) before leaning on it.
