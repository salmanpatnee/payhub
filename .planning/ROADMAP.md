# Roadmap: PayHub

## Overview

PayHub ships in seven phases ordered by dependency: data model and encryption first (everything downstream depends on a correct schema and verified AES-256 round-trip), then auth, then brand and Stripe account management (which unlock payment creation), then the payment creation form, then the client payment page with Stripe Elements, then webhook-driven status sync, and finally notifications and the admin dashboard. Each phase delivers one coherent, verifiable capability before the next begins.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Foundation** - Laravel 13 + Inertia v3 + Vue 3 + Tailwind 4 + shadcn-vue + Fortify install, then migrations, models, encrypted casts, factories *(completed 2026-05-03)*
- [x] **Phase 2: Auth + User Management** - Invite-only Fortify auth, role-based access, admin and user login/logout *(completed 2026-05-03)*
- [x] **Phase 3: Brand + Stripe Account Management** - Brand CRUD, StripeAccount CRUD, key encryption, per-account StripeService, test/live key enforcement *(completed 2026-05-05)*
- [x] **Phase 4: Payment Creation + Link Generation** - Payment form, UUID link generation, currency selection, payment history views *(completed 2026-05-09)*
- [x] **Phase 5: Client Payment Page** - Branded unauthenticated payment page, Stripe Elements, 3DS handling, success/failure pages *(completed 2026-05-09)*
- [x] **Phase 6: Webhooks + Status Sync** - Per-account webhook endpoints, signature verification, queued fulfillment, authoritative status writes *(completed 2026-05-12)*
- [ ] **Phase 7: Notifications + Dashboard** - Admin email notification, unified dashboard, filtering, user payment history

## Phase Details

### Phase 1: Foundation
**Goal**: Laravel 13 application is scaffolded with Inertia v3, Vue 3, Tailwind CSS 4, shadcn-vue, and Fortify installed — then the database schema is correct, all four core models are in place with proper relationships, and AES-256 encryption round-trips successfully on Stripe credentials.
**Depends on**: Nothing (first phase)
**Requirements**: SEC-01
**Success Criteria** (what must be TRUE):
  1. `php artisan serve` and `npm run dev` start without errors; a browser renders the Inertia root page with Tailwind styles applied
  2. Laravel Fortify is installed and registered; Inertia v3 server and client adapters are wired up
  3. shadcn-vue CLI is initialised; at least one component (e.g. Button) is available in resources/js/components/ui/
  4. All migrations run cleanly on a fresh database with no errors
  5. A StripeAccount record with an encrypted secret_key can be saved and its decrypted value retrieved correctly via the Laravel encrypted cast
  6. Amount fields store and retrieve integer cent values without floating-point drift
  7. Model relationships (Brand has many StripeAccounts, Payment belongs to Brand and StripeAccount) are traversable in Tinker
  8. Seeders populate enough local dev data (one brand, one Stripe account, one admin user) to support manual testing in Phase 2
**Plans**: TBD
**UI hint**: yes

### Phase 2: Auth + User Management
**Goal**: Authenticated team members can log in and out, session persistence works, and Admin-only features are inaccessible to User-role accounts — with no path for public self-registration.
**Depends on**: Phase 1
**Requirements**: AUTH-01, AUTH-02, AUTH-03, AUTH-04, AUTH-05, AUTH-06
**Success Criteria** (what must be TRUE):
  1. Admin and User can log in with email and password; the session persists across page loads until explicit logout
  2. Logging out from any authenticated page destroys the session and redirects to the login page
  3. An account with the User role cannot access Admin-only routes or UI controls
  4. The client payment route (/pay/{uuid}) is reachable without any login session
  5. There is no publicly accessible registration page — new accounts can only be created by an Admin
**Plans**: 5 plans (Wave 0: test stubs, Wave 1: config + backend, Wave 2: frontend)

