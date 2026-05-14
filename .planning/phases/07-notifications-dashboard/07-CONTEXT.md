# Phase 7: Notifications + Dashboard - Context

**Gathered:** 2026-05-14
**Status:** Ready for planning

<domain>
## Phase Boundary

Admin email notification when a payment succeeds (via queued job), and enhanced payment list page with server-side filtering — Admin sees all payments across all brands with brand/Stripe account/status/date range filters; User sees own payment history with status/date range filters. Existing `/payments` route and `payments/Index.vue` are enhanced — no new routes or pages needed.

Out of scope: client email receipt on payment success (per-brand From address, deferred to v2), per-brand email sender identity, pagination (not in requirements), dashboard charts/analytics (v2).

</domain>

<decisions>
## Implementation Decisions

### Email Notification — Recipients & Sender
- **D-01:** Recipients = **all admin users** — `User::role('admin')->get()` at dispatch time. Every user with the Admin role receives the email. No configurable recipient list for v1.
- **D-02:** From address = **single system address from `.env`** — `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`. No per-brand sender. Same decision as why client email receipts were deferred.

### Email Notification — Content
- **D-03:** Email body = **full payment details**: client name, client email, amount + currency (formatted), brand name, Stripe account name, service, package, note (if present), and a link to the admin payment detail page (`/payments/{uuid}`).

### Dashboard Page Structure
- **D-04:** **Enhance existing `/payments` page** — not a new route or component. `payments/Index.vue` gains a filter bar; `PaymentController::index()` gains query-param-based filtering. Role-gating already correct (admin sees all, user sees own).
- **D-05:** **Filters shown to both roles.** Admin-only filters: brand, Stripe account (only meaningful when seeing all payments). Shared filters: status, date range. Users see status + date range filters on their own history.

### Filter Implementation
- **D-06:** **Server-side via Inertia** — filters become query params (e.g., `?brand_id=1&status=completed&from=2026-01-01&to=2026-05-14`). Controller applies `->when()` scopes to the Eloquent query. Only matching rows returned.
- **D-07:** **Auto-submit on change** — each filter input change triggers `router.get(route('payments.index'), filters, { preserveState: true, replace: true })`. No Apply button needed.

### Notification Architecture
- **D-08:** **Inline dispatch from `HandleStripeWebhookJob`** — after `$updated > 0` (status write succeeded), dispatch `SendPaymentNotification` job. No event/listener bus. Two-job chain: webhook job → notification job.
- **D-09:** Class names: queued job = `App\Jobs\SendPaymentNotification`, mailable = `App\Mail\PaymentSucceeded`. Follow Laravel convention (`php artisan make:job`, `php artisan make:mail`).

### Claude's Discretion
- Date range filter UI: two HTML `<input type="date">` inputs (from / to) in the filter bar.
- Email template: Laravel markdown mailable for clean, formatted output (`--markdown` flag on `make:mail`).
- Filter state on page: controller passes `filters` prop back via `Inertia::render()` so the Vue component can pre-populate the filter inputs on page load / back-navigation.
- `SendPaymentNotification` receives `Payment $payment` constructor arg; resolves admins and sends mailable inside `handle()`.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project Definition
- `.planning/PROJECT.md` — Core constraints, tech stack, Stripe multi-account pattern
- `.planning/REQUIREMENTS.md` — NOTIFY-01, NOTIFY-02, DASH-01, DASH-02, DASH-03, DASH-04 (all Phase 7 requirements)
- `.planning/ROADMAP.md` — Phase 7 goal and 4 success criteria

### Security Rules (CLAUDE.md — MUST enforce)
- `NEVER` call `Stripe::setApiKey()` globally — per-account StripeClient only (not directly relevant here but maintain)
- `NEVER` log `client_secret` — already enforced in HandleStripeWebhookJob; maintain in any new job

### Prior Phase Context
- `.planning/phases/06-webhooks-status-sync/06-CONTEXT.md` — D-08: `HandleStripeWebhookJob` is the dispatch point for notification; `$updated > 0` guard prevents duplicate sends
- `app/Jobs/HandleStripeWebhookJob.php` — actual hook point; notification dispatch added after `$updated > 0` check

