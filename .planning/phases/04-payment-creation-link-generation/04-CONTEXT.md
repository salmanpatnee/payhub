# Phase 4: Payment Creation + Link Generation - Context

**Gathered:** 2026-05-05
**Status:** Ready for planning

<domain>
## Phase Boundary

Admin and User can create a payment record specifying brand, Stripe account (independent selection), amount in decimal dollars/pounds (converted to cents server-side), currency (USD or GBP), client name, client email, service (open text), package (Basic / Standard / Premium / Platinum / Diamond), and an optional note. The creation form shows a live Stripe fee breakdown (fee kept by Stripe vs amount received) before submission. On success the system generates a UUID-based shareable link (/pay/{uuid}) and redirects to a dedicated show page with a prominent copy button.

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

### Payment Form Fields
- **D-08:** `client_name` (string, required) — customer name. Separate from `client_email`. Stored in DB. Displayed on show page and index table.
- **D-09:** `client_email` (string, required) — already in schema. No change.
- **D-10:** `service` (string, nullable) — free-text field replacing the old `description` column. Describes what service is being charged for.
- **D-11:** `package` (enum, nullable) — dropdown with exactly five options: Basic, Standard, Premium, Platinum, Diamond. Stored as lowercase in DB (`basic`, `standard`, `premium`, `platinum`, `diamond`). Displayed as title-case in UI.
- **D-12:** `note` (text, nullable) — textarea for internal notes. Not shown to client on payment page.
- **D-13:** `description` column dropped. `service` + `package` + `note` replace it. Migration must remove `description` and add `client_name`, `service`, `package`, `note`.

### Stripe Fee Breakdown (creation form)
- **D-14:** The payment create form shows a live fee breakdown panel below the amount/currency fields. Computed client-side (Vue `computed`) — no server round-trip.
  - USD rate: **2.9% + $0.30** per transaction (Stripe standard)
  - GBP rate: **1.5% + £0.20** per transaction (Stripe standard UK)
  - Display three rows: **Charge amount**, **Stripe fee**, **You receive**
  - All formatted with `Intl.NumberFormat` matching the selected currency
  - Labelled as "Estimated — based on standard Stripe rates"
  - Breakdown only shows when amount > 0

### Claude's Discretion
- Exact shadcn-vue components used in the payment form and table (follow patterns from brands/Create.vue and brands/Index.vue)
- Route naming conventions (suggest `payments.index`, `payments.create`, `payments.store`, `payments.show`)
- Status badge colors (pending = yellow/warning, completed = green/success, failed = red/destructive)
- Copy-to-clipboard implementation (native `navigator.clipboard.writeText` or a small utility)
- How the formatted amount is displayed (e.g. `$25.00` / `£10.50` — format server-side in the Inertia prop or client-side with `Intl.NumberFormat`)
- Visual layout of fee breakdown panel (Card, table-like rows, or inline summary — match shadcn-vue style)

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

- Payment create form fields (in order): Brand (Select), Stripe Account (Select), Currency (Select: USD/GBP), Amount (number input), Client Name (text input), Client Email (email input), Service (text input), Package (Select: Basic/Standard/Premium/Platinum/Diamond), Note (textarea).
- Fee breakdown panel renders below amount/currency. Vue `computed` recalculates on every amount or currency change. USD: `fee = amount * 0.029 + 0.30`. GBP: `fee = amount * 0.015 + 0.20`. `youReceive = amount - fee`.
- Two Select dropdowns populated from Inertia props: `brands` (all brands) and `stripeAccounts` (only `is_active = true` accounts). Both required.
- Amount field: `<Input type="number" min="0.01" step="0.01" />` with placeholder "0.00". FormRequest: `$validated['amount'] = (int) round($request->amount * 100)`.
- Currency field: Select with two options only — "USD ($)" and "GBP (£)". Default to USD.
- Package field: Select with five options — Basic, Standard, Premium, Platinum, Diamond. Optional. Value sent as lowercase.
- `service` and `note` are nullable strings — no minimum length validation.
- After store, controller returns `redirect()->route('payments.show', $payment)` (routes by uuid via getRouteKeyName).
- Show page (`/payments/{uuid}`): prominent link box with `window.location.origin . '/pay/' . $payment->uuid` and clipboard copy button. Below: payment detail summary card showing client name, client email, service, package, note, amount, currency, brand, status.
- Index table columns: Client Name, Client Email, Service, Package, Amount (formatted), Currency, Brand, Status badge, Created, Copy Link action.
- `user_id` set to `auth()->id()` in controller store — never from client input.
- Migration: add `client_name` (string), `service` (string nullable), `package` (enum nullable), `note` (text nullable). Remove `description`. Run `migrate:fresh --seed`.

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
