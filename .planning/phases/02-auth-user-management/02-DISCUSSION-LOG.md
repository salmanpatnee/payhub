# Phase 2: Auth + User Management - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-03
**Phase:** 02-auth-user-management
**Areas discussed:** User creation flow, Dashboard shell, 2FA scope

---

## Gray Area Selection

| Option | Selected |
|--------|----------|
| User creation flow | ✓ |
| Admin enforcement pattern | |
| Dashboard shell | ✓ |
| 2FA scope | ✓ |

**Note:** Admin enforcement pattern not selected — carried forward as Claude's Discretion (both route middleware + UI v-if gates).

---

## User Creation Flow

| Option | Description | Selected |
|--------|-------------|----------|
| Admin UI form | /admin/users page with create/list/manage | ✓ |
| Artisan / Tinker only | No UI, CLI only | |
| Seeder only | Lock users to seeded accounts | |

**User's choice:** Admin UI form

---

### User management scope

| Option | Description | Selected |
|--------|-------------|----------|
| Create + list + role assign | Create, list, change role | |
| Create + list only | No role change after creation | |
| Full CRUD | Create, edit, delete/deactivate | ✓ |

**User's choice:** Full CRUD

---

### User management location

| Option | Description | Selected |
|--------|-------------|----------|
| /admin/users | Dedicated admin section | ✓ |
| /settings/users | Inside settings area | |
| You decide | Claude picks route structure | |

**User's choice:** /admin/users

---

### Initial password handling

| Option | Description | Selected |
|--------|-------------|----------|
| Admin sets password directly | Admin types password in form | ✓ |
| Auto-generated, shown once | System generates random password | |
| Defer to planning | Leave for planner | |

**User's choice:** Admin sets password directly

---

## Dashboard Shell

| Option | Description | Selected |
|--------|-------------|----------|
| Real nav shell, placeholder content | Sidebar with all nav links, placeholder pages | ✓ |
| Minimal — logged in page only | No nav structure | |

**User's choice:** Real nav shell with sidebar layout

---

### Layout variant

| Option | Description | Selected |
|--------|-------------|----------|
| Sidebar | AppSidebarLayout.vue | ✓ |
| Top header nav | AppHeaderLayout.vue | |
| You decide | Claude picks | |

**User's choice:** Sidebar (AppSidebarLayout.vue)

---

## 2FA Scope

| Option | Description | Selected |
|--------|-------------|----------|
| Defer 2FA UI entirely | No settings page, email+password only | ✓ |
| Include 2FA settings page | Build enable/disable/QR/recovery flow | |
| Admin-only 2FA enforcement | Require 2FA for Admins | |

**User's choice:** Defer 2FA UI entirely

---

## Claude's Discretion

- Admin enforcement pattern: both route middleware (Spatie `role:admin`) AND UI gates (v-if on shared auth.user roles) — not discussed, decided by default
- Whether Register.vue is deleted or returns 403/404
- Exact shadcn-vue components in admin forms/tables
- Pagination vs. full list on users table
- User deactivation mechanism (soft-delete vs. is_active flag)

## Deferred Ideas

- Invite-only registration with signed URLs — v2
- 2FA settings UI — deferred, infrastructure in place
