# Phase 4: Payment Creation + Link Generation - Context

**Gathered:** 2026-05-05
**Status:** Ready for planning

<domain>
## Phase Boundary

Admin and User can create a payment record specifying brand, Stripe account (independent selection), amount in decimal dollars/pounds (converted to cents server-side), currency (USD or GBP), description, and client email. On success the system generates a UUID-based shareable link (/pay/{uuid}) and redirects to a dedicated show page with a prominent copy button.

Payment list at /payments is a simple table scoped by role (Admin sees all, User sees own). No filtering in Phase 4 — filters come in Phase 7.

No Stripe API calls in Phase 4. No PaymentIntent creation. No client-facing payment page. No webhooks. Record creation and link generation only.

</domain>

<decisions>
## Implementation Decisions

### Brand ↔ Stripe Account Pairing
- **D-01:** Brand and Stripe account are selected via **independent dropdowns** — two separate `<select>` elements, no cascade filtering. Aligns with Phase 3's decision to decouple Stripe accounts from brands (brand_id dropped from stripe_accounts table).
- **D-02:** Backend validation: brand must exist (FK check), Stripe account must exist and `is_active = true`. No cross-referencing between brand and Stripe account — user is responsible for selecting a compatible pair.

### Route Structure and Access
- **D-03:** Payment routes live at `/payments` under `auth + verified` middleware (NOT under `/admin/` prefix). Both Admin and User roles can create payments. `/admin/` remains reserved for admin-only management (users, brands, stripe-accounts).
  - `GET  /payments`          → index (role-scoped list)
  - `GET  /payments/create`   → create form
  - `POST /payments`          → store
  - `GET  /payments/{uuid}`   → show page (post-creation landing, shareable link)
- **D-04:** `/payments` index query is role-scoped: Admin sees all payments (with brand, stripeAccount, user eager-loaded); User sees only `where('user_id', auth()->id())`.

### Amount Input
- **D-05:** Form accepts **decimal dollar/pound input** (e.g. `25.00`, `10.50`). Server-side FormRequest multiplies by 100, rounds, and stores as integer cents. Validation: amount > 0 in dollars (i.e. at least 1 cent after conversion). No server-side maximum — Stripe's own limits apply at payment time.
- SEC-02 enforced: amount is read exclusively from the server-side `Payment` record at payment time. No client-supplied amount is ever accepted for processing.

### Post-Creation Flow
- **D-06:** After successful `store()`, redirect to `/payments/{uuid}` (show page). The show page displays the shareable link prominently in a copy-to-clipboard box, plus payment summary (amount formatted, currency, status badge, client email, brand name). A "Back to payments" link returns to the index.
- **D-07:** Phase 4 `/payments` index is a **simple unfiltered table** — columns: amount (formatted), currency, brand name, Stripe account name, status (badge), created at, client email, copy-link action. No search or filtering. Full filtering deferred to Phase 7 dashboard (DASH-02).

### Claude's Discretion
- Exact shadcn-vue components used in the payment form and table (follow patterns from brands/Create.vue and brands/Index.vue)
- Route naming conventions (suggest `payments.index`, `payments.create`, `payments.store`, `payments.show`)
- Status badge colors (pending = yellow/warning, completed = green/success, failed = red/destructive)
- Copy-to-clipboard implementation (native `navigator.clipboard.writeText` or a small utility)
- How the formatted amount is displayed (e.g. `$25.00` / `£10.50` — format server-side in the Inertia prop or client-side with `Intl.NumberFormat`)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project Definition
- `.planning/PROJECT.md` — Core constraints, tech stack, Stripe multi-account pattern, out-of-scope list
- `.planning/REQUIREMENTS.md` — PAY-01 through PAY-07, SEC-02 (all Phase 4 requirements)
- `.planning/ROADMAP.md` — Phase 4 goal, 5 success criteria, depends-on Phase 3

### Prior Phase Context
- `.planning/phases/03-brand-stripe-account-management/03-CONTEXT.md` — Phase 3 decisions, especially: Stripe accounts decoupled from brands (no brand_id), webhook_secret deferred to Phase 6, deactivated accounts excluded from dropdowns (is_active filter)
- `.planning/phases/02-auth-user-management/02-CONTEXT.md` — Route prefix pattern (/admin/ = admin-only), AppSidebarLayout, Payments nav link wired to /payments

### Research
- `.planning/research/SUMMARY.md` — Stack pitfalls, StripeService pattern

