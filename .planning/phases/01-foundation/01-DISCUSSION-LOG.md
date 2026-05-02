# Phase 1: Foundation - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-02
**Phase:** 1-Foundation
**Areas discussed:** Scaffold strategy, shadcn-vue style, Schema completeness

---

## Scaffold Strategy

| Option | Description | Selected |
|--------|-------------|----------|
| Official starter kit | `laravel new payhub --kit=vue` — ships Inertia v2 + Vue 3 + Tailwind 4 + Vite pre-wired; Breeze auth stubs included | ✓ |
| Bare install + manual wiring | `laravel new` (no kit), then manually install and wire each package | |

**User's choice:** Official starter kit (`laravel new payhub --kit=vue`)
**Notes:** None

---

| Option | Description | Selected |
|--------|-------------|----------|
| Keep as placeholders | Leave Breeze auth pages in place; Phase 2 wires Fortify backend to them | ✓ |
| Remove immediately | Strip all Breeze auth pages in Phase 1; Phase 2 builds from scratch | |

**User's choice:** Keep Breeze auth pages as Phase 2 placeholders
**Notes:** None

---

## shadcn-vue Style

| Option | Description | Selected |
|--------|-------------|----------|
| New York | Smaller radius (0.375rem), crisper borders, tighter density — admin/dashboard feel | ✓ |
| Default | More rounded (0.5rem), softer shadows — consumer app feel | |

**User's choice:** New York style
**Notes:** None

---

| Option | Description | Selected |
|--------|-------------|----------|
| Minimal (Button only) | Just enough to pass Phase 1 success criterion | |
| Core set | Button, Input, Label, Card, Badge — 5 components every phase needs immediately | ✓ |

**User's choice:** Core set: Button, Input, Label, Card, Badge
**Notes:** None

---

## Schema Completeness

| Option | Description | Selected |
|--------|-------------|----------|
| Full final schema | All 4 models with every column across all 7 phases; no alter-table migrations later | ✓ |
| Minimal for Phase 1 only | Only columns for Phase 1 success criteria; later phases add columns | |

**User's choice:** Full final schema in Phase 1 migrations
**Notes:** Includes expires_at (nullable, NULL = non-expiring) per research recommendation

---

| Option | Description | Selected |
|--------|-------------|----------|
| Enum column | `ENUM('pending','completed','failed','cancelled')` — DB-enforced | ✓ |
| String column | VARCHAR — flexible for future statuses without migration | |

**User's choice:** Enum column
**Notes:** None

---

| Option | Description | Selected |
|--------|-------------|----------|
| logo_path — local storage | Nullable VARCHAR storing Storage path; rendered via `Storage::url()` | ✓ |
| logo_url — external URL | Nullable VARCHAR storing full absolute URL | |

**User's choice:** logo_path (local storage)
**Notes:** None

---

## Claude's Discretion

- Migration file naming and ordering
- Factory definitions (Faker-based)
- Model cast definitions beyond `encrypted`
- Pest test structure for encryption round-trip
- APP_PREVIOUS_KEYS placeholder wording in .env.example

## Deferred Ideas

- APP_KEY rotation runbook — noted as MODERATE risk in research; user chose not to discuss; Phase 1 adds .env.example placeholder only; full runbook deferred to v2
- Laravel Horizon — deferred to Phase 6
- Additional shadcn-vue components — added per-phase as needed
