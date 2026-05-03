---
phase: 02-auth-user-management
plan: "04"
subsystem: frontend-ui
tags: [vue, inertia, admin, user-management, shadcn-vue, crud]
dependency_graph:
  requires:
    - 02-02  # AdminUserController, StoreUserRequest, UpdateUserRequest
    - 02-03  # AppSidebar role-aware nav, auth.ts roles type
  provides:
    - admin/users/Index.vue — user list table with delete dialog
    - admin/users/Create.vue — create user form
    - admin/users/Edit.vue — edit user form with self-delete protection
  affects:
    - resources/js/pages/auth/Register.vue
    - resources/js/pages/auth/VerifyEmail.vue
    - resources/js/pages/settings/Profile.vue
    - resources/js/pages/auth/Login.vue
    - resources/js/pages/Welcome.vue
tech_stack:
  added: []
  patterns:
    - useForm from @inertiajs/vue3 for all form submissions
    - defineOptions layout.breadcrumbs for AppSidebarLayout breadcrumb injection
    - isSelf computed (usePage().props.auth.user.id comparison) for self-delete guard
    - Dialog v-model:open for confirmation modals
    - Badge :variant conditional (secondary for admin, outline for user)
key_files:
  created:
    - resources/js/pages/admin/users/Index.vue
    - resources/js/pages/admin/users/Create.vue
    - resources/js/pages/admin/users/Edit.vue
  modified:
    - resources/js/pages/auth/Register.vue
    - resources/js/pages/auth/VerifyEmail.vue
    - resources/js/pages/settings/Profile.vue
    - resources/js/pages/auth/Login.vue
    - resources/js/pages/Welcome.vue
decisions:
  - defineOptions breadcrumb in Edit.vue uses static 'Edit user' (not props.user.name) — Vue hoists defineOptions outside setup(), so runtime props are inaccessible there
  - Register.vue and VerifyEmail.vue migrated from Inertia Form+wayfinder to useForm — wayfinder does not generate register/verification routes when Fortify features are disabled
metrics:
  duration: ~45 minutes
  completed: 2026-05-03T10:28:48Z
  tasks_completed: 2
  files_changed: 8
---

# Phase 02 Plan 04: Admin User Management Vue Pages Summary

Three Inertia page components for Admin CRUD user management — Index (list + delete dialog), Create (form), Edit (form with self-delete guard) — all building cleanly with npm run build and backed by 51 passing tests.

## Tasks Completed

| Task | Name | Commit | Key Files |
|------|------|--------|-----------|
| 1 | Build admin/users/Index.vue | e6e57d0a | resources/js/pages/admin/users/Index.vue |
| 2 | Build admin/users/Create.vue and Edit.vue | 0a49011a | resources/js/pages/admin/users/Create.vue, Edit.vue |
| Fix | Remove broken wayfinder imports | d01db731 | Register.vue, VerifyEmail.vue, Profile.vue, Login.vue, Welcome.vue |

## Verification Results

- `npm run build` — exit 0, 2989 modules transformed
- `php artisan test --filter=Auth` — 28 passed, 10 skipped (todo stubs), 0 failed
- `php artisan test` — 51 passed, 10 skipped, 0 failed

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] defineOptions() cannot reference props in Edit.vue**
- **Found during:** Task 2
- **Issue:** `defineOptions({ layout: { breadcrumbs: [{ title: props.user.name }] } })` causes Vue compiler error: "cannot reference locally declared variables because it will be hoisted outside of the setup() function"
- **Fix:** Replaced `props.user.name` with static string `'Edit user'` in the breadcrumb title
- **Files modified:** resources/js/pages/admin/users/Edit.vue
- **Commit:** 0a49011a

**2. [Rule 1 - Bug] Broken wayfinder route imports for disabled Fortify features**
- **Found during:** npm run build verification
- **Issue:** Register.vue imported `{ store } from '@/routes/register'`, VerifyEmail.vue imported `{ send } from '@/routes/verification'`, Profile.vue imported `{ send } from '@/routes/verification'`, Login.vue and Welcome.vue imported `{ register } from '@/routes'` — wayfinder does not generate these route modules when `Features::registration()` and `Features::emailVerification()` are disabled in config/fortify.php (disabled in plan 02-01)
- **Fix:** Register.vue converted to standard `useForm` with hardcoded POST `/register`; VerifyEmail.vue converted to `useForm` with hardcoded POST `/email/verification-notification`; Profile.vue had `send` import removed and href inlined; Login.vue and Welcome.vue had `register` import removed and href hardcoded to `/register`
- **Files modified:** Register.vue, VerifyEmail.vue, Profile.vue, Login.vue, Welcome.vue
- **Commit:** d01db731

## Decisions Made

1. **Static breadcrumb in Edit.vue** — Vue's `defineOptions()` macro is hoisted at compile time, so `props` from `defineProps()` is not accessible. Using `'Edit user'` static string is the correct pattern. The page `<Head :title>` still shows the dynamic user name.

2. **useForm migration for disabled auth pages** — Register.vue and VerifyEmail.vue still exist in the codebase (Fortify keeps the views) but their wayfinder route imports break the build. Converting to `useForm` with hardcoded routes is safe: these pages are never reached in practice (registration is invite-only) but must compile.

## Known Stubs

None — all three admin user pages are fully wired to their controller props (users array, user object, roles array) from AdminUserController.

## Threat Surface Scan

No new network endpoints, auth paths, file access patterns, or schema changes introduced by this plan. All pages are purely Inertia frontend components backed by existing AdminUserController routes (added in 02-02). T-02-04-01 through T-02-04-04 from the plan threat model are all mitigated as specified.

## Self-Check: PASSED

- FOUND: resources/js/pages/admin/users/Index.vue
- FOUND: resources/js/pages/admin/users/Create.vue
- FOUND: resources/js/pages/admin/users/Edit.vue
- FOUND: commit e6e57d0a (feat: Index.vue)
- FOUND: commit 0a49011a (feat: Create.vue + Edit.vue)
- FOUND: commit d01db731 (fix: wayfinder route imports)
