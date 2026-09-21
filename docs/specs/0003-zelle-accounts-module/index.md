# 0003. Zelle Accounts module beside Bank Accounts

**Date**: 2026-09-21
**Status**: In Progress

## Summary

You are adding a new "Zelle Accounts" tab that works like Bank Accounts. Admin and account role users store Zelle details (holder name, email, optional mobile number, currency), switch accounts on or off, and assign agents to them. An assigned agent logs in and sees only their active accounts as read only cards with copy buttons. There is no activity log. Everything reuses patterns you already run, so no new packages are needed.

## Requirements

**User stories**:
- As an admin or account user, I want to add, edit, delete, activate and deactivate Zelle accounts and assign agents, so that agents always have the right details.
- As an agent, I want to see and copy the Zelle details assigned to me, so that I can share them with a client quickly.
- As an admin or account user, I want to search accounts by email or mobile number, so that I can find one fast.

**Acceptance criteria**:
- **AC-1**: Admin and account role users can create a Zelle account with holder name (required), email (required, valid), mobile number (optional), currency (USD or GBP), and active flag.
- **AC-2**: Email is saved in lowercase and must be unique among non deleted Zelle accounts. A deleted account frees its email for reuse.
- **AC-3**: Mobile number, when given, allows only digits, spaces, `+`, `-`, `(`, `)`, max 20 characters.
- **AC-4**: Admin and account role users can edit and soft delete an account. Agents get 403 on create, edit, update, delete, activate and deactivate.
- **AC-5**: Admin and account role users can activate or deactivate an account. Assignments are kept when deactivated.
- **AC-6**: Admin and account role users can assign users to an account. Only users with the agent role are accepted, and the server rejects any other id. An update request with no `user_ids` key leaves assignments unchanged.
- **AC-7**: The admin list shows all non deleted accounts, paginated 15 per page, with one search box, a currency filter and a status filter (all, active, inactive). Filters survive pagination.
- **AC-8**: Search matches part of the email or part of the mobile number. Spaces, dashes and brackets are ignored when matching mobile numbers, and `%` and `_` in the search text are treated as normal characters.
- **AC-9**: An agent opening the Zelle tab sees only accounts that are active, not deleted, and assigned to them. Cards are read only and show holder name, email, mobile number (when present) and currency.
- **AC-10**: Each value on an agent card has a copy button, and a "Copy all" button copies a formatted block. A short "Copied" confirmation shows after copying.
- **AC-11**: The "Zelle Accounts" sidebar item shows for all three roles. Users with no role or no assignments see an empty state.
- **AC-12**: No activity log is written for Zelle accounts.

## Decision

**Chosen option**: Option 1: New `zelle_accounts` table and module.

Build a separate Zelle Accounts module modeled on Bank Accounts, with the review fixes applied from the start (paginated list, transaction on writes, agent only assignment, safe `user_ids` handling).

**Implementation skills**: `laravel-best-practices` (`.claude/skills/laravel-best-practices/`) · `inertia-vue-development` (`.claude/skills/inertia-vue-development/`) · `pest-testing` (`.claude/skills/pest-testing/`) · `laravel-permission-development` (`.claude/skills/laravel-permission-development/`) · `tailwindcss-development` (`.claude/skills/tailwindcss-development/`)

## Feature design

**Data model sketch**:

`zelle_accounts`

| Field | Type | Rules |
|---|---|---|
| `id` | bigint PK | |
| `account_name` | string 255 | required, the holder name |
| `email` | string 255 | required, valid email, stored lowercase |
| `mobile_number` | string 20 | nullable |
| `currency` | string | required, `usd` or `gbp` via `App\Enums\SupportedCurrency` |
| `is_active` | boolean | default true |
| `created_at`, `updated_at`, `deleted_at` | timestamps | soft delete |

`user_zelle_account` (pivot, many to many with `users`): `id`, `zelle_account_id` FK cascade on delete, `user_id` FK cascade on delete, timestamps, unique on the pair.

No database unique index on `email`, because MySQL cannot ignore soft deleted rows in a plain unique index. Uniqueness is enforced in the form requests.

