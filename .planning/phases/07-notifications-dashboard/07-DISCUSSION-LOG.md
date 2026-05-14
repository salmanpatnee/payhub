# Phase 7: Notifications + Dashboard - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-14
**Phase:** 07-notifications-dashboard
**Areas discussed:** Notification email, Dashboard page, Filter approach, Notification trigger

---

## Notification Email — Who

| Option | Description | Selected |
|--------|-------------|----------|
| All admin users | Every user with Admin role gets the email | ✓ |
| Only the payment creator | Creator (could be non-admin User) notified | |
| Both — creator + all admins | Creator always notified; admins also notified | |

**User's choice:** All admin users
**Notes:** Simple, no config, fits small agency team model.

---

## Notification Email — From Address

| Option | Description | Selected |
|--------|-------------|----------|
| Single system address from .env | MAIL_FROM_ADDRESS + MAIL_FROM_NAME | ✓ |
| Per-brand From address | Requires per-brand SMTP — same blocker as client receipts | |

**User's choice:** Single system address from .env
**Notes:** Resolves the STATE.md blocker by choosing the simpler path. Per-brand email deferred to v2.

---

## Notification Email — Body Content

| Option | Description | Selected |
|--------|-------------|----------|
| Payment essentials | Client name, amount, brand, Stripe account, UUID link | |
| Full payment details | All essentials + service, package, note, client email | ✓ |
| Minimal | Just amount and a link | |

**User's choice:** Full payment details
**Notes:** All available payment data in the notification.

---

## Dashboard Page Structure

| Option | Description | Selected |
|--------|-------------|----------|
| Enhance existing /payments page | Add filters to payments/Index.vue | ✓ |
| New /dashboard page for admins | Separate route/component; users keep /payments | |

**User's choice:** Enhance existing /payments page
**Notes:** Role-gating already correct; no new routes needed.

---

## Dashboard — Filter Visibility

| Option | Description | Selected |
|--------|-------------|----------|
| Admin-only filters | Filters only for admins; users see unfiltered own list | |
| Filters for both roles | Users also filter their own history (status, date range) | ✓ |

**User's choice:** Filters for both roles
**Notes:** Admin gets brand + Stripe account + status + date range; User gets status + date range.

---

## Filter Approach — Mechanism

| Option | Description | Selected |
|--------|-------------|----------|
| Server-side via Inertia | Query params, DB-level filtering | ✓ |
| Client-side JS | All data loaded, filtered in Vue | |

**User's choice:** Server-side via Inertia
**Notes:** Correct for any data size; bookmarkable URLs.

---

## Filter Approach — Submission

| Option | Description | Selected |
|--------|-------------|----------|
| Auto-submit on change | router.get() on each input change, preserveState | ✓ |
| Apply button | Manual submit after setting all filters | |

**User's choice:** Auto-submit on change
**Notes:** Instant feedback, no extra click needed.

---

## Notification Trigger — Architecture

| Option | Description | Selected |
|--------|-------------|----------|
| Inline in HandleStripeWebhookJob | Dispatch SendPaymentNotification after $updated > 0 | ✓ |
| PaymentCompleted event + listener | Event bus, queued listener — more decoupled | |

**User's choice:** Inline in HandleStripeWebhookJob
**Notes:** Simpler, fewer classes, maps directly to the 2-event requirement.

---

## Notification Trigger — Naming

| Option | Description | Selected |
|--------|-------------|----------|
| SendPaymentNotification + PaymentSucceeded | Follows Laravel convention | ✓ |
| You decide | Let Claude pick names | |

**User's choice:** SendPaymentNotification job + PaymentSucceeded mailable

---

## Claude's Discretion

- Date range filter: two HTML `<input type="date">` inputs (from / to)
- Email template: Laravel markdown mailable
- Filter state passed back as `filters` prop from controller for pre-population
- `SendPaymentNotification` receives `Payment $payment`; resolves admins in `handle()`
- Email subject: "Payment received — {client_name} ({formatted_amount})"

## Deferred Ideas

- Client email receipt on payment success — per-brand From address required (v2)
- Pagination — not in v1 requirements (v2)
- Dashboard charts / analytics (v2)
- CSV export (v2)
