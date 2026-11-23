# Review, fix/minor-issues, 2026-11-23

**Reviewed by**: claude-opus-5-5 (review subagent; author model not recorded)
**Scope**: 25 files, branch vs master (merge base 2075434f)
**Verdict**: Changes requested

## Summary
Adds the shared `payment_attempts` log (spec 0016): one table, a logger with per payment locking and event key dedupe, five extractors (Stripe, Revolut, Square, Viva, Whop), call sites in the three signed webhook controllers and the Viva and Whop jobs, an admin only `attemptLog` prop and a Vue card. The code is careful and follows the spec closely: the logger is fully wrapped, payment lookups are scoped by account, Viva only logs after the API re-fetch, the Viva reorder keeps status safety, and admin gating is correct. The one Major is that the logger's unique key recovery branch (the part that makes concurrency safe) has no test, although a test name claims it does. The rest are Minor items around Square decline coverage, digit masking, a small race in the Square repeat check, and log noise.

## Major

### 🟠 Unique key recovery and sequence clash retry are untested, `app/Services/PaymentAttempts/PaymentAttemptLogger.php:78`
**Problem**: The `catch (UniqueConstraintViolationException)` branch (return the existing row on an `(provider, event_key)` clash, retry once on a `(payment_id, sequence)` clash, rethrow on the second try) is never run by a test. `tests/Feature/PaymentAttemptLogTest.php:418` is named "returns the existing row for a repeated event key and retries a sequence clash", but both repeat calls go through the in-transaction `exists` check, so neither the catch nor the retry runs. Spec build plan step 7 asks for exactly this test, since SQLite ignores the row lock.
**Why it matters**: This is the error handling that AC-3 relies on in production (MySQL, parallel webhooks plus queue retries). A bug here (for example a wrong lookup after rollback, or retrying the wrong case) would either lose a real event or throw into the outer catch and lose the row without anyone noticing.
**Suggested fix**: Add two logger tests that force the catch path: (1) insert a row with the same `(provider, event_key)` from inside the transaction (for example through a `PaymentAttempt::creating` listener that inserts the clashing row first) and assert the existing row comes back; (2) make the first insert clash on `(payment_id, sequence)` with a different event key and assert the second try writes `sequence + 1`. Rename the existing test to match what it checks.

## Minor

### 🟡 Square declines may never reach the log, `app/Http/Controllers/SquareWebhookController.php:17`
**Problem**: The controller only accepts `payment.updated`. For a card declined during `CreatePayment`, Square creates the payment already `FAILED` and may send only `payment.created`, with no `payment.updated`. I am not sure of this; it depends on Square's event behaviour, which the tests do not show (they post `payment.updated` with status `FAILED`).
**Why it matters**: "Why did the client's payment fail" is the main user story. If declines only come as `payment.created`, Square rows will show successes and almost no failures.
**Suggested fix**: Check one declined sandbox charge's events. If `payment.created` carries the `FAILED` status, accept it for logging only (log, then return before the status job, or let the job ignore it), and add it to the spec follow-up.

### 🟡 Square "same status as last row" check runs outside the lock, `app/Services/PaymentAttempts/Extractors/SquareAttemptExtractor.php:32`
**Problem**: The repeat status skip queries the last row in the extractor, before `store()` takes the payment lock. Two `payment.updated` events with `COMPLETED` (different `event_id`s) arriving in parallel can both pass the check and both be written.
**Why it matters**: Duplicate "succeeded" rows on the admin page. Diagnostic only, so low impact.
**Suggested fix**: Accept and document, or move the "skip if same as last" rule into the locked part (for example an optional callback in `AttemptData` or a `skipIfLastEventEquals` flag that `store()` checks after taking the lock).

### 🟡 Digit masking hides Viva order codes and misses runs of 20 or more digits, `app/Services/PaymentAttempts/AttemptPayloadSanitizer.php:104`
**Problem**: (1) `VivaAttemptExtractor` stores `orderCode` as a string (`VivaAttemptExtractor.php:53`). Viva order codes are 16 digits, so the sanitizer turns it into `[masked]` in every Viva row's response, removing a useful value that is not sensitive. (2) The regex needs no digit before or after the match, so a 20+ digit run (for example a PAN glued to an expiry, `42424242424242421226`) is not masked at all.
**Why it matters**: (1) Admins lose the order code they would look up in the Viva dashboard. (2) It is a gap in the second guard. The allowlist makes it unlikely, but AC-10 promises the guard.
**Suggested fix**: Keep `orderCode` as an int in the Viva response (ints are not masked), or allowlist it from masking. Mask any run of 13 or more digits (drop the upper bound or the closing lookahead) so longer runs are covered too.