### Key Existing Files to Extend
- `app/Http/Controllers/PaymentController.php` — `index()` method to extend with `->when()` filter scopes; `filters` prop added to Inertia response
- `resources/js/pages/payments/Index.vue` — add filter bar above table; connect to `router.get()` with `preserveState: true`
- `app/Models/User.php` — has `Notifiable` trait; `User::role('admin')->get()` via spatie HasRoles

### Laravel Conventions
- `CLAUDE.md` — stack constraints (Laravel 13, Vue 3 Composition API + `<script setup lang="ts">`, Tailwind 4, shadcn-vue)
- Queue driver: `database` for dev — `php artisan queue:work` for local testing

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `resources/js/pages/payments/Index.vue` — existing table with correct DASH-04 columns (amount, currency, brand, status, created, client email). Filter bar slots above the table. Status badge + `formatAmount()` already implemented.
- `app/Http/Controllers/PaymentController.php` — `index()` already role-gates correctly. Extend `$query` with `->when($request->brand_id, fn($q, $v) => $q->where('brand_id', $v))` pattern.
- `app/Jobs/HandleStripeWebhookJob.php` — `$updated` return from atomic update. Dispatch `SendPaymentNotification` when `$updated > 0` and event type is `payment_intent.succeeded`.
- `app/Models/User.php` — `Notifiable` trait + `HasRoles` (spatie). `User::role('admin')->get()` returns all admins.
- Copy-to-clipboard pattern in `payments/Index.vue` — reuse or adapt for any interactive elements in filter bar.

### Established Patterns
- All new PHP classes via `php artisan make:` commands
- Vue 3 Composition API + `<script setup lang="ts">` — all Vue files
- Inertia `router.get()` with `{ preserveState: true, replace: true }` for filter navigation (avoids pushing history entry per keystroke)
- shadcn-vue `<Select>`, `<Button>`, `<Badge>` — reuse existing UI components from prior phases
- Queue driver: `database`; `$tries = 3`, `$backoff = [1, 5, 10]` — follow `HandleStripeWebhookJob` conventions
- Markdown mailables (`php artisan make:mail PaymentSucceeded --markdown=emails.payment-succeeded`)

### Integration Points
- `routes/web.php` — no new routes needed; `/payments` already exists (named `payments.index`)
- `HandleStripeWebhookJob::handle()` — add `SendPaymentNotification::dispatch($payment)` after `$updated > 0` guard (for `payment_intent.succeeded` only)
- Controller: pass `'filters' => $request->only(['brand_id', 'stripe_account_id', 'status', 'from', 'to'])` and brand/account lists for Admin dropdown population

</code_context>

<specifics>
## Specific Ideas

- Filter bar layout: dropdowns (brand, Stripe account, status) + date range inputs, all in a single horizontal row above the table. Auto-submit on each change.
- Admin-only filter inputs (brand, Stripe account) rendered conditionally: `v-if="$page.props.auth.user.roles.includes('admin')"` or pass an `isAdmin` boolean prop.
- `SendPaymentNotification` job: `Payment $payment` in constructor; inside `handle()`, resolve `User::role('admin')->get()` and loop `Mail::to($admin)->queue(new PaymentSucceeded($payment))`.
- `PaymentSucceeded` mailable: `__construct(public Payment $payment)`. Markdown template shows all D-03 fields. Subject: "Payment received — {client_name} ({formatted_amount})".
- Controller filter scopes: use `->when()` for each optional filter. Date range: `->when($from, fn($q, $v) => $q->whereDate('created_at', '>=', $v))` + `->when($to, ...)`.

</specifics>

<deferred>
## Deferred Ideas

- **Client email receipt on payment success** — per-brand From address required; deferred to v2 (already in REQUIREMENTS.md v2 deferred list).
- **Pagination** — not in v1 requirements. Full list for now. Add in v2 if payment volume justifies it.
- **Dashboard charts / analytics** — v2 (REQUIREMENTS.md: "Cross-brand analytics with charts").
- **CSV export** — v2 (in REQUIREMENTS.md v2 deferred list).

</deferred>

---

*Phase: 07-notifications-dashboard*
*Context gathered: 2026-05-14*
