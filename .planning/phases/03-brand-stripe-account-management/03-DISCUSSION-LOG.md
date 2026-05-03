# Phase 3: Brand + Stripe Account Management - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-03
**Phase:** 03-brand-stripe-account-management
**Areas discussed:** Logo upload, Color input UX

---

## Gray Area Selection

| Option | Selected |
|--------|----------|
| Logo upload | ✓ |
| Color input UX | ✓ |
| Stripe nav structure | |
| Secret key edit UX | |

---

## Logo Upload

| Option | Description | Selected |
|--------|-------------|----------|
| File upload (Recommended) | Admin uploads image; stored via Laravel Storage local disk; displayed via Storage::url() | ✓ |
| URL input only | Admin pastes external image URL; simpler but depends on external host | |
| Skip logo for v1 | Logo optional, defer upload to v2 | |

**User's choice:** File upload via Laravel Storage (local disk)

---

### Logo — Storage disk and constraints

| Option | Description | Selected |
|--------|-------------|----------|
| Local disk, any image (Recommended) | config/filesystems 'local' disk, storage:link, accept jpg/png/webp/svg, max ~2MB | ✓ |
| Local disk, strict constraints | PNG/SVG only, max 500KB, min dimensions 200x200px | |
| S3 / cloud storage | AWS S3, requires credentials, out of scope for v1 | |

**User's choice:** Local disk, any image format, max ~2MB

---

## Color Input UX

| Option | Description | Selected |
|--------|-------------|----------|
| Native picker + hex text (Recommended) | `<input type="color">` + shadcn Input synced in Vue | ✓ |
| Hex text only | Plain shadcn Input fields, no visual feedback | |
| Live brand preview card | Color pickers + live preview of brand appearance | |

**User's choice:** Native picker + hex text (synced)

---

### Color — Live preview card

| Option | Description | Selected |
|--------|-------------|----------|
| Yes, live preview card (Recommended) | Preview card with brand name + logo thumbnail + colored header strip, updates in real-time | ✓ |
| No, pickers only | Just native picker + hex fields, no preview | |

**User's choice:** Yes — live brand preview card

---

## Claude's Discretion

- Stripe account nav structure (dedicated section vs. brand sub-resource)
- Secret key edit UX (masked placeholder on edit form)
- Test/live key blocking implementation
- Webhook secret capture (deferred to Phase 6)
- Brand slug auto-generation
- Color hex validation
- Old logo file cleanup on update

## Deferred Ideas

- Webhook secret input form — Phase 6
- Logo CDN / S3 — v2
- Brand deletion UI — no requirement in v1
