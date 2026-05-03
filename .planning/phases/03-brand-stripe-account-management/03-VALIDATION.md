---
phase: 3
slug: brand-stripe-account-management
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-03
---

# Phase 3 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4.6 + pestphp/pest-plugin-laravel 4.1 |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test tests/Feature/Admin/BrandManagementTest.php tests/Feature/Admin/StripeAccountManagementTest.php` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~15 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test tests/Feature/Admin/BrandManagementTest.php tests/Feature/Admin/StripeAccountManagementTest.php`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** ~15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 3-W0-01 | Wave 0 | 0 | BRAND-01, BRAND-02, BRAND-03 | — | N/A | Feature stub | `php artisan test tests/Feature/Admin/BrandManagementTest.php` | ❌ W0 | ⬜ pending |
| 3-W0-02 | Wave 0 | 0 | BRAND-04, STRIPE-01, STRIPE-03, STRIPE-04, STRIPE-05 | T-03-01 | Test keys blocked in production | Feature stub | `php artisan test tests/Feature/Admin/StripeAccountManagementTest.php` | ❌ W0 | ⬜ pending |
| 3-01-01 | Backend | 1 | BRAND-01 | — | N/A | Feature (HTTP) | `php artisan test tests/Feature/Admin/BrandManagementTest.php` | ❌ W0 | ⬜ pending |
| 3-01-02 | Backend | 1 | BRAND-02 | — | N/A | Feature (HTTP) | `php artisan test tests/Feature/Admin/BrandManagementTest.php` | ❌ W0 | ⬜ pending |
| 3-01-03 | Backend | 1 | BRAND-03 | — | N/A | Feature (HTTP) | `php artisan test tests/Feature/Admin/BrandManagementTest.php` | ❌ W0 | ⬜ pending |
| 3-01-04 | Backend | 1 | BRAND-04 | T-03-01 | Test key blocked; app()->environment('production') check | Feature (HTTP) | `php artisan test tests/Feature/Admin/StripeAccountManagementTest.php` | ❌ W0 | ⬜ pending |
| 3-01-05 | Backend | 1 | STRIPE-01 | — | brand_id from route binding, never request body | Feature (HTTP) | `php artisan test tests/Feature/Admin/StripeAccountManagementTest.php` | ❌ W0 | ⬜ pending |
| 3-01-06 | Backend | 1 | STRIPE-02 | — | secret_key encrypted at rest | Feature (Unit) | `php artisan test tests/Feature/EncryptionRoundTripTest.php` | ✅ Exists | ⬜ pending |
| 3-01-07 | Backend | 1 | STRIPE-03 | T-03-02 | Invalid key pair rejected before save | Feature (mocked) | `php artisan test tests/Feature/Admin/StripeAccountManagementTest.php` | ❌ W0 | ⬜ pending |
| 3-01-08 | Backend | 1 | STRIPE-04 | — | Blank secret_key keeps existing; new key re-validates | Feature (HTTP) | `php artisan test tests/Feature/Admin/StripeAccountManagementTest.php` | ❌ W0 | ⬜ pending |
| 3-01-09 | Backend | 1 | STRIPE-05 | — | is_active = false without delete | Feature (HTTP) | `php artisan test tests/Feature/Admin/StripeAccountManagementTest.php` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Admin/BrandManagementTest.php` — stubs for BRAND-01, BRAND-02, BRAND-03
- [ ] `tests/Feature/Admin/StripeAccountManagementTest.php` — stubs for BRAND-04, STRIPE-01, STRIPE-03, STRIPE-04, STRIPE-05 with spatie permission setUp() pattern
- [ ] `StripeService` injectable mock OR `StripeClient` binding in test service container for STRIPE-03 test isolation

*Note: Existing infrastructure covers STRIPE-02 via `tests/Feature/EncryptionRoundTripTest.php`.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Stripe API key validation with real keys | STRIPE-03 | Live API call — cannot hit Stripe in automated tests | Use test keys from Stripe dashboard: store a StripeAccount via the UI, observe no validation error. Then use wrong secret key, observe "Stripe key validation failed" alert. |
| Logo display after storage:link | BRAND-01 | Requires `php artisan storage:link` + browser render | Run `php artisan storage:link` once, upload a logo via brand create form, confirm logo renders in brand list and preview card. |
| Color picker + live preview reactivity | BRAND-01 | Browser interaction — cannot automate without E2E | Open brand create, move color picker, confirm preview card header strip changes in real time. |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
