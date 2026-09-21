# 0003. Zelle Accounts module beside Bank Accounts (rationale)

## Context

Agents need a place to find the Zelle details they share with clients, the same way they use Bank Accounts today. Zelle accounts are identified by an email and an optional mobile number, not by bank numbers, so they do not fit the Bank Accounts table without leaving most columns empty.

Constraints from the project: Laravel 13, Inertia v3, Vue 3, Pest, roles `admin`, `agent`, `account` (spatie permission). Payments only accept USD and GBP, which is exactly what this feature needs. This feature is separate from the payment providers, so the four provider parity rule does not apply.

Assumptions with no spec: the role model and the Bank Accounts module (documented in `docs/agent.md`, not in a spec). Build approach is not recorded in the project, so this spec assumes thin end to end slices (Tracer Bullet).

## Options considered

### Option 1: New `zelle_accounts` table and module (mirrors Bank Accounts)

Own table, model, policy, controller, requests and Vue pages, copying the Bank Accounts shape.

**Pros**:
- Clean fields, no empty bank columns.
- Same behavior your team already knows, easy to test and review.
- Each module can change on its own.

**Cons**:
- Some duplicated code (controller flow, cards, copy button).

### Option 2: Add a `type` column to `bank_accounts`

Reuse the table with a `zelle` type and mostly empty bank columns.

**Pros**:
- Least new code.

**Cons**:
- Bank validation ("one of sort code, routing number or IBAN") and its activity log would need special cases.
- Mixed data, confusing UI, harder to secure and search cleanly.

### Option 3: One shared "payment details" module for both

Extract a generic base and build Bank and Zelle on it.

**Pros**:
- Long term reuse.

**Cons**:
- Refactors a working module before it is needed. Two examples is too early to know the right abstraction.

## Rationale

The fields differ enough from a bank account (email and mobile instead of bank numbers) that sharing the table would force special cases in validation, search and the UI. Copying the proven Bank Accounts shape gives the smallest new surface and the most familiar code. A shared base would be premature with only two examples. Duplication is accepted and can be merged later once the pattern is stable.

