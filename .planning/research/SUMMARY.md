# Research Summary: PayHub

**Project:** PayHub - Centralized Multi-Brand Payment Hub
**Synthesized:** 2026-05-02
**Overall confidence:** HIGH (all four research files carry HIGH confidence on core decisions)

---

## Executive Summary

PayHub is a monolithic Laravel 12 application serving two distinct surfaces from a single domain: an authenticated admin/user panel (Inertia.js SPA) for managing brands and creating payment links, and unauthenticated client payment pages (UUID-addressed, Stripe Elements) for collecting payments. The defining architectural challenge is that every brand maps to a completely separate Stripe account, meaning each payment operation requires per-brand credential resolution, per-account PaymentIntent creation, and per-account webhook signature verification. The correct pattern throughout is a StripeAccount model that holds AES-256-encrypted credentials, a StripeService that instantiates a fresh StripeClient per account (never globally), and per-brand webhook URLs that resolve the correct signing secret on each inbound event.

The stack is fully decided with high confidence: PHP 8.3, Laravel 12, Fortify (invite-only, no public registration), Inertia.js v2 + Vue 3 (Composition API only), Tailwind CSS v4, shadcn-vue 2.6 (Reka UI primitives), stripe/stripe-php v20, vue-stripe-js v2, and spatie/laravel-stripe-webhooks v3.11 for multi-secret webhook handling. Laravel Cashier is explicitly excluded. The data model has four core entities: Brand, StripeAccount, Payment, and User, with StripeAccount secret_key and webhook_secret stored with the Laravel encrypted cast.

The top execution risks are all security and correctness issues specific to multi-account Stripe: cross-account key contamination, trusting client-side payment confirmation over webhooks, webhook raw-body mutation breaking signature verification, amount tampering on the client payment page, and APP_KEY rotation destroying all encrypted Stripe secrets at once. Every one of these has a documented prevention strategy and must be addressed before the system handles real payments.

---

## Recommended Stack

| Layer | Package | Version | Decision |
|---|---|---|---|
| Runtime | PHP | 8.3 | Laravel 12 requires 8.2+; 8.3 is the production-stable target |
| Framework | Laravel | 12.x | Released Feb 2025; ships Vue 3 + Inertia + Tailwind 4 starter kit |
| Auth | laravel/fortify | ^1.31 | Disable public registration; invite-only via invitations table + signed URLs |
| RBAC | spatie/laravel-permission | ^7.4 | Roles: super-admin, brand-admin, viewer |
| Frontend adapter | @inertiajs/vue3 | ^2.3 | v2 is current; use useForm() for all form submissions |
| Server adapter | inertiajs/inertia-laravel | ^2.0 | Handles shared data via HandleInertiaRequests middleware |
| UI framework | Vue 3 | 3.x | Composition API + script setup only |
| Styling | Tailwind CSS | ^4.0 | CSS-first config; @import tailwindcss in app.css |
| Components | shadcn-vue CLI | ^2.6 | Code copied into project; Reka UI v2 as primitives |
| Build | Vite + @tailwindcss/vite | bundled | Do not use Webpack/Mix or PostCSS approach |
| Stripe PHP | stripe/stripe-php | ^20.1 | Per-request StripeClient instantiation for multi-account |
| Stripe JS | @stripe/stripe-js + vue-stripe-js | ^5.x + ^2.0.2 | Initialize per-brand via page props publishable key |
| Webhooks | spatie/laravel-stripe-webhooks | ^3.11 | Multi-secret support; raw-body handled correctly |
| Queues | Laravel Queues + Redis | ^7.x Redis | Webhook handlers must queue and return 200 immediately |
| Queue UI | Laravel Horizon | ^5.x | Production only; gate at /horizon |
| Encryption | Laravel encrypted cast | native | AES-256-CBC via APP_KEY on secret_key and webhook_secret columns |
| Dev tooling | Telescope + Pest + Pint | dev-only | Telescope for webhook debugging; Pest for PaymentIntent lifecycle tests |

Do not use: Laravel Cashier, Breeze, Jetstream, Vuex, Radix Vue, @vue-stripe/vue-stripe, Nuxt UI, PrimeVue, cloudcreativity/laravel-stripe.

---

## Table Stakes Features

Features that must ship in v1. Absence means the product is non-functional.

