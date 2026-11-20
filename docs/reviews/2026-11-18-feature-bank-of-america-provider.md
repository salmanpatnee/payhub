# Review, feature/bank-of-america-provider (Worldpay scope, 4e50884^..HEAD), 2026-11-18

**Reviewed by**: claude-opus-5-5 (review subagent; author model not stated)
**Scope**: 75 source files (plus compiled `public/build` assets), branch vs master, limited to the Worldpay commits (specs 0014 and 0015)
**Verdict**: Changes requested

## Summary
Adds Worldpay as the 11th provider: Hosted Payment Pages by redirect, with paid status read back through Payment Queries by a single resolver (poll, return, sweep, optional signed webhook), plus card fields groundwork (spec 0015: account mode, charge attempt table, resolver and sweep support). The hosted flow is careful and well tested: server side amount, consent check, host allow list on the page URL, reference reserved before the call, guarded single update, always 200 webhook, secrets encrypted and never returned. The main issue is that admins can already pick the `card_fields` mode, but nothing at runtime honours it. There are also smaller gaps: the `failure` return word now closes pages, which the spec does not allow; the `retry` state can lead to a dead end; and a sweep dedupe bug affects card attempts.

## Major

### 🟠 `card_fields` mode can be chosen but does nothing, `resources/js/pages/admin/worldpay-accounts/Create.vue:192`, `app/Http/Controllers/WorldpayPaymentController.php:259`, `app/Http/Controllers/ClientPaymentController.php` (`showWorldpay`)
**Problem**: The admin Create and Edit forms offer "card fields" mode, and the requests save it with a Checkout ID and Google Pay settings. But no card routes, `PayWorldpayCard.vue` or `WorldpayCardController` exist. `showWorldpay` always renders the hosted `PayWorldpay` page, and `payable()` never checks the mode. Spec 0015 AC-2 says the hosted checkout must answer 422 "This account takes card payments on the PayHub page." for a `card_fields` account. Here it creates a hosted page instead.
**Why it matters**: An admin who picks card fields gets an account that the admin list labels as card fields but that takes payments as a hosted page. Once one payment exists, the mode lock (AC-2) stops the admin from switching back, so the only fix is a new account. The spec contract and the code disagree in production.
**Suggested fix**: While card fields are paused, either hide or disable the `card_fields` option (and reject `mode=card_fields` in both requests with a "not available yet" message), or put the mode behind a config flag that defaults to off. If the option stays, add the AC-2 422 to `checkout()` for a `card_fields` account.

## Minor

### 🟡 The `failure`/`error` return word closes pages, which the spec does not allow, `app/Http/Controllers/ClientPaymentController.php` (`showWorldpay`, the `closed_at` update)
**Problem**: Spec 0014 AC-9 says `closed_at` is set "only from an expiry or closed event", and AC-10 says the return word is display only. The new code closes every usable page whenever someone loads `?worldpay_return=failure` or `error`. This follows the Try finding of 2026-11-18, but the spec was not updated. There are two risks. First, `reuse_after_decline` is `true` (the same Try run showed a client could pay on the same page after a refusal), so the "closed" page can still be paid at Worldpay while Pay now hands out page 2. A client with the old tab still open can then pay twice, and the resolver only logs that as `duplicate`. Second, anyone with the link can load the failure URL and burn pages until `max_pages` (3), after which the link is dead.
**Suggested fix**: Record this rule in spec 0014 (AC-9 and AC-10) with the Try evidence. Then do one of these: close only the newest page, and only when a lookup shows a decline or no row; or keep the word as a hint and require the resolver to have run (as `handOut` already does) before making page 2. Also check the two config comments (page rejects every card after failure, and `reuse_after_decline: true`). They describe opposite Try behaviour and should be reconciled.

### 🟡 `retry` state ignores `max_pages`, `app/Http/Controllers/WorldpayPaymentController.php:232-257`
**Problem**: AC-10 says `retry` is returned only when "a new checkout would really succeed". `state()` checks that the page is dead and that the decline is fresh, but not whether the payment already has `max_pages` usable pages. On the last page the poll shows Pay now, and the click always gets 422 "This payment link can no longer be used."
**Suggested fix**: Return `waiting` or `unavailable` (or a `blocked` state) when the usable page count is at least `max_pages`, and add a test.

### 🟡 Final card attempts are looked up on every sweep instead of once, `app/Services/Worldpay/WorldpayChargeResolver.php:126-127`
**Problem**: `attemptsToLookUp` decides "already looked up late" by finding a sweep lookup row created after `created_at + 60h`. But `record()` folds a repeat with the same outcome and event into the previous row (only `checks` goes up), so no new row is created. Any final attempt that already has a lookup row with the same outcome is therefore looked up on every sweep from hour 60 to hour 72, about 70 calls instead of 1. The test at `tests/Feature/WorldpayCardResolverTest.php:184` passes only because the attempt has no earlier lookup row.
**Suggested fix**: Store a `late_lookup_at` timestamp on the attempt, or check `last_checked_at` instead of `created_at`. Extend the test so an earlier lookup row with the same outcome exists.

