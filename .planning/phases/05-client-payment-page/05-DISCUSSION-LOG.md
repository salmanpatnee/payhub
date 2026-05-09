# Phase 5: Client Payment Page - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-05-09
**Phase:** 05-client-payment-page
**Areas discussed:** Post-payment UX, Client-facing content, Non-pending payment guard

---

## Post-payment UX

| Option | Description | Selected |
|--------|-------------|----------|
| Separate pages with redirects | `/pay/{uuid}/success` and `/pay/{uuid}/failed` as distinct Inertia pages; Stripe `return_url` points to success route | ✓ |
| Inline state machine, one page | Single page transitions between idle → processing → success/error; no redirect | |

**User's choice:** Separate pages with redirects

---

### Failure retry option

| Option | Description | Selected |
|--------|-------------|----------|
| Yes — link back to payment page | Failure page shows error + "Try again" link to `/pay/{uuid}` | ✓ |
| No — failure is terminal | Failure page only; client must contact business for new link | |

**User's choice:** Yes — failure page has "Try again" link

---

### Success page content

| Option | Description | Selected |
|--------|-------------|----------|
| Brand logo + amount + service + confirmation message | Branded success: logo, "Payment received", formatted amount, service name, thank-you | ✓ |
| Brand logo + amount only | Minimal success page — no service/package shown | |
| You decide | Claude picks layout | |

**User's choice:** Brand logo + amount + service + confirmation message

---

## Client-facing Content

### Data shown on payment page

| Option | Description | Selected |
|--------|-------------|----------|
| Brand + amount + service only | Logo, amount, currency, service. Package and note hidden as internal fields | |
| Brand + amount + service + package | Also shows package tier (Basic/Standard/etc.) | ✓ |
| All fields including note | Shows service, package, and internal note | |

**User's choice:** Brand + amount + service + package (note remains hidden)

---

### Brand color application

| Option | Description | Selected |
|--------|-------------|----------|
| CSS variables on root element | `--brand-primary` / `--brand-secondary` injected via inline style on layout root | ✓ |
| Tailwind arbitrary values in template | `[color:#xxx]` scattered in markup | |
| Inline style on key elements only | Primary button and header bar only | |

**User's choice:** CSS variables on root element

---

### Payment page layout

| Option | Description | Selected |
|--------|-------------|----------|
| New standalone PaymentLayout.vue | Minimal public layout shared by pay, success, failed, and guard pages | |
| No layout — self-contained pages | Each public page manages its own branding inline | |

**User's free-text response:** "New standalone page use frontend-design skill to design this page. It is the life of this project."

**Notes:** User emphasized the payment page design is the highest-priority UX asset in the project. Planner must use `/gsd-ui-phase 5` or the `frontend-design` skill to produce a polished design contract before implementation — this is not a functional scaffold.

---

## Non-pending Payment Guard

### Already-completed payment

| Option | Description | Selected |
|--------|-------------|----------|
| Branded 'already paid' read-only page | PaymentLayout shell with clear message and amount/date | ✓ |
| Redirect to success page | Redirect to `/pay/{uuid}/success` — simpler but misleading | |
| Generic 404 | Treat non-pending as not-found | |

**User's choice:** Branded 'already paid' read-only page

---

### Guard granularity

| Option | Description | Selected |
|--------|-------------|----------|
| One 'unavailable' page, status-aware message | Single guard page; message adapts by status (completed/failed/cancelled) | ✓ |
| Separate page per status | Three distinct pages for each state | |
| You decide | Claude picks approach | |

**User's choice:** One 'unavailable' page with status-aware message

---

## Claude's Discretion

- **PaymentIntent creation timing** — user did not select this area. Claude decided: controller creates PI on page load, passes `client_secret` in Inertia props only (never in URL).
- Stripe Elements appearance configuration (theme, colors)
- Loading/processing state during `confirmPayment()` (spinner, disabled form)
- Error message wording for card declines (use Stripe's `error.message`)
- Mobile layout of Elements form
- Choice between `confirmPayment()` vs `confirmCardPayment()` — prefer newer `confirmPayment()` for 3DS handling

## Deferred Ideas

- Email receipt on payment success — deferred to v2 (per-brand email sender identity unresolved)
- Payment cancellation by client — v2 feature
- Partial payments / installments — out of scope