### 🟡 Viva 1798 events add an API call and a possible retry loop, `app/Jobs/HandleVivaWebhookJob.php:75`
**Problem**: 1798 used to be ignored with a 200. Now each one calls `retrieveTransaction`. If Viva's retrieve endpoint returns an error for failed transactions (the id and behaviour are unconfirmed), then on the sync queue the controller logs `viva.webhook.processing_failed` at error level and does not mark the event processed, so Viva redelivers it every hour. The same happens for late events on completed payments, which used to be answered without an API call.
**Why it matters**: Error log and Sentry noise, and repeated API calls, for an event that only feeds a diagnostic log.
**Suggested fix**: Confirm in a sandbox (already in follow-up). Until then, for 1798 consider catching the retrieve failure in the job (log at info, return) so the event is marked processed.

### 🟡 Failure log line can carry payment data into Sentry breadcrumbs, `app/Services/PaymentAttempts/PaymentAttemptLogger.php:138`
**Problem**: `Log::warning` includes `$e->getMessage()`. For a `QueryException` that message is the full SQL with bindings (payment id, reference, reason, JSON). sentry-laravel records log lines as breadcrumbs, which attach to the `captureMessage` event sent right after.
**Why it matters**: AC-2 asks for no payment id or reference in the Sentry report. The message itself is clean, but the breadcrumb is not.
**Suggested fix**: Log only `$e::class` plus, for a `QueryException`, its SQL state code, not the message.

### 🟡 Per provider test matrix from AC-16 is incomplete, `tests/Feature/PaymentAttemptLogTest.php`
**Problem**: AC-16 asks for success, failure, retry, duplicate, late and missing details for every provider. Covered well for Stripe and Viva. Gaps: Revolut has no duplicate or missing details test, Square has no late event (completed payment) or duplicate `event_id` test, Whop has no late event test.
**Why it matters**: The Square and Revolut call sites sit before the completed guard. Without a late event test, a future reorder would not be caught.
**Suggested fix**: Add the missing cases. Each is a few lines with the existing `pal*` helpers.

## Nits
- ⚪ `app/Services/PaymentAttempts/PaymentAttemptLogger.php:48`: the Sentry fingerprint event is `unknown` for Square, Viva and Whop (no `event`/`type` in their context), so their failures group together. Pass an `event` key from those call sites, or read it from the extracted data.
- ⚪ `app/Services/PaymentAttempts/PaymentAttemptLogger.php:48`: `reportFailure(string $event)` is called with a raw context value inside the catch. A non-string would throw a TypeError out of `record()`. Cast it with `(string)`.
- ⚪ `app/Http/Controllers/StripeWebhookController.php:56` and `RevolutWebhookController.php:70`: the scoped payment lookup runs outside the logger's safety, and a second query repeats the unscoped one above it. Square wraps its lookup; do the same, or pass a closure to the logger.
- ⚪ `app/Http/Controllers/PaymentController.php:682`: `$user` parameter is untyped.
- ⚪ `app/Services/PaymentAttempts/AttemptExtractor.php:18`: the signature (extra `Payment` argument, nullable return) differs from the spec's `extract(array, array): AttemptData`. Update the spec so it matches the code.
- ⚪ `app/Services/PaymentAttempts/AttemptPayloadSanitizer.php:18`: `pan`/`number` match the whole key only. The spec text says "whole key or whole word segment", which contradicts its own `reference_number` example. Fix the spec wording.
- ⚪ `resources/js/components/payments/PaymentAttemptLog.vue:37`: `formatAmount`/`formatDateTime` are reimplemented here; the spec says to reuse the page's helpers.
- ⚪ `resources/js/components/payments/PaymentAttemptLog.vue:207`: the phone details button has no `aria-controls` (the wide table has one).
- ⚪ `database/factories/PaymentAttemptFactory.php:22`: `sequence` is fixed at 1, so two factory rows for one payment clash. Use a sequence or a state.
- ⚪ `app/Services/PaymentAttempts/Extractors/VivaAttemptExtractor.php:38`: Viva `insDate` has no timezone and is parsed as app time; check that it is UTC.

## Strengths
- `record()` really cannot break a webhook: everything, including the Sentry report, is wrapped, and a test drops the table and confirms the payment still completes.
- The Viva reorder is correct and safe: the order code cross check still runs first, logging happens only after Viva's API confirms the transaction, 1798 returns before any status code, and the completed guard removal is safe because the job only updates `pending`/`failed`. The non-promoted properties keep already-queued jobs working.
- Lookups are scoped by provider account everywhere (Stripe, Revolut, Square fallback, Viva, Whop with `provider = whop`), and tests cover the other account cases.
- Admin only gating is a single server side check, the legacy `attempts` card is untouched, and the Vue renders only escaped text.

## Test coverage
Strong for the logger's happy and failure paths, Stripe, Viva, Whop, sanitizer secrets, role visibility, soft deletes and the fake provider extensibility test. Not covered: the unique key recovery and sequence retry branch (Major above), Square late and duplicate events, Revolut duplicate and missing details, and Whop late events. Front end is checked by `types:check` only, which is acceptable for a display component.
