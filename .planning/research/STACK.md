# Technology Stack

**Project:** PayHub — Multi-Brand Centralized Payment Infrastructure
**Researched:** 2026-05-02
**Overall confidence:** HIGH (all core decisions verified against official docs or Packagist)

---

## Recommended Stack

### Core Framework (PHP)

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| PHP | 8.3 | Runtime | Laravel 13 requires 8.2+. PHP 8.3 is the recommended production target: broad hosting support, named arguments, typed class constants, readonly improvements. PHP 8.4 is supported but ecosystem package adoption lags slightly. |
| Laravel | 13.x | Application framework | Released 2025-03-17. Near-zero breaking changes from L12. Requires PHP 8.2+. Officially ships a Vue 3 + Inertia.js + shadcn-vue + Tailwind 4 starter kit. Carbon 3.x and UUID v7 by default. |
| laravel/fortify | ^1.31 | Authentication backend | Frontend-agnostic auth backend. Provides login, password reset, email verification, 2FA. Disable `Features::registration()` in `config/fortify.php` to block public registration; implement invite-only via a separate `invitations` table + signed token. Do NOT use Laravel Breeze or Jetstream — they are no longer receiving updates as of L13. |

**Confidence:** HIGH — verified via laravel.com/docs/13.x/releases and Packagist.

---

### Frontend Framework

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Vue | 3.x (Composition API) | UI layer | Required by Inertia Vue adapter. Use Composition API + `<script setup>` exclusively — Options API is a dead end for new code. |
| @inertiajs/vue3 | ^3.0 | Client-side Inertia adapter | v2 is current; v1 Vue 2 adapter removed. Ships partial reloads, deferred props, prefetching, SSR. Install as `@inertiajs/vue3`. |
| inertiajs/inertia-laravel | ^3.0 | Server-side Inertia adapter | Composer package that handles response wrapping, shared data, and middleware. |
| Vite | bundled with Laravel | Asset pipeline | Laravel 13 ships `vite.config.js` by default. Use `laravel/vite-plugin` (already in laravel/laravel). Do NOT use Webpack/Mix. |

**Confidence:** HIGH — @inertiajs/vue3 v2.3.14 confirmed on npm; Laravel 13 Vue starter kit uses Inertia v3.

**Key Inertia patterns for this project:**
- Use `Inertia::share()` in a middleware (e.g. `HandleInertiaRequests`) to pass auth user, brands list, and CSRF token globally.
- Use `usePage().props` composable in Vue to access shared data.
- Use `useForm()` for all form submissions — it provides automatic loading states, validation error binding, and progress tracking. Never use raw `fetch` or axios for Inertia-routed forms.
- Page-level data (brand config, payment form fields) arrives as page props from the controller. Cross-page state (current user, flash messages) lives in Inertia shared data, not Pinia.
- Reserve Pinia only for client-side ephemeral state: multi-step form wizard progress, UI panel open/closed states. Do not use Pinia to mirror server state.

---

