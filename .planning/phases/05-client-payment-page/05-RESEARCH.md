# Phase 5: Client Payment Page - Research

**Researched:** 2026-05-09
**Domain:** Stripe Elements (vue-stripe-js v2 + @stripe/stripe-js v9.4), Laravel public controller, Inertia v3 layout system, CSS custom property theming
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Controller creates PaymentIntent on page load using `new StripeClient($account->secret_key)` — no global `setApiKey()`. `client_secret` passed in Inertia props only.
- **D-02:** After creating the PaymentIntent, controller updates `payments.stripe_payment_intent_id` with the returned `pi_xxx` ID.
- **D-03:** Guard check runs before PI creation: if `payment->status !== 'pending'`, render guard view — no PI created.
- **D-04:** Success and failure are separate Inertia pages with routes `GET /pay/{uuid}/success` and `GET /pay/{uuid}/failed`.
- **D-05:** Failure page includes "Try again" link back to `/pay/{uuid}`.
- **D-06:** Success page shows: brand logo, "Payment received" heading, formatted amount, service name (if set). No package or note.
- **D-07:** Payment page shows: brand logo/name, amount, currency, service (if set), package (if set). `note` field NOT shown.
- **D-08:** Brand colors injected as CSS variables on layout root — `--brand-primary` and `--brand-secondary`. Forwarded to `--color-brand-primary`/`--color-brand-secondary` via `brand-theme.css [data-brand]` rule.
- **D-09:** New `PaymentLayout.vue` for the public payment experience — standalone, no sidebar, no auth nav. Shared by Pay, Success, Failed, Unavailable pages.
- **D-10:** Design quality is critical. UI-SPEC.md is the approved design contract.
- **D-11:** Single guard page handles all non-pending statuses with status-aware messages.
- **D-12:** Guard enforced server-side in controller; `ClientPayment/Unavailable.vue` rendered with status and brand props.

### Claude's Discretion

