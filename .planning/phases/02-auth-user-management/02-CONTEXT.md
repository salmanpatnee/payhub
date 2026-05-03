# Phase 2: Auth + User Management - Context

**Gathered:** 2026-05-03
**Status:** Ready for planning

<domain>
## Phase Boundary

Authenticated team members can log in and out via Laravel Fortify (email + password), session persistence works, Admin-only routes and UI are inaccessible to User-role accounts, Admin can create and manage team accounts via a `/admin/users` UI, and there is no path for public self-registration.

No Stripe integration. No payment creation. No branded pages. Auth infrastructure only.

</domain>

<decisions>
## Implementation Decisions

### Registration Disabling
- **D-01:** Remove `Features::registration()` from `config/fortify.php` features array. Public registration is permanently disabled for v1. The `Register.vue` page can be deleted or left as a dead route returning 404 — planner decides.

### User Creation by Admin
- **D-02:** Admin creates new team accounts via a dedicated `/admin/users` UI form. No Tinker/Artisan-only approach — a real admin page is required.
- **D-03:** The `/admin/users` section supports **full CRUD**: list all users, create new user (name, email, password, role), edit existing user (including role change), delete/deactivate user.
- **D-04:** When creating a new user, Admin sets the password directly in the form. No auto-generated passwords, no email invite in Phase 2 (invite flow deferred to v2).
- **D-05:** `/admin/users` lives under the `/admin/` prefix — establishing the pattern for all future admin pages (Brands, Stripe accounts in Phase 3, etc.).

### Admin Enforcement Pattern
- **D-06:** Both layers of enforcement required:
  1. **Route middleware**: Spatie `role:admin` middleware on all `/admin/*` routes.
  2. **UI gates**: Shared Inertia data passes `auth.user` with roles; Vue components use `v-if` checks on role to show/hide admin-only nav items and controls.
- **D-07:** `/pay/{uuid}` route (Phase 5 client page) must be reachable without any auth session — ensure it is NOT inside the `auth` middleware group. Stub or note in routes for Phase 5.

### Dashboard Shell
- **D-08:** Build a **real nav shell** after login, not a minimal placeholder. The sidebar layout (`AppSidebarLayout.vue`) is the chosen variant.
- **D-09:** Sidebar nav links: Brands, Payments, Users (Admin-only), Settings. In Phase 2, links that have no backing page yet show a "coming soon" placeholder Inertia page.
- **D-10:** The nav establishes the structural pattern all downstream phases build on — Phase 3 wires Brands, Phase 4 wires Payments, Phase 7 wires the dashboard stats.

### Two-Factor Authentication
- **D-11:** 2FA UI **deferred entirely** from Phase 2. `TwoFactorAuthenticatable` stays on the User model (required for Fortify compatibility) but no 2FA settings page, QR code flow, or recovery codes UI is built. Users authenticate with email + password only in Phase 2.

### Claude's Discretion
- Whether `Register.vue` is deleted or returns 403/404
- Exact shadcn-vue components used in the `/admin/users` form and table
- Pagination vs. full list on the users table (team is small — full list acceptable)
- Route naming conventions under `/admin/`
- Fortify's `home` path (already set to `/dashboard` in config — keep as-is)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project Definition
- `.planning/PROJECT.md` — Core constraints, tech stack, out-of-scope list
- `.planning/REQUIREMENTS.md` — AUTH-01 through AUTH-06 (all Phase 2 requirements)
- `.planning/ROADMAP.md` — Phase 2 goal, success criteria (5 items), depends-on Phase 1

### Prior Phase Context
- `.planning/phases/01-foundation/01-CONTEXT.md` — D-01 through D-12: scaffold decisions, shadcn-vue New York style, five seeded components (Button, Input, Label, Card, Badge), schema decisions

### Research
- `.planning/research/SUMMARY.md` — Stack recommendations, pitfalls including Fortify + Inertia wiring notes
- `.planning/research/ARCHITECTURE.md` — Two route surfaces (authenticated admin, unauthenticated client), StripeService pattern

### Stack Configuration (already installed)
- `config/fortify.php` — Current Fortify feature flags (registration currently ON — Phase 2 turns it OFF)
- `app/Providers/FortifyServiceProvider.php` — Fortify view bindings already wired to Inertia pages
- `app/Models/User.php` — HasRoles + TwoFactorAuthenticatable already in place

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Providers/FortifyServiceProvider.php` — All Fortify view routes already bound to Inertia pages (Login, ForgotPassword, ResetPassword, TwoFactorChallenge, VerifyEmail, ConfirmPassword). Phase 2 should NOT re-wire these — modify only as needed.
- `resources/js/layouts/app/AppSidebarLayout.vue` — Chosen layout for authenticated shell
- `resources/js/layouts/AuthLayout.vue` — Already wraps auth pages (Login, ForgotPassword, etc.)
- `resources/js/pages/auth/Login.vue` — Already conditionally shows "Sign up" link via `canRegister` prop. When registration is disabled, this prop becomes `false` and the link disappears automatically — no template change needed.
- shadcn-vue: Button, Input, Label, Card, Badge available in `resources/js/components/ui/`

### Established Patterns
- Vue 3 Composition API + `<script setup lang="ts">` — all new components must follow this
- shadcn-vue **New York** style (D-04 from Phase 1) — all new UI components must use this style
- Spatie `HasRoles` already on User model — use `$user->hasRole('admin')` and `$user->assignRole()` directly

### Integration Points
- `routes/web.php` — Currently has `auth + verified` group for `/dashboard`. Phase 2 adds `/admin/*` group with `auth + role:admin` middleware and `/admin/users` resource routes.
- `app/Actions/Fortify/CreateNewUser.php` — Fortify's user creation action. Admin-initiated user creation should use a separate controller/action, not re-use this Fortify action (which is for registration flow).

</code_context>

<specifics>
## Specific Ideas

- The sidebar nav's "Users" link must be conditionally rendered for Admin role only — this is the first example of the UI gate pattern (D-06) in the app.
- Phase 1's seeder already created one Admin user — the admin user management UI will immediately show this user in the list, which confirms the feature works end-to-end.
- The `/admin/` prefix establishes the convention for Phase 3 (Brand CRUD at `/admin/brands`) and future admin pages — planner should be aware of this dependency.

</specifics>

<deferred>
## Deferred Ideas

- **Invite-only registration with signed URLs** — v2 feature. Admin creates accounts manually for v1. (Noted in REQUIREMENTS.md v2 section)
- **2FA settings UI** — Infrastructure installed, UI deferred. No QR code flow, no recovery codes page in Phase 2.
- **User deactivation (soft-delete vs. active flag)** — Full CRUD is in scope, but the exact deactivation mechanism (soft delete vs. `is_active` flag) is Claude's discretion.
- **Admin-assigned password reset** — Admin can change a user's password via the edit form. Forced-reset-on-next-login is out of scope for v1.

</deferred>

---

*Phase: 02-auth-user-management*
*Context gathered: 2026-05-03*
