# Review, feature/nmi-provider, 2026-11-14

**Reviewed by**: claude-opus-5-5 (author on another model)
**Scope**: 83 files, feature/nmi-provider vs master (public/build and package-lock.json excluded)
**Verdict**: Changes requested

## Summary
Adds NMI as the tenth provider: Payment Component pay page with 3DS and Google Pay, server side v5 sale, `query.php` read back through a single status writer (`NmiChargeResolver`), signed webhook, stale sweep, admin CRUD, and parity edits across payments, dashboard, export, CSP, user forms and receipts. The design follows the house pattern closely and the test suite is strong (112 NMI tests pass in parallel). One real double charge gap remains: when NMI says "approved" but the read back does not settle it, that evidence is stored as `unclear`, and the only thing stopping a second sale is a 120 second cache key.

## Major
### 🟠 An approved sale reply that fails the read back loses its "approved" mark, so a second sale is possible after 120 seconds, `app/Http/Controllers/NmiPaymentController.php:133`
**Problem**: When the sale reply is `response_code` 100 with a transaction id but `readBack()` does not end in `completed` (NMI lag, `query.php` error, a 5xx), the reply is turned into `unclear` (line 214/221) and the attempt row is saved as `status = unclear` (line 137). `payableAccount()` (line 321) only blocks new charges when a row has `status = approved`. So the server holds positive proof that the card was charged, but after the pause key (`unclear_pause_seconds`, 120s) expires, a new charge is accepted. `ResolveNmiCharge` runs only once at +15s and does not retry on `OUTCOME_ERROR` (it never throws), so if `query.php` is slow or down for 2 minutes, nothing settles the payment before the pause runs out. The page also stops showing the waiting state on reload once the pause is gone (`confirming` in `ClientPaymentController::showNmi` depends on the cache key).
**Why it matters**: A customer whose card was charged can be charged again for the same payment. The resolver would then see two approved sales and refuse to complete either (`OUTCOME_DUPLICATE`), leaving a double charged customer and a payment stuck in `pending`. This is the exact risk AC-8 says the pause is there to prevent.
**Suggested fix**: Treat "sale reply said 100 with a transaction id" as blocking in its own right. For example, keep a marker on the attempt row (the stored `response_code` '100' plus `transaction_id` already identify it) and have `payableAccount()` also refuse when such a row exists and the payment is not completed; or keep the pause until the resolver settles it instead of a fixed TTL for this case. Make `ResolveNmiCharge` throw (or re-dispatch) on `OUTCOME_ERROR` / `OUTCOME_NOT_FOUND` so it retries with backoff. Add a test: approved sale reply, empty or failing read back, pause expired, second charge request is refused.

### 🟠 The approved-but-not-confirmed path has no test, `tests/Feature/NmiChargeTest.php:94`
**Problem**: Tests cover approved plus a matching read back, and unclear from E_DUPLICATE or timeout, but not "sale reply approved, read back empty / error / mismatch". This is the branch in `readBack()` (`NmiPaymentController.php:207-222`) that decides whether money already taken is treated as unclear.
**Why it matters**: It is branching, money safety logic (TESTS = configured), and it is where the major above hides.
**Suggested fix**: Add cases for read back returning an empty `<nm_response>`, an `<error_response>`, and a thrown ConnectionException; assert outcome `unclear`, pause kept, `ResolveNmiCharge` queued, attempt row keeps the transaction id, and a second charge is refused.

## Minor
### 🟡 Sweep give up overwrites whatever the last attempt row is, not only an unclear one, `app/Services/Nmi/NmiStalePaymentSweep.php:51`
**Problem**: AC-15 says the sweep marks "the last `unclear` attempt row" as error. The code takes the last row of any status (line 51) and overwrites it (line 67). A payment whose only charge got a clear HTTP 400 (`error`, reason "NMI rejected the request fields") or whose last row is `3ds_failed` has that diagnostic replaced by "No transaction found at NMI" after 30 minutes.
**Why it matters**: The attempt table is the money audit trail; the real reason for a failure is lost for operators.
**Suggested fix**: Only update the latest row whose status is `sent` or `unclear`; skip the payment when there is none.

### 🟡 Pause key is not checked again inside the charge lock, `app/Http/Controllers/NmiPaymentController.php:60`
**Problem**: The pause is checked before the lock is taken; inside the lock only `payableAccount()` is re-run. A request that passed the pause check, then got the lock right after an unclear charge released it, would send a second sale. The window is tiny, but the fix is one line.
**Suggested fix**: Re-check `Cache::has($this->pauseKey($payment))` after `$lock->get()` succeeds.

### 🟡 Status endpoint has no route throttle, `routes/web.php:445`
**Problem**: `pay.nmi.status` relies on the 5 second cache guard only for NMI calls; every request still does model binding, a DB read and a cache write. The Whop and Kurv status routes carry `throttle:60,1`.
**Suggested fix**: Add `throttle:60,1` (polling is every 3 seconds, so 20/min per client is normal).

