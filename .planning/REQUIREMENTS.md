# PayHub — v1 Requirements

## v1 Requirements

### Auth & Access (AUTH)

- [x] **AUTH-01**: User can log in with email and password via Laravel Fortify
- [x] **AUTH-02**: User session persists across page loads until explicit logout
- [x] **AUTH-03**: User can log out from any authenticated page
- [x] **AUTH-04**: Admin can assign roles (Admin / User) to team members
- [x] **AUTH-05**: Access to admin features is restricted to Admin role only
- [x] **AUTH-06**: Unauthenticated access to client payment page (/pay/{uuid}) is allowed without login

### Brand Management (BRAND)

- [ ] **BRAND-01**: Admin can create a brand (name, logo, primary color, secondary color)
- [ ] **BRAND-02**: Admin can edit brand details
- [ ] **BRAND-03**: Admin can list all brands
- [ ] **BRAND-04**: System detects test vs live Stripe keys and blocks test keys in production

### Stripe Account Management (STRIPE)

- [ ] **STRIPE-01**: Admin can add a Stripe account (publishable key + secret key) and link it to a brand
- [ ] **STRIPE-02**: Secret key is encrypted at rest using AES-256 (Laravel encrypted cast)
- [ ] **STRIPE-03**: System validates the key pair against Stripe API on save
- [ ] **STRIPE-04**: Admin can edit an existing Stripe account's keys
- [ ] **STRIPE-05**: Admin can deactivate or archive a Stripe account without deleting it

### Payment Creation (PAY)

- [x] **PAY-01**: Admin or User can create a payment specifying amount, currency, brand, client name, client email, service (open text), package (Basic / Standard / Premium / Platinum / Diamond), and an optional note
- [x] **PAY-02**: Admin or User can select a specific Stripe account for the payment (from active accounts)
- [x] **PAY-03**: Client name and email address are captured when creating a payment
- [x] **PAY-04**: System generates a unique shareable UUID payment link on payment creation
- [x] **PAY-05**: Payment amount is stored server-side and cannot be modified via client request
- [x] **PAY-06**: Creator selects currency per payment — USD ($) or GBP (£) only
- [x] **PAY-07**: Payment links never expire (valid until paid or manually cancelled)
- [x] **PAY-08**: Payment creation form shows live Stripe fee breakdown (Stripe fee and amount received) computed client-side from the entered amount and currency

### Client Payment Experience (CLIENT)

- [ ] **CLIENT-01**: Client opens payment page (/pay/{uuid}) without login
- [ ] **CLIENT-02**: Payment page displays the correct brand's logo and colors
- [ ] **CLIENT-03**: Stripe Elements form is embedded inline (no redirect to stripe.com)
- [ ] **CLIENT-04**: Stripe Elements is initialized with the correct brand's publishable key
- [ ] **CLIENT-05**: System handles 3DS / SCA authentication challenges (requires_action)
- [ ] **CLIENT-06**: Client sees a success page after payment is confirmed
- [ ] **CLIENT-07**: Client sees an error/failure page if payment fails
- [ ] **CLIENT-08**: Payment page is mobile-responsive

### Webhooks & Status Sync (WEBHOOK)

- [x] **WEBHOOK-01**: Each Stripe account has a dedicated webhook endpoint URL (/webhook/stripe/{accountId})
- [x] **WEBHOOK-02**: Stripe webhook signature is verified per account using that account's signing secret
- [x] **WEBHOOK-03**: payment_intent.succeeded event updates payment status to completed in DB
- [x] **WEBHOOK-04**: payment_intent.payment_failed event updates payment status to failed in DB
- [x] **WEBHOOK-05**: All DB writes on payment completion are driven by webhook only (not client confirmation)
- [x] **WEBHOOK-06**: Webhook handler returns HTTP 200 immediately; fulfillment is queued

### Notifications (NOTIFY)

- [ ] **NOTIFY-01**: Admin receives email notification when a payment succeeds
- [ ] **NOTIFY-02**: Notification is sent via queued job (non-blocking)

### Dashboard & Reporting (DASH)

- [ ] **DASH-01**: Admin can view all payments across all brands in a unified list
- [ ] **DASH-02**: Admin can filter payments by brand, Stripe account, status, and date range
- [ ] **DASH-03**: User can view their own payment history (payments they created)
- [ ] **DASH-04**: Payment list shows: amount, currency, brand, status, created date, client email

### Security (SEC)