### 🟡 Sending `mode: null` on update changes the mode and skips the mode lock, `app/Http/Controllers/Admin/WorldpayAccountController.php:97`, `app/Http/Requests/Admin/UpdateWorldpayAccountRequest.php:55`
**Problem**: `mode` is `nullable`. When it is sent as null, `validated()` holds `'mode' => null`, so `+ ['mode' => $account->mode]` does not fill it in, and `applyMode` falls back to `hosted`. The lock check in `after()` treats null as "unchanged", so a `card_fields` account that has payments silently becomes `hosted` and loses its Checkout ID. The Google Pay check (`$this->input('mode') ?? 'hosted'`) also rejects Google Pay on an Edit that leaves `mode` out.
**Suggested fix**: Make `mode` `sometimes|required` (not nullable) on update, or treat null as the saved mode in both places.

### 🟡 The resolver lock can expire while the resolver is still running, `app/Services/Worldpay/WorldpayChargeResolver.php:82-101`
**Problem**: The lock is 60 seconds. One run can take up to 45 seconds (deadline) plus one 10 second lookup, and when the recheck key is set a second full run follows inside the same lock. A second resolver can then start in parallel. The guarded update stops a double completion and a double notification, but duplicate lookup rows and two Worldpay calls at once can happen. Spec AC-8 sized the lock for one run.
**Suggested fix**: Make the recheck run share the first run's deadline, or size `lookup_lock_seconds` for two runs.

### 🟡 One extra database query per Worldpay row in the CSV export, `app/Exports/PaymentsExport.php:84`
**Problem**: `worldpayPages()->orderByDesc('sequence')->value(...)` runs a query for every unpaid Worldpay row in the export.
**Suggested fix**: Eager load the newest page (for example a `latestOfMany` relation) in the export query.

## Nits
- ⚪ `app/Services/Worldpay/WorldpayStalePaymentSweep.php:72-74`, the "one call per second per account" pause is per payment, not per call. A payment with 3 pages or several attempts makes them back to back.
- ⚪ `app/Services/Worldpay/WorldpayChargeResolver.php:487`, the condition keeps `three_ds_state` when a `challenge_required` attempt moves to `approved`. This is the opposite of the comment ("a finished attempt no longer holds a 3D Secure link").
- ⚪ `app/Http/Controllers/WorldpayWebhookController.php:61-65`, the webhook only matches `worldpay_pages`, so card attempt references are never found (fine while card fields are paused, but worth a TODO).
- ⚪ `AGENTS.md`, still says ten providers and lacks a Worldpay bullet and the `webhook/worldpay/*` CSRF entry (the spec defers this to `/sync`; do it before merge to master).
- ⚪ `app/Http/Controllers/PaymentController.php:16-18` and `UserController.php:15`, the new `use` lines are out of alphabetical order (Pint may flag this).

## Strengths
- The money path is sound. The amount comes only from `Payment.amount`, the currency is the constant GBP, and the page URL must be https on a host in the allow list. The reference row is reserved before the call, so a reference is never reused. Completion is one guarded `whereIn('status', ['pending','failed'])` update that sends the notification only when a row changed.
- The resolver is the only status writer. Duplicate, mismatch, cancelled and a late second sale are logged with both ids and never change the status, and Sentry alerts fire once per key. Unknown events never fail a payment.
- Secrets are handled well. The username, password and webhook secret are encrypted, not mass assignable, and sent to the browser only as `has_*` flags. Logs carry HTTP status and exception class only. The webhook compares signatures with `hash_equals` and never logs the header.
- Provider parity is thorough: enum, exports, dashboard (filters, totals, Accounts Today), user assignment, request rules, receipt pages, sidebar and badge, `clearStaleProviderTransactionIds`, delete and edit locks, and `ProviderAccountTable`. `WorldpayParityTest` covers this.

## Test coverage
Strong. There are eight Worldpay test files and close to 90 cases, covering checkout reuse, grace, replace and `max_pages`, the host allow list, 502 failure rows, the limiter, consent and inactive account checks, resolver outcomes (mismatch, duplicate, cancelled, recheck lock, closed only from an event), the webhook signature variants and limits, the sweep window, admin CRUD and test connection messages, and parity. Gaps:
- No test that `retry` respects `max_pages`.
- The final attempt "once" test does not include an earlier lookup row with the same outcome, so it misses the dedupe bug.
- No test that `mode: null` on update keeps the mode.
- No test that a `card_fields` account is refused by the hosted checkout (spec 0015 AC-2), because the behaviour is missing.
