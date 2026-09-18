# Memory — Clover Hosted Iframe Integration (spec 0002): Build + Debug + Verify

Last updated: 2026-09-18

## What was built

**Full `/develop` build of spec 0002** (`docs/specs/0002-clover-hosted-iframe-integration/`), switching Clover from spec 0001's Hosted Checkout (redirect) to Hosted Iframe (embedded card form, synchronous charge, no webhook — Clover documents none for this integration type). Branch: `feat/clover-hosted-iframe-integration`, still uncommitted.

Key pieces:
- Migrations rewritten: `clover_accounts` drops `webhook_secret`, adds `api_access_key`; `clover_charge_attempts` rebuilt (`idempotency_key`, nullable `clover_charge_id`, `status`, `attempts_count`, `last_checked_at`); `processed_clover_events` dropped (no webhook to dedupe).
- `App\Services\Clover\CloverClient` rewritten: `createCharge()`/`getCharge()` no longer `->throw()` (caller classifies), new `findChargeByReference()`, split hosts (`checkout.*.clover.com` for the SDK, `scl.*.clover.com` for charges, `api.*.clover.com` for merchant verification).
- New `App\Services\Clover\CloverChargeClassifier` (4-bucket: approved/declined/hard_error/unknown) and `CloverChargeResolver` (shared guarded-update, used by both the sync handler and the resolver job).
- New `App\Jobs\ResolveCloverChargeAttempt` (+ scheduled sweep in `routes/console.php`), replacing the deleted `ReconcileCloverPayments`.
- `ClientPaymentController`: `showClover()` rewritten (no session logic), new `chargeClover()`/`cloverStatus()` endpoints, old `cloverReturn()` removed.
- New `resources/js/pages/ClientPayment/CloverPaymentForm.vue` (Clover Hosted Iframe SDK) + rewritten `PayClover.vue` (consent → card form → approved/declined/unknown → poll/timeout states).
- Admin CRUD (`CloverAccountController`, requests, Create/Edit/Index.vue) updated for `api_access_key`; webhook UI removed.
- Deleted: `CloverWebhookController`, `HandleCloverWebhookJob`, `ProcessedCloverEvent`, `ReconcileCloverPayments`.
- Fixed two provider-parity bugs the switch would otherwise have broken: `PaymentController`'s stale-transaction-id clearing and `PaymentsExport`'s reference fallback both still referenced the dropped session columns.

**Result: spec 0002 status is now `Accepted`** (was `Proposed` → `In Progress` → `Accepted`, user-confirmed this session). `verify.md` fully checked off with live evidence.

## Decisions made

