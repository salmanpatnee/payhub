---
phase: 05-client-payment-page
plan: "01"
subsystem: payments
tags: [stripe, inertia, controller, route-model-binding, laravel]

dependency_graph:
  requires:
    - phase: 05-00
      provides: tests/Feature/ClientPaymentTest.php with 12 RED Pest tests
    - phase: 04-payment-creation-link-generation
      provides: Payment model with uuid, stripe_payment_intent_id, brand/stripeAccount relations
    - phase: 03-brand-stripe-account-management
      provides: StripeAccount with publishable_key (plain) and secret_key (encrypted cast)
  provides:
    - app/Http/Controllers/ClientPaymentController.php with show(), success(), failed()
    - Three public /pay/{payment} routes in routes/web.php
    - Minimal Vue stubs for ClientPayment/{Pay,Unavailable,Success,Failed}.vue
  affects:
    - 05-02 (PaymentLayout + Pay.vue full implementation)
    - 05-03 (Success/Failed page full implementation)
    - 05-04 (Unavailable page full implementation)
    - 06-webhooks-status-sync (uses stripe_payment_intent_id stored by show())

tech-stack:
  added: []
  patterns:
    - "Per-account StripeClient via app()->make(StripeClient::class, ['apiKey' => $secret]) — enables test mocking via app()->bind() while satisfying no-global-setApiKey CLAUDE.md rule"
    - "Route parameter named {payment} (not {uuid}) so Laravel implicit binding matches Payment $payment type hint and resolves by getRouteKeyName()='uuid'"
    - "Guard-before-StripeClient pattern: status check runs before any Stripe API call — non-pending payments never incur a PI creation"

key-files:
  created:
    - app/Http/Controllers/ClientPaymentController.php
    - resources/js/pages/ClientPayment/Pay.vue
    - resources/js/pages/ClientPayment/Unavailable.vue
    - resources/js/pages/ClientPayment/Success.vue
    - resources/js/pages/ClientPayment/Failed.vue
  modified:
    - routes/web.php

key-decisions:
  - "Route parameter is {payment} not {uuid}: Laravel implicit binding requires the route wildcard name to match the controller parameter name; since getRouteKeyName()='uuid', binding resolves correctly by UUID value"
  - "app()->make(StripeClient::class, ['apiKey' => $secret]) instead of new StripeClient($secret): allows test mocking via app()->bind(StripeClient::class, fn() => $mock) while enforcing the no-global-setApiKey rule"
  - "Vue component stubs created (Rule 3): Inertia's assertInertia() checks component files exist on disk — four minimal stubs unblock all 12 tests; full UI in Wave 2 plans"

patterns-established:
  - "ClientPaymentController: load brand+stripeAccount relations, guard status, create PI via container-resolved StripeClient, write stripe_payment_intent_id, pass clientSecret only in Inertia props"
  - "paymentProps() excludes client_name, client_email, note — D-07: these are admin-only fields not shown to clients"
  - "brandProps() constructs logo_url as '/storage/' + logo_path with null-safe check"

requirements-completed:
  - CLIENT-01
  - CLIENT-02
  - CLIENT-03
  - CLIENT-04
  - CLIENT-05
  - CLIENT-06
  - CLIENT-07
  - SEC-04

duration: 16min
completed: "2026-05-09"
---

# Phase 5 Plan 01: ClientPaymentController + Routes Summary