### Styling

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Tailwind CSS | ^4.0 | Utility CSS framework | v4.0 released 2025-01-22. Oxide Rust engine: full builds 5x faster, incremental 100x faster. CSS-first config replaces `tailwind.config.js`. Automatic content detection — no `content:` array needed. |
| @tailwindcss/vite | bundled with Tailwind 4 | Vite plugin | First-party Vite plugin. Replaces PostCSS approach. Add to `vite.config.ts` as `import tailwindcss from "@tailwindcss/vite"`. Import Tailwind in `app.css` with `@import "tailwindcss"`. |
| shadcn-vue | ^2.6 (CLI-based, not a package version) | Component library | Vue port of shadcn/ui. Code is copied into your project — you own it fully. As of February 2025, migrated from Radix Vue to Reka UI v2 as the headless primitive layer. Supports Tailwind v4 natively (see v4 docs at shadcn-vue.com). 50+ components including sidebar, data-table, combobox, form, dialog, toast. |
| Reka UI | ^2.x (installed by shadcn-vue CLI) | Headless primitives | Accessibility primitives for Vue (Vue's Radix UI equivalent). Handles focus trapping, ARIA, keyboard navigation. Installed automatically by `shadcn-vue` CLI. Do NOT install manually or mix with Radix Vue. |

**Confidence:** HIGH — Tailwind v4.0 release date verified; shadcn-vue changelog confirms Tailwind v4 + Reka UI v2 migration.

**Critical shadcn-vue setup note:** The `shadcn-vue` CLI initializes the project, generates `components.json`, and copies component source into `src/components/ui/`. Run it once:
```bash
npx shadcn-vue@latest init
npx shadcn-vue@latest add button card dialog form input label select table toast
```
For multi-brand theming, define CSS custom properties per brand in a `brand-theme.css` and swap them via a `data-brand="[slug]"` attribute on the layout root. Tailwind 4's `@theme` directive works with CSS variables — design your brand tokens as `--color-primary`, `--color-secondary` etc. and reference them in the shadcn-vue component themes.

---

### Payment Processing

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| stripe/stripe-php | ^20.1 | Official Stripe PHP SDK | v20.1.0 is current stable (April 2025). Direct PaymentIntent API for one-time payments. Supports per-request API key via options array — critical for multi-account. Do NOT use Laravel Cashier (built for subscriptions; overkill and adds complexity). Do NOT use cloudcreativity/laravel-stripe (abandoned, old SDK). Do NOT use elegantly/laravel-stripe (niche wrapper with limited activity). |
| @stripe/stripe-js | ^5.x | Stripe.js browser SDK | Loads Stripe.js from Stripe's CDN. Always loads latest Stripe.js regardless of npm version. Use `loadStripe(publishableKey)` with the brand's publishable key. |
| vue-stripe-js | ^2.0.2 | Vue 3 Stripe Elements wrapper | Provides `<StripeElements>` + `<StripeElement>` Vue 3 components. Minimal abstraction, full access to Stripe.js methods. v2.0.2 released January 2025, actively maintained. Preferred over @vue-stripe/vue-stripe (heavier, more opinionated) and raw DOM manipulation. |
| spatie/laravel-stripe-webhooks | ^3.11 | Webhook processing | v3.11.0 (April 2026). Handles signature verification, DB logging, deduplication, queued job dispatch. Supports multiple webhook endpoints with separate signing secrets via `configKey` route parameter — required for multi-account setup. Laravel 8–13 supported. |

**Confidence:** HIGH — stripe/stripe-php v20.1.0 confirmed on Packagist; spatie/laravel-stripe-webhooks v3.11.0 confirmed; vue-stripe-js v2.0.2 confirmed on GitHub.

**Multi-account Stripe pattern:**
Store each brand's `stripe_secret_key` and `stripe_publishable_key` in a `brands` table column, encrypted with Laravel's built-in `encrypted` cast (AES-256-CBC). At payment time, retrieve the brand, decrypt the key, and pass it per-request:

```php
// stripe-php v20 per-request API key
$stripe = new \Stripe\StripeClient($brand->stripe_secret_key); // OR use global client with options:
$paymentIntent = $stripe->paymentIntents->create([
    'amount' => $amountInCents,
    'currency' => $brand->currency,
    'automatic_payment_methods' => ['enabled' => true],
], [
    'api_key' => $brand->stripe_secret_key,  // per-request override
]);
```

For webhooks with multiple accounts, register a separate webhook endpoint per brand in each Stripe dashboard and use the `configKey` route param:
```php
Route::stripeWebhooks('webhooks/stripe/{configKey}');
// Route: /webhooks/stripe/brand-slug
// Config key: signing_secret_brand-slug
```

**PaymentIntent lifecycle states this project must handle:**
1. `requires_payment_method` — Initial state; pass `client_secret` to frontend
2. `requires_action` — 3DS challenge; Stripe.js handles automatically if using `confirmPayment()`
3. `processing` — Async methods only (not relevant for cards)
4. `succeeded` — Confirmed via webhook `payment_intent.succeeded` — fulfill order here, NOT on client redirect
5. `canceled` — Terminal failure state; surface error to user

**Security rule:** Never fulfill an order based solely on a client-side success callback. Always use the `payment_intent.succeeded` webhook as the authoritative trigger. The client redirect is for UX only.

**Stripe Elements appearance per brand:**
```js
const elements = stripe.elements({
  clientSecret,
  appearance: {
    theme: 'stripe', // or 'flat', 'night'
    variables: {
      colorPrimary: brand.primary_color,
      colorBackground: '#ffffff',
      fontFamily: brand.font_family,
      borderRadius: '6px',
    },
  },
});
```
Brand appearance config (colors, font) can be stored in the `brands` table as JSON and served to the frontend as Inertia page props.

---

### Access Control & Auth

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| laravel/fortify | ^1.31 | Authentication backend | Disable public registration. Implement invite flow via custom controller + `invitations` table. Fortify handles login, logout, password reset, 2FA independently of frontend. |
| spatie/laravel-permission | ^7.4 | RBAC | v7.4.1 (April 2026). Requires PHP 8.3+ on latest version. Laravel 12+13 supported. Role + permission middleware. Recommended roles: `super-admin`, `brand-admin`, `viewer`. Use `@can` Blade directives passed as Inertia props or check permissions server-side in controllers before passing data. |

**Confidence:** HIGH — spatie/laravel-permission v7.4.1 confirmed on Packagist.

**Invite-only flow:** Comment out `Features::registration()` in `config/fortify.php`. Create a custom `InvitationController` that validates a signed URL token, pre-fills the email in a registration form, and creates the user on submission. Use Laravel's `URL::signedRoute()` for invite links.

---

### Encryption (Sensitive Fields)

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Laravel built-in `encrypted` cast | Native (L13) | AES-256-CBC encryption of Stripe keys in DB | Laravel's `Crypt` facade uses AES-256-CBC + MAC signing via APP_KEY. The `encrypted` cast on Eloquent models auto-encrypts/decrypts transparently. This is sufficient for storing Stripe secret keys. Do NOT use a third-party encryption package. Do NOT store raw Stripe secret keys. |

**Confidence:** HIGH — Laravel 13 encryption docs confirmed.

**Implementation:**
```php
// Brand model
protected function casts(): array
{
    return [
        'stripe_secret_key' => 'encrypted',
        'stripe_webhook_secret' => 'encrypted',
    ];
}
```

**Important:** The `encrypted` cast uses the single `APP_KEY`. This means: rotate `APP_KEY` carefully using `APP_PREVIOUS_KEYS` for graceful key rotation without losing existing data. Never commit `APP_KEY` to version control. Store it in environment secrets (e.g., Laravel Forge secrets, AWS Secrets Manager, or Herd Pro).

**Per-record key consideration:** Laravel's built-in `encrypted` cast uses the application-wide APP_KEY, not a per-record key. For this project that is acceptable — the threat model is a database dump without app server access. If per-record keys are required in future, implement a custom Eloquent cast using `openssl_encrypt()` with AES-256-CBC and a derived key (HKDF). Do not implement this complexity upfront.

---

### Queue & Background Jobs

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Laravel Queues | native (L12) | Async webhook processing | Stripe webhooks must return 200 within 5 seconds. Dispatch a queued job on receipt and return 200 immediately. spatie/laravel-stripe-webhooks does this natively. |
| Redis | ^7.x | Queue driver | Redis is the production queue driver. Use `database` queue driver for local development only. Redis (or Valkey) handles thousands of queue operations/second with O(1) push/pop. |
| Laravel Horizon | ^5.x | Queue monitoring dashboard | First-party Redis queue dashboard. Provides job throughput, retry management, supervisor config as code. Install in production. Accessible at `/horizon` (protect with Horizon gate). |

**Confidence:** MEDIUM — Redis + Horizon recommendation is standard Laravel production practice; specific Horizon version not verified against Packagist but it tracks Laravel releases closely.

---

### Development Tooling

| Technology | Version | Purpose | Why |
|------------|---------|---------|-----|
| Laravel Telescope | ^5.x | Request/query/job inspector | Development-only. Monitors HTTP requests, DB queries, queued jobs, exceptions, Stripe webhook events. More comprehensive than Debugbar for async job inspection — critical for webhook debugging. |
| Laravel Pint | bundled with L13 | Code style fixer | Opinionated PSR-12 fixer. Zero-config. Run as pre-commit hook. |
| Pest PHP | ^3.x | Testing framework | Laravel 13 starter kit ships with Pest. Expressive syntax for feature tests. Use for PaymentIntent lifecycle tests with Stripe test mode keys. |
| stripe/stripe-php test helpers | included in ^20.x | Stripe test clock + events | Use Stripe test mode webhooks and `stripe trigger payment_intent.succeeded` CLI to test webhook handlers locally without real payments. |

**Confidence:** MEDIUM — Telescope and Pest versions are standard; not individually verified against Packagist.

---

## What NOT to Use

| Package | Why Not |
|---------|---------|
| Laravel Cashier | Designed for subscriptions and customer portal. No value for one-time payment flows. Adds Billable trait complexity. Mixing Cashier with direct PaymentIntent API creates confusing overlap. |
| cloudcreativity/laravel-stripe | Explicitly warns "do not use for new projects." Pinned to old Stripe SDK version. Unmaintained beyond Laravel version bumps. |
| Vuex | Replaced by Pinia. Vuex is not Vue 3 idiomatic. Do not add Vuex. |
| Axios (standalone) | Inertia's `useForm()` and `router` handle all server communication. Only add Axios if you build a dedicated API endpoint (not needed for v1). |
| Laravel Breeze / Jetstream | Deprecated as starter kits in L12. Fortify + custom Inertia pages gives the same result with less scaffolding lock-in. |
| @vue-stripe/vue-stripe | More opinionated wrapper with less adoption momentum than vue-stripe-js. Creates unnecessary abstraction over Stripe.js. |
| Nuxt UI / PrimeVue | Conflict with shadcn-vue approach. shadcn-vue is already decided — do not add a second component library. |
| Radix Vue | shadcn-vue migrated to Reka UI in Feb 2025. Do not install Radix Vue; use Reka UI (comes with shadcn-vue CLI). |

---

## Alternatives Considered

| Category | Recommended | Alternative | Why Not |
|----------|-------------|-------------|---------|
| Auth | Laravel Fortify | Laravel Sanctum | Sanctum is API token auth; Fortify is session auth. This app uses session/Inertia, not API tokens. |
| Queue driver (prod) | Redis | SQS | Redis + Horizon provides better DX and local dev parity. SQS is valid for AWS-only deploys but adds IAM complexity. |
| Vue state | Inertia props + Pinia (light) | Vuex | Vuex is deprecated pattern for Vue 3. Pinia is official successor. |
| Webhook handling | spatie/laravel-stripe-webhooks | Manual verification + controller | spatie package adds queuing, deduplication, DB logging, multi-secret support out of the box. No reason to reinvent. |
| Component primitives | Reka UI (via shadcn-vue) | Radix Vue | Reka UI is the active successor; shadcn-vue dropped Radix Vue in Feb 2025. |
| Encryption | Laravel built-in `encrypted` cast | joelwmale/laravel-encryption | Built-in cast is zero-dependency and uses the same AES-256-CBC cipher. Third-party package adds no value here. |

---

## Installation Reference

```bash
# PHP backend dependencies
composer require laravel/fortify
composer require spatie/laravel-permission
composer require stripe/stripe-php
composer require spatie/laravel-stripe-webhooks
composer require laravel/telescope --dev
composer require laravel/horizon

# Frontend dependencies
npm install @inertiajs/vue3
npm install @stripe/stripe-js vue-stripe-js
npm install pinia

# shadcn-vue (CLI-based — do not use npm install shadcn-vue directly)
npx shadcn-vue@latest init
# Then add components individually:
npx shadcn-vue@latest add button card badge dialog form input label select table textarea toast tooltip

# Tailwind 4 (first-party Vite plugin — included in L12 starter kit)
npm install tailwindcss @tailwindcss/vite
```

---

## Sources

- Laravel 13 Release Notes: https://laravel.com/docs/13.x/releases (HIGH confidence)
- Laravel 13 Encryption: https://laravel.com/docs/13.x/encryption (HIGH confidence)
- Stripe PaymentIntents lifecycle: https://docs.stripe.com/payments/paymentintents/lifecycle (HIGH confidence)
- Stripe Elements Appearance API: https://docs.stripe.com/elements/appearance-api (HIGH confidence)
- Stripe PHP SDK (Packagist): https://packagist.org/packages/stripe/stripe-php — v20.1.0 confirmed (HIGH confidence)
- stripe-php per-request API key: https://github.com/stripe/stripe-php (HIGH confidence)
- spatie/laravel-permission (Packagist): https://packagist.org/packages/spatie/laravel-permission — v7.4.1 confirmed (HIGH confidence)
- spatie/laravel-stripe-webhooks: https://github.com/spatie/laravel-stripe-webhooks — v3.11.0, multi-secret support confirmed (HIGH confidence)
- Tailwind CSS v4.0 release: https://tailwindcss.com/blog/tailwindcss-v4 (HIGH confidence)
- shadcn-vue changelog (Reka UI migration, Tailwind v4): https://www.shadcn-vue.com/docs/changelog (HIGH confidence)
- @inertiajs/vue3 v2.3 on npm (HIGH confidence)
- vue-stripe-js v2.0.2: https://github.com/ectoflow/vue-stripe-js (HIGH confidence)
- Laravel Fortify (Packagist): https://packagist.org/packages/laravel/fortify — v1.31.2 (HIGH confidence)
- shadcn-vue Tailwind v4 docs: https://v3.shadcn-vue.com/docs/tailwind-v4 (HIGH confidence)
