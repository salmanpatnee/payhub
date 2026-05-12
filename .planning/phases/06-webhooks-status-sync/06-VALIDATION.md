---
phase: 6
slug: webhooks-status-sync
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-12
---

# Phase 6 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4 (pestphp/pest ^4) |
| **Config file** | `phpunit.xml` |
| **Quick run command** | `php artisan test --compact --filter=StripeWebhook` |
| **Full suite command** | `php artisan test --compact` |
| **Estimated runtime** | ~10 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --compact --filter=StripeWebhook`
- **After every plan wave:** Run `php artisan test --compact`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 10 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 06-00-01 | 00 | 0 | WEBHOOK-01..06, SEC-03 | — | Stub tests RED | feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ W0 | ⬜ pending |
| 06-01-01 | 01 | 1 | SEC-03 | T-CSRF | Webhook route excluded from CSRF | feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ W0 | ⬜ pending |
| 06-01-02 | 01 | 1 | WEBHOOK-01 | — | Route resolves StripeAccount by ID | feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ W0 | ⬜ pending |
| 06-01-03 | 01 | 1 | WEBHOOK-02 | T-SIG | Tampered sig → 400 | feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ W0 | ⬜ pending |
| 06-01-04 | 01 | 1 | WEBHOOK-02 | T-SIG | Missing sig → 400 | feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ W0 | ⬜ pending |
| 06-01-05 | 01 | 1 | WEBHOOK-06 | — | Controller returns 200 immediately (Queue::fake) | feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ W0 | ⬜ pending |
| 06-02-01 | 02 | 1 | WEBHOOK-03 | — | payment_intent.succeeded → status=completed + paid_at | feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ W0 | ⬜ pending |
| 06-02-02 | 02 | 1 | WEBHOOK-04 | — | payment_intent.payment_failed → status=failed | feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ W0 | ⬜ pending |
| 06-02-03 | 02 | 1 | WEBHOOK-05 | — | Idempotency: already terminal payment not re-processed | feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ W0 | ⬜ pending |
| 06-03-01 | 03 | 2 | D-04 | — | Blank webhook_secret on submit preserves existing | feature | `php artisan test --compact --filter=StripeAccountTest` | ❌ W0 | ⬜ pending |
| 06-03-02 | 03 | 2 | D-03 | T-LEAK | has_webhook_secret (bool) passed — never raw secret | feature | `php artisan test --compact --filter=StripeAccountTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/StripeWebhookTest.php` — stubs for WEBHOOK-01 through WEBHOOK-06, SEC-03 (all RED)
- [ ] Helper function `fakeStripeSignature(string $payload, string $secret): string` in test file — builds valid `t=...,v1=...` Stripe-Signature header for integration tests

*Existing test infrastructure covers the runner setup — only the new test file and helper are needed.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Stripe CLI end-to-end (real webhook delivery) | WEBHOOK-01..06 | Requires running Stripe CLI + queue:work | Run `stripe listen --forward-to localhost:8000/webhook/stripe/{id}`, trigger test events, verify DB |
| Webhook endpoint URL copy button in Edit form | D-02 | UI clipboard interaction | Open `/admin/stripe-accounts/{id}/edit`, verify URL displayed + copy button works |
| webhook_secret masked input shows ●●●●●●●● when secret stored | D-03 | UI visual state | Edit an account with webhook_secret set; field shows placeholder, not actual secret |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 10s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
