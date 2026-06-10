# Payments Analytics Dashboard — Plan & Status

> Living document. Keep the **Status Tracker** updated as work lands so we always know what's done and what's pending.

**Current status:** ✅ **Phase 1 MVP shipped** (branch `feat/payments-dashboard`, commit `fe8464d`). Dashboard live at `/dashboard`, gated to admin + account, full test suite green. Phase 2 / Phase 3 not started.

---

## Charting Library Decision

**Library: Unovis (via shadcn-vue charts)** — `@unovis/ts` + `@unovis/vue`.

Chosen because it is native to our existing stack (Vue 3 + reka-ui + Tailwind 4 + shadcn-vue). Charts theme directly off our CSS variables, so brand colors (`brands.primary_color`) drop straight in with no second design language. SVG-based, tree-shakable, components owned in our own repo (shadcn pattern).

### Install

```bash
npx shadcn-vue@latest add chart
# or manual:
npm install @unovis/ts @unovis/vue
```

CLI copies `components/ui/chart/` into the project and adds `--chart-1..5` color vars to the app CSS.

### Known gaps (intentional substitutions)

| Need | Unovis native? | Our approach |
|---|---|---|
| Funnel | ❌ | Horizontal stacked bar (`VisStackedBar`) |
| Treemap | ❌ | Bar chart (`VisStackedBar`) |

Neither blocks the plan — both already designed around bars.

### Alternatives considered (rejected)

- **ECharts** — most powerful but overkill; own theming layer fights the shadcn look.
- **ApexCharts** — canvas, separate theme system, larger bundle.
- **Chart.js** — dated look, weak against the design system.
- **Recharts** — React only.

---

## Data Reality (ground truth)

Based on a one-off production DB export (63 payments, 14-day window). _The raw export file held live Stripe secrets and was deleted after analysis — do not re-commit DB dumps._

- Statuses are **`completed` / `pending` / `failed`** (no refunds yet).
- **Single payment provider (Stripe)** with **3 Stripe accounts** — there is no Square. Use the Stripe-account dimension, not "Stripe vs Square".
- **`service` is empty** on all rows — use **`package`** (premium/standard/basic/platinum) as the product dimension.
- **`relationship_manager`** is the sales-rep dimension — a first-class breakdown.
- Amounts stored as **integer cents**. Currencies **USD + GBP** — **never sum across currencies**; keep split or apply an explicit FX note.
- Headline operational fact: ~48% of links sit **pending** ($17k+ unconverted) — conversion, not revenue, is the #1 story.

---

## Phase 1 — What We Will Implement (MVP)

Goal: answer every management question with the smallest useful surface.

### KPI Cards (6)
1. **Collected (period)** — Σ completed, split USD / GBP, trend badge vs prior period.
2. **Conversion Rate** — completed ÷ (completed + failed + expired-pending).
3. **Pending Pipeline** — Σ pending amount + count badge.
4. **Success Rate** — completed ÷ (completed + failed). (Distinct from conversion.)
5. **Avg Payment Value** — mean completed ticket.
6. **Active Brands** — distinct brands with ≥1 completed in period.

### Charts (6)
1. **Revenue Trend** — `VisLine`/`VisArea`, daily, USD/GBP split.
2. **Conversion Funnel** — horizontal stacked bar (Created → Pending → Completed, leakage = Failed/Expired). *Highest-leverage view.*
3. **Brand Performance** — horizontal `VisStackedBar`, sorted desc, click-to-filter.
4. **RM Leaderboard** — bar + sortable table (revenue, count, avg, conversion %).
5. **Currency Split** — `VisDonut` (USD vs GBP).
6. **Stale-Pending / High-Value Worklist** — shadcn tables (no chart lib).

### Cross-cutting
- Global sticky filter bar: Date range, Brand, RM, Currency, Refresh.
- Auto-insight strip (deep-linked sentences).
- Multi-currency safety (no cross-currency sums).
- Reference-code formatting (`#000123`).

---

## Phase 2 — Near-Term (after MVP)