**State transitions**: `is_active` true to false (deactivate) and false to true (activate). Delete is a soft delete from either state.

**API surface** (all routes inside the existing `auth` + `verified` group):

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `/zelle-accounts` (`zelle-accounts.index`) | GET | `search`, `currency`, `status`, `page` (all opt) | Admin: paginated rows with assigned user count. Agent: their active accounts | any role | none |
| `/zelle-accounts/create` | GET | none | form props with agent list | admin or account | 403 |
| `/zelle-accounts` | POST | `account_name`, `email`, `currency` (req); `mobile_number`, `is_active`, `user_ids` (opt) | redirect to index with flash | admin or account | 403, 422 |
| `/zelle-accounts/{zelle_account}/edit` | GET | none | account plus `user_ids`, agent list | admin or account | 403, 404 |
| `/zelle-accounts/{zelle_account}` | PUT | same as POST | redirect to index with flash | admin or account | 403, 404, 422 |
| `/zelle-accounts/{zelle_account}` | DELETE | none | redirect to index | admin or account | 403, 404 |
| `/zelle-accounts/{zelle_account}/activate` | PATCH | none | redirect back | admin or account | 403, 404 |
| `/zelle-accounts/{zelle_account}/deactivate` | PATCH | none | redirect back | admin or account | 403, 404 |

There is no `show` route (same as Bank Accounts). A 404 also covers soft deleted accounts.

**Value sourcing**:

| Action | Value produced / displayed | Source |
|---|---|---|
| Admin list | rows, `assigned_users_count` | `zelle_accounts` columns, `withCount('assignedUsers')` |
| Admin list | current page, filters echoed back | query string inputs `search`, `currency`, `status`, `page` |
| Agent list | own accounts | `$request->user()->zelleAccounts()` filtered by `is_active = true` (soft deleted excluded automatically) |
| Create / edit form | agent choices | `User::role('agent')`, plus already assigned ids on edit |
| Store / update | lowercase email | the request `email` input, lowercased in `prepareForValidation` |
| Store / update | assigned users | `user_ids` input, validated as agent role ids |
| Copy all block | text lines | the card's own values: name, email, mobile (line skipped if empty), currency label |
| Flash messages | success text | fixed strings in the controller |

**Key invariants**:
- `email` is lowercase and unique among rows where `deleted_at` is null (checked with `Rule::unique(...)->whereNull('deleted_at')`, ignoring the current row on update).
- `currency` is only `usd` or `gbp`.
- Only agent role users end up in `user_zelle_account`.
- Agents never see inactive or deleted accounts, and never see accounts not assigned to them.
- Store, update, and assignment sync run inside one database transaction.
- `is_active` and assignments are independent, so deactivating never touches the pivot.

**Security model**:
- `ZelleAccountPolicy`: `create`, `update`, `delete` allowed for `admin` or `account`. Registered like `BankAccountPolicy`.
- Form request `authorize()` is dropped. The controller uses `Gate::authorize` only, so there is one source of truth for access.
- Index has no gate but returns rows by role: admin or account see all, agent sees only their own active accounts, anyone else gets an empty list.
- Frontend hiding is UX only. The server is the real gate.
- Email and mobile are stored as plain text (search needs it). This is contact info that agents already share with clients. No compliance scope beyond your existing PII handling.

**Configuration required**: none.

**Critical test scenarios**:
- Happy path: admin creates an account with a mobile number, edits it, deactivates, reactivates and deletes it, verifies **AC-1**, **AC-4**, **AC-5**.
- Happy path: account role user creates and edits an account, verifies **AC-1**, **AC-4**.
- Failure case: duplicate email (different casing) is rejected, and is allowed again after the first account is soft deleted, verifies **AC-2**.
- Failure case: invalid mobile characters and a currency of `pkr` are rejected, verifies **AC-1**, **AC-3**.
- Failure case: assigning a non agent user id returns 422, verifies **AC-6**.
- Edge: update with no `user_ids` key keeps assignments, update with `user_ids: []` clears them, verifies **AC-6**.
- Search: partial email, partial mobile with different punctuation, and a literal `%` all behave correctly, verifies **AC-8**.
- List: filters by currency and status, 16 rows paginate to 2 pages with filters kept, verifies **AC-7**.
- Auth/permission: agent gets 403 on create, store, edit, update, delete, activate, deactivate, verifies **AC-4**.
- Agent view: agent sees only active, assigned, non deleted accounts, deactivating hides one and reactivating brings it back, verifies **AC-5**, **AC-9**.
- No role user sees an empty state without an error, verifies **AC-11**.
- No `activity_logs` row is created by any Zelle action, verifies **AC-12**.
- Frontend (manual or browser check): copy buttons and "Copy all" output, verifies **AC-10**.