**Public payment backend: ClientPaymentController with show/success/failed, three public /pay/* routes, and Inertia-compatible Vue stubs turning all 12 ClientPaymentTest cases GREEN**

## Performance

- **Duration:** ~16 min
- **Started:** 2026-05-09T17:42:37Z
- **Completed:** 2026-05-09T17:59:09Z
- **Tasks:** 2
- **Files created:** 5, **Files modified:** 1

## Accomplishments

- `ClientPaymentController` with `show()` (guard + PaymentIntent creation + clientSecret prop), `success()` (redirect_status validation), and `failed()` (brand props)
- All security rules enforced: no `Stripe::setApiKey()`, `clientSecret` only in Inertia props, amount from DB only, secret_key never in props
- Three public `/pay/{payment}` routes outside auth middleware, replacing the Phase 4 abort(404) stub
- 12/12 ClientPaymentTest cases pass GREEN (up from 1/12 RED in Wave 0)

## Task Commits

1. **Task 1: Create ClientPaymentController** — `2305f958` (feat)
2. **Task 2: Replace stub routes + Vue stubs** — `8bf69347` (feat)

## Files Created/Modified

- `app/Http/Controllers/ClientPaymentController.php` — Public controller: show/success/failed + brandProps/paymentProps helpers
- `routes/web.php` — Replaced abort(404) stub with 3 real routes + added ClientPaymentController import
- `resources/js/pages/ClientPayment/Pay.vue` — Minimal stub with typed props (full UI in Wave 2)
- `resources/js/pages/ClientPayment/Unavailable.vue` — Minimal stub with typed props
- `resources/js/pages/ClientPayment/Success.vue` — Minimal stub with typed props
- `resources/js/pages/ClientPayment/Failed.vue` — Minimal stub with typed props

## Decisions Made

- **Route wildcard is `{payment}` not `{uuid}`**: Laravel implicit model binding matches by controller parameter name (`$payment`), then uses `getRouteKeyName()='uuid'` for the DB lookup. Using `{uuid}` caused an empty model injection (no binding match), discovered via debug output showing `status=[]`.
- **`app()->make(StripeClient::class, ['apiKey' => ...])` over `new StripeClient(...)`**: The `new` keyword bypasses the Laravel container, making Mockery mocking via `app()->bind()` impossible. Using `app()->make()` with `['apiKey' => ...]` satisfies both test mocking and the no-global-setApiKey CLAUDE.md rule. Verified: `app()->bind(StripeClient::class, fn() => $mock)` takes precedence over constructor params.
- **Minimal Vue stubs as Rule 3 deviation**: Inertia's `assertInertia()` with `->component(...)` validates component files exist on disk in test env (`ensure_pages_exist: true` in config/inertia.php). Four stubs created with correct prop types so Wave 2 plans only need to fill in UI content.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Fixed implicit route model binding: {uuid} → {payment}**
- **Found during:** Task 2 — first test run after routes added
- **Issue:** Route `Route::get('/pay/{uuid}', ...)` with controller `show(Payment $payment)` — Laravel matches route parameters to controller parameters by name. `{uuid}` did not match `$payment`, so no implicit binding occurred. The controller received an empty `Payment` model (id=null, status='') causing a TypeError when `brandProps(null)` was called.
- **Fix:** Changed route parameters from `{uuid}` to `{payment}` on all three routes. `getRouteKeyName()='uuid'` still resolves by UUID value — URL structure unchanged.
- **Files modified:** `routes/web.php`
- **Verification:** Debug output confirmed `payment->id= status=[]` with `{uuid}`, and 4/12 tests started passing with `{payment}`.
- **Committed in:** `8bf69347` (Task 2 commit)

**2. [Rule 3 - Blocking] Created minimal Vue component stubs for Inertia assertInertia() compatibility**
- **Found during:** Task 2 — second test run after route binding fix
- **Issue:** Inertia's `assertInertia(fn($page) => $page->component('ClientPayment/Pay'))` checks that `resources/js/pages/ClientPayment/Pay.vue` exists on disk (`ensure_pages_exist: true`). Files did not exist, causing 8 of 12 tests to fail with "Inertia page component file does not exist".
- **Fix:** Created four minimal typed Vue stubs with correct prop interfaces: `Pay.vue`, `Unavailable.vue`, `Success.vue`, `Failed.vue`. These are intentional stubs — full UI shipped in Wave 2 plans (05-02 through 05-04).
- **Files modified:** `resources/js/pages/ClientPayment/` (4 new files)
- **Verification:** All 12 tests pass after stubs added.
- **Committed in:** `8bf69347` (Task 2 commit)

---

**Total deviations:** 2 auto-fixed (1 bug, 1 blocking)
**Impact on plan:** Both fixes necessary for tests to pass. No scope creep — Vue stubs are minimal typed shells that Wave 2 will fill in.

## Known Stubs

| Stub | File | Reason |
|------|------|--------|
| Empty template body | `resources/js/pages/ClientPayment/Pay.vue` | Wave 2 plan (05-02) implements full Stripe Elements UI |
| Empty template body | `resources/js/pages/ClientPayment/Unavailable.vue` | Wave 2 plan (05-04) implements guard page UI |
| Empty template body | `resources/js/pages/ClientPayment/Success.vue` | Wave 2 plan (05-03) implements success page UI |
| Empty template body | `resources/js/pages/ClientPayment/Failed.vue` | Wave 2 plan (05-03) implements failure page UI |

These stubs do not block the plan's goal — the plan's goal is the server-side backend and route registration, which is complete. Full UI is Wave 2 scope.

## Threat Surface Scan

No new endpoints beyond those in the plan's threat model. The three `/pay/{payment}` routes are exactly the surfaces analyzed in the STRIDE register (T-05-01 through T-05-06). All mitigations implemented:
- T-05-01 (amount tampering): `$payment->amount` from DB only — never from request
- T-05-02 (client_secret exposure): only in Inertia props, not logged, not in URL
- T-05-03 (direct success navigation): `redirect_status` guard in success()
- T-05-04 (cross-account key): `app()->make(StripeClient, ['apiKey' => $account->secret_key])` per-request

## Issues Encountered

- Route model binding mismatch: discovered only at test runtime. Standard Laravel implicit binding requires parameter name to match controller variable name. The plan's route spec used `{uuid}` (descriptive) but controller used `$payment` (semantic). Fixed by changing to `{payment}`.

## Next Phase Readiness

- Controller is complete and tested — Wave 2 plans can import and use the prop shapes
- Vue stubs are in place with correct TypeScript prop definitions — Wave 2 can add template markup
- `pay.success` and `pay.failed` named routes exist for `confirmPayment()` return_url in Wave 2

## Self-Check: PASSED

- [x] `app/Http/Controllers/ClientPaymentController.php` exists
- [x] `routes/web.php` modified with 3 public routes
- [x] `resources/js/pages/ClientPayment/Pay.vue` exists
- [x] `resources/js/pages/ClientPayment/Unavailable.vue` exists
- [x] `resources/js/pages/ClientPayment/Success.vue` exists
- [x] `resources/js/pages/ClientPayment/Failed.vue` exists
- [x] `.planning/phases/05-client-payment-page/05-01-SUMMARY.md` exists
- [x] Commit `2305f958` exists (Task 1: ClientPaymentController)
- [x] Commit `8bf69347` exists (Task 2: routes + Vue stubs)
- [x] All 12 ClientPaymentTest cases pass GREEN

---
*Phase: 05-client-payment-page*
*Completed: 2026-05-09*
