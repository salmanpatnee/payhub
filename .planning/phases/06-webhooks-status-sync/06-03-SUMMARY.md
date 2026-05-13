---
phase: "06-webhooks-status-sync"
plan: "03"
subsystem: "admin-ui"
tags: [tdd, webhook-secret, stripe-account, inertia, vue, form-security]
dependency_graph:
  requires: ["06-01", "06-02"]
  provides:
    - "StripeAccountController::edit() passes has_webhook_secret (bool) + webhook_endpoint_url"
    - "StripeAccountController::update() blank-means-preserve for webhook_secret"
    - "UpdateStripeAccountRequest validates webhook_secret with whsec_ prefix rule"
    - "Edit.vue: read-only endpoint URL with copy button + masked webhook_secret input"
    - "D-03: has_webhook_secret bool in Inertia props (never raw secret)"
    - "D-04: blank webhook_secret on submit preserves existing"
  affects: ["06-04"]
tech_stack:
  added: []
  patterns:
    - "has_webhook_secret (bool) pattern — raw encrypted value never serialized to Inertia response"
    - "blank-means-preserve pattern for sensitive field update (same as secret_key)"
    - "Direct assignment for encrypted non-fillable column ($stripeAccount->webhook_secret = $value)"
    - "except(['secret_key', 'webhook_secret']) on fill() — both sensitive columns excluded from mass-assignment"
    - "copiedEndpoint ref + clipboard fallback pattern (matching Show.vue)"
key_files:
  created: []
  modified:
    - app/Http/Controllers/Admin/StripeAccountController.php
    - app/Http/Requests/Admin/UpdateStripeAccountRequest.php
    - resources/js/pages/admin/stripe-accounts/Edit.vue
    - tests/Feature/StripeWebhookTest.php
decisions:
  - "has_webhook_secret (bool) only in edit() props — raw secret and encrypted ciphertext never in Inertia response (T-06-03)"
  - "webhook_secret excluded from fill() — assigned directly like secret_key (T-06-11)"
  - "whsec_ prefix validation mirrors secret_key's sk_ prefix validation pattern"
  - "TDD: 4 new tests covering D-04, D-03, T-06-10, and new-value replacement"
metrics:
  duration: "~4 minutes"
  completed: "2026-05-12"
  tasks_completed: 2
  files_modified: 4
---

# Phase 6 Plan 03: StripeAccount Webhook Secret Edit UI Summary

**One-liner:** Webhook_secret provisioning on StripeAccount edit form with has_webhook_secret bool guard (never raw secret), whsec_ prefix validation, blank-means-preserve, and clipboard copy for endpoint URL.

## What Was Built

### Task 1: StripeAccountController + UpdateStripeAccountRequest (TDD)

**RED phase** (`tests/Feature/StripeWebhookTest.php`):
- Replaced `markTestIncomplete` D-04 stub with real assertion: blank `webhook_secret` on update preserves existing encrypted value
- D-03: edit() response has `has_webhook_secret` bool + `webhook_endpoint_url`; `webhook_secret` key must be absent from Inertia props
- T-06-10: non-`whsec_` format rejected with validation error
- New: valid `whsec_` value replaces existing `webhook_secret`

**GREEN phase** (backend implementation):

`StripeAccountController::edit()`:
- Added `has_webhook_secret => ! empty($stripeAccount->webhook_secret)` (T-06-04b: accesses via Eloquent cast, result is bool — secret value never serialized)
- Added `webhook_endpoint_url => config('app.url').'/webhook/stripe/'.$stripeAccount->id`
- Raw `webhook_secret` and encrypted ciphertext never in response (T-06-03)

`StripeAccountController::update()`:
- Added `if ($request->filled('webhook_secret'))` conditional (same blank-means-preserve as `secret_key`)
- Direct assignment: `$stripeAccount->webhook_secret = $request->validated('webhook_secret')` — NOT via `fill()` (T-06-11)
- `fill()` now excludes `['secret_key', 'webhook_secret']` (previously only excluded `secret_key`)

`UpdateStripeAccountRequest::rules()`:
- Added `webhook_secret` with nullable string + closure checking `whsec_` prefix (T-06-10)
- Empty/null values pass through: blank = preserve (D-04)

### Task 2: Edit.vue

