---
phase: 5
slug: client-payment-page
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-09
---

# Phase 5 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest PHP 4.6 with pest-plugin-laravel |
| **Config file** | `tests/Pest.php` — `uses(Tests\TestCase::class)->in('Feature', 'Unit')` |
| **Quick run command** | `php artisan test --filter ClientPayment` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~15 seconds (filter), ~60 seconds (full) |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter ClientPayment`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 15 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 05-00-01 | 00 | 0 | CLIENT-01 | — | Guest reaches /pay/{uuid} without auth redirect | Feature (HTTP) | `php artisan test --filter "guest can view pay route"` | ❌ W0 | ⬜ pending |
| 05-00-02 | 00 | 0 | CLIENT-01 | — | Unknown UUID returns 404 | Feature (HTTP) | `php artisan test --filter "unknown uuid returns 404"` | ❌ W0 | ⬜ pending |
| 05-00-03 | 00 | 0 | CLIENT-02 | — | Brand props (logo_url, colors) in Inertia props | Feature (HTTP) | `php artisan test --filter "brand props passed to Pay page"` | ❌ W0 | ⬜ pending |
| 05-00-04 | 00 | 0 | CLIENT-03 CLIENT-04 | — | clientSecret and publishable_key in props; secret_key absent | Feature (HTTP) | `php artisan test --filter "client secret passed as prop"` | ❌ W0 | ⬜ pending |
| 05-00-05 | 00 | 0 | SEC-04 | T-SEC-04 | client_secret not in any route URL | Feature (HTTP) | `php artisan test --filter "client secret not in url"` | ❌ W0 | ⬜ pending |
| 05-00-06 | 00 | 0 | D-03 D-12 | — | Non-pending payment renders Unavailable page | Feature (HTTP) | `php artisan test --filter "completed payment shows unavailable"` | ❌ W0 | ⬜ pending |
| 05-00-07 | 00 | 0 | D-02 | — | stripe_payment_intent_id stored after show() | Feature (HTTP, Mocked) | `php artisan test --filter "payment intent id stored after show"` | ❌ W0 | ⬜ pending |
| 05-00-08 | 00 | 0 | CLIENT-06 | — | success page with redirect_status=succeeded returns 200 | Feature (HTTP) | `php artisan test --filter "success page renders when redirect_status succeeded"` | ❌ W0 | ⬜ pending |
| 05-00-09 | 00 | 0 | CLIENT-06 | — | success with non-succeeded redirect_status redirects to failed | Feature (HTTP) | `php artisan test --filter "success redirects when redirect_status not succeeded"` | ❌ W0 | ⬜ pending |
| 05-00-10 | 00 | 0 | CLIENT-07 | — | failed page renders with brand props | Feature (HTTP) | `php artisan test --filter "failed page renders"` | ❌ W0 | ⬜ pending |

---

## Wave 0 Requirements

- [ ] `tests/Feature/ClientPaymentTest.php` — stubs for CLIENT-01 through CLIENT-08, SEC-04, D-02, D-03
- [ ] Mockery-based `StripeClient` stub pattern for PaymentIntent creation isolation

*Existing infrastructure covers all test framework setup — no new packages needed.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| 3DS authentication challenge presented and completed | CLIENT-05 | Stripe 3DS iframe is browser-rendered; cannot be HTTP-tested | Use Stripe test card `4000002500003155` (requires 3DS); complete challenge; verify success redirect |
| Mobile layout renders correctly (≥320px) | CLIENT-08 | Visual layout; Tailwind classes not verifiable via HTTP | Open /pay/{uuid} in browser, DevTools → 375px (iPhone SE); verify card is full-width, button is thumb-tappable |
| Brand colors applied to Stripe Elements inputs | CLIENT-02 | CSS variable read by Stripe's iframe; not in server response | Open payment page with a brand with a distinctive color; verify Elements input focus ring matches brand color |
| Elements loading skeleton appears before onReady | UI-SPEC.md | JavaScript lifecycle; not in HTTP response | Open payment page with throttled network; verify spinner shows before Elements mounts |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 15s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
