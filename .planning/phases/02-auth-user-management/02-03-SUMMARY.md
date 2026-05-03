---
phase: 02-auth-user-management
plan: "03"
subsystem: frontend-shell
tags: [vue, inertia, sidebar, rbac, typescript, navigation]
dependency_graph:
  requires:
    - 02-01 (spatie middleware aliases registered, roles in Inertia shared props)
    - 02-02 (admin routes + UserController built)
  provides:
    - User TypeScript type with roles: string[] field
    - Role-aware AppSidebar with 5-item nav (Users admin-only)
    - ComingSoon.vue placeholder page for unimplemented sections
    - /admin/brands and /payments Inertia routes pointing to ComingSoon
  affects:
    - resources/js/types/auth.ts (roles field, optional timestamps)
    - resources/js/components/AppSidebar.vue (role-aware computed nav)
    - resources/js/pages/placeholders/ComingSoon.vue (new page)
    - routes/web.php (two new placeholder routes)
tech_stack:
  added: []
  patterns:
    - computed spread pattern for role-gated nav items (no NavItem type change needed)
    - usePage() + computed isAdmin from page.props.auth.user.roles.includes('admin')
    - Route::inertia() shorthand for static Inertia page routes
    - defineOptions layout/breadcrumbs for Inertia page layout registration
key_files:
  created:
    - resources/js/pages/placeholders/ComingSoon.vue
  modified:
    - resources/js/types/auth.ts
    - resources/js/components/AppSidebar.vue
    - routes/web.php
decisions:
  - "Used computed spread pattern (...isAdmin.value ? [...] : []) — no NavItem type change needed"
  - "Used plain <a> anchor in SidebarHeader instead of Inertia Link to avoid as-child incompatibility"
  - "Skipped /settings/profile placeholder route — already exists in routes/settings.php"
  - "email_verified_at, created_at, updated_at made optional — HandleInertiaRequests no longer sends them"
metrics:
  duration: "~10 minutes"
  completed: "2026-05-03"
  tasks_completed: 2
  tasks_total: 2
  files_modified: 3
  files_created: 1
  deviations: 1
---

# Phase 2 Plan 03: TypeScript Types, Role-Aware Sidebar, ComingSoon Placeholder Summary

**One-liner:** User type extended with roles: string[], AppSidebar rebuilt as computed role-aware nav with 5 items (Users admin-only), and ComingSoon.vue created with /admin/brands and /payments placeholder routes inside auth+verified middleware.

## Tasks Completed

| Task | Name | Commit | Files |
|------|------|--------|-------|
| 1 | Update TypeScript types and rebuild AppSidebar with role-aware nav | 0d380695 | resources/js/types/auth.ts, resources/js/components/AppSidebar.vue |
| 2 | Create ComingSoon placeholder page and add placeholder routes | a8104c35 | resources/js/pages/placeholders/ComingSoon.vue, routes/web.php |

## What Was Built

**Task 1 — resources/js/types/auth.ts:**

Added `roles: string[]` after the `email` field. Made `email_verified_at`, `created_at`, and `updated_at` optional (`?`) since `HandleInertiaRequests` (Plan 02-01) now sends a narrowed user object that omits those timestamp fields. The `Auth` type and `TwoFactorConfigContent` type are unchanged.

**Task 1 — resources/js/components/AppSidebar.vue:**

Complete rewrite. Key changes from the starter kit default:

1. Added `usePage()` + `computed isAdmin` reading `page.props.auth.user?.roles?.includes('admin') ?? false`
2. Converted `mainNavItems` from a static `const` array to a `computed` that conditionally spreads the Users item when `isAdmin` is true
3. Added 4 new nav items: Brands (`/admin/brands`, Building2), Payments (`/payments`, CreditCard), Users (`/admin/users`, Users icon — admin-only), Settings (`/settings/profile`, Settings)
4. Removed `NavFooter` import and `footerNavItems` array entirely — starter kit Repository/Documentation links removed per UI-SPEC
5. Replaced Inertia `Link` in `SidebarHeader` with plain `<a>` tag to avoid the `as-child` + Inertia Link incompatibility in some shadcn-vue versions
6. SidebarFooter now contains `NavUser` only