## Build plan

Ordered as thin end to end slices (Tracer Bullet, assumed).

1. Migrations for `zelle_accounts` and `user_zelle_account`, `ZelleAccount` model (soft deletes, `assignedUsers()`, enum cast, email lowercase mutator), `User::zelleAccounts()`, factory with `inactive` state, and `ZelleAccountPolicy`, satisfies **AC-1**, **AC-2**, **AC-6**, **AC-12**
2. Thin thread: routes, `ZelleAccountController@index` and `@create`/`@store`, `StoreZelleAccountRequest`, Vue `zelle-accounts/Index.vue` and `Create.vue`, sidebar item, so an admin can create one and see it listed, satisfies **AC-1**, **AC-2**, **AC-3**, **AC-11**
3. Edit, update, soft delete, activate and deactivate, with `UpdateZelleAccountRequest` (reusing the store rules and ignoring the current row for unique email), `Edit.vue`, and the row actions, satisfies **AC-4**, **AC-5**
4. Assignment: agent only `user_ids` validation, safe sync (only when the key is present), transaction wrapping, assignee picker in Create and Edit, satisfies **AC-6**
5. Admin list filters: search (email or normalized mobile with escaped wildcards), currency and status filters, pagination 15 with query string kept, satisfies **AC-7**, **AC-8**
6. Agent read only cards with per value copy and "Copy all" (reuse the pattern in `bank-accounts/BankDetailCard.vue`), empty state, satisfies **AC-9**, **AC-10**, **AC-11**
7. Run `php artisan wayfinder:generate` for the new routes, then `npm run build` check for `tsc` and `eslint`, satisfies **AC-11**
8. Pest feature tests for every scenario above in `tests/Feature/ZelleAccountManagementTest.php`, satisfies **AC-1** to **AC-12**
9. Docs: add a Zelle row to the nav matrix and a short "Zelle Accounts" section in `docs/agent.md`, and a one line pointer in `CLAUDE.md`, satisfies **AC-11**
10. Run `vendor/bin/pint --dirty --format agent` and the affected tests, satisfies **AC-1** to **AC-12**

## Consequences

**Positive**:
- Agents get one clear place for Zelle details, with fast copy.
- The paginated list, safe assignment sync, agent only check and transaction avoid the gaps found in the Bank Accounts review.
- No new packages, env vars or infrastructure.

**Negative / tradeoffs**:
- Duplicated shape with Bank Accounts. Two modules to keep in step until a shared abstraction is worth building.
- No audit trail. Nobody can see who changed or deleted an account. This was a deliberate choice.
- Email uniqueness is checked in the app, so two simultaneous saves of the same email could both pass. The chance is very low for an admin only screen.
- Search uses `LIKE` with `%text%`, so it cannot use an index. Fine at this size.

**Neutral**:
- Sidebar gets another item. Wayfinder files under `resources/js/actions` and `resources/js/routes` are regenerated.
- `SupportedCurrency` is reused, so the Zelle currency list changes together with Payments.

## Follow-up

- [ ] Optional: apply the same review fixes to Bank Accounts (safe `user_ids` sync, agent only assignment, pagination, transaction).
- [ ] Optional: a light activity log for Zelle later if you decide you need an audit trail.
- [ ] The two open doc edits in `CLAUDE.md` and `docs/agent.md` from the Bank Accounts docs work are still uncommitted on this branch. Commit them separately before or with this feature.