- Clover's synchronous `/v1/charges` response is treated as authoritative (a scoped, documented exception to "status comes from webhooks only" — Clover has no webhook for Hosted Iframe).
- CSV export / dashboard "Provider Reference" stores Clover's **order id**, not the API charge id — confirmed by the user against Clover's real merchant dashboard that only the order id is searchable there. Requires one best-effort follow-up `GET` after an approved charge (order id isn't on the `POST` response); never blocks the response if it fails, falls back to the charge id.
- `clover_charge_attempts.idempotency_key` is generated as a 12-char random string (not a UUID) — sent as both Clover's idempotency key and `external_reference_id`, which Clover caps at 12 characters.
- Clover's charge request now sends `metadata: {reference_code, payment_uuid}`, mirroring Stripe/Revolut, so a transaction is cross-checkable in Clover's own dashboard.

## Problems solved (6 live `/debug` rounds this session, all confirmed against a real Clover sandbox + real merchant dashboard, not mocks)

1. **CSP never whitelisted Clover.** `app/Http/Middleware/SecurityHeaders.php` had entries for Stripe/Revolut/Square but not Clover — the Hosted Iframe SDK script was silently blocked, surfacing as "Payment system is unavailable." Fixed: added `checkout.sandbox.dev.clover.com`/`checkout.clover.com` to `script-src`/`frame-src`/`connect-src`.
2. **`external_reference_id` too long.** Sent as a 36-char UUID; Clover caps it at 12 chars → `invalid_request_error`. Fixed: generate a 12-char key instead.
3. **Classifier treated any `error` key as a decline**, masking bug #2 as "your card was declined" instead of the real request-shape error.
4. **Classifier didn't match Clover's real decline shape.** A genuine decline has no top-level `paid` and a camelCase `declineCode` (not the docs' `decline_code`) — fell into "unknown", leaving the client stuck on "Confirming your payment…" forever. Fixed: check `error.declineCode`/`error.type === 'card_error'` explicitly.
5. **Rate limiter was silently IP-keyed.** `RateLimiter::for('clover-charge', ...)` read `$request->route('payment')?->uuid`, but route/model binding hasn't resolved yet at that pipeline stage — it's a raw string, so `->uuid` silently returned null and the limiter fell back to `$request->ip()` every time. One payment's testing could exhaust a totally different payment's limit. Fixed: use the raw route param string directly.
6. **No cross-check metadata + wrong "Provider Reference" field** — see Decisions above.

Also: found and fixed a gap in my own test suite (a test dispatching `ResolveCloverChargeAttempt` without `Queue::fake()`, relying on `QUEUE_CONNECTION=sync` inline execution — harmless until a wildcard HTTP fake started returning realistic data).

**Sandbox gotcha discovered** (not a PayHub bug, just noise to expect while testing): Clover's sandbox enforces undocumented velocity limits — a "sale count per card" and a separate "sale count per IP address" limit — that produce ordinary-looking declines regardless of card validity once enough test transactions have run. Hit repeatedly today from heavy live testing; expect it to affect fresh testing for a while.

## Current state

- Branch `feat/clover-hosted-iframe-integration` — **everything is still uncommitted** (large set of modified/new/deleted files from `git status`, including `public/build/assets/*` churn from the `npm run build` runs during verification — this repo tracks build output).
- Full Pest suite: 475/476 passing. The 1 failure (`NotificationTest::...queues PaymentSucceeded mail to all admins only`) is pre-existing and unrelated — confirmed via `git stash` (fails identically with none of this session's changes present).
- Pint, `npm run types:check`, `npm run build` all clean (2 pre-existing unrelated TS errors in `viva-accounts/Edit.vue` and `ssr.ts`, not touched this session).
- `docs/specs/0002-clover-hosted-iframe-integration/verify.md` fully checked off, includes a "Live sandbox verification (2026-09-18)" section documenting the 6 bugs above.
- A few leftover test `CloverAccount`/`Payment` rows remain in the local dev DB from verification — harmless, not cleaned up (some may be the user's own test data, not just mine).

## Next session starts with

- Nothing code-blocking remains for spec 0002 — it's `Accepted`. Natural next step: review the diff, commit, and decide whether to push `feat/clover-hosted-iframe-integration` / open a PR.
- Per spec 0002's own Consequences: `CLAUDE.md`'s "all payment status comes from webhooks only" critical rule should be updated (via `/sync`) to name Clover Hosted Iframe as the scoped, documented exception — **not yet done**.
- Optional, flagged but not done: swap `decline_reason`'s field-priority to prefer Clover's `message` over `declineCode` (the message is more informative for operator diagnostics — e.g. it revealed the sandbox velocity-limit gotcha above, which `declineCode` alone just showed as generic "issuer_declined").

## Open questions

- When/whether to commit and push this session's work.
- Whether to run `/sync` now to update `CLAUDE.md`'s webhook-only rule per spec 0002's Consequences note.
- Whether to clean up the remaining test Clover payments/accounts in the dev DB, or leave them.
- The spec's own remaining Follow-up item (flagged, non-blocking): `findChargeByReference()` (the resolver job's fallback when no charge id was ever captured) has never been exercised against a genuine unresolvable Clover response — every "unknown" hit during testing resolved via the charge-id path instead.