**Task 2 — resources/js/pages/placeholders/ComingSoon.vue:**

New file at `resources/js/pages/placeholders/ComingSoon.vue`. Minimal centered placeholder with:
- `<Head title="Coming Soon" />`
- `defineOptions` layout with breadcrumbs: `[{ title: 'Coming Soon', href: '#' }]`
- Centered copy: "This section is coming in a future update."
- No card wrapper, no icon, no heading — informational only per UI-SPEC

**Task 2 — routes/web.php:**

Two placeholder Inertia routes added to the existing `auth + verified` middleware group:
- `Route::inertia('/admin/brands', 'placeholders/ComingSoon')->name('brands.index')`
- `Route::inertia('/payments', 'placeholders/ComingSoon')->name('payments.index')`

`/settings/profile` was NOT added as a placeholder — it already exists in `routes/settings.php` as `profile.edit`.

## Verification Results

- `grep -n "roles: string\[\]" resources/js/types/auth.ts`: line 5 — PASS
- `grep -n "isAdmin" resources/js/components/AppSidebar.vue`: lines 21, 29 — PASS (2 matches)
- `grep -n "Building2" resources/js/components/AppSidebar.vue`: line 3 (import) — PASS
- `grep -n "NavFooter" resources/js/components/AppSidebar.vue`: no matches — PASS
- `grep -n "footerNavItems" resources/js/components/AppSidebar.vue`: no matches — PASS
- `grep -n "admin/users" resources/js/components/AppSidebar.vue`: line 29 — PASS
- `resources/js/pages/placeholders/ComingSoon.vue` exists with "coming in a future update" — PASS
- `grep -n "defineOptions" ComingSoon.vue`: line 4 with layout/breadcrumbs — PASS
- `grep -n "brands.index" routes/web.php`: line 13 — PASS
- `grep -n "payments.index" routes/web.php`: line 14 — PASS
- `/settings/profile` NOT duplicated — already in routes/settings.php — PASS
- No file deletions in either commit — PASS

Note: `npm run build` and `php artisan route:list` verification were not runnable from the worktree (vendor/ not present in worktree; main repo routes differ). Static file verification confirms all acceptance criteria.

## Deviations from Plan

### Auto-fixed Issues

None — the plan was executed exactly as written. The plan already specified using `<a>` instead of Inertia `Link` in `SidebarHeader` as a note, so no deviation was needed.

One minor clarification: The plan listed the wave context note about the merge from `phase-2-auth-user-management` — the worktree was initialized from `master` before the wave-1 plans ran. A `git merge phase-2-auth-user-management` was performed at the start to bring in 02-01 and 02-02 changes before executing 02-03. This is standard worktree initialization behavior, not a deviation.

## Known Stubs

- `resources/js/pages/placeholders/ComingSoon.vue` — Intentional placeholder. Phase 3 will replace the Brands route, Phase 4 will replace the Payments route. The ComingSoon.vue itself is the plan's stated output.
- `/admin/brands` route → Phase 3 will replace `placeholders/ComingSoon` with the real Brands controller
- `/payments` route → Phase 4 will replace `placeholders/ComingSoon` with the real Payments controller

These stubs are tracked and intentional. They do not block the plan's goal (establishing the sidebar nav shell and providing destinations for all nav links).

## Threat Flags

All threats from the plan's threat register are addressed:

| Threat ID | Disposition | Implementation |
|-----------|-------------|----------------|
| T-02-03-01 | accept | AppSidebar v-if is UX-only; backend role:admin middleware (from 02-02) is authoritative |
| T-02-03-02 | accept | roles array is string[] (e.g. ['admin']) — no sensitive data |
| T-02-03-03 | mitigate | /admin/brands and /payments are inside auth+verified group — unauthenticated users redirect to login |

No new threat surface introduced.

## Self-Check: PASSED

- resources/js/types/auth.ts: FOUND, contains roles: string[]
- resources/js/components/AppSidebar.vue: FOUND, contains isAdmin computed, Building2, no NavFooter
- resources/js/pages/placeholders/ComingSoon.vue: FOUND, contains "coming in a future update"
- routes/web.php: FOUND, contains brands.index and payments.index routes
- Commit 0d380695: FOUND in git log
- Commit a8104c35: FOUND in git log
