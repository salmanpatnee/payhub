---
phase: 2
slug: auth-user-management
status: draft
nyquist_compliant: false
wave_0_complete: false
created: 2026-05-03
---

# Phase 2 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 4.6 with pest-plugin-laravel 4.1 |
| **Config file** | `tests/Pest.php` |
| **Quick run command** | `php artisan test --filter=Auth` |
| **Full suite command** | `php artisan test` |
| **Estimated runtime** | ~30 seconds |

---

## Sampling Rate

- **After every task commit:** Run `php artisan test --filter=Auth`
- **After every plan wave:** Run `php artisan test`
- **Before `/gsd-verify-work`:** Full suite must be green
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 2-01-01 | 01 | 1 | AUTH-01 | — | Login rejects invalid credentials | Feature (HTTP) | `php artisan test --filter=AuthenticationTest` | ✅ | ⬜ pending |
| 2-01-02 | 01 | 1 | AUTH-02 | — | Session persists with remember token | Feature (HTTP) | `php artisan test --filter=SessionPersistenceTest` | ❌ W0 | ⬜ pending |
| 2-01-03 | 01 | 1 | AUTH-03 | — | Logout destroys session, redirects to login | Feature (HTTP) | `php artisan test --filter=AuthenticationTest` | ✅ | ⬜ pending |
| 2-02-01 | 02 | 1 | AUTH-05 | EoP | Non-admin gets 403 on /admin/users | Feature (HTTP) | `php artisan test --filter=AdminAccessControlTest` | ❌ W0 | ⬜ pending |
| 2-02-02 | 02 | 1 | AUTH-05 | EoP | Unauthenticated redirects to login | Feature (HTTP) | `php artisan test --filter=AdminAccessControlTest` | ❌ W0 | ⬜ pending |
| 2-02-03 | 02 | 1 | AUTH-05 | UnAuth | GET /register returns 404 (registration disabled) | Feature (HTTP) | `php artisan test --filter=AdminAccessControlTest` | ❌ W0 | ⬜ pending |
| 2-03-01 | 03 | 2 | AUTH-04 | Tamper | Admin creates user with role via POST /admin/users | Feature (HTTP) | `php artisan test --filter=AdminUserManagementTest` | ❌ W0 | ⬜ pending |
| 2-03-02 | 03 | 2 | AUTH-04 | Tamper | Admin updates user role via PATCH /admin/users/{id} | Feature (HTTP) | `php artisan test --filter=AdminUserManagementTest` | ❌ W0 | ⬜ pending |
| 2-03-03 | 03 | 2 | AUTH-04 | DoS | Admin self-delete blocked | Feature (HTTP) | `php artisan test --filter=AdminUserManagementTest` | ❌ W0 | ⬜ pending |
| 2-03-04 | 03 | 2 | AUTH-04 | — | Admin deletes user via DELETE /admin/users/{id} | Feature (HTTP) | `php artisan test --filter=AdminUserManagementTest` | ❌ W0 | ⬜ pending |
| 2-04-01 | 04 | 2 | AUTH-06 | — | GET /pay/{uuid} returns non-redirect without session | Feature (HTTP) | `php artisan test --filter=PublicPaymentRouteTest` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Auth/AdminUserManagementTest.php` — stubs for AUTH-04 (admin CRUD: create, update role, delete, self-delete guard)
- [ ] `tests/Feature/Auth/AdminAccessControlTest.php` — stubs for AUTH-05 (403 for user role, 302 for unauthenticated, 404 for /register)
- [ ] `tests/Feature/Auth/SessionPersistenceTest.php` — stubs for AUTH-02 (remember-me session persistence)
- [ ] `tests/Feature/Auth/PublicPaymentRouteTest.php` — stubs for AUTH-06 (/pay/{uuid} no auth required)

*All stub files need `beforeEach` with `app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions()` to avoid spatie cache failures across tests.*

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Sidebar nav shows "Users" link for Admin, hides it for User role | AUTH-05 (UI gate) | UI conditional rendering not covered by HTTP feature tests | Log in as Admin → verify "Users" nav item visible. Log in as User → verify "Users" nav item absent. |
| AppSidebarLayout.vue renders correctly for authenticated users | AUTH-01 | Layout/visual correctness | Log in → confirm sidebar layout present with Brands, Payments, Settings nav links. |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
