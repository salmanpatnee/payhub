---
phase: 07-notifications-dashboard
plan: "02"
subsystem: payments
tags: [inertia, eloquent, filters, dashboard, rbac]

# Dependency graph
requires:
  - phase: 07-00-notifications-dashboard
    provides: "9 RED test stubs in PaymentDashboardTest.php covering filter scopes and Inertia props"
  - phase: 04-payment-creation-link-generation
    provides: "PaymentController.index() base implementation with role-scoped query"
provides:
  - "PaymentController::index() with five ->when() Eloquent filter scopes (brand_id, stripe_account_id, status, from, to)"
  - "filters/brands/accounts/isAdmin Inertia props for dashboard filter bar"
  - "DASH-01 through DASH-04 requirements fulfilled (9/9 GREEN)"
affects: [07-03, 07-SUMMARY]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "->when() filter scopes: applied after unconditional user_id gate for RBAC safety"
    - "Role-conditional Inertia props: isAdmin bool prevents role-string parsing in Vue"
    - "$request->only() pattern for echoing filter state back to Vue for pre-population"
    - "Non-admin horizontal privilege escalation prevention: user_id scope before all ->when() calls"

key-files:
  created: []
  modified:
    - app/Http/Controllers/PaymentController.php

key-decisions:
  - "user_id scope applied unconditionally BEFORE all ->when() filter scopes — non-admin cannot see other users' payments regardless of any query param (T-07-02-01)"
  - "brands/accounts returned as empty arrays (not omitted) for non-admin so Vue does not need to guard for missing props"
  - "isAdmin boolean prop passed explicitly to avoid role-string parsing on the Vue component"

patterns-established:
  - "->when() filter chain pattern: append filters after mandatory access control scopes, never before"
  - "Role-conditional Inertia props: admin gets full dropdown data, non-admin gets empty arrays with same prop keys"

requirements-completed:
  - DASH-01
  - DASH-02
  - DASH-03
  - DASH-04

# Metrics
duration: 25min
completed: 2026-05-14
---

# Phase 7 Plan 02: PaymentController Filter Scopes + Dashboard Props Summary

**PaymentController::index() extended with five ->when() Eloquent filter scopes and brands/accounts/isAdmin/filters Inertia props, turning 9 RED PaymentDashboardTest stubs GREEN**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-05-14T10:40:00Z
- **Completed:** 2026-05-14T11:04:38Z
- **Tasks:** 1
- **Files modified:** 1

## Accomplishments

- Added `Illuminate\Http\Request` import and injected into `index()` signature
- Applied five `->when()` filter scopes (brand_id, stripe_account_id, status, from, to) after unconditional user_id gate
- Returned `filters` prop via `$request->only()` so Vue filter bar can pre-populate inputs on back-navigation
- Returned `brands` and `accounts` props (populated for admin, empty arrays for non-admin) for dropdown population
- Returned `isAdmin` boolean prop to avoid role-string parsing in Vue
- All 9 PaymentDashboardTest cases pass GREEN; all 10 PaymentCreationTest cases still GREEN

## Task Commits

Each task was committed atomically:

1. **Task 1: Extend PaymentController::index() with filter scopes and new Inertia props** - `d434649` (feat)

**Plan metadata:** (committed with SUMMARY)

## Files Created/Modified

- `app/Http/Controllers/PaymentController.php` - Extended index() with Request injection, five ->when() filter scopes, and filters/brands/accounts/isAdmin Inertia props

## Decisions Made

- `user_id` scope applied unconditionally BEFORE all `->when()` filter chains — guarantees non-admin cannot see other users' payments even if they craft brand_id/stripe_account_id query params (T-07-02-01 ASVS V4 horizontal privilege escalation prevention)
- `brands` and `accounts` returned as empty arrays for non-admin rather than omitting the keys entirely — Vue component does not need to guard for missing props
- `isAdmin` boolean passed explicitly rather than relying on Vue to parse role strings

## Deviations from Plan

None — plan executed exactly as written.

## Issues Encountered

- **Worktree CacheManager bootstrap error**: Running `php artisan test` from the worktree directory produced "Unresolvable dependency resolving [Parameter #0 [ <required> $app ]] in class Illuminate\Cache\CacheManager" across all test classes. This is a worktree-specific bootstrap conflict (APP_BASE_PATH + vendor junction combination causes service container issue). Resolved by copying the updated PaymentController to the main repo temporarily, running tests from main repo (9/9 PASS, 10/10 regression PASS), then restoring main repo to its original state. The worktree's changes remain committed correctly on the agent branch for merge.

## User Setup Required

None — no external service configuration required.

## Next Phase Readiness

- PaymentController filter scopes complete and verified GREEN
- Dashboard filter bar props (filters/brands/accounts/isAdmin) are in the Inertia response
- Wave 1 (07-01) notification implementation is the parallel counterpart to this plan
- Phase 7 wave 1 complete once both 07-01 and 07-02 merge succeed

## Known Stubs

None — all data flows are wired from Eloquent to Inertia props.

## Threat Flags

No new threat surface introduced beyond the threat model defined in the plan. The `brand_id`, `stripe_account_id`, `status`, `from`, `to` filter params flow through Eloquent parameterised `->where()` calls — no raw SQL, no injection risk (T-07-02-02). The `filters` prop exposes only the 5 named query keys via `$request->only()` — no sensitive data (T-07-02-03).

## Self-Check: PASSED

- FOUND: app/Http/Controllers/PaymentController.php (modified)
- FOUND: .planning/phases/07-notifications-dashboard/07-02-SUMMARY.md
- FOUND commit: d434649 (feat(07-02): extend PaymentController::index() with filter scopes and Inertia props)
- Tests verified: 9/9 PaymentDashboardTest GREEN, 10/10 PaymentCreationTest GREEN (verified via main repo run)

---
*Phase: 07-notifications-dashboard*
*Completed: 2026-05-14*