Plans:
- [x] 02-00-PLAN.md — Wave 0 test stubs (AdminUserManagementTest, AdminAccessControlTest, SessionPersistenceTest, PublicPaymentRouteTest)
- [x] 02-01-PLAN.md — Config layer (bootstrap/app.php spatie aliases, fortify.php disable registration, HandleInertiaRequests roles)
- [x] 02-02-PLAN.md — Backend CRUD (routes/web.php admin group + pay stub, AdminUserController, StoreUserRequest, UpdateUserRequest)
- [x] 02-03-PLAN.md — Frontend shell (auth.ts types, AppSidebar role-aware nav, ComingSoon placeholder, placeholder routes)
- [x] 02-04-PLAN.md — Admin user pages (Index.vue user table, Create.vue form, Edit.vue form with self-delete guard)

**UI hint**: yes

### Phase 3: Brand + Stripe Account Management
**Goal**: Admin can create and configure brands with visual identity, attach encrypted Stripe account credentials validated against the live Stripe API, and the system blocks test keys from reaching production.
**Depends on**: Phase 2
**Requirements**: BRAND-01, BRAND-02, BRAND-03, BRAND-04, STRIPE-01, STRIPE-02, STRIPE-03, STRIPE-04, STRIPE-05
**Success Criteria** (what must be TRUE):
  1. Admin can create, edit, and list brands with name, logo, primary color, and secondary color
  2. Admin can add a Stripe account (publishable + secret key) linked to a brand; the secret key is stored encrypted and the form never re-displays the raw key after save
  3. Saving a Stripe account with a mismatched key pair fails with a validation error from the live Stripe API check
  4. Saving a test-mode key (pk_test_/sk_test_ prefix) in the production environment is blocked with a clear error
  5. Admin can deactivate an existing Stripe account without deleting it; deactivated accounts are excluded from payment creation dropdowns
**Plans**: TBD
**UI hint**: yes

### Phase 4: Payment Creation + Link Generation
**Goal**: Admin and User can create a payment record specifying brand, Stripe account, amount (USD or GBP), client name, client email, service, package (Basic / Standard / Premium / Platinum / Diamond), and an optional note — with a live Stripe fee breakdown shown on the form — and receive a unique shareable link that never expires until paid or cancelled.
**Depends on**: Phase 3
**Requirements**: PAY-01, PAY-02, PAY-03, PAY-04, PAY-05, PAY-06, PAY-07, PAY-08, SEC-02
**Success Criteria** (what must be TRUE):
  1. Admin or User can submit the payment creation form and a payment record is saved with the correct brand, Stripe account, amount in cents, currency, client name, client email, service, package, and note
  2. The system generates a UUID-based shareable link (/pay/{uuid}) immediately after creation; the link is copyable from the UI
  3. Currency selection is restricted to USD and GBP; no other currency can be submitted
  4. The payment amount read when processing is always sourced from the server-side Payment record — no client-supplied amount is accepted
  5. The generated link remains accessible and in a pending state until the payment is completed or manually cancelled
  6. The creation form shows a live fee breakdown (Stripe fee and amount received) that updates when amount or currency changes
**Plans**: TBD
**UI hint**: yes

### Phase 5: Client Payment Page
**Goal**: A client who opens a payment link sees the correct brand's logo and colors, completes payment via an inline Stripe Elements form initialized with that brand's publishable key, passes 3DS challenges where required, and lands on a branded success or failure page.
**Depends on**: Phase 4
**Requirements**: CLIENT-01, CLIENT-02, CLIENT-03, CLIENT-04, CLIENT-05, CLIENT-06, CLIENT-07, CLIENT-08, SEC-04
**Success Criteria** (what must be TRUE):
  1. A client opens /pay/{uuid} without any login and sees the correct brand's logo and colors applied to the page
  2. The Stripe Elements form loads inline (no redirect to stripe.com) initialized with the brand's own publishable key
  3. A payment requiring 3DS authentication presents the challenge and completes correctly after the client authenticates
  4. After a successful payment the client is redirected to a branded success page; after a failure the client sees a branded error page with a clear message
  5. The client payment page renders correctly on mobile screens
  6. The PaymentIntent client_secret is never exposed in URLs, logs, or API responses beyond the page load that renders the Elements form
**Plans**: 6 plans (Wave 0: test stubs, Wave 1: controller + routes, Wave 2: layout + resolver, Wave 3: Pay.vue + terminal pages, Wave 4: gap closure CR-01/CR-02/WR-01/WR-02)