### Security Rules (CLAUDE.md)
- SEC-02: Payment amount read exclusively from server-side `Payment` record — no client-supplied amount accepted
- Never call `Stripe::setApiKey()` globally — always `new StripeClient($account->secret_key)`
- Amounts always stored as integer cents — no floats

### Existing Code
- `app/Models/Payment.php` — UUID auto-generation in boot(), `getRouteKeyName() = 'uuid'`, integer amount cast, `expires_at`/`paid_at` datetime casts, brand/stripeAccount/user BelongsTo relationships
- `app/Models/Brand.php` — payments() HasMany; fillable: name, slug, website_url, logo_path, primary_color, secondary_color
- `app/Models/StripeAccount.php` — payments() HasMany; is_active boolean cast; secret_key/webhook_secret encrypted cast; NOT mass-assignable
- `routes/web.php` — /payments placeholder at line 16-17 (auth+verified, currently ComingSoon)
- `resources/js/pages/admin/brands/Create.vue` — Card form pattern with useForm(), breadcrumbs, InputError, grid layout
- `resources/js/pages/admin/brands/Index.vue` — Table index pattern with ConfirmDeleteDialog
- `resources/js/pages/admin/stripe-accounts/Index.vue` — Table with status badge, deactivate action — reference for status display

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `resources/js/pages/admin/brands/Create.vue` — Card-based create form with CardHeader/CardContent/CardFooter, useForm(), InputError, breadcrumbs. Payment create form should follow this pattern exactly.
- `resources/js/pages/admin/brands/Index.vue` — Table-based index. Payment list follows the same pattern.
- `resources/js/components/ui/` — Card, Input, Label, Button, Badge, Select, Dialog all available. Select is key for brand and Stripe account dropdowns.
- `app/Http/Requests/Admin/StoreUserRequest.php` / `UpdateUserRequest.php` — FormRequest validation pattern. Payment creation needs a `StorePaymentRequest`.

### Established Patterns
- Vue 3 Composition API + `<script setup lang="ts">` — all new components
- shadcn-vue New York style — all new UI components
- `defineOptions({ layout: { breadcrumbs: [...] } })` — breadcrumb pattern
- `useForm()` from `@inertiajs/vue3` — form submissions
- Inertia redirect after store with `->with('success', '...')` flash (though Phase 4 redirects to show page, not index)

### Integration Points
- `routes/web.php` line 16-17 — `/payments` ComingSoon placeholder must be replaced with a full resource route (minus `edit`/`update`/`destroy` — payments are immutable once created in Phase 4)
- `resources/js/layouts/app/AppSidebar*.vue` — Payments nav link already exists pointing to `/payments` — no sidebar change needed
- `app/Models/StripeAccount.php` — `is_active` filter must be applied when fetching accounts for the payment form dropdown (`StripeAccount::where('is_active', true)->get()`)

</code_context>

<specifics>
## Specific Ideas

- The payment create form needs two Select dropdowns populated from Inertia props: `brands` (all brands) and `stripeAccounts` (only `is_active = true` accounts). Both required fields.
- Amount field: `<Input type="number" min="0.01" step="0.01" />` with placeholder "0.00". FormRequest: `$validated['amount'] = (int) round($request->amount * 100)`.
- Currency field: a Select with two options only — "USD ($)" and "GBP (£)". Default to USD.
- After store, controller returns `redirect()->route('payments.show', $payment)` (routes by uuid via getRouteKeyName).
- Show page (`/payments/{uuid}`): prominent link box with `window.location.origin . '/pay/' . $payment->uuid` and a clipboard copy button. Below: payment detail summary card.
- Index table: amount formatted as `$25.00` or `£10.50` — use PHP `number_format($payment->amount / 100, 2)` with currency symbol prefix in the Inertia prop, or format client-side with `Intl.NumberFormat`.
- `user_id` on the payment must be set to `auth()->id()` in the controller store method — not from client input.

</specifics>

<deferred>
## Deferred Ideas

- **Payment cancellation/void** — v2 feature (in REQUIREMENTS.md v2). Payments are immutable in Phase 4.
- **Editing a payment** — Not in scope. Payment records are write-once after creation.
- **Status filter on /payments index** — Deferred to Phase 7 dashboard (DASH-02).
- **PaymentIntent creation** — Phase 4 creates the DB record and UUID only. Stripe API PaymentIntent is created in Phase 5 when the client opens the payment page.
- **Link expiry** — `expires_at` column exists on Payment model but PAY-07 says links never expire. Set `expires_at = null` on creation. If expiry is ever needed, it's a v2 feature.

</deferred>

---

*Phase: 04-payment-creation-link-generation*
*Context gathered: 2026-05-05*