### 🟡 "Private key pasted as public key" uses a second message, against AC-1, `app/Http/Requests/Admin/StoreNmiAccountRequest.php:52`
**Problem**: AC-1 (decided 2026-11-14) says one message for both the format error and the equals-private-key case. Both Store and Update (`UpdateNmiAccountRequest.php:55`) add "This looks like a private key. Paste the public tokenization key." In practice a private key rarely matches the dashed regex, so this branch is almost never hit, but it disagrees with the spec.
**Suggested fix**: Use the same fixed message, or update the spec if the second wording is now wanted.

### 🟡 `three_ds_result` column is never written, `app/Http/Controllers/NmiPaymentController.php:255`
**Problem**: AC-11 lists `three_ds_result` on the attempt row; `recordAttempt()` sets avs and cvv only. The 3DS status (`card_holder_auth` / eci) is available in the request.
**Suggested fix**: Save the short 3DS result (for example the `card_holder_auth` value or eci, cut to 8 chars) on card charges with 3DS, or drop the column.

### 🟡 Account currency and environment can be changed while payments are in flight, `app/Http/Requests/Admin/UpdateNmiAccountRequest.php:25`
**Problem**: Editing an account's `currency` breaks the invariant "currency always equals the account's currency" for its pending payments (the sale still sends the payment currency). Changing `environment` sends later lookups for existing `nmi_order_id`s to the other host, where they are never found, so the sweep gives up on real payments.
**Suggested fix**: Refuse a currency or environment change when the account has pending payments with `nmi_first_attempt_at` set (or any pending payments for currency).

### 🟡 A non JSON 4xx sale reply (for example a bad private key) becomes unclear, `app/Services/Nmi/NmiSaleReply.php:109`
**Problem**: A 401/403/404 with no `error_code` and no `response_code` falls to `$code === null` and returns `unclear`, which pauses the payment for 120s and queues a lookup, when it is really a configuration error.
**Suggested fix**: Map HTTP 4xx (other than the known `E_DUPLICATE` body) without a `response_code` to `error`; keep `unclear` for 5xx, empty body and transport errors.

### 🟡 Sweep keeps querying declined, abandoned payments for 48 hours, `app/Services/Nmi/NmiStalePaymentSweep.php:25`
**Problem**: Any pending NMI payment with a charge in the last 48 hours is looked up every 10 minutes, including ones that ended with a clear decline. With a 1 second gap per account, a busy account can make the run long.
**Suggested fix**: Limit the sweep to payments whose latest attempt is `sent` or `unclear` (or whose pause is set), as the Authorize.net sweep intent suggests.

## Nits
- ⚪ `resources/js/pages/ClientPayment/AGENTS.md:9`, key files table has no `PayNmi.vue` + `NmiPaymentForm.vue` row; `CardLockedNotice.vue` row still says MPGS and Authorize.net only.
- ⚪ `app/Services/CardLock/CardLockGuard.php:10`, class docblock still says "MPGS or Authorize.net"; constant `ANET_FAILED` now also serves NMI, a neutral name would read better.
- ⚪ `resources/js/pages/ClientPayment/PayNmi.vue:133` and `NmiPaymentForm.vue:91`, hard coded `/pay/.../nmi/status` and `/nmi/charge` URLs instead of Wayfinder helpers.
- ⚪ `config/services.php:64`, the NMI `google_pay` wallet host list is still marked as a starting set; Google Pay is unverified (open question 5).

## Strengths
- Clean separation: `NmiSaleReply` and `NmiQueryReply` are pure, are the only classes reading NMI field names, and are well unit tested (including XXE safety with `LIBXML_NONET`).
- Single status writer with a guarded `whereIn('status', ['pending','failed'])` update, exact amount/currency/order id match, one approved sale rule, and notification only on a real row change.
- Pause set before the sale, E_DUPLICATE treated as unclear, token and keys never logged (exception class only), keys encrypted and not mass assignable, CSP added only on the NMI pay page.
- Parity is complete across export, dashboard filters and totals, Accounts Today, user forms, payment maps, receipts and badges.

## Test coverage
112 NMI tests pass (`php artisan test --parallel --filter=Nmi`). Covered: happy path with read back, decline/check_details, duplicate, timeout pause, gateway error, amount and currency mismatch, multiple approved sales, cancelled payment, 3DS failure and required 3DS fields, consent, rate limits, card lock, status guard, webhook signature, dedupe and retry, sweep give up, CSP per page, admin CRUD, parity surfaces. Not covered: approved sale reply whose read back is empty, errors or throws (the Major above); `ResolveNmiCharge` when the lookup errors; sweep give up when the last row is not `unclear`; account edit while payments are pending.