Plans:
- [x] 05-00-PLAN.md — Wave 0: ClientPaymentTest.php stubs with Mockery StripeClient pattern (12 test cases, all RED)
- [x] 05-01-PLAN.md — Wave 1: ClientPaymentController (show/success/failed) + routes/web.php replacement (3 public routes)
- [x] 05-02-PLAN.md — Wave 2: app.ts resolver (ClientPayment/ → null) + PaymentLayout.vue (branded standalone layout)
- [x] 05-03-PLAN.md — Wave 3a: Pay.vue (Stripe Elements, loadStripe gate, confirmPayment, brand theming)
- [x] 05-04-PLAN.md — Wave 3b: Success.vue + Failed.vue + Unavailable.vue (terminal + guard pages)
- [x] 05-05-PLAN.md — Wave 4 (gap closure): CR-01 retrieve-and-reuse PI, CR-02 success status guard, WR-01/WR-02 Vue null guards

**UI hint**: yes

### Phase 6: Webhooks + Status Sync
**Goal**: Each Stripe account has its own webhook endpoint that verifies the signature using that account's secret, queues fulfillment immediately, and writes payment status to the database authoritatively — the only path by which a payment is ever marked completed or failed.
**Depends on**: Phase 5
**Requirements**: WEBHOOK-01, WEBHOOK-02, WEBHOOK-03, WEBHOOK-04, WEBHOOK-05, WEBHOOK-06, SEC-03
**Success Criteria** (what must be TRUE):
  1. Each Stripe account exposes a distinct webhook endpoint URL (/webhook/stripe/{accountId}) that resolves the correct signing secret for that account
  2. An event with a tampered or missing signature returns HTTP 400 and is not processed
  3. A payment_intent.succeeded event updates the payment record status to completed in the database; a payment_intent.payment_failed event updates it to failed
  4. The webhook controller returns HTTP 200 immediately; the fulfillment job (status write, downstream actions) executes asynchronously via the queue
  5. Payment status is never written based on a client-side confirmPayment() callback — all DB writes come exclusively from the webhook handler
**Plans**: 4 plans (Wave 0: test stubs, Wave 1: controller + job, Wave 2: admin UI)

Plans:
- [x] 06-00-PLAN.md — Wave 0: StripeWebhookTest.php stubs (11 test cases, all RED) + fakeStripeSignature() helper
- [x] 06-01-PLAN.md — Wave 1a: StripeWebhookController + webhook route + CSRF exclusion (bootstrap/app.php)
- [x] 06-02-PLAN.md — Wave 1b: HandleStripeWebhookJob (status DB writes, idempotency gate 2)
- [x] 06-03-PLAN.md — Wave 2: StripeAccountController edit/update + UpdateStripeAccountRequest + Edit.vue webhook_secret fields

### Phase 7: Notifications + Dashboard
**Goal**: Admin receives an email notification when a payment succeeds, and can view a unified dashboard of all payments across all brands with filtering — while each User can view their own payment history.
**Depends on**: Phase 6
**Requirements**: NOTIFY-01, NOTIFY-02, DASH-01, DASH-02, DASH-03, DASH-04
**Success Criteria** (what must be TRUE):
  1. Admin receives an email notification when a payment_intent.succeeded webhook is processed; the email is sent via a queued job (non-blocking)
  2. Admin can view a unified payment list showing all payments across all brands with amount, currency, brand name, status, created date, and client email
  3. Admin can filter the payment list by brand, Stripe account, status, and date range and the list updates to match
  4. User can view their own payment history showing only payments they created
**Plans**: TBD
**UI hint**: yes

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6 → 7

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Foundation | 2/2 | Complete | 2026-05-03 |
| 2. Auth + User Management | 5/5 | Complete | 2026-05-03 |
| 3. Brand + Stripe Account Management | 5/5 | Complete | 2026-05-05 |
| 4. Payment Creation + Link Generation | 3/3 | Complete | 2026-05-09 |
| 5. Client Payment Page | 6/6 | Complete | 2026-05-09 |
| 6. Webhooks + Status Sync | 4/4 | Complete | 2026-05-13 |
| 7. Notifications + Dashboard | 0/TBD | Not started | - |
