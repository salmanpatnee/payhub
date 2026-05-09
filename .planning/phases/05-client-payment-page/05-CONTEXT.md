# Phase 5: Client Payment Page - Context

**Gathered:** 2026-05-09
**Status:** Ready for planning

<domain>
## Phase Boundary

A public, unauthenticated page at `/pay/{uuid}` where a client opens a payment link, sees the brand's visual identity, and completes payment via an inline Stripe Elements form initialized with that brand's publishable key. Successful payment redirects to a branded success page; failure redirects to a branded failure page with a retry link. Non-pending payments (already completed, failed, or cancelled) show a branded guard page instead of the form.

No payment status is written to the database in this phase — that is Phase 6 (webhooks). Phase 5 creates the PaymentIntent server-side and renders the Elements form.

</domain>

<decisions>
## Implementation Decisions

### PaymentIntent Creation (Claude's decision — user did not select this area)
- **D-01:** Controller creates the PaymentIntent **on page load** using `new StripeClient($account->secret_key)` — no global `setApiKey()`. The `client_secret` is passed in Inertia props only (never in a URL or API response body outside the page load).
- **D-02:** After creating the PaymentIntent, the controller updates `payments.stripe_payment_intent_id` with the returned `pi_xxx` ID. This allows the webhook handler in Phase 6 to look up the payment record.
- **D-03:** Guard check runs before PI creation: if `payment->status !== 'pending'`, render the guard view immediately — no PI is created for already-processed payments.

### Post-payment UX
- **D-04:** Success and failure are **separate Inertia pages** with their own routes:
  - `GET /pay/{uuid}/success` → `ClientPayment/Success.vue`
  - `GET /pay/{uuid}/failed` → `ClientPayment/Failed.vue`
  - Stripe's `confirmPayment()` `return_url` points to the success route. Stripe will append `?payment_intent=...&payment_intent_client_secret=...&redirect_status=...` query params — the success controller reads `redirect_status` to confirm success before rendering.
- **D-05:** The failure page includes a "Try again" link back to `/pay/{uuid}`. The client can re-attempt without needing a new link from the admin.
- **D-06:** Success page content: brand logo, "Payment received" heading, formatted amount (with currency), service name (if set), confirmation message. No package or note on success page.

### Client-facing Content
- **D-07:** The payment page shows the client: brand logo/name, formatted amount, currency, service (if set), package tier (if set). The `note` field is **not shown** — it is an internal admin field.
- **D-08:** Brand colors are injected as **CSS variables on the page root element**: `--brand-primary: {primary_color}; --brand-secondary: {secondary_color}`. Vue sets these via a `style` binding on the layout root. Tailwind utility classes and the Stripe Elements appearance object reference these variables.
- **D-09:** A new **`PaymentLayout.vue`** is created for the public payment experience. It is a standalone layout (no sidebar, no auth nav) shared by: the payment page, success page, failure page, and guard page. It renders brand logo at top, centered card content below, and nothing else. All four public pages use this layout.
- **D-10:** **Design quality is critical for this phase.** The user explicitly flagged the client payment page as "the life of this project." The planner MUST invoke `/gsd-ui-phase 5` or the `frontend-design` skill before implementation to produce a high-fidelity design contract. This is not a functional scaffold — it needs polished, professional UI.

### Non-pending Payment Guard
- **D-11:** A single guard page handles all non-pending statuses. Status-aware message:
  - `completed` → "This payment has already been completed."
  - `failed` → "Payment unsuccessful — please contact us to arrange a new payment."
  - `cancelled` → "This payment link is no longer active."
  - The guard page uses `PaymentLayout.vue` (same branded shell) with no Stripe Elements form.
- **D-12:** Guard is enforced in the server-side controller — not client-side. If `payment->status !== 'pending'`, the controller renders `ClientPayment/Unavailable.vue` with status and brand props. No PaymentIntent is created.

