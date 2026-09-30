# Verify: Stripe account health · spec 0004 · updated 2026-09-30
_Steps derived from spec 0004 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual
- [x] Log in as admin, open `/stripe-health` → one card per Stripe account (active and inactive), each with a badge → AC-6
- [x] Add a Stripe account with a valid key on `/admin/stripe-accounts/create` → card and list row show a status right after saving → AC-10
- [x] Add or edit an account with a key Stripe rejects → save still works, badge shows Unreachable with a cleaned error, no key text visible → AC-10, AC-11
- [x] Press Check now on one card → toast appears, "Last checked" becomes "just now" → AC-8
- [x] Press Check all now → every card refreshes → AC-8
- [x] Press Check now 11 times within a minute → the 11th is rejected with 429 → AC-8
- [x] Log in as a user with the `account` role → page and buttons work; as an `agent` → 403 → AC-8
- [x] Restricted account (charges off) → card shows Restricted, "Charges: Off", and Stripe's reason or the "Stripe did not say why" note → AC-2, AC-6
- [x] Open `/admin/stripe-accounts` → each row shows the same badge as the health page → AC-9
- [x] Card success rates: compare 7 and 30 day numbers with the Payments list for one account (completed / completed + failed, pending and cancelled ignored) → AC-7
- [x] Card "Pending over 24 h" equals the count of pending payments older than 24 hours for that account → AC-7
- [x] Account with no saved row (delete its health row in the DB) → card says "Not checked yet" → AC-6
- [x] View page source / Inertia props for `/stripe-health` and `/admin/stripe-accounts` → no `sk_`, `rk_` or `whsec_` value → AC-11

## Commands
- [x] `php artisan stripe:check-health` → one line per account, one `stripe_account_health` row each (inactive included) → AC-1
- [x] `php artisan stripe:check-health 3` → only account 3 is checked → AC-1
- [x] `php artisan stripe:check-health 9999` → "not found", non zero exit → AC-1
- [x] `php artisan schedule:list` → `stripe:check-health` every 15 minutes → AC-5
- [x] Run the command twice; compare `status_changed_at` (unchanged) and `last_checked_at` (moved) in the DB → AC-4
- [x] Temporarily use a bad key on one account, run the command → that row is `unreachable`, old data kept, other accounts still updated → AC-3
- [x] `php artisan test tests/Feature/StripeHealthTest.php` → all pass → AC-1 to AC-11

## Value sourcing checks
- [x] Charges/payouts/details/country/currency/remote id match what the Stripe dashboard shows for the account → AC-2
- [x] Requirement lists and disabled reason match Stripe's `requirements` (an account with none shows none, no error) → AC-2
- [x] Status matches the rules: charges off = restricted, payouts off or due items = needs attention, else healthy → AC-2
- [x] "In this status" only resets when the status changes, not on every check → AC-4
- [x] `last_error` has no key and is at most 500 characters → AC-11
- [x] Payments outside the window by `created_at` (not `paid_at`) are excluded from the rates → AC-7

## Acceptance-criteria coverage
- AC-1 … covered by commands 1 to 3 · AC-2 … status and value checks · AC-3 … bad key command · AC-4 … twice run check · AC-5 … schedule:list · AC-6 … cards, Not checked yet · AC-7 … rate and pending checks · AC-8 … Check now, throttle, roles · AC-9 … list badge · AC-10 … add and edit account · AC-11 … props, error text checks
