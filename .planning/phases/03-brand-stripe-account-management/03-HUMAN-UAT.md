---
status: partial
phase: 03-brand-stripe-account-management
source: [03-VERIFICATION.md]
started: 2026-05-04T00:00:00Z
updated: 2026-05-04T00:00:00Z
---

## Current Test

[awaiting human testing]

## Tests

### 1. Stripe API Key Validation (STRIPE-03)
expected: Submit a Stripe account with an intentionally wrong secret_key — destructive Alert appears, no record saved. Bypass is disabled only in testing env; must verify in local or staging with APP_ENV != testing.
result: [pending]

### 2. Logo Upload and Display
expected: After `php artisan storage:link`, upload a logo when creating/editing a brand — uploaded image appears in the brand list and live preview card in the browser.
result: [pending]

### 3. Color Picker Live Preview Reactivity
expected: On the Create/Edit brand page, changing the color picker or typing a hex value in the text input updates the preview card background in real time with no page reload.
result: [pending]

## Summary

total: 3
passed: 0
issues: 0
pending: 3
skipped: 0
blocked: 0

## Gaps
