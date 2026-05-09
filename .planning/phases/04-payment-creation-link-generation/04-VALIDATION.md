---
phase: 4
slug: payment-creation-link-generation
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-05
---

# Phase 4 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4.6 (PHPUnit class-style — existing project convention) |
| **Config file** | `tests/Pest.php` |
| **Quick run command** | `php artisan test --filter PaymentCreationTest` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~30 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter PaymentCreationTest`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** ~30 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| Migration + factory update | W0 | 0 | PAY-01 | — | N/A | Feature | `php artisan test` | ❌ W0 | ⬜ pending |
| Test stub creation | W0 | 0 | PAY-01–PAY-07, SEC-02 | — | Amount from FormRequest only | Feature | `php artisan test --filter PaymentCreationTest` | ❌ W0 | ⬜ pending |
| PAY-01 admin create payment | W1 | 1 | PAY-01 | T-4-01 | Amount stored as cents, not raw decimal | Feature | `php artisan test --filter PaymentCreationTest` | ❌ W0 | ⬜ pending |
| PAY-01 user (non-admin) create | W1 | 1 | PAY-01 | — | N/A | Feature | `php artisan test --filter PaymentCreationTest` | ❌ W0 | ⬜ pending |
| PAY-02 active stripe account only | W1 | 1 | PAY-02 | T-4-02 | Inactive Stripe account rejected (422) | Feature | `php artisan test --filter PaymentCreationTest` | ❌ W0 | ⬜ pending |
| PAY-03 client name + email stored | W1 | 1 | PAY-03 | — | N/A | Feature | `php artisan test --filter PaymentCreationTest` | ❌ W0 | ⬜ pending |
| PAY-04 UUID generated + show route | W1 | 1 | PAY-04 | — | N/A | Feature | `php artisan test --filter PaymentCreationTest` | ❌ W0 | ⬜ pending |
| PAY-05 amount integrity | W1 | 1 | PAY-05 | T-4-03 | DB amount = round(input × 100); never re-read from request | Feature | `php artisan test --filter PaymentCreationTest` | ❌ W0 | ⬜ pending |
| PAY-06 currency enum validation | W1 | 1 | PAY-06 | T-4-04 | usd/gbp accepted; any other value rejected (422) | Feature | `php artisan test --filter PaymentCreationTest` | ❌ W0 | ⬜ pending |
| PAY-07 expires_at null | W1 | 1 | PAY-07 | — | N/A | Feature | `php artisan test --filter PaymentCreationTest` | ❌ W0 | ⬜ pending |
| PAY-08 fee breakdown panel | W2 | 2 | PAY-08 | — | Client-side only — no server state | Manual | — | N/A | ⬜ pending |
| SEC-02 amount from server record | W1 | 1 | SEC-02 | T-4-05 | controller reads $request->validated() (int cents), never $request->amount raw | Feature | `php artisan test --filter PaymentCreationTest` | ❌ W0 | ⬜ pending |
| Role-scoped index | W1 | 1 | PAY-01 | — | N/A | Feature | `php artisan test --filter PaymentCreationTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/PaymentCreationTest.php` — stubs for PAY-01 through PAY-07, SEC-02
- [ ] Update `database/factories/PaymentFactory.php` — remove `description`, add `client_name`, `service`, `package`, `note`
- [ ] Update `app/Models/Payment.php` fillable — add `client_name`, `service`, `package`, `note`; remove `description`
- [ ] Verify `tests/Feature/PaymentAmountIntegrityTest.php` still passes after factory update (existing test — must not break)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Live fee breakdown updates on amount/currency change | PAY-08 | Vue `computed` — no server round-trip, no backend state to assert | Load `/payments/create`. Enter amount `25.00`, select USD. Confirm three rows: "Charge amount $25.00", "Stripe fee $1.03", "You receive $23.98". Change to GBP, confirm fee recalculates (1.5% + £0.20). Breakdown hidden when amount is empty. |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
