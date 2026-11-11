# 0011. Dashboard "Received in this period" switch

**Date**: 2026-11-11
**Status**: Accepted

## Summary

The dashboard date filter always works on the day a payment link was created. That means "Collected" can count money that arrived after the range ended and miss money that arrived inside it. This spec adds a checkbox called "Received in this period". When it is on, all completed money on the dashboard is counted by the day it was paid. When it is off, nothing changes. No database change is needed.

## Context

The dashboard filters in `DashboardMetrics` (`app/Services/DashboardMetrics.php`). `baseQuery()` applies "From" and "To" to `created_at`, and `kpis()`, `currencySplit()` and the Brand and RM panels all read from it. So "Collected" means "created in this range and now completed", not "paid in this range".

Two panels already count by `paid_at`: the revenue trend chart and "Accounts Today". The result is that the top Collected number and the chart can show different totals for the same dates, which is confusing when someone is reconciling money against a bank or provider payout.

Pending payments have no paid date, so they can only ever be counted by created date. Completed payments always have `paid_at` in current data (a check of the local database found 0 of 11 missing), but production data may differ.

Stack and conventions come from `AGENTS.md` (Laravel 13, Inertia v3, Vue 3, Pest). Platform: web. This is an enhancement to existing code with no scope row (the project has no `docs/scope/`).

## Requirements

**User stories**:
- As an admin or account user, I want to see how much money was received inside a date range so that the dashboard matches what arrived in the bank or provider account.
- As the same user, I want to turn this off so that I can still see numbers by the day links were created.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: The dashboard filter bar shows a checkbox labelled "Received in this period". It is off by default.
- **AC-2**: With the checkbox off, every dashboard number is exactly what it is today (no change for existing users).
- **AC-3**: With the checkbox on and "From" or "To" set, "Collected", "Average payment value", "Active brands" and the currency split count only completed payments whose paid day is inside the range. A payment created inside the range but paid after it is not counted. A payment created before the range but paid inside it is counted.
- **AC-4**: With the checkbox on, Brand performance and RM leaderboard show revenue and completed count by paid day, and are sorted by that paid day revenue. Their conversion rate still uses created date (completed links created in range divided by links created in range). A row that exists only because of paid day money (no links created in range) shows "—" for conversion, never a divide by zero. The top conversion rate and success rate in `kpis()` also stay on created date.
- **AC-5**: With the checkbox on, a completed payment with no `paid_at` is counted by its created date instead, so no money disappears from "Collected".
- **AC-6**: Pending amount and count, the funnel, the Worklist, conversion rate and success rate always use created date, whether the checkbox is on or off.
- **AC-7**: Revenue trend and "Accounts Today" behave as they do today in both states.
- **AC-8**: With the checkbox on but no dates set, nothing changes (all time), and the filter bar shows a hint: "Pick a date range for this to take effect." A range with only "From" or only "To" works as an open ended range, the same as today.
- **AC-9**: The checkbox state is kept in the page address as `received=1`, so reload and shared links keep it. "Clear" turns it off. All existing filters (brand, provider, account, currency) still combine with it. A missing, `0` or unreadable `received` value means off, and never an error.
- **AC-10**: The Central Dashboard API (`CentralSummary`, spec 0008) is not changed.

## Options considered

### Option 1: A checkbox that changes which date the completed money is counted by

One new filter, `received`. When on, completed money queries use paid day (with created date as a fallback), everything else stays put. Off by default.

**Pros**:
- Existing numbers and saved habits stay the same until someone opts in.
- One small, easy to explain control.

**Cons**:
- Two date meanings live in one page, so some panels (pending, rates) still use created date. The label and a short hint must make that clear.

### Option 2: Switch the whole dashboard to paid date for good

Replace the created date logic everywhere.

**Pros**:
- One simple rule.

**Cons**:
- Pending has no paid date, so it cannot follow. Conversion rate would also stop making sense (it could pass 100%). It also silently changes numbers people already rely on.

### Option 3: A date mode dropdown ("Created" or "Paid")

Same behaviour as Option 1 but as a two choice select instead of a checkbox.

**Pros**:
- Names both modes out loud.

**Cons**:
- Takes more room in a crowded filter bar. The engineer asked for a check or switch.

## Decision

**Chosen option**: Option 1: A checkbox that changes which date the completed money is counted by

Add a boolean `received` filter that moves completed money to paid day and leaves everything that cannot follow it on created date.

## Rationale

The filter bar is already full and the team asked for a check or switch. Pending money has no paid date and conversion needs the created group as its base, so a full switch (Option 2) would give wrong or odd numbers. Off by default protects every number users see today. Falling back to created date when `paid_at` is empty keeps totals honest with old data, at the cost of one small difference from the trend chart (which skips such rows today).

## Feature design

**Data model sketch**: no change. Reads existing `payments.status`, `payments.paid_at`, `payments.created_at`.

**API surface** (the existing page route, one new query parameter):
| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `dashboard` (`DashboardController@index`) | GET | `received` (`1` means on; read with `$request->boolean('received')`, no validation rule) plus the existing filters | existing props plus `filters.received` (bool) | existing admin and account access | none for `received` (a bad value is treated as off) |

