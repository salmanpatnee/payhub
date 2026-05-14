---
phase: 7
slug: notifications-dashboard
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-14
---

# Phase 7 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest v4 |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --compact --filter=NotificationTest\|PaymentDashboardTest` |
| **Full suite command** | `php artisan test --compact` |
| **Estimated runtime** | ~10 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --compact --filter=NotificationTest|PaymentDashboardTest`
- **After every plan wave:** Run `php artisan test --compact`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** ~15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| Wave 0 stubs (NOTIFY) | 07-00 | 0 | NOTIFY-01, NOTIFY-02 | — | N/A | feature | `php artisan test --compact --filter=NotificationTest` | ❌ W0 | ⬜ pending |
| Wave 0 stubs (DASH) | 07-00 | 0 | DASH-01, DASH-02, DASH-03, DASH-04 | — | N/A | feature | `php artisan test --compact --filter=PaymentDashboardTest` | ❌ W0 | ⬜ pending |
| SendPaymentNotification job | 07-01 | 1 | NOTIFY-02 | — | Queued, not synchronous | feature | `php artisan test --compact --filter=NotificationTest` | ✅ W0 | ⬜ pending |
| PaymentSucceeded mailable | 07-01 | 1 | NOTIFY-01 | — | Queued to all admins only | feature | `php artisan test --compact --filter=NotificationTest` | ✅ W0 | ⬜ pending |
| HandleStripeWebhookJob dispatch hook | 07-01 | 1 | NOTIFY-01, NOTIFY-02 | — | Dispatch only on succeeded + $updated>0 | feature | `php artisan test --compact --filter=NotificationTest` | ✅ W0 | ⬜ pending |
| PaymentController filter scopes | 07-02 | 2 | DASH-01, DASH-02, DASH-03 | T-07-01 | Non-admins cannot see others' payments via filter params | feature | `php artisan test --compact --filter=PaymentDashboardTest` | ✅ W0 | ⬜ pending |
| payments/Index.vue filter bar | 07-03 | 3 | DASH-02, DASH-04 | — | N/A (client-side rendering) | manual | Browser smoke test | — | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/NotificationTest.php` — stubs for NOTIFY-01, NOTIFY-02 (dispatch, mail queued, mail content, no notification for failed payments)
- [ ] `tests/Feature/PaymentDashboardTest.php` — stubs for DASH-01, DASH-02, DASH-03, DASH-04 (filter scopes, role gating, Inertia prop assertions)

*Existing `StripeWebhookTest.php` infrastructure (fakeStripeSignature, forgetCachedPermissions) is reusable without changes.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Filter bar auto-submit on change | DASH-02 | Vue watch() + router.get() — cannot be asserted in feature tests | Open /payments, change Status dropdown, verify URL updates + list filters without page reload |
| Email rendered in browser | NOTIFY-01 | Mailable HTML output | Run `php artisan queue:work`, trigger webhook, check `storage/logs/laravel.log` for email content |
| Filter inputs pre-populated on back-navigation | DASH-02 | Inertia state management | Apply filter, navigate away, press back, verify filters restored |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