| Feature | Notes |
|---|---|
| Brand management CRUD (name, logo, colors, Stripe account binding) | Everything downstream depends on at least one brand existing |
| Per-brand Stripe account credentials (publishable + secret key, encrypted) | Revenue routing depends on this being correct |
| Invite-only user management (Admin + User roles) | No public registration; Fortify registration disabled |
| Payment creation (amount, currency, brand, description, client name/email) | Core function |
| Shareable UUID payment link (/pay/{uuid}) | How clients receive payment requests |
| Branded client payment page (Stripe Elements, per-brand logo/colors) | Clients must see the correct brand to trust the charge |
| Stripe PaymentIntent lifecycle (create on page load, handle requires_action for 3DS) | Silent failures for European cards without this |
| Payment success page post-confirmation | Client needs immediate confirmation |
| Webhook handling (payment_intent.succeeded, payment_intent.payment_failed) | Authoritative source of truth for payment status |
| Payment status tracking (pending / succeeded / failed) in DB | Staff need to know who has paid |
| Client email receipt (webhook-triggered) | Clients expect a record |
| Admin notification email (webhook-triggered) | Ops awareness |
| Payment history list (per-user filtered; all-brands for admin) | Basic accountability |
| Refund capability via Stripe API (admin-only) | Admin should not need to log in to Stripe directly |
| Multi-currency support (creator-selected at payment time) | Wrong currency breaks payments |

Defer to v1.1: CSV export, cross-brand analytics dashboard with charts, Slack notifications, SSO.

---

## Architecture Highlights

### Four core models

- Brand: name, slug, logo_url, primary_color, secondary_color; owns StripeAccount and Payments
- StripeAccount: publishable_key (plain), secret_key (encrypted), webhook_secret (encrypted), is_active; belongs to Brand
- Payment: uuid, brand_id, stripe_account_id, user_id, amount (integer cents), currency, description, status, stripe_payment_intent_id, client_email, expires_at, paid_at
- User: standard auth user; role via spatie/laravel-permission

Encrypted columns (secret_key, webhook_secret) must be TEXT type in migrations and can never be searched via SQL WHERE clauses.

### StripeService - the multi-account pattern

Never call Stripe::setApiKey() globally. Always instantiate: new StripeClient(stripeAccount->secret_key). The encrypted cast decrypts automatically. This is the only safe pattern under concurrent requests.

### Two distinct route surfaces