- Stripe Elements appearance object configuration (theme, colors — should reference `--brand-primary` CSS variable)
- Loading/processing state during `confirmPayment()` (spinner on submit button, disable form)
- Exact error message wording for card declines (use Stripe's `error.message` from `confirmPayment()` result)
- Mobile layout of the Elements form (single column on small screens)
- Whether to use `stripe.confirmPayment()` vs `stripe.confirmCardPayment()` — prefer `confirmPayment()` (handles all payment method types including 3DS automatically)

### Deferred Ideas (OUT OF SCOPE)

- Email receipt on payment success
- Payment cancellation by client
- Partial payments / installments
- Multiple payment method types — `automatic_payment_methods: enabled` covers Stripe side, UI is card-focused
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| CLIENT-01 | Client opens `/pay/{uuid}` without login | Public route outside `auth` middleware group; `Payment::getRouteKeyName()` returns `'uuid'` |
| CLIENT-02 | Payment page displays correct brand logo and colors | CSS custom property injection via `[data-brand]` attribute; `brand-theme.css` already wired; Brand model has `logo_path`, `primary_color`, `secondary_color` |
| CLIENT-03 | Stripe Elements form embedded inline (no redirect to stripe.com) | `StripeElements` + `StripeElement` (type="payment") from `vue-stripe-js` v2.0.2 mounts inline |
| CLIENT-04 | Stripe Elements initialized with correct brand's publishable key | `publishable_key` (unencrypted) passed as Inertia prop; `StripeElements :stripeKey="stripeAccount.publishable_key"` |
| CLIENT-05 | 3DS/SCA authentication challenges handled | `automatic_payment_methods: {enabled: true}` on PaymentIntent + `stripe.confirmPayment()` handles 3DS redirect automatically |
| CLIENT-06 | Client sees success page after payment confirmed | `GET /pay/{uuid}/success` controller reads `?redirect_status=succeeded` from Stripe redirect |
| CLIENT-07 | Client sees error/failure page if payment fails | `GET /pay/{uuid}/failed` renders `ClientPayment/Failed.vue`; `confirmPayment()` errors surface via `result.error.message` in Alert |
| CLIENT-08 | Payment page is mobile-responsive | `max-w-md` centered card, `min-h-svh`, `px-4` mobile padding, button `size="lg"`, Stripe Elements `layout: 'auto'` |
| SEC-04 | `client_secret` never logged, in URLs, or exposed beyond payment page response | `client_secret` only in Inertia props for the `pay.show` page; success route reads `redirect_status` not `client_secret`; no logging |
</phase_requirements>

---

## Summary

Phase 5 builds the public-facing payment experience: four Vue pages sharing a standalone `PaymentLayout.vue`, served by a single `ClientPaymentController` with three public routes. The backend work is a focused Laravel controller that resolves a Payment by UUID, checks status, creates a Stripe PaymentIntent using the brand's per-account `StripeClient`, stores the PI ID, and passes `client_secret` as an Inertia prop. The frontend work is the Stripe Elements integration using `vue-stripe-js` v2.0.2 with the `StripeElements` + `StripeElement` component pair, styled to match the brand's injected CSS custom properties.

The most technically nuanced area is the `app.ts` Inertia layout resolver — it currently assigns `AppLayout` (the admin sidebar) to any page not matching `auth/` or `settings/`. Pages under `ClientPayment/` will incorrectly receive the admin layout unless the resolver is extended with a `ClientPayment/` case returning the new `PaymentLayout`. This is the single most consequential wiring task in the phase.

The UI-SPEC.md is fully approved and prescriptive — every Tailwind class, state transition, and copy string is specified. The implementation is largely translation of the spec into code, not design decisions.

**Primary recommendation:** Build in wave order — (0) test stubs, (1) controller + routes, (2) PaymentLayout + app.ts wiring, (3) Pay.vue with Stripe Elements, (4) Success/Failed/Unavailable pages. The layout resolver fix in app.ts must land before any ClientPayment page is testable in the browser.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Payment page routing (public, no auth) | API/Backend (Laravel routes) | — | Routes must be outside `auth` middleware group; UUID resolution is server-side |
| PaymentIntent creation | API/Backend (Controller) | — | CLAUDE.md rule: amount always read server-side; `new StripeClient()` per account |
| Guard check (non-pending status) | API/Backend (Controller) | — | D-12: enforced server-side, never client-side |
| `client_secret` delivery | API/Backend (Inertia props) | — | SEC-04: prop only, never URL, never logged |
| Brand theming (CSS variables) | Frontend Server (SSR layout) | Browser (CSS var read) | Variables injected in Layout `:style` binding; Stripe appearance reads them at mount |
| Stripe Elements initialization | Browser / Client | — | `loadStripe()` + `StripeElements` mount is browser-only; no SSR |
| `confirmPayment()` + 3DS flow | Browser / Client | — | Stripe.js browser API; 3DS challenges render in Stripe-hosted iframe |
| Success/failure routing decision | API/Backend (Controller) | — | `success()` controller reads `?redirect_status` from Stripe query param |
| Payment status DB write | DEFERRED to Phase 6 | — | Webhook-only; Phase 5 does not write payment status |

---

## Standard Stack

### Core — Already Installed [VERIFIED: package.json, composer.json]

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `vue-stripe-js` | 2.0.2 | `StripeElements` + `StripeElement` Vue components | Already installed; purpose-built for this |
| `@stripe/stripe-js` | 9.4.0 | `loadStripe()` async loader; TypeScript types | Already installed; Stripe official async loader |
| `stripe/stripe-php` | 20.1.0 | Server-side PaymentIntent creation via `StripeClient` | Already installed; used in Phase 3 |
| `@inertiajs/vue3` | 3.x | `Inertia::render()` + `defineOptions({ layout })` + `<Head>` | Project standard |
| `lucide-vue-next` | 0.468.0 | `CheckCircle2`, `XCircle`, `Ban`, `LockIcon`, `AlertCircle` icons | Already installed |

### UI Components — Already Installed [VERIFIED: resources/js/components/ui/]

| Component | Status | Used By |
|-----------|--------|---------|
| `Card`, `CardHeader`, `CardContent`, `CardFooter`, `CardTitle`, `CardDescription` | Present | All four pages |
| `Button` | Present | Pay.vue submit, Failed.vue retry |
| `Badge` | Present | Currency display in Pay.vue |
| `Alert`, `AlertDescription` | Present (confirmed in ui/alert/) | Pay.vue error state |
| `Separator` | Present (confirmed in ui/separator/) | Pay.vue divider between summary and Elements |
| `Spinner` | Present | Elements loading skeleton + processing state |

**UI-SPEC.md stated Alert and Separator were missing — they are in fact already present. No `npx shadcn-vue add` commands needed for this phase.**

### No New Dependencies Required

All runtime dependencies for Phase 5 are already installed. No `npm install` or `composer require` steps needed.

---

## Architecture Patterns

### System Architecture Diagram

```
Browser (unauthenticated client)
    │
    │  GET /pay/{uuid}
    ▼
Laravel Router (public, no auth middleware)
    │
    │  Route::get('/pay/{uuid}', [ClientPaymentController::class, 'show'])
    │  Route::get('/pay/{uuid}/success', [ClientPaymentController::class, 'success'])
    │  Route::get('/pay/{uuid}/failed',  [ClientPaymentController::class, 'failed'])
    ▼
ClientPaymentController::show()
    │
    ├─ Load Payment by UUID (with brand + stripeAccount eager-loaded)
    │
    ├─ [Guard] payment->status !== 'pending'?
    │       YES → Inertia::render('ClientPayment/Unavailable', [status, brand])
    │       NO  → continue
    │
    ├─ new StripeClient($account->secret_key)->paymentIntents->create([
    │       amount   => $payment->amount,        ← integer cents from DB
    │       currency => $payment->currency,
    │       automatic_payment_methods => ['enabled' => true],
    │   ])
    │
    ├─ $payment->update(['stripe_payment_intent_id' => $pi->id])
    │
    └─ Inertia::render('ClientPayment/Pay', [
            payment        → uuid, amount, currency, service, package
            brand          → name, logo_url, primary_color, secondary_color, slug
            stripeAccount  → publishable_key only
            clientSecret   → $pi->client_secret        ← ONLY here, never in URL
        ])
            │
            ▼
    Vue: ClientPayment/Pay.vue (rendered in PaymentLayout)
            │
            ├─ CSS vars --brand-primary / --brand-secondary injected on layout root
            ├─ loadStripe(publishableKey) → stripe instance
            ├─ StripeElements (stripeKey, elementsOptions: { clientSecret, appearance })
            │       └─ StripeElement (type="payment") → PaymentElement mounted
            │
            └─ Submit → stripe.confirmPayment({
                    elements,
                    confirmParams: {
                        return_url: '/pay/{uuid}/success'   ← NO client_secret in URL
                    }
                })
                        │
                        ├─ 3DS required → Stripe opens hosted iframe (automatic)
                        │
                        ├─ Success → Stripe redirects to:
                        │   /pay/{uuid}/success?payment_intent=pi_xxx
                        │                      &payment_intent_client_secret=pi_xxx_secret_xxx
                        │                      &redirect_status=succeeded
                        │
                        └─ Failure → result.error → show Alert in Pay.vue
                                     OR Stripe redirect to return_url with redirect_status=failed
```

```
ClientPaymentController::success()
    │
    ├─ Load Payment by UUID (with brand)
    ├─ Read request('redirect_status')
    │       !== 'succeeded' → redirect to /pay/{uuid}/failed
    └─ Inertia::render('ClientPayment/Success', [payment, brand])

ClientPaymentController::failed()
    │
    ├─ Load Payment by UUID (with brand)
    └─ Inertia::render('ClientPayment/Failed', [payment, brand])
```

### Recommended Project Structure

```
app/Http/Controllers/
└── ClientPaymentController.php     # New — public controller, no auth

resources/js/
├── layouts/
│   └── PaymentLayout.vue           # New — standalone public layout
└── pages/
    └── ClientPayment/              # New directory
        ├── Pay.vue                 # Main payment form with Stripe Elements
        ├── Success.vue             # Post-payment success state
        ├── Failed.vue              # Failure state with retry link
        └── Unavailable.vue         # Guard page for non-pending payments

tests/Feature/
└── ClientPaymentTest.php           # New — covers CLIENT-01 through CLIENT-08 + SEC-04
```

### Pattern 1: Inertia Layout Resolver (CRITICAL — must update app.ts)

The existing `app.ts` layout resolver uses string prefix matching. Pages in `ClientPayment/` currently fall through to the `default` case and receive `AppLayout` (admin sidebar). This MUST be fixed.

**Existing resolver (routes/web.php and app.ts confirm pattern):**

```typescript
// resources/js/app.ts — MUST ADD this case
import PaymentLayout from '@/layouts/PaymentLayout.vue';

// In the layout switch:
case name.startsWith('ClientPayment/'):
    return PaymentLayout;
```

**Key insight:** `PaymentLayout` receives brand props — but the Inertia layout resolver receives the component name, not the props. The layout must accept props via slots/provide, OR the page component itself must handle passing brand props to the layout. The standard Inertia approach is: the page passes brand data as layout-level props by using a layout component that accepts `defineProps` and is passed data via the parent `<component>` in app.ts — but that is complex. The simpler pattern (used in this project already for auth) is: the layout resolver returns the layout class, and the page component renders the layout directly via `defineOptions({ layout: PaymentLayout })` or by wrapping content in the layout inside the template.

**Recommended approach for PaymentLayout:** Since `PaymentLayout` needs brand props (logo, colors, slug) that vary per payment, use the **persistent layout with props via provide/inject** or the simpler **page-wraps-layout** pattern where the page does NOT use `defineOptions({ layout: ... })` but instead imports `PaymentLayout` directly and wraps its content. However, to keep consistency with the Inertia resolver pattern:

The cleanest approach: Add `ClientPayment/` to the app.ts resolver returning `null` (no wrapper layout), and in each ClientPayment page, import `PaymentLayout` directly and use it as the root element of the template with brand props passed directly. This avoids the prop-threading problem entirely.

```typescript
// app.ts resolver addition
case name.startsWith('ClientPayment/'):
    return null;  // Each ClientPayment page manages its own layout
```

```vue
<!-- ClientPayment/Pay.vue — uses PaymentLayout directly -->
<script setup lang="ts">
import PaymentLayout from '@/layouts/PaymentLayout.vue'
const props = defineProps<{ payment, brand, stripeAccount, clientSecret }>()
</script>
<template>
  <PaymentLayout :brand="props.brand">
    <!-- card content -->
  </PaymentLayout>
</template>
```

**Alternative (simpler, identical result):** Use `defineOptions({ layout: PaymentLayout })` in each page, and have PaymentLayout read brand props from the Inertia shared data OR accept them as optional props with defaults. Given that brand varies per payment, the page-wraps-layout pattern (return null in resolver, import layout in page) is the cleaner approach since PaymentLayout needs real brand data.

### Pattern 2: StripeElements + StripeElement Usage (vue-stripe-js v2)

[VERIFIED: node_modules/vue-stripe-js/dist/vue-stripe.d.ts, vue-stripe.js]

The `StripeElements` component:
- `stripeKey: string` — the publishable key; calls `window.Stripe(stripeKey)` internally
- `elementsOptions: StripeElementsOptions` — pass `{ clientSecret, appearance }` here
- Exposes `elements` and `instance` via template slot
- The slot renders only when `elementsUsable` is true (elements object has keys)

The `StripeElement` component:
- `type: StripeElementType` — use `'payment'` for the PaymentElement
- `elements: StripeElements_2` — pass from the `StripeElements` slot
- Exposes `stripeElement` ref via `expose`

**Critical finding:** `vue-stripe-js` v2 uses `window.Stripe()` (not `loadStripe` from `@stripe/stripe-js`). The Stripe.js CDN script is NOT loaded in `app.blade.php`. The project must either:

1. Add `<script src="https://js.stripe.com/v3/" async>` to `app.blade.php` (easy but loads on all pages), OR
2. Use `loadStripe` from `@stripe/stripe-js` in the Pay.vue `onMounted`, which sets `window.Stripe` when resolved, then initialize `StripeElements` only after that resolves.

**Recommended (approach 2):** Call `loadStripe(publishableKey)` in Pay.vue's `onMounted`. The `StripeElements` component can then be conditionally rendered after the Stripe instance is ready. This approach avoids loading the Stripe script on admin pages.

```typescript
// Pay.vue
import { loadStripe } from '@stripe/stripe-js'
import { StripeElements, StripeElement } from 'vue-stripe-js'
import { ref, onMounted } from 'vue'

const stripeLoaded = ref(false)

onMounted(async () => {
    await loadStripe(props.stripeAccount.publishable_key)
    stripeLoaded.value = true
})
```

Then conditionally render:
```vue
<template v-if="stripeLoaded">
  <StripeElements
    :stripe-key="stripeAccount.publishable_key"
    :elements-options="elementsOptions"
  >
    <template #default="{ instance, elements }">
      <StripeElement type="payment" :elements="elements" @ready="onReady" />
    </template>
  </StripeElements>
</template>
<template v-else>
  <!-- Spinner skeleton (loading state from UI-SPEC.md) -->
</template>
```

**`confirmPayment()` call:** Must use the `instance` from the `StripeElements` slot, not a global:

```typescript
async function handleSubmit(instance: Stripe, elements: StripeElements) {
    processing.value = true
    errorMessage.value = null
    
    const { error } = await instance.confirmPayment({
        elements,
        confirmParams: {
            return_url: `${window.location.origin}/pay/${props.payment.uuid}/success`
        }
    })
    
    if (error) {
        errorMessage.value = error.message ?? 'An unexpected error occurred. Please try again.'
        processing.value = false
    }
    // No else — Stripe redirects on success
}
```

### Pattern 3: CSS Custom Property → Stripe Elements Appearance

[VERIFIED: resources/css/brand-theme.css — already implemented]

The `brand-theme.css` file already has the `[data-brand]` rule that forwards `--brand-primary` to `--color-brand-primary`. The layout root sets `data-brand` + `:style` binding.

The Stripe Elements appearance object must read `--brand-primary` at mount time (not hardcoded) because each payment page has a different brand:

```typescript
// Read at mount time — CSS vars are live once the layout root renders
const brandPrimary = computed(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--brand-primary').trim()
    || '#000000'
)

const elementsOptions = computed(() => ({
    clientSecret: props.clientSecret,
    appearance: {
        theme: 'stripe',
        variables: {
            colorPrimary: brandPrimary.value,
            colorBackground: '#ffffff',
            colorText: 'hsl(0, 0%, 3.9%)',
            colorDanger: 'hsl(0, 84.2%, 60.2%)',
            fontFamily: '"Instrument Sans", ui-sans-serif, system-ui, sans-serif',
            borderRadius: '6px',
            spacingUnit: '4px',
        },
        rules: {
            '.Input': { border: '1px solid hsl(0, 0%, 92.8%)', boxShadow: 'none' },
            '.Input:focus': {
                border: '1px solid var(--brand-primary)',
                boxShadow: '0 0 0 3px color-mix(in srgb, var(--brand-primary) 20%, transparent)',
            },
            '.Label': { fontSize: '14px', fontWeight: '400', color: 'hsl(0, 0%, 3.9%)' },
            '.Error': { fontSize: '14px' },
        },
    },
}))
```

**Timing trap:** `getComputedStyle` must be called AFTER the layout root (with `data-brand` + `:style`) has been mounted to the DOM. This is guaranteed when called inside a `computed` referenced after `onMounted`, or in `onMounted` after `await nextTick()`.

### Pattern 4: PaymentIntent Server-Side Creation

[VERIFIED: composer.json shows stripe/stripe-php v20.1.0, StripeAccountController.php shows established pattern]

```php
// ClientPaymentController::show()
use Stripe\StripeClient;

$payment->loadMissing(['brand', 'stripeAccount']);

// Guard check first — D-03
if ($payment->status !== 'pending') {
    return Inertia::render('ClientPayment/Unavailable', [
        'status' => $payment->status,
        'brand'  => [
            'name'            => $payment->brand->name,
            'slug'            => $payment->brand->slug,
            'logo_url'        => $payment->brand->logo_path
                                    ? '/storage/' . $payment->brand->logo_path
                                    : null,
            'primary_color'   => $payment->brand->primary_color,
            'secondary_color' => $payment->brand->secondary_color,
        ],
    ]);
}

// Create PaymentIntent — NEVER global setApiKey()
$stripe = new StripeClient($payment->stripeAccount->secret_key);
$pi     = $stripe->paymentIntents->create([
    'amount'                   => $payment->amount,    // already integer cents
    'currency'                 => $payment->currency,  // 'usd' or 'gbp'
    'automatic_payment_methods' => ['enabled' => true],
]);

// Store PI ID for Phase 6 webhook lookup — D-02
$payment->update(['stripe_payment_intent_id' => $pi->id]);

return Inertia::render('ClientPayment/Pay', [
    'payment' => [
        'uuid'     => $payment->uuid,
        'amount'   => $payment->amount,
        'currency' => $payment->currency,
        'service'  => $payment->service,
        'package'  => $payment->package,
        // client_name, client_email, note — NOT passed (D-07)
    ],
    'brand' => [
        'name'            => $payment->brand->name,
        'slug'            => $payment->brand->slug,
        'logo_url'        => $payment->brand->logo_path
                                ? '/storage/' . $payment->brand->logo_path
                                : null,
        'primary_color'   => $payment->brand->primary_color,
        'secondary_color' => $payment->brand->secondary_color,
    ],
    'stripeAccount' => [
        'publishable_key' => $payment->stripeAccount->publishable_key,
        // secret_key: NEVER passed to frontend
    ],
    'clientSecret' => $pi->client_secret,  // SEC-04: prop only, never URL
]);
```

### Pattern 5: Route Model Binding by UUID

[VERIFIED: app/Models/Payment.php — `getRouteKeyName()` returns `'uuid'`]

Laravel's route model binding uses `uuid` as the key. Route definition uses the `{uuid}` wildcard:

```php
Route::get('/pay/{uuid}', [ClientPaymentController::class, 'show'])->name('pay.show');
```

The controller type-hints `Payment $payment` but Laravel binds by `uuid` column automatically because `getRouteKeyName()` is defined. No manual `Payment::where('uuid', ...)` needed.

### Anti-Patterns to Avoid

- **Using `defineOptions({ layout: AppLayout })` on ClientPayment pages:** The default resolver already sends authenticated pages to AppLayout. ClientPayment pages must bypass this via the resolver returning `null` + importing PaymentLayout directly.
- **Calling `Stripe::setApiKey()` globally:** Project rule from CLAUDE.md. Always `new StripeClient($account->secret_key)`.
- **Writing payment status from `confirmPayment()` callback:** CLAUDE.md rule. Phase 5 NEVER writes status; that is Phase 6 (webhooks).
- **Accepting `amount` from the client request:** CLAUDE.md rule. Amount always read from `$payment->amount` server-side.
- **Storing or logging `client_secret`:** SEC-04. It lives only as an Inertia prop for the page render — not in session, not in URL, not in logs.
- **Hardcoding brand color in Stripe appearance object:** Must be read via `getComputedStyle` at mount time — see timing note above.
- **Reading `--brand-primary` before layout mounts:** `getComputedStyle` returns empty string if called in `setup()` before DOM mount. Must be called in `onMounted` or a `computed` that runs after mount.
- **Re-creating PaymentIntent on page reload:** If the user refreshes `/pay/{uuid}`, the controller creates a NEW PaymentIntent. This is acceptable for Phase 5 — the old orphaned PI is harmless. (Idempotency key optimization is a v2 concern.)

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| 3DS / SCA challenge | Custom redirect flow | `confirmPayment()` with `automatic_payment_methods` | Stripe handles all 3DS types (redirect, iframe) automatically |
| Card form validation | Custom regex input validation | Stripe Elements `PaymentElement` | PCI compliance; Stripe handles card number, expiry, CVV validation |
| Stripe.js loading | Manual CDN `<script>` management | `loadStripe()` from `@stripe/stripe-js` | Handles async load, sets `window.Stripe`, prevents double-load |
| Currency formatting | Custom formatter | `Intl.NumberFormat` (already used in Show.vue) | Handles locale, currency symbol, decimal precision correctly |
| Non-pending page logic | Client-side route guard | Server-side controller guard before PI creation | Guards at API boundary are the only reliable enforcement |

**Key insight:** Stripe Elements is a PCI-compliant iframe. Any custom card input field would require PCI SAQ D compliance (most burdensome tier). Using Elements keeps the project at SAQ A (least burdensome).

---

## Common Pitfalls

### Pitfall 1: `window.Stripe` Not Available at StripeElements Mount

**What goes wrong:** `vue-stripe-js` calls `window.Stripe(key)` in `onMounted`. If Stripe.js hasn't loaded, `window.Stripe` is undefined — the Elements object is never created, the slot never renders.

**Why it happens:** There's no Stripe CDN script in `app.blade.php`. The `@stripe/stripe-js` package provides `loadStripe()` which loads the CDN dynamically, but it must be called before `StripeElements` mounts.

**How to avoid:** In `Pay.vue`, call `await loadStripe(publishableKey)` in `onMounted` and set a `stripeLoaded` ref to `true` only after it resolves. Conditionally render `<StripeElements>` only when `stripeLoaded` is true. This ensures `window.Stripe` exists when `StripeElements` mounts.

**Warning signs:** Elements loading skeleton never disappears; `StripeElements` slot never renders; console shows "Stripe is not loaded."

### Pitfall 2: Inertia Layout Resolver Sends ClientPayment Pages to Admin Layout

**What goes wrong:** `app.ts` resolver falls through to `default: return AppLayout` for `ClientPayment/Pay.vue`. The payment page renders inside the admin sidebar layout — authenticated users see it with nav; unauthenticated users get a broken page or redirect.

**Why it happens:** The resolver switch has no case for `ClientPayment/`.

**How to avoid:** Add `case name.startsWith('ClientPayment/'): return null;` to the resolver AND import `PaymentLayout` directly in each ClientPayment page, using it as the template root.

**Warning signs:** `/pay/{uuid}` in browser shows admin sidebar; or unauthenticated access redirects to login.

### Pitfall 3: `client_secret` Appearing in Stripe's `return_url` Redirect

**What goes wrong:** Stripe appends `payment_intent_client_secret` as a query parameter to the `return_url` on redirect. If the success controller logs `request()->all()` or stores the full query string, the `client_secret` is persisted in violation of SEC-04.

**Why it happens:** Stripe's redirect is standard OAuth-style — it adds params to the URL. The param is present in the browser address bar and in the server's request.

**How to avoid:** The `success()` controller ONLY reads `request('redirect_status')`. It does NOT log the request, does NOT store query params. The `client_secret` query param is discarded. Never call `Log::info(request()->all())` in the success controller.

**Warning signs:** Laravel logs showing `payment_intent_client_secret` values.

### Pitfall 4: `getComputedStyle('--brand-primary')` Returns Empty String

**What goes wrong:** The Stripe Elements appearance object gets `colorPrimary: ''` (empty), which causes Elements to fall back to its default blue color regardless of brand.

**Why it happens:** `getComputedStyle` is called before the layout root element (with `data-brand` + `:style` binding) has been mounted. In `setup()` or in a `computed` that runs before mount, the CSS variable isn't yet in scope.

**How to avoid:** Read the CSS variable in `onMounted` (or after `await nextTick()` in a watcher). Pass the resolved value to the `elementsOptions` computed. Always provide a non-empty fallback (`|| '#000000'`).

**Warning signs:** Stripe Elements shows default blue; button shows brand color but Elements form doesn't.

### Pitfall 5: PaymentIntent Created Multiple Times for Same Payment

**What goes wrong:** Each page load of `/pay/{uuid}` creates a new PaymentIntent. If a user opens the link in multiple tabs or refreshes, multiple PIs are created, and `stripe_payment_intent_id` is overwritten with the latest.

**Why it happens:** Phase 5 creates PI on every `show()` call without checking if one already exists.

**How to avoid (within Phase 5 scope):** Check `$payment->stripe_payment_intent_id` — if it already exists, retrieve it from Stripe and reuse it rather than creating a new one. This is the idempotent pattern. However, if this adds complexity, the simpler Phase 5 approach of always creating a new PI is acceptable — orphaned PIs don't charge the customer and expire after 24 hours. Phase 5 CONTEXT.md does not mandate idempotency, so always-create is acceptable for v1.

**Warning signs:** Stripe Dashboard shows many uncompleted PIs per payment link.

### Pitfall 6: `success()` Controller Renders Without Verifying `redirect_status`

**What goes wrong:** Client manually navigates to `/pay/{uuid}/success` (without Stripe redirect). The page renders "Payment received" even though no payment was made.

**Why it happens:** Controller doesn't check `redirect_status=succeeded` before rendering.

**How to avoid:** `success()` controller reads `request('redirect_status')`. If not `'succeeded'`, redirect to `/pay/{uuid}/failed`. This is documented in D-04 and the CONTEXT.md specifics.

**Warning signs:** Success page accessible via direct URL navigation.

---

## Code Examples

### ClientPaymentController::show() — Server-side skeleton

```php
// Source: CONTEXT.md specifics + StripeAccountController.php established pattern
use Stripe\StripeClient;

public function show(Payment $payment): Response|RedirectResponse
{
    $payment->loadMissing(['brand', 'stripeAccount']);

    if ($payment->status !== 'pending') {
        return Inertia::render('ClientPayment/Unavailable', [
            'status' => $payment->status,
            'brand'  => $this->brandProps($payment->brand),
        ]);
    }

    $stripe = new StripeClient($payment->stripeAccount->secret_key);
    $pi     = $stripe->paymentIntents->create([
        'amount'                    => $payment->amount,
        'currency'                  => $payment->currency,
        'automatic_payment_methods' => ['enabled' => true],
    ]);

    $payment->update(['stripe_payment_intent_id' => $pi->id]);

    return Inertia::render('ClientPayment/Pay', [
        'payment'       => $this->paymentProps($payment),
        'brand'         => $this->brandProps($payment->brand),
        'stripeAccount' => ['publishable_key' => $payment->stripeAccount->publishable_key],
        'clientSecret'  => $pi->client_secret,
    ]);
}

private function brandProps(Brand $brand): array
{
    return [
        'name'            => $brand->name,
        'slug'            => $brand->slug,
        'logo_url'        => $brand->logo_path ? '/storage/' . $brand->logo_path : null,
        'primary_color'   => $brand->primary_color,
        'secondary_color' => $brand->secondary_color,
    ];
}

private function paymentProps(Payment $payment): array
{
    return [
        'uuid'     => $payment->uuid,
        'amount'   => $payment->amount,
        'currency' => $payment->currency,
        'service'  => $payment->service,
        'package'  => $payment->package,
    ];
}
```

### Pay.vue — Stripe Elements initialization (key pattern)

```typescript
// Source: vue-stripe-js v2 type definitions + @stripe/stripe-js API
import { StripeElements, StripeElement } from 'vue-stripe-js'
import { loadStripe } from '@stripe/stripe-js'
import { ref, computed, onMounted } from 'vue'

const props = defineProps<{
    payment: { uuid: string; amount: number; currency: string; service: string | null; package: string | null }
    brand:   { name: string; slug: string; logo_url: string | null; primary_color: string; secondary_color: string }
    stripeAccount: { publishable_key: string }
    clientSecret: string
}>()

const stripeLoaded  = ref(false)
const processing    = ref(false)
const errorMessage  = ref<string | null>(null)
const elementsReady = ref(false)

onMounted(async () => {
    await loadStripe(props.stripeAccount.publishable_key)
    stripeLoaded.value = true
})

const brandPrimary = computed(() =>
    getComputedStyle(document.documentElement).getPropertyValue('--brand-primary').trim()
    || props.brand.primary_color
    || '#000000'
)

const elementsOptions = computed(() => ({
    clientSecret: props.clientSecret,
    appearance: { /* ...from UI-SPEC.md... */ },
}))

async function submit(instance: any, elements: any) {
    processing.value = true
    errorMessage.value = null

    const { error } = await instance.confirmPayment({
        elements,
        confirmParams: {
            return_url: `${window.location.origin}/pay/${props.payment.uuid}/success`,
        },
    })

    if (error) {
        errorMessage.value = error.message ?? 'An unexpected error occurred. Please try again.'
        processing.value = false
    }
    // No else — Stripe redirects on success; processing stays true during redirect
}
```

### app.ts layout resolver addition

```typescript
// Source: VERIFIED — resources/js/app.ts current state + Inertia v3 resolver pattern
import PaymentLayout from '@/layouts/PaymentLayout.vue';

// Add to switch:
case name.startsWith('ClientPayment/'):
    return null;  // Pages import PaymentLayout directly and pass brand props
```

### success() controller — redirect_status check

```php
// Source: CONTEXT.md D-04, specifics section
public function success(Payment $payment): Response|RedirectResponse
{
    if (request('redirect_status') !== 'succeeded') {
        return redirect()->route('pay.failed', $payment->uuid);
    }

    $payment->loadMissing('brand');

    return Inertia::render('ClientPayment/Success', [
        'payment' => [
            'amount'   => $payment->amount,
            'currency' => $payment->currency,
            'service'  => $payment->service,
        ],
        'brand' => $this->brandProps($payment->brand),
    ]);
}
```

### PaymentLayout.vue — structure

```vue
<!-- Source: UI-SPEC.md — prescriptive Tailwind classes -->
<script setup lang="ts">
import { LockIcon } from 'lucide-vue-next'

const props = defineProps<{
    brand: {
        name: string
        slug: string
        logo_url: string | null
        primary_color: string
        secondary_color: string
    }
}>()
</script>

<template>
    <div
        :data-brand="brand.slug"
        :style="`--brand-primary: ${brand.primary_color}; --brand-secondary: ${brand.secondary_color}`"
        class="min-h-svh flex flex-col items-center justify-center bg-muted/40 px-4 py-10"
    >
        <!-- Logo area -->
        <div class="mb-8 flex flex-col items-center gap-3">
            <img
                v-if="brand.logo_url"
                :src="brand.logo_url"
                :alt="brand.name"
                class="h-10 max-w-[180px] w-auto object-contain"
            />
            <span v-else class="font-semibold text-lg">{{ brand.name }}</span>
        </div>

        <!-- Card slot -->
        <div class="w-full max-w-md">
            <slot />
        </div>

        <!-- Footer -->
        <p class="mt-8 text-xs text-muted-foreground flex items-center gap-2">
            <LockIcon class="size-3" />
            Secured by Stripe
        </p>
    </div>
</template>
```

---

## State of the Art

| Old Approach | Current Approach | Impact |
|--------------|------------------|--------|
| `stripe.confirmCardPayment()` | `stripe.confirmPayment()` | Handles all payment methods (3DS, wallets, redirects) automatically — use the newer API |
| Manual 3DS handling (`handleCardAction`) | `automatic_payment_methods: {enabled: true}` + `confirmPayment()` | Stripe handles all 3DS flows automatically |
| Separate card element inputs (cardNumber, cardExpiry, cardCvc) | Single `PaymentElement` (type="payment") | Stripe auto-selects payment method, handles country-specific fields |
| Global `Stripe.setApiKey()` | Per-request `new StripeClient($secretKey)` | Required for multi-account — prevents cross-account contamination |

**Not applicable to this phase:**
- The `vue-stripe-js` library v2 is current — no migration needed.
- `@stripe/stripe-js` v9.4 is current — no migration needed.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `loadStripe()` from `@stripe/stripe-js` sets `window.Stripe` which `vue-stripe-js` then uses | Standard Stack, Pitfall 1 | If `vue-stripe-js` v2 does NOT use `window.Stripe` and requires a different init, the Elements won't render. Verified via source code inspection of `vue-stripe.js` line `R = (t, e) => { if (!window.Stripe) throw...` — confirmed `window.Stripe` is used. HIGH confidence. |
| A2 | Stripe appends `payment_intent_client_secret` to the `return_url` on redirect (not just `redirect_status`) | Pitfall 3 | This is Stripe standard behavior. LOW impact — the `success()` controller only reads `redirect_status` regardless. |
| A3 | Idempotency of PaymentIntent creation (same payment, multiple page loads) is out of scope for Phase 5 | Pitfall 5 | If the user's experience is poor due to duplicate PIs, this becomes Phase 5 scope. But CONTEXT.md does not mandate it. |

---

## Open Questions (RESOLVED)

1. **Do ClientPayment pages use `return null` in app.ts resolver or `return PaymentLayout`?**
   - What we know: `PaymentLayout` needs brand props that vary per payment, not shared-data props.
   - What's unclear: Whether Inertia v3 allows a layout to receive props from the page component when specified in the resolver.
   - Recommendation: Use `return null` in resolver + import PaymentLayout directly in each page template. This is the established pattern in the codebase for layouts needing per-page data (see how auth pages use AuthCardLayout with props via `defineOptions({ layout: { title, description } })`). However, the `defineOptions` approach passes a plain object (not a component with props) — so brand data can't flow that way. **Resolve with: import PaymentLayout in each page, pass brand as prop in template.**

2. **Should `stripe_payment_intent_id` be checked before creating a new PI to avoid duplicates?**
   - What we know: Phase 5 CONTEXT.md does not require idempotency.
   - What's unclear: Business tolerance for orphaned PIs in Stripe Dashboard.
   - Recommendation: Check existing `stripe_payment_intent_id` — if present, retrieve it via `$stripe->paymentIntents->retrieve($id)` and reuse the `client_secret`. Add a `catch` in case the PI was cancelled. This small addition prevents Stripe Dashboard clutter and is the correct production behavior.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| `vue-stripe-js` | Stripe Elements form | Available | 2.0.2 | — |
| `@stripe/stripe-js` | `loadStripe()` async loader | Available | 9.4.0 | — |
| `stripe/stripe-php` | Server-side PaymentIntent creation | Available | 20.1.0 | — |
| `lucide-vue-next` | Icons (CheckCircle2, XCircle, Ban, LockIcon, AlertCircle) | Available | 0.468.0 | — |
| All shadcn-vue components needed | Card, Button, Badge, Alert, Separator, Spinner | Available (confirmed in ui/ directory) | 2.6 | — |
| Stripe API (test mode) | Manual testing | Available (test keys in dev seed) | — | Use Stripe CLI test mode |

**Missing dependencies with no fallback:** None — all dependencies installed.

**Missing dependencies with fallback:** None.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest PHP 4.6 with `pest-plugin-laravel` |
| Config file | `tests/Pest.php` — `uses(Tests\TestCase::class)->in('Feature', 'Unit')` |
| Quick run command | `php artisan test --filter ClientPayment` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| CLIENT-01 | `/pay/{uuid}` returns 200 for guest (no auth redirect) | Feature (HTTP) | `php artisan test --filter "guest can view pay route"` | Wave 0 |
| CLIENT-01 | `/pay/{uuid}` returns 404 for unknown UUID | Feature (HTTP) | `php artisan test --filter "unknown uuid returns 404"` | Wave 0 |
| CLIENT-02 | Brand colors and logo_url passed as Inertia props | Feature (HTTP) | `php artisan test --filter "brand props passed to Pay page"` | Wave 0 |
| CLIENT-03 | `clientSecret` present in Inertia props for pending payment | Feature (HTTP) | `php artisan test --filter "client secret passed as prop"` | Wave 0 |
| CLIENT-04 | `publishable_key` present in Inertia props (not `secret_key`) | Feature (HTTP) | `php artisan test --filter "publishable key in props, not secret"` | Wave 0 |
| CLIENT-05 | 3DS handled by `confirmPayment()` — no custom flow | Manual (Stripe test card 4000002500003155) | — | Manual only |
| CLIENT-06 | `/pay/{uuid}/success?redirect_status=succeeded` returns 200 | Feature (HTTP) | `php artisan test --filter "success page renders when redirect_status succeeded"` | Wave 0 |
| CLIENT-06 | `/pay/{uuid}/success?redirect_status=failed` redirects to failed | Feature (HTTP) | `php artisan test --filter "success redirects when redirect_status not succeeded"` | Wave 0 |
| CLIENT-07 | `/pay/{uuid}/failed` returns 200 with brand props | Feature (HTTP) | `php artisan test --filter "failed page renders"` | Wave 0 |
| CLIENT-08 | Mobile layout — Tailwind classes present | Visual/Manual | — | Manual only |
| SEC-04 | `clientSecret` not in URL of any route | Feature (HTTP) | `php artisan test --filter "client secret not in url"` | Wave 0 |
| D-03 | Non-pending payment renders Unavailable (not Pay) | Feature (HTTP) | `php artisan test --filter "completed payment shows unavailable"` | Wave 0 |
| D-02 | `stripe_payment_intent_id` updated on page load | Feature (HTTP, Mocked Stripe) | `php artisan test --filter "payment intent id stored after show"` | Wave 0 |

**Note on Stripe mocking:** Tests that exercise `new StripeClient()->paymentIntents->create()` must mock the Stripe API. The recommended approach is to mock the `StripeClient` class using Mockery (already in dev dependencies) or use HTTP faking. This is essential — tests cannot call real Stripe API in CI.

### Stripe Mocking Pattern for Tests

```php
// tests/Feature/ClientPaymentTest.php — established Mockery pattern
use Mockery;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

beforeEach(function () {
    // Mock StripeClient before each test that exercises show()
    $mockPi      = new PaymentIntent();
    $mockPi->id  = 'pi_test_mock123';
    $mockPi->client_secret = 'pi_test_mock123_secret_xyz';

    $mockService = Mockery::mock(StripeClient::class);
    $mockService->paymentIntents = Mockery::mock();
    $mockService->paymentIntents->shouldReceive('create')
        ->andReturn($mockPi);

    $this->app->bind(StripeClient::class, fn() => $mockService);
});
```

**Alternative:** Laravel `Http::fake()` doesn't cover Stripe PHP SDK calls. Use Mockery or a `StripeService` wrapper (already discussed in research SUMMARY.md). For Phase 5, inline Mockery in tests is acceptable since no StripeService class exists yet.

### Sampling Rate

- **Per task commit:** `php artisan test --filter ClientPayment`
- **Per wave merge:** `php artisan test`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps

- [ ] `tests/Feature/ClientPaymentTest.php` — covers all CLIENT-* + SEC-04 + D-02/D-03 above
- [ ] Mockery-based `StripeClient` stub pattern for test isolation

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | Public route — no auth required by design |
| V3 Session Management | No | No session created for payment clients |
| V4 Access Control | Yes | Guard check server-side (D-12) — non-pending payments show Unavailable, not the form |
| V5 Input Validation | Yes | No user-supplied amount accepted; UUID resolved via model binding |
| V6 Cryptography | Yes | `secret_key` read via Laravel `encrypted` cast; `client_secret` not logged or stored |

### Known Threat Patterns for This Phase

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Client supplies custom amount | Tampering | Amount always read from server-side `$payment->amount`; no amount field in Pay.vue |
| `client_secret` leaked via logs | Information Disclosure | Never call `Log::*` in show() after PI creation; `client_secret` not stored; Inertia prop only |
| Direct navigation to `/pay/{uuid}/success` | Spoofing | `success()` controller verifies `redirect_status=succeeded`; redirect to failed if absent |
| UUID enumeration | Information Disclosure | UUID v4 has 2^122 possible values — not practically enumerable. No additional protection needed for v1. |
| Cross-account key contamination | Tampering | Per-request `new StripeClient($account->secret_key)` — never global `setApiKey()` |
| PaymentIntent amount tampering | Tampering | Amount comes from DB record, not request body |

---

## Project Constraints (from CLAUDE.md)

All the following CLAUDE.md directives apply to Phase 5 and must be enforced in every plan and implementation task:

| Directive | Phase 5 Implication |
|-----------|---------------------|
| NEVER call `Stripe::setApiKey()` globally | `ClientPaymentController::show()` uses `new StripeClient($account->secret_key)` |
| NEVER trust `confirmPayment()` for DB writes | Phase 5 does NOT write payment status; all status writes are Phase 6 webhooks |
| NEVER accept amount from client request | `amount` is read from `$payment->amount` only; no amount field on Pay.vue |
| `secret_key` and `webhook_secret` use Laravel `encrypted` cast | Already on StripeAccount model; controller reads via `$account->secret_key` (auto-decrypted) |
| Webhook routes excluded from CSRF | Not applicable to Phase 5 (webhook routes are Phase 6) |
| `PaymentIntent client_secret` never logged, stored in URLs, or exposed beyond page load | `client_secret` only in Inertia prop for `ClientPayment/Pay` render |
| Amounts always integer cents | `$payment->amount` is already cast to integer; passed as integer to PaymentIntent `create()` |
| Currency: USD and GBP only | `$payment->currency` validated at payment creation (Phase 4); passed through as-is |
| Composition API + `<script setup lang="ts">` for all new Vue components | All four ClientPayment pages + PaymentLayout must use this |

---

## Sources

### Primary (HIGH confidence)
- `node_modules/vue-stripe-js/dist/vue-stripe.d.ts` — TypeScript types for StripeElements, StripeElement, createElement, createElements, initStripe
- `node_modules/vue-stripe-js/dist/vue-stripe.js` — Source implementation confirming `window.Stripe` dependency and element mount lifecycle
- `resources/css/brand-theme.css` — Confirmed `[data-brand]` CSS variable forwarding already implemented
- `resources/css/app.css` — Confirmed CSS variable definitions, font, Tailwind 4 setup
- `resources/js/app.ts` — Confirmed layout resolver structure; ClientPayment/ case must be added
- `app/Models/Payment.php` — Confirmed `getRouteKeyName()`, `stripe_payment_intent_id` fillable, `amount` integer cast
- `app/Models/Brand.php` — Confirmed `logo_path`, `primary_color`, `secondary_color`, `slug` fields
- `app/Models/StripeAccount.php` — Confirmed `publishable_key` unencrypted, `secret_key` encrypted cast
- `app/Http/Controllers/Admin/StripeAccountController.php` — Confirmed established `new StripeClient($secretKey)` pattern
- `app/Http/Controllers/PaymentController.php` — Confirmed `Inertia::render()` prop shaping pattern
- `package.json` — Confirmed vue-stripe-js 2.0.2, @stripe/stripe-js 9.4.0, lucide-vue-next 0.468.0
- `composer.json` — Confirmed stripe/stripe-php 20.1.0

### Secondary (MEDIUM confidence)
- `resources/js/pages/payments/Show.vue` — `formatAmount()` via `Intl.NumberFormat` pattern; confirmed working in codebase
- `resources/js/layouts/auth/AuthCardLayout.vue` — Layout structure reference; confirmed NOT suitable to extend
- `.planning/phases/05-client-payment-page/05-UI-SPEC.md` — Approved design contract; all Tailwind classes, copy, and component structure are authoritative

### Tertiary (LOW confidence)
- None — all findings verified against installed source code or project files.

---

## Metadata

**Confidence breakdown:**
- Standard Stack: HIGH — all packages confirmed via node_modules and composer.json
- Architecture: HIGH — controller pattern established in Phase 3/4; layout resolver verified in app.ts
- Stripe Integration: HIGH — vue-stripe-js v2 source code read directly; API confirmed via .d.ts
- Pitfalls: HIGH — `window.Stripe` requirement confirmed in source; layout resolver gap confirmed in app.ts
- Testing: MEDIUM — mocking approach is standard Mockery; specific Stripe mock pattern is project convention extrapolated from Phase 2/4 test patterns

**Research date:** 2026-05-09
**Valid until:** 2026-06-09 (packages are pinned in package.json/composer.json; Stripe API is stable)