**Value sourcing**:
| Action | Value produced / displayed | Source |
|---|---|---|
| Currency split | completed money only (it never shows pending) | `completedQuery()` below |
| Collected, average value, active brands | completed money in range | `payments` where `status = completed`, range applied to `DATE(COALESCE(paid_at, created_at))` when `received` is on, else `created_at` as today |
| Brand and RM revenue and completed count | revenue and count by paid day | same completed query grouped by brand or RM and currency |
| Brand and RM total count and conversion | links created in range, and how many of them completed (`createdCompletedCount`, kept separate from the paid day `completedCount`) | existing created date query (unchanged) |
| Brand and RM sort order | paid day revenue when on | the revenue value above |
| Top conversion and success rates | completed over links created in range | existing created date counts (unchanged) |
| Pending amount and count, funnel, worklist | pending and status counts | existing created date query (unchanged) |
| Checkbox state shown in the UI | `received` | `filters.received` prop, taken from the `received` query value |
| The day boundary | database date of the column | same `DATE()` behaviour as the existing `whereDate` calls (no new timezone rule) |

**Key invariants**:
- With `received` off, the SQL conditions are the same as today.
- Money is never summed across currencies, still integer cents.
- A completed payment is counted at most once per query (the fallback uses one date per row).

**Security model**: unchanged. Only users who can already open the dashboard see it. The new value is read as a boolean and never reaches SQL as text (the date values are bound parameters).

**Critical test scenarios** (Pest, in `tests/Feature/DashboardMetricsTest.php`):
- Created inside range, paid after it: counted when off, not counted when on, verifies **AC-2**, **AC-3**.
- Created before range, paid inside it: not counted when off, counted when on, verifies **AC-3**.
- Completed with no `paid_at`, created inside range: counted when on, verifies **AC-5**.
- Pending created inside range appears the same on and off, verifies **AC-6**.
- Brand revenue and completed count follow paid day, conversion rate does not, verifies **AC-4**.
- No dates set with `received=1`: same result as off, verifies **AC-8**.
- `received=1` is returned in `filters`; `received=0`, empty or junk is treated as off with no error, verifies **AC-9**.
- Boundary: paid on the "To" day at 23:59 is counted, paid at 00:00 the next day is not, verifies **AC-3**.
- A row with paid day money but no links created in range shows "—" for conversion and no error, verifies **AC-4**.
- Only "From" set, and only "To" set, each work as an open ended range, verifies **AC-8**.
- Revenue trend and Accounts Today results identical on and off, verifies **AC-7**.
- Dashboard page test still passes for admin and account roles, verifies **AC-1**.

## Build plan

1. In `DashboardController`, read `$request->boolean('received')` (no validation rule) and pass it as a real boolean to `DashboardMetrics`. Return it in `resolvedFilters()` and add `received: boolean` to `DashboardFilterValues` in `resources/js/types/dashboard.ts`, satisfies **AC-9**.
2. In `DashboardMetrics`, add a `completedQuery()` helper: completed status plus the date range on `COALESCE(paid_at, created_at)` when `received` is on and a range is set, otherwise the same as `baseQuery()` plus `status = completed`. Point `revenueByCurrency(COMPLETED)`, `avgByCurrency` and `activeBrands` in `kpis()` at it. Keep pending on `baseQuery()`, satisfies **AC-2**, **AC-3**, **AC-5**, **AC-6**, **AC-8**.
3. Update `groupedPerformance()` so revenue and `completedCount` come from `completedQuery()` while `totalCount` and a separate `createdCompletedCount` (the conversion numerator) still come from `baseQuery()`. Rows that appear only in the paid date set must still be listed, with conversion shown as "—" (null) when `totalCount` is 0. Sort by the paid day revenue. The top `conversionRate` and `successRate` in `kpis()` keep using the `baseQuery()` counts, satisfies **AC-4**.
4. Leave `revenueTrend()`, `accountsToday()`, `funnel()`, `worklist()` and `CentralSummary` alone, satisfies **AC-6**, **AC-7**, **AC-10**.
5. In `DashboardFilters.vue`, add the checkbox (existing `@/components/ui/checkbox`, no new component) labelled "Received in this period", with a short hint: "Counts completed money by the day it was paid. Pending and rates still use created date." When it is on and no date is set, show: "Pick a date range for this to take effect." Add it to the reactive state, `buildQuery()` (send `received=1` only when on), and `clearFilters()`. Put it where it fits the filter bar without breaking the mobile layout, satisfies **AC-1**, **AC-9**.
6. Add the Pest tests listed above, then run `php artisan test --parallel`, `npm run types:check && npm run lint:check && npm run format:check`, and `npm run build` (compiled assets are committed), satisfies **AC-1** to **AC-10**.

## Consequences

**Positive**:
- "Collected" can now match what actually arrived in a date range.
- Nothing changes for anyone who does not turn it on.

**Negative / tradeoffs**:
- Two date meanings share one page when it is on. Pending and conversion still use created date, so a user may see Collected and the conversion subline disagree on purpose. The hint text must say what the checkbox covers.
- The `COALESCE` date filter cannot use an index on `paid_at` or `created_at`. This is fine at current volume (small table, same cost class as the existing `whereDate` calls). If the dashboard slows, switch to a timestamp range: `(paid_at >= start and paid_at < end + 1 day) or (paid_at is null and created_at in range)`.
- `paid_at` is written from webhooks, so the "paid day" is the database date, which may differ from the business day near midnight. This is the same risk the dashboard already has and is accepted.
- The trend chart and Accounts Today skip payments with no `paid_at`, while Collected falls back to created date, so those can differ slightly on old data.

**Neutral**:
- No migration, no new environment values, no change to the Central Dashboard API.
- Built output in `public/build` must be rebuilt and committed.

## Follow-up

- [ ] Check production for completed payments with no `paid_at` (query: `status = completed and paid_at is null`). If there are many, decide whether to backfill them.
- [ ] Check whether any dashboard export or drill down link should carry `received=1` along with the other filters.
- [ ] If the team later wants the Central Dashboard API (spec 0008) to offer the same choice, write it as its own spec because that API has a fixed contract.