- `StripeAccountProp` type extended with `has_webhook_secret: boolean` and `webhook_endpoint_url: string`
- `useForm` extended with `webhook_secret: ''` (blank = preserve)
- `Copy` icon added to lucide-vue-next import
- `copiedEndpoint` ref and `copyEndpointUrl()` async function (with textarea fallback, matching Show.vue pattern)
- Template: read-only Webhook Endpoint URL display with copy button (D-02)
- Template: masked webhook signing secret input with `v-if="stripeAccount.has_webhook_secret"` bullet indicator (D-03)
- Both fields inserted after secret_key and before test status div

## Test Results

```
php artisan test --compact --filter="StripeAccountManagement|StripeWebhookTest"
# Result: 25 tests, 25 passed (1 incomplete — STRIPE-03, pre-existing manual verification)
```

| Test | Status | Requirement |
|------|--------|-------------|
| blank webhook_secret on update preserves existing secret | GREEN | D-04 |
| edit() returns has_webhook_secret bool — never raw secret | GREEN | D-03 |
| webhook_secret not starting with whsec_ is rejected | GREEN | T-06-10 |
| providing valid whsec_ value updates webhook_secret | GREEN | new |
| All existing StripeAccountManagementTest cases | GREEN | no regressions |
| All existing StripeWebhookTest cases (WEBHOOK-01 through WEBHOOK-06, SEC-03) | GREEN | no regressions |

## Commits

| Phase | Commit | Description |
|-------|--------|-------------|
| RED | 615e345 | test(06-03): add failing tests for webhook_secret provisioning |
| GREEN (backend) | ac8fe47 | feat(06-03): extend StripeAccountController and UpdateStripeAccountRequest |
| GREEN (frontend) | fad9671 | feat(06-03): extend Edit.vue with webhook endpoint URL and secret input |

## Deviations from Plan

None — plan executed exactly as written. All must_haves truths confirmed:
- Admin sees webhook endpoint URL on edit form
- Admin can copy endpoint URL with button
- webhook_secret field is a masked/password input with placeholder bullets when secret stored
- Blank submit preserves existing secret (D-04)
- Non-`whsec_` value returns validation error
- `has_webhook_secret` (bool) passed to frontend — raw secret never in Inertia response

## Known Stubs

None. All D-03 and D-04 behaviors are fully implemented and tested.

## Threat Surface Scan

No new network endpoints or auth paths introduced. All threat model mitigations applied:

| Threat ID | Mitigation Applied |
|-----------|-------------------|
| T-06-03 | `has_webhook_secret` (bool) only in edit() — `missing('stripeAccount.webhook_secret')` assertion GREEN |
| T-06-04b | `! empty($stripeAccount->webhook_secret)` accesses via Eloquent cast; bool result — secret never serialized |
| T-06-10 | Nullable + `whsec_` prefix closure in `UpdateStripeAccountRequest`; rejection test GREEN |
| T-06-11 | Direct assignment `$stripeAccount->webhook_secret = $value`; excluded from `fill()` |

## TDD Gate Compliance

- [x] RED gate: commit `615e345` — `test(06-03): add failing tests for webhook_secret provisioning`
- [x] GREEN gate: commit `ac8fe47` — `feat(06-03): extend StripeAccountController and UpdateStripeAccountRequest`
- [x] GREEN gate (frontend): commit `fad9671` — `feat(06-03): extend Edit.vue...`

## Self-Check: PASSED

- [x] `app/Http/Controllers/Admin/StripeAccountController.php` contains `has_webhook_secret`
- [x] `app/Http/Controllers/Admin/StripeAccountController.php` contains `webhook_endpoint_url`
- [x] `app/Http/Controllers/Admin/StripeAccountController.php` contains `filled('webhook_secret')`
- [x] `app/Http/Controllers/Admin/StripeAccountController.php` excludes `webhook_secret` from `fill()`
- [x] `app/Http/Requests/Admin/UpdateStripeAccountRequest.php` contains `webhook_secret` rule with `whsec_` check
- [x] `resources/js/pages/admin/stripe-accounts/Edit.vue` contains `has_webhook_secret` in type and template
- [x] `resources/js/pages/admin/stripe-accounts/Edit.vue` contains `webhook_endpoint_url` in type and template
- [x] `resources/js/pages/admin/stripe-accounts/Edit.vue` has 2 `type="password"` fields
- [x] `resources/js/pages/admin/stripe-accounts/Edit.vue` contains `copiedEndpoint` ref
- [x] All 25 StripeAccountManagement + StripeWebhook tests pass
- [x] Commits 615e345, ac8fe47, fad9671 exist