- Daily Collections (vertical bar).
- Payment Status Trend (stacked bar per day).
- Revenue by Package (bar).
- Revenue by Stripe Account (bar) — replaces the old "Stripe vs Square" idea.
- Week-over-Week growth (bar + delta labels).
- Avg payment cycle time (`paid_at − created_at`) — cheap, high value.
- Stale-pending automation (flag/re-notify links nearing `expires_at`).

---

## Phase 3 — Future / Advanced (defer until volume supports it)

> At 63 rows / 14 days these would be noise. Gate each on data volume.

| Feature | Gate |
|---|---|
| Month-over-Month growth | ≥2 full months |
| Revenue forecasting | ≥2–3 months of daily data |
| Cohort analysis | Requires repeat payers |
| Client LTV | Requires repeat-purchase history |
| Lead → payment conversion | Requires upstream lead/CRM data |
| Anomaly detection (failure/pending spikes) | ~few hundred payments for a baseline |
| AI-generated narrative ("what changed & why") | After core metrics stable |

---

## Status Tracker

Legend: ✅ Done · 🚧 In progress · ⬜ Not started

### Foundation
| Item | Status | Notes |
|---|---|---|
| Decide chart library (Unovis) | ✅ | This document |
| Install `@unovis/ts` + `@unovis/vue` / `add chart` | ✅ | `components/ui/chart/` added |
| Add `--chart-1..5` CSS vars | ✅ | Already present in `app.css` |
| Dashboard route + Inertia page | ✅ | `DashboardController` behind `role:admin\|account` |
| Backend aggregation endpoint/service | ✅ | `app/Services/DashboardMetrics.php`, multi-currency safe |
| Global filter bar (date/brand/RM/currency) | ✅ | `DashboardFilters.vue` |
| Redirect admin+account → dashboard | ✅ | `Navigation::homePathFor` + `LoginResponse` |
| Sidebar nav item (admin+account) | ✅ | `AppSidebar.vue` |

### Phase 1 — MVP
| Item | Status | Notes |
|---|---|---|
| KPI: Collected | ✅ | Per-currency |
| KPI: Conversion Rate | ✅ | |
| KPI: Pending Pipeline | ✅ | |
| KPI: Success Rate | ✅ | |
| KPI: Avg Payment Value | ✅ | Per-currency |
| KPI: Active Brands | ✅ | |
| Chart: Revenue Trend | ✅ | Unovis line/area, USD+GBP |
| Chart: Conversion Funnel | ✅ | CSS horizontal bars |
| Chart: Brand Performance | ✅ | Ranked bars |
| Chart: RM Leaderboard | ✅ | Bar + table |
| Chart: Currency Donut | ✅ | Unovis donut |
| Table: Stale-Pending / High-Value worklist | ✅ | |
| Auto-insight strip | ✅ | Server-computed sentences |
| Tests (gate, metrics, redirect) | ✅ | 18 tests, full suite green |

### Phase 2 — Near-Term
| Item | Status | Notes |
|---|---|---|
| Daily Collections | ⬜ | |
| Status Trend (stacked) | ⬜ | |
| Revenue by Package | ⬜ | |
| Revenue by Stripe Account | ⬜ | |
| Week-over-Week growth | ⬜ | |
| Avg payment cycle time | ⬜ | |
| Stale-pending automation | ⬜ | |

### Phase 3 — Future
| Item | Status | Notes |
|---|---|---|
| Month-over-Month growth | ⬜ | Gated on volume |
| Revenue forecasting | ⬜ | Gated on volume |
| Cohort analysis | ⬜ | Gated on repeat payers |
| Client LTV | ⬜ | Gated on repeat payers |
| Lead → payment conversion | ⬜ | Needs CRM/lead data |
| Anomaly detection | ⬜ | Gated on volume |
| AI-generated narrative | ⬜ | After core stable |

---

## Critical Rules (carry into implementation)

- **Never sum USD + GBP** into one figure — keep split or apply explicit FX.
- **Conversion ≠ Success rate** — report both (abandonment vs gateway failure).
- Use **`package`**, not `service` (empty).
- One Stripe provider, **3 accounts** — no "Stripe vs Square".
- Amounts are **integer cents** — format on display.
- All aggregation **server-side**; never trust client for amounts/status.
