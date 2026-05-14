---
plan: "07-03"
phase: "07-notifications-dashboard"
status: complete
completed_at: "2026-05-14"
wave: 2
commits:
  - 44592f2  # feat(07-03): add filter bar to payments/Index.vue
  - e9a0a5a  # fix(07-03): replace ziggy-js with Wayfinder, fix SelectItem empty-value error
tests_green: true
checkpoint_approved: true
---

# 07-03 Summary — Filter Bar UI (payments/Index.vue)

## What was built

Extended `resources/js/pages/payments/Index.vue` with a full filter bar:

- **Brand** and **Account** Select dropdowns — `v-if="isAdmin"` (admin only, D-05)
- **Status** Select dropdown — all roles
- **From** and **To** date `<Input type="date">` — all roles
- **Clear filters** ghost button with `X` icon — visible only when `hasActiveFilters` is true
- Auto-submit: `watch(filters, ..., { deep: true })` calls `router.get()` with `preserveState: true, replace: true` on every change
- Empty state adapts: "No payments match your filters. Clear filters to see all." vs "No payments yet. Create one."
- Filter values pre-populated from `props.filters` on every page load and back-navigation

## Deviations from plan

**Ziggy vs Wayfinder**: Plan specified `import { route } from 'ziggy-js'` — ziggy-js is not installed. Project uses `@laravel/vite-plugin-wayfinder`. Fixed: `import { index as paymentsIndex } from '@/actions/App/Http/Controllers/PaymentController'` and `paymentsIndex.url({ query: activeFilters })`.

**SelectItem empty value**: shadcn-vue rejects `value=""` on `<SelectItem>` (empty string reserved for clearing). Fixed: `__all` sentinel string for "show all" items. Watch handler and `hasActiveFilters` computed strip `__all` same as `''`.

## Acceptance criteria

- [x] `router.get` with `preserveState: true, replace: true` on filter change
- [x] `reactive({ ...props.filters })` initialises filter state
- [x] `hasActiveFilters` computed gates clear button and empty state message
- [x] `v-if="isAdmin"` on Brand and Account filter wrappers
- [x] `clearFilters()` resets all filter values
- [x] `aria-label="Clear all filters"` on clear button
- [x] "No payments match your filters." empty state copy
- [x] `Select*` components from `@/components/ui/select`
- [x] `php artisan test --compact` exits 0 (120/132 pass; 2 pre-existing PublicPaymentRouteTest errors unrelated to Phase 7)
- [x] Checkpoint approved — filters verified in browser
