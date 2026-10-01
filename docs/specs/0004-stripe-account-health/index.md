# 0004. Stripe account health monitoring

**Date**: 2026-09-30
**Status**: Accepted

## Summary

PayHub will check every Stripe account on a schedule and save whether Stripe still lets that account take payments. A new page, Stripe Health, shows one card per account with a colour badge, what Stripe is asking for, and how payments on that account are doing. This means a restricted account (like the one that broke a client's pay link) shows up in PayHub before a client hits it, and nobody has to log in to Stripe to find out.

## Requirements

**User stories**:
- As an admin or account user, I want to see at a glance which Stripe accounts can take payments so that I find restrictions before clients do.
- As an admin or account user, I want to press Check now after fixing something in Stripe so that I can see the account turn healthy right away.
- As an admin or account user, I want to see how payments on each account are performing so that I can spot an account that is quietly failing.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: Running `php artisan stripe:check-health` checks every active Stripe account (inactive accounts are skipped), using that account's own secret key, and saves exactly one row per checked account in `stripe_account_health`. Passing an account id checks only that account.
- **AC-2**: The saved status follows these rules in order. `unreachable` when the Stripe call fails. `restricted` when `charges_enabled` is false. `needs_attention` when charges are on but payouts are off, or Stripe lists any overdue or currently due items. Otherwise `healthy`. A missing `requirements` field from Stripe counts as empty.
- **AC-3**: When a check fails, the last good data is kept, the status becomes `unreachable`, and a cleaned error text is saved in `last_error`. One failing account never stops the others from being checked.
- **AC-4**: `last_checked_at` updates on every check. `status_changed_at` updates only when the status value actually changes (and on the very first check).
- **AC-5**: The command is scheduled every 15 minutes and cannot overlap with a run that is still going.
- **AC-6**: The `/stripe-health` page shows one card per active account (inactive accounts are hidden) with: account name, prefix, active flag, status badge, charges and payouts on or off, the lists of what Stripe needs, Stripe's disabled reason when present, when it was last checked, how long it has been in this status, and the error text when unreachable. An account with no saved row shows "Not checked yet".
- **AC-7**: Each card shows the 7 day and 30 day success rate (completed out of completed plus failed, ignoring pending and cancelled) with the counts, and the number of payments pending for more than 24 hours.
- **AC-8**: Check now works per account and for all accounts. It runs inside the request, updates the saved data, and the page then shows the fresh result. It is limited to 10 requests per minute per user. Admin and account roles can use the page and the buttons. Agents receive 403.
- **AC-9**: The Stripe Accounts list shows the same status badge on each row.
- **AC-10**: Creating a Stripe account, or changing its secret key, triggers one check right after saving. If Stripe fails, the save still succeeds and the badge shows the problem.
- **AC-11**: No secret key or webhook secret appears in any page data, log line or `last_error`.

## Decision

**Chosen option**: Option 1: Scheduled check that saves the latest result

One shared checker class runs from the scheduled command, the Check now buttons and the account save hook, and writes one `stripe_account_health` row per account.

## Feature design

**Data model sketch**:

`stripe_account_health` (one row per Stripe account)

| Field | Type | Notes |
|---|---|---|
| `id` | primary key | |
| `stripe_account_id` | foreign key to `stripe_accounts`, unique | cascade on delete |
| `stripe_remote_id` | string, nullable | Stripe's `acct_...` id |
| `status` | string, required | `healthy`, `needs_attention`, `restricted`, `unreachable`. Cast to enum `App\Enums\StripeHealthStatus` |
| `charges_enabled` | boolean, nullable | |
| `payouts_enabled` | boolean, nullable | |
| `details_submitted` | boolean, nullable | |
| `disabled_reason` | string, nullable | from Stripe's `requirements.disabled_reason` |
| `requirements` | JSON, nullable | keys: `currently_due`, `past_due`, `eventually_due`, `pending_verification` (arrays of strings), `current_deadline` (unix seconds or null) |
| `country` | string(2), nullable | |
| `default_currency` | string(3), nullable | |
| `last_error` | text, nullable | cleaned, max 500 characters |
| `status_changed_at` | timestamp, nullable | |
| `last_checked_at` | timestamp, nullable | |
| `created_at`, `updated_at` | timestamps | |

Relationship: `StripeAccount` hasOne `StripeAccountHealth` (the new model). No balance data is stored.

**State transitions**:
`status` can move between any two values on any check, since Stripe can change an account's state in any direction. `status_changed_at` is set only when the new value differs from the saved one.

**API surface**:
| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `/stripe-health` (`stripe-health.index`) | GET | none | Inertia page with `accounts[]` (see Value sourcing) | login, verified, role admin or account | 403 for agents |
| `/stripe-health/check` (`stripe-health.check-all`) | POST | none | redirect back with a flash message | same, throttle 10 per minute | 403, 429 |
| `/stripe-health/{stripe_account}/check` (`stripe-health.check`) | POST | `stripe_account` route binding | redirect back with a flash message | same, throttle 10 per minute | 403, 404, 429 |
| `php artisan stripe:check-health {account?}` | command | optional account id | console summary line per account | scheduler or shell | account not found |

The checker never throws for a Stripe or network failure. It records `unreachable` and returns. The command and the buttons therefore always finish.

**Value sourcing**:
| Action | Value produced / displayed | Source |
|---|---|---|
| Check | `charges_enabled`, `payouts_enabled`, `details_submitted`, `country`, `default_currency`, `stripe_remote_id` | Stripe `accounts->retrieve()` fields `charges_enabled`, `payouts_enabled`, `details_submitted`, `country`, `default_currency`, `id` |
| Check | `requirements` lists and deadline, `disabled_reason` | Stripe `requirements.currently_due`, `past_due`, `eventually_due`, `pending_verification`, `current_deadline`, `disabled_reason`. Missing means empty or null |
| Check | `status` | derived from the fields above by the AC-2 rules |
| Check | `status_changed_at` | current time, only when derived status differs from the saved status |
| Check | `last_checked_at` | current time |
| Check | `last_error` | exception message from the failed call, with any `sk_`, `rk_` or `pk_` key pattern removed, cut to 500 characters |
| Check | the key used | `$account->secret_key` (encrypted cast) for that account only |
| Page card | account name, prefix, active flag | `stripe_accounts.account_name`, `prefix`, `is_active` |
| Page card | "in this status since" | `status_changed_at` |
| Page card | success rate 7 and 30 days | count of `payments` for that `stripe_account_id` with `status` completed divided by completed plus failed, where `created_at` is within the window. Soft deleted payments excluded by the model. Shown as "No data" when the divisor is 0 |
| Page card | stuck pending count | count of `payments` for that account with `status = 'pending'` and `created_at` older than 24 hours |
| List badge | status | `stripe_account_health.status` loaded with the accounts list |

**Key invariants**:
- Exactly one health row per Stripe account (unique index on `stripe_account_id`).
- A check never overwrites good data with empty data. On failure only `status`, `last_error` and `last_checked_at` change.
- A secret key is only ever used inside the checker to build that account's `StripeClient`. Never `Stripe::setApiKey()` (project rule).
- Page data is built from an explicit list of fields, never by sending the model to the page.

**Security model**:
- All three routes sit in the `auth`, `verified`, `role:admin|account` group. Agents get 403.
- The admin and account roles can both press Check now (engineer's choice). The throttle limits Stripe calls.
- Only status fields, requirement lists and error text reach the browser. Keys, webhook secrets and the encrypted values are never sent.
- No card data is involved, so no PCI scope is added. No money moves, so no activity log entry is written for a check.

**Configuration required**:
- None. The scheduler must already be running (`schedule:run` every minute), as the Clover sweep needs it.

**Critical test scenarios** (each maps to an acceptance criterion in ## Requirements):
- Happy path: with Stripe faked as fully healthy, the command saves a `healthy` row for every active account and none for inactive ones, verifies **AC-1**, **AC-2**
- Status rules: charges off gives `restricted`, payouts off gives `needs_attention`, overdue items give `needs_attention`, missing `requirements` gives `healthy`, verifies **AC-2**
- Failure case: Stripe throws for one account, that account becomes `unreachable` with old data kept and the others still update, verifies **AC-3**
- Status timestamps: unchanged status keeps `status_changed_at`, a changed one moves it, verifies **AC-4**
- Schedule: the event is registered every 15 minutes without overlapping, verifies **AC-5**
- Page: props contain one card per account, "Not checked yet" for an account with no row, verifies **AC-6**
- Performance: 7 and 30 day counts, completed over completed plus failed ignoring pending and cancelled, stuck pending over 24 hours, verifies **AC-7**
- Auth and permission: admin and account users can open the page and press Check now, an agent gets 403, the eleventh request in a minute gets 429, verifies **AC-8**
- List badge: the Stripe Accounts list props include each account's status, verifies **AC-9**
- Save hook: creating an account triggers a check, a Stripe failure still saves the account, verifies **AC-10**
- Secrets: no page prop, log record or `last_error` contains a key value, verifies **AC-11**

## Build plan

Order follows thin end to end slices (Tracer Bullet, the default for production work, since no approach is recorded for this feature): first one account checked and saved, then shown, then the rest around it.

1. Create the migration for `stripe_account_health`, the `StripeAccountHealth` model, the `StripeHealthStatus` enum, the `hasOne` relation and a factory, satisfies **AC-1**, **AC-4**
2. Build the `StripeAccountHealthChecker` service (per account `StripeClient`, status rules, error cleaning, timestamp rules, never throws), with Pest tests using the existing `app()->bind(StripeClient::class, ...)` fake pattern, satisfies **AC-1**, **AC-2**, **AC-3**, **AC-4**, **AC-11**
3. Add the `stripe:check-health {account?}` command and register it in `routes/console.php` every 15 minutes with `withoutOverlapping()`, with tests, satisfies **AC-1**, **AC-3**, **AC-5**
4. Add `StripeHealthController` (index, checkAll, check), the three routes in the `role:admin|account` group with `throttle:10,1`, and regenerate Wayfinder, with authorization and throttle tests, satisfies **AC-6**, **AC-8**
5. Add the live performance query (7 and 30 day rate, stuck pending) as one grouped query for all accounts, with tests, satisfies **AC-7**
6. Build `resources/js/pages/StripeHealth/Index.vue` (cards, badge component, Check now buttons, empty and "Not checked yet" states) and add the sidebar link for admin and account roles, satisfies **AC-6**, **AC-7**, **AC-8**
7. Show the badge on the Stripe Accounts list (`Admin\StripeAccountController@index` eager loads health, the Index page shows the badge), with a props test, satisfies **AC-9**
8. Call the checker after store and after update when the secret key changed in `Admin\StripeAccountController`, inside a try and catch so saving never fails, with tests, satisfies **AC-10**
9. Add the secrets test (props, logs, `last_error`) and run Pint, the Pest suite, `tsc` and eslint, satisfies **AC-11**

## Consequences

**Positive**:
- A restricted or unreachable Stripe account is visible within 15 minutes, or immediately after Check now.
- The reason Stripe gives is on the page, so no tinker or Stripe login is needed for the first diagnosis.
- The saved status and "since" date are the base for alerts later.

**Negative / tradeoffs**:
- Data can be up to 15 minutes old.
- Stripe may return no requirements or reason for standalone accounts (your restricted accounts returned none). The page can then say "restricted" without saying why, and must not look broken.
- A restricted key that cannot read account details shows as `unreachable` with the permission error, even though the key works for payments.
- Check all runs the accounts one after another inside one request. If Stripe hangs, the request is slow. No global timeout is set because that would change every Stripe call in the app.
- The account role can trigger Stripe calls (engineer's choice), limited only by the throttle.

**Neutral**:
- One new table, one enum, one service, one command, one controller and one page.
- The sidebar gets one new entry, and the nav access matrix in `docs/agent.md` needs a matching line.

## Follow-up

- [ ] Alerts when a status changes to `restricted` or `unreachable` (uses `status_changed_at`)
- [ ] Warning on the payment form when the chosen Stripe account is not healthy
- [ ] Health for Revolut, Square, Viva and Clover, using whatever each API exposes
- [ ] Stripe balance display, if wanted later
- [ ] Add the Stripe Health entry to the nav access matrix in `docs/agent.md` (admin and account roles)