### Claude's Discretion
- Stripe Elements appearance object configuration (theme, colors — should reference `--brand-primary` CSS variable)
- Loading/processing state during `confirmPayment()` (spinner on submit button, disable form)
- Exact error message wording for card declines (use Stripe's `error.message` from `confirmPayment()` result)
- Mobile layout of the Elements form (single column on small screens)
- Whether to use `stripe.confirmPayment()` (with Elements) or `stripe.confirmCardPayment()` — prefer the newer `confirmPayment()` API which handles all payment method types including 3DS automatically

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project Definition
- `.planning/PROJECT.md` — Core constraints, tech stack, Stripe multi-account pattern, out-of-scope list
- `.planning/REQUIREMENTS.md` — CLIENT-01 through CLIENT-08, SEC-04 (all Phase 5 requirements)
- `.planning/ROADMAP.md` — Phase 5 goal and 6 success criteria

### Prior Phase Context
- `.planning/phases/04-payment-creation-link-generation/04-CONTEXT.md` — Phase 4 decisions; especially: PaymentIntent deferred to Phase 5, no Stripe API calls in Phase 4, `/pay/{uuid}` route stub at routes/web.php:51
- `.planning/phases/03-brand-stripe-account-management/03-CONTEXT.md` — Stripe accounts decoupled from brands; `publishable_key` is unencrypted (safe to pass to client); `secret_key` is encrypted

### Security Rules (CLAUDE.md — MUST enforce)
- `client_secret` NEVER in URLs, logs, or any response beyond the page load that renders the Elements form
- NEVER call `Stripe::setApiKey()` globally — always `new StripeClient($account->secret_key)`
- NEVER trust `confirmPayment()` callback for DB writes — all status writes come from webhooks (Phase 6)
- NEVER accept amount from client request — always read from server-side `Payment` record

### Research
- `.planning/research/SUMMARY.md` — StripeService pattern, stack pitfalls

### Key Existing Files
- `routes/web.php` — Phase 5 stub at line 51: `Route::get('/pay/{uuid}', fn () => abort(404))->name('pay.show')` — this must be replaced with a real controller
- `app/Models/Payment.php` — `stripe_payment_intent_id` (fillable, nullable), `getRouteKeyName() = 'uuid'`, `status` field
- `app/Models/Brand.php` — `logo_path`, `primary_color`, `secondary_color` fields
- `app/Models/StripeAccount.php` — `publishable_key` (unencrypted, mass-assignable), `secret_key` (encrypted cast)
- `resources/js/pages/payments/Show.vue` — reference for how brand name, amount formatting (Intl.NumberFormat), and status display work in this codebase
- `package.json` — `vue-stripe-js` v2.0.2 and `@stripe/stripe-js` v9.4.0 already installed

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `resources/js/pages/admin/brands/Create.vue` — Card-based form pattern (CardHeader/CardContent/CardFooter, useForm()). The payment form within the client page will adapt this pattern.
- `resources/js/layouts/auth/AuthCardLayout.vue` — Closest existing layout to what's needed for the public payment page. Study its structure but do NOT reuse it — create a new `PaymentLayout.vue` instead (auth layouts carry Fortify/Inertia auth assumptions).
- `resources/js/pages/payments/Show.vue` — `formatAmount()` utility and `feeBreakdown` computed. The client payment page will need its own `formatAmount()` — can copy the pattern.
- `resources/js/components/ui/` — Card, Button, Badge, Input, Label all available via shadcn-vue.

### Established Patterns
- Vue 3 Composition API + `<script setup lang="ts">` — all new components
- `defineOptions({ layout: PaymentLayout })` — how to assign the new public layout (Inertia layout object pattern)
- `useForm()` from `@inertiajs/vue3` — for any form submissions (guard page has none; payment page does NOT use useForm for Stripe — Stripe Elements has its own submit flow)
- `Intl.NumberFormat` for currency formatting — already established in Show.vue

### Integration Points
- `routes/web.php` line 51 — the Phase 5 stub route must be replaced with a `ClientPaymentController` and three additional routes for success, failed, and unavailable pages
- `app/Models/Payment.php` `stripe_payment_intent_id` — controller must write this field after PI creation (before rendering the page)
- `StripeAccount.publishable_key` — passed as Inertia prop to client (safe — not encrypted); used to initialize `loadStripe()` on the client side
- `Brand` fields `logo_path`, `primary_color`, `secondary_color` — passed as props for layout theming

</code_context>

<specifics>
## Specific Ideas

- New controller: `app/Http/Controllers/ClientPaymentController.php` — public controller, no `auth` middleware. Methods: `show()`, `success()`, `failed()`.
- Route structure (replacing the Phase 5 stub):
  ```
  Route::get('/pay/{uuid}',          [ClientPaymentController::class, 'show'])->name('pay.show');
  Route::get('/pay/{uuid}/success',  [ClientPaymentController::class, 'success'])->name('pay.success');
  Route::get('/pay/{uuid}/failed',   [ClientPaymentController::class, 'failed'])->name('pay.failed');
  ```
- Controller `show()`: load Payment with brand and stripeAccount, check status → if not pending render `ClientPayment/Unavailable.vue`. If pending, create PaymentIntent via `new StripeClient($account->secret_key)->paymentIntents->create(...)`, store `stripe_payment_intent_id`, render `ClientPayment/Pay.vue` with `client_secret` in props.
- PaymentIntent `create()` params: `amount` from `$payment->amount` (integer cents), `currency` from `$payment->currency`, `automatic_payment_methods: ['enabled' => true]` (handles 3DS automatically).
- Stripe Elements initialization in Vue: `loadStripe(publishableKey)` → `stripe.elements({ clientSecret })` → mount `PaymentElement` to a div. Submit calls `stripe.confirmPayment({ elements, confirmParams: { return_url: route('pay.success', uuid) } })`.
- Brand colors CSS variable injection: `<div :style="{ '--brand-primary': brand.primary_color, '--brand-secondary': brand.secondary_color }">` on the PaymentLayout root.
- The `success()` controller reads `?redirect_status=succeeded` from Stripe's redirect. If not `succeeded`, redirect to `/pay/{uuid}/failed`.
- The `failed()` controller does NOT create a PaymentIntent — it just renders the failure page with brand props and a link back to `/pay/{uuid}`.
- **Design priority**: Use `/gsd-ui-phase 5` before planning to produce a UI-SPEC.md for the payment page, success page, and failure page. The user said this is "the life of this project" — treat it as a premium design deliverable, not a functional stub.

</specifics>

<deferred>
## Deferred Ideas

- **Email receipt on payment success** — Deferred to v2 (per-brand email sender identity unresolved — STATE.md blocker). Phase 7 handles admin notification only.
- **Payment cancellation by client** — Not in scope. Clients cannot cancel a payment link. Admin cancellation is a v2 feature.
- **Partial payments / installments** — Out of scope for this project entirely.
- **Multiple payment method types (SEPA, iDEAL, etc.)** — Using `automatic_payment_methods: enabled` covers this at the Stripe level, but the UI is designed for card input. If non-card methods appear, Stripe Elements handles them automatically.

</deferred>

---

*Phase: 05-client-payment-page*
*Context gathered: 2026-05-09*
