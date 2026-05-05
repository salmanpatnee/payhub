---
phase: 03-brand-stripe-account-management
plan: "04"
subsystem: payments
tags: [stripe, vue, inertia, frontend, secret-key-masking, deactivation]

# Dependency graph
requires:
  - phase: 03-02
    provides: StripeAccountController (index, create, store, edit, update, deactivate), Inertia props contract

provides:
  - stripe-accounts/Index.vue: accounts table with deactivation dialog and PATCH form
  - stripe-accounts/Create.vue: create form with Stripe API error Alert
  - stripe-accounts/Edit.vue: edit form with masked secret key field (never pre-filled)

affects: [phase-04-payment-creation]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - secret-key-never-in-inertia-props
    - deactivate-patch-not-delete
    - stripe-api-error-alert
    - blank-secret-key-keeps-existing
    - publishable-key-preview-masking

key-files:
  created:
    - resources/js/pages/admin/brands/stripe-accounts/Index.vue
    - resources/js/pages/admin/brands/stripe-accounts/Create.vue
    - resources/js/pages/admin/brands/stripe-accounts/Edit.vue
  modified: []

decisions:
  - "secret_key field always starts blank on Edit form — never pre-populated from Inertia props (security)"
  - "Deactivation uses PATCH not DELETE — stripe account record kept in DB for audit/history"
  - "publishable_key_preview shown in index (first 12 chars + bullets), full key in edit form (not sensitive)"
  - "Stripe API errors surface as form.errors.stripe_api via destructive Alert, not field-level error"

metrics:
  duration: "~25 minutes"
  completed: "2026-05-04"
  tasks_completed: 2
  tasks_total: 2
  files_created: 3
  files_modified: 0
---

# Phase 3 Plan 04: Stripe Account Vue Pages Summary

**One-liner:** Three Stripe account admin pages — accounts list with deactivation dialog, create form with API error Alert, and edit form with masked secret key field (never pre-filled).

## What Was Built

### Task 1 — stripe-accounts/Index.vue (commit 5a0b80f)

Stripe accounts list page for a brand. Shows a table of accounts with `account_name`, `publishable_key_preview` (masked), and an `is_active` badge. Active accounts show a "Deactivate" button (PowerOff icon); inactive accounts do not. Clicking deactivate opens a Dialog confirmation with "Keep active" / "Deactivate" copy. The `deactivateForm.patch()` sends PATCH to `/admin/brands/{brand}/stripe-accounts/{account}/deactivate`. Empty state shown when no accounts exist.

Key security properties:
- `secret_key` is never referenced on this page
- `publishable_key_preview` (backend-computed) is displayed, not the full key
- Deactivation button is a UX guard only — server enforces auth via `role:admin` middleware

### Task 2 — stripe-accounts/Create.vue and Edit.vue (commit 8e6fa64)

**Create.vue** — Card form with `account_name` (text), `publishable_key` (text), `secret_key` (password type, `autocomplete="new-password"`). Shows a destructive Alert at the top of the form when `form.errors.stripe_api` is set (Stripe key validation failure). Helper text: "Stored encrypted. Never displayed after saving." Submit via `form.post()`.

**Edit.vue** — Pre-populates `account_name` and `publishable_key` from Inertia props. `secret_key` always starts as `''` (empty string) — never pre-filled. Input shows `placeholder="sk_••••••••••••••••"` per UI-SPEC with helper text "Leave blank to keep the current secret key. Paste a new key to replace it." Same destructive Alert for `form.errors.stripe_api`. Submit via `form.patch()`.

## Deviations from Plan

None — plan executed exactly as written. The plan's task code blocks were followed precisely, with all UI-SPEC copy verified.

**Note on test execution:** The worktree does not have `vendor/` (git worktrees share the main project filesystem but vendor is not version-controlled). PHP test verification could not be run from the worktree. The underlying backend tests (StripeAccountManagementTest.php) were built in plan 03-02 and are passing in the main project. The Vue pages in this plan do not modify any PHP code.

## Threat Surface Scan

No new network endpoints, auth paths, or file access patterns introduced. All three Vue pages are purely frontend Inertia pages wired to the existing backend routes from plan 03-02. No new surface beyond what was already planned.

## Known Stubs

None. All three pages are fully wired to their Inertia props. The `stripe_accounts` prop is live data from `StripeAccountController@index`. The deactivate form PATCH is wired to the real endpoint.

## Self-Check

Files created:
- resources/js/pages/admin/brands/stripe-accounts/Index.vue — EXISTS
- resources/js/pages/admin/brands/stripe-accounts/Create.vue — EXISTS
- resources/js/pages/admin/brands/stripe-accounts/Edit.vue — EXISTS

Commits:
- 5a0b80f: feat(03-04): build stripe-accounts/Index.vue with deactivation dialog
- 8e6fa64: feat(03-04): build stripe-accounts/Create.vue and Edit.vue

## Self-Check: PASSED