- /admin/* and /payments/* - authenticated, Inertia, Fortify session auth
- /pay/{uuid} - unauthenticated, BrandResolution middleware resolves Brand + StripeAccount from UUID in one eager-loaded query; attaches to request attributes
- /webhook/stripe/{accountId} - no CSRF, no auth; raw body required; loads StripeAccount by accountId param to retrieve correct webhook_secret

### PaymentIntent creation timing

Create the PaymentIntent when the client loads /pay/{uuid}, not when the admin generates the link. Store stripe_payment_intent_id on the Payment record. On subsequent page loads, check for an existing non-canceled intent and reuse it. Avoids Stripe dashboard pollution from abandoned links.

### Webhook fulfillment rule (non-negotiable)

All database writes (status updates, paid_at, email triggers) happen only inside the payment_intent.succeeded webhook handler. The client-side confirmPayment() callback is UX-only. Never trust a client-supplied payment status string.

### Build order (dependency-driven)

1. Migrations + models + encrypted casts: everything depends on schema being correct
2. StripeService + encryption round-trip test: de-risk credential handling early
3. Admin panel - Brand CRUD, StripeAccount CRUD: populates data needed by later phases
4. Auth: Fortify invite-only flow, roles
5. Payment builder: create + history + link generation
6. Client payment page: BrandResolution, Inertia page, Stripe Elements, branding from props
7. Webhooks: per-account URLs, signature verification, PaymentCompleted event, queued jobs
8. Post-payment: status sync, email receipt, admin notification

---

## Top Pitfalls to Avoid

### 1. Cross-account key contamination (CRITICAL)

Publishable key for Brand A combined with secret key for Brand B charges the wrong merchant account. Prevention: treat publishable_key, secret_key, and webhook_secret as an inseparable triplet on StripeAccount. Instantiate StripeClient with the decrypted secret from the same record that provided the publishable key. Validate key-pair consistency via stripe.accounts.retrieve() on save.

### 2. Trusting client-side payment confirmation (CRITICAL)

confirmPayment() callback used to write status=succeeded to DB. A user who closes the browser, or a forged payload, causes missed or fraudulent fulfillment. Prevention: all DB writes on payment completion must come from the payment_intent.succeeded webhook event, verified with constructEvent(). The client redirect is for UX display only.

### 3. Webhook raw body mutation breaks signature verification (CRITICAL)

Laravel JSON middleware normalises the request body before your controller sees it; constructEvent() then always throws SignatureVerificationException. Prevention: exclude webhook routes from CSRF middleware; read body with getContent() not request->all(); use spatie/laravel-stripe-webhooks which handles this correctly.

### 4. Single webhook secret for all brands (CRITICAL)

Multi-account requires per-account webhook secrets. Using one secret means events from most brands are unverified or rejected. Prevention: per-brand webhook URLs at /webhook/stripe/{accountId}; load StripeAccount by accountId param; call constructEvent() with that account's webhook_secret.

### 5. Amount tampered by client on payment page (CRITICAL)

Client modifies amount in a request before PaymentIntent is created server-side, paying cents for a high-value charge. CVE-2026-2890 is a documented real-world instance of this exact pattern. Prevention: amount is read exclusively from the server-side Payment record keyed by UUID. No amount field is accepted from the client request body.

### 6. APP_KEY rotation destroys all encrypted Stripe secrets (MODERATE)

Rotating APP_KEY without APP_PREVIOUS_KEYS makes all stored Stripe credentials permanently unreadable, causing complete payment outage. Prevention: use APP_PREVIOUS_KEYS during rotation; run a migration to re-encrypt all records with the new key; add a key_version column for auditability.

### 7. PaymentIntent created on every page load - orphaned intents (MODERATE)

Refreshing the payment page creates new intents indefinitely. Stripe cancels intents after too many confirmation attempts; the dashboard becomes unauditable. Prevention: store stripe_payment_intent_id on the Payment record; check for an existing active intent before creating a new one.

---

## Open Questions

Decisions that must be resolved before or during planning:

| Question | Impact | Recommendation |
|---|---|---|
| Is expires_at on payment links required at v1? | Affects BrandResolution validation and Payment data model | Include field; default NULL (non-expiring) unless business requires otherwise |
| Database queue driver or Redis from day one? | Redis requires infrastructure setup | Start with database queue; migrate to Redis when webhook volume warrants it |
| Client payment page: Inertia or plain Blade+Vue? | Inertia adds overhead for a stateless public page | Either works; Inertia is consistent with the rest of the app |
| Real-time payment status updates or page reload sufficient? | Real-time requires Pusher/Soketi | Page reload is acceptable for an internal agency tool at v1 |
| Per-brand email From address for receipts? | Requires per-brand SMTP config or multi-sender transactional email provider | Must resolve before Phase 7 |
| Test/live key enforcement in production: hard block or warning? | Affects brand onboarding flow | Block on save if APP_ENV=production and key prefix is pk_test_ |
| CSV export in v1 or v1.1? | Low complexity but adds scope | Defer to v1.1; admin can use Stripe dashboard initially |

---

## Roadmap Implications

The feature dependency chain and architecture build order converge on the same seven-phase structure.

### Phase 1 - Foundation: Data Model + Encryption

Migrations, models, relationships, encrypted casts verified end-to-end. Factories and seeders for local dev. Integer-only amount handling and CurrencyFormatter service established. No UI, no Stripe API calls yet. This phase is non-negotiable first - every other phase depends on the schema and encryption being correct.
- Pitfalls to avoid: MOD-4 establish APP_KEY rotation plan before storing first real key; MIN-1 use bcmath and integer amounts from day one
- Research flag: Standard patterns, no additional research needed

### Phase 2 - Auth + User Management

Fortify configuration with public registration disabled, invite-only flow via signed URLs, spatie/laravel-permission roles. Admin and user login pages as Inertia views.
- Pitfalls to avoid: none specific to this phase
- Research flag: Standard Laravel patterns, no additional research needed

### Phase 3 - Brand + Stripe Account Management

Brand CRUD, StripeAccount CRUD with write-only secret key form, key-pair validation against Stripe API on save, test/live mode detection and enforcement, StripeService implementation and manual round-trip test. This phase unlocks all downstream payment features.
- Pitfalls to avoid: CRITICAL-1 key contamination; MOD-7 test/live key mismatch; MIN-3 per-brand Stripe.js initialization
- Research flag: Standard patterns, no additional research needed

### Phase 4 - Payment Creation + Link Generation

Payment creation form with brand/account selector, UUID generation, shareable link construction, payment history views. StripeService.createPaymentIntent() not called here; lazy creation happens at page load in Phase 5.
- Pitfalls to avoid: CRITICAL-5 amount derived from DB only not request body; MOD-6 design the intent-reuse check now
- Research flag: Standard patterns, no additional research needed

### Phase 5 - Client Payment Page

BrandResolution middleware, ClientPaymentController, PaymentIntent creation on page load with reuse check, Inertia page with brand CSS variables from props, loadStripe(publishableKey) per brand, Stripe Elements mount, confirmPayment() with return_url, payment complete page using GET not POST.
- Pitfalls to avoid: CRITICAL-5; MOD-1 3DS requires_action handling; MOD-5 CSRF on 3DS redirect; MOD-6; MIN-2 CSP headers; MIN-3
- Research flag: Phase-level research recommended for Stripe Elements appearance API and 3DS return_url pattern

### Phase 6 - Webhooks + Payment Status

Per-brand webhook endpoint registration in Stripe dashboards, StripeWebhookController with per-account secret lookup, WebhookService::verify(), PaymentCompleted event, SendPaymentConfirmation queued listener, idempotency via unique constraint on stripe_event_id, payment status sync. Test using Stripe CLI.
- Pitfalls to avoid: CRITICAL-2 no client-side fulfillment; CRITICAL-3 raw body handling; CRITICAL-4 per-account secret; MOD-2 deduplication; MIN-4 idempotency key scoping
- Research flag: Spatie package multi-secret configuration is well documented, no additional research needed

### Phase 7 - Notifications + Polish

Branded client email receipt, admin notification email, refund capability via Stripe API, admin dashboard stats, payment link copy-to-clipboard, brand selector with Stripe account preview. CSV export deferred to v1.1.
- Pitfalls to avoid: MOD-3 zero-decimal currencies in any amount display or export logic
- Research flag: Resolve per-brand email sender identity (Open Question 5) before building this phase

---

## Confidence Assessment

| Area | Confidence | Notes |
|---|---|---|
| Stack | HIGH | All packages verified on Packagist/npm with exact versions. No speculative choices. |
| Features | HIGH | Feature set well-bounded for an internal agency tool. Dependency chain is explicit. |
| Architecture | HIGH | Patterns verified against Stripe docs, Laravel 12 docs, and community sources. |
| Pitfalls | HIGH | All critical pitfalls backed by official Stripe documentation. CVE-2026-2890 provides real-world validation of CRITICAL-5. |

Gaps requiring attention during planning:
- Per-brand email sender identity must be resolved before Phase 7
- Queue infrastructure choice (database vs Redis) is low risk to defer to Phase 6 planning
- Real-time dashboard requirements should be settled before Phase 7 to avoid retrofitting

---

## Sources

- Laravel 12 release notes + encryption docs: laravel.com/docs/12.x
- Stripe PaymentIntents lifecycle: docs.stripe.com/payments/paymentintents/lifecycle
- Stripe webhook signature verification: docs.stripe.com/webhooks/signature
- Stripe integration security guide: docs.stripe.com/security/guide
- Stripe Elements Appearance API: docs.stripe.com/elements/appearance-api
- stripe/stripe-php v20.1.0: packagist.org/packages/stripe/stripe-php
- spatie/laravel-permission v7.4.1: packagist.org/packages/spatie/laravel-permission
- spatie/laravel-stripe-webhooks v3.11.0: github.com/spatie/laravel-stripe-webhooks
- Tailwind CSS v4.0: tailwindcss.com/blog/tailwindcss-v4
- shadcn-vue changelog Reka UI + Tailwind v4: shadcn-vue.com/docs/changelog
- @inertiajs/vue3 v2.3: npmjs.com/package/@inertiajs/vue3
- vue-stripe-js v2.0.2: github.com/ectoflow/vue-stripe-js
- CVE-2026-2890 payment amount bypass: himpfen.com/formidable-forms-stripe-payment-bypass-cve-2026-2890