- [ ] **SEC-01**: Stripe secret keys and webhook secrets are encrypted at rest (AES-256 via Laravel encrypted cast)
- [x] **SEC-02**: Payment amount is read exclusively from the server-side Payment record — no client input accepted for amount
- [x] **SEC-03**: Webhook routes are excluded from CSRF middleware; raw body is preserved for signature verification
- [ ] **SEC-04**: PaymentIntent client_secret is never logged, stored in URLs, or exposed beyond the payment page response

---

## v2 Requirements (Deferred)

- Client email receipt on payment success (post-v1 — requires per-brand email sender config decision)
- Per-brand subdomains (pay.brandA.com) — DNS/SSL complexity, defer until brand count justifies
- Invite-only registration flow — Admin creates accounts manually for v1
- Cancel/void a payment link — Admin can invalidate without deleting
- Duplicate a payment link — re-use as template
- Refund from dashboard (via Stripe API)
- CSV export of payment history
- Slack/webhook notifications
- SSO (Google/Microsoft)
- Duplicate event deduplication (webhook idempotency)
- Retry visibility / dead-letter queue UI
- APP_KEY rotation runbook documentation
- Cross-brand analytics with charts

---

## Out of Scope

- **Additional currencies beyond USD/GBP** — two currencies sufficient for agency's markets
- **Subscriptions / recurring billing** — one-time payments only; Stripe Cashier explicitly excluded
- **Client login / accounts** — clients are anonymous recipients of payment links
- **Public registration** — invite-only access control
- **Per-brand subdomains** — single domain (pay.agency.com) for v1
- **External secret stores** (AWS Secrets Manager, Vault) — encrypted DB is sufficient
- **Open-ended payment amounts entered by client** — amounts are always admin/user-set
- **SaaS / multi-tenant evolution** — internal agency tool for v1

---

## Traceability

| REQ-ID | Phase | Status |
|--------|-------|--------|
| AUTH-01 | Phase 2 | Complete |
| AUTH-02 | Phase 2 | Complete |
| AUTH-03 | Phase 2 | Complete |
| AUTH-04 | Phase 2 | Complete |
| AUTH-05 | Phase 2 | Complete |
| AUTH-06 | Phase 2 | Complete |
| BRAND-01 | Phase 3 | Pending |
| BRAND-02 | Phase 3 | Pending |
| BRAND-03 | Phase 3 | Pending |
| BRAND-04 | Phase 3 | Pending |
| STRIPE-01 | Phase 3 | Pending |
| STRIPE-02 | Phase 3 | Pending |
| STRIPE-03 | Phase 3 | Pending |
| STRIPE-04 | Phase 3 | Pending |
| STRIPE-05 | Phase 3 | Pending |
| PAY-01 | Phase 4 | Complete |
| PAY-02 | Phase 4 | Complete |
| PAY-03 | Phase 4 | Complete |
| PAY-04 | Phase 4 | Complete |
| PAY-05 | Phase 4 | Complete |
| PAY-06 | Phase 4 | Complete |
| PAY-07 | Phase 4 | Complete |
| PAY-08 | Phase 4 | Complete |
| CLIENT-01 | Phase 5 | Pending |
| CLIENT-02 | Phase 5 | Pending |
| CLIENT-03 | Phase 5 | Pending |
| CLIENT-04 | Phase 5 | Pending |
| CLIENT-05 | Phase 5 | Pending |
| CLIENT-06 | Phase 5 | Pending |
| CLIENT-07 | Phase 5 | Pending |
| CLIENT-08 | Phase 5 | Pending |
| WEBHOOK-01 | Phase 6 | Complete |
| WEBHOOK-02 | Phase 6 | Complete |
| WEBHOOK-03 | Phase 6 | Complete |
| WEBHOOK-04 | Phase 6 | Complete |
| WEBHOOK-05 | Phase 6 | Complete |
| WEBHOOK-06 | Phase 6 | Complete |
| NOTIFY-01 | Phase 7 | Pending |
| NOTIFY-02 | Phase 7 | Pending |
| DASH-01 | Phase 7 | Pending |
| DASH-02 | Phase 7 | Pending |
| DASH-03 | Phase 7 | Pending |
| DASH-04 | Phase 7 | Pending |
| SEC-01 | Phase 1 | Pending |
| SEC-02 | Phase 4 | Complete |
| SEC-03 | Phase 6 | Complete |
| SEC-04 | Phase 5 | Pending |
