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

- [x] **BRAND-01**: Admin can create a brand (name, logo, primary color, secondary color)
- [x] **BRAND-02**: Admin can edit brand details
- [x] **BRAND-03**: Admin can list all brands
- [x] **BRAND-04**: System detects test vs live Stripe keys and blocks test keys in production

### Stripe Account Management (STRIPE)

- [x] **STRIPE-01**: Admin can add a Stripe account (publishable key + secret key) and link it to a brand
- [x] **STRIPE-02**: Secret key is encrypted at rest using AES-256 (Laravel encrypted cast)
- [x] **STRIPE-03**: System validates the key pair against Stripe API on save
- [x] **STRIPE-04**: Admin can edit an existing Stripe account's keys
- [x] **STRIPE-05**: Admin can deactivate or archive a Stripe account without deleting it

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

- [x] **CLIENT-01**: Client opens payment page (/pay/{uuid}) without login
- [x] **CLIENT-02**: Payment page displays the correct brand's logo and colors
- [x] **CLIENT-03**: Stripe Elements form is embedded inline (no redirect to stripe.com)
- [x] **CLIENT-04**: Stripe Elements is initialized with the correct brand's publishable key
- [x] **CLIENT-05**: System handles 3DS / SCA authentication challenges (requires_action)
- [x] **CLIENT-06**: Client sees a success page after payment is confirmed
- [x] **CLIENT-07**: Client sees an error/failure page if payment fails
- [x] **CLIENT-08**: Payment page is mobile-responsive

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

### Revolut Payment Provider (REVOLUT)

- [x] **REVOLUT-01**: Admin can add a Revolut account (`secret_key` + `webhook_signature_key`), encrypted at rest
- [x] **REVOLUT-02**: Admin or User can create a payment against an active Revolut account
- [x] **REVOLUT-03**: Client pay page creates a Merchant API order and completes checkout under the correct Revolut account
- [x] **REVOLUT-04**: `/webhook/revolut/{account}` verifies HMAC-SHA256 `v1.{timestamp}.{rawBody}` signature (300s replay tolerance) and is the sole writer of payment status
- [x] **REVOLUT-05**: Revolut webhook idempotency is keyed on `order_id:event_type` (`ProcessedRevolutEvent`) since Revolut delivers no event id
- [x] **REVOLUT-06**: Revolut rows appear correctly in CSV export and dashboard/filter surfaces

### Square Payment Provider (SQUARE)

- [x] **SQUARE-01**: Admin can add a Square account (`access_token` + `webhook_signature_key`), encrypted at rest; sandbox credentials blocked in production
- [x] **SQUARE-02**: A `SquareAccount` is single-currency; creating/updating a payment against a mismatched-currency account is rejected
- [x] **SQUARE-03**: Client pay page tokenizes via Square Web Payments SDK; amount always read server-side from the `Payment` record
- [x] **SQUARE-04**: `/webhook/square/{squareAccount}` verifies `x-square-hmacsha256-signature` via the Square SDK's `WebhooksHelper`
- [x] **SQUARE-05**: Square webhook idempotency tracked via `ProcessedSquareEvent`
- [x] **SQUARE-06**: Square rows appear correctly in CSV export, including Provider Reference (fixed — was blank, commit `2aa7451`)
- [x] **SQUARE-07**: Square rows appear correctly in dashboard/filter surfaces, including "Accounts Today" account resolution

### Viva Payments Provider (VIVA)

- [x] **VIVA-01**: Admin can add a Viva account (`client_secret`, `api_key`, `webhook_verification_key`), encrypted at rest; demo credentials blocked in production
- [x] **VIVA-02**: Creating or updating a payment against a Viva account is rejected unless currency is GBP (flat platform rule, not per-account)
- [x] **VIVA-03**: Client pay page redirects to Viva Smart Checkout via an OAuth2 client_credentials order; `viva_order_code` captured at order-creation time
- [x] **VIVA-04**: `/webhook/viva/{vivaAccount}` verifies the `Viva-Signature` header and is the sole writer of completed status; idempotency via `ProcessedVivaEvent`
- [x] **VIVA-05**: Viva rows appear correctly in CSV export and the payment detail page — Provider Reference falls back to `viva_order_code` when `viva_transaction_id` isn't set yet (fixed 2026-07-11)
- [x] **VIVA-06**: Moving a Viva payment to a different account clears both `viva_order_code` and `viva_transaction_id` (fixed 2026-07-11, same merge)
- [ ] **VIVA-07**: Failed Viva payments transition to `failed` status via webhook — blocked on confirming the `TransactionFailed` event type id against a live sandbox; currently a failed Viva payment stays `pending` indefinitely

### Security (SEC)

- [x] **SEC-01**: Stripe secret keys and webhook secrets are encrypted at rest (AES-256 via Laravel encrypted cast)
- [x] **SEC-02**: Payment amount is read exclusively from the server-side Payment record — no client input accepted for amount
- [x] **SEC-03**: Webhook routes are excluded from CSRF middleware; raw body is preserved for signature verification
- [x] **SEC-04**: PaymentIntent client_secret is never logged, stored in URLs, or exposed beyond the payment page response

---

## v2 Requirements (Deferred)

- Client email receipt on payment success (post-v1 — requires per-brand email sender config decision)
- Per-brand subdomains (pay.brandA.com) — DNS/SSL complexity, defer until brand count justifies
- Invite-only registration flow — Admin creates accounts manually for v1
- Cancel/void a payment link — Admin can invalidate without deleting
- Duplicate a payment link — re-use as template
- Refund from dashboard (via Stripe API)
- Slack/webhook notifications
- SSO (Google/Microsoft)
- Duplicate event deduplication (webhook idempotency)
- Retry visibility / dead-letter queue UI
- APP_KEY rotation runbook documentation
- Cross-brand analytics with charts

**Delivered post-v1** (was listed here as deferred, since implemented):
- ~~CSV export of payment history~~ — delivered (`PaymentsExport`, `payments.export` route), covers all four providers

**Carried over from the stale-provider-txn-id fix (2026-07-10/11)**, tracked in memory `project-stale-provider-txn-ids`:
- Read-side stale-id recovery (à la `reusableStripePaymentIntent()`) for Revolut/Square/Viva pay pages — only Stripe has it
- Amount-drift protection on reused provider orders for Revolut/Square/Viva

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
| BRAND-01 | Phase 3 | Complete |
| BRAND-02 | Phase 3 | Complete |
| BRAND-03 | Phase 3 | Complete |
| BRAND-04 | Phase 3 | Complete |
| STRIPE-01 | Phase 3 | Complete |
| STRIPE-02 | Phase 3 | Complete |
| STRIPE-03 | Phase 3 | Complete |
| STRIPE-04 | Phase 3 | Complete |
| STRIPE-05 | Phase 3 | Complete |
| PAY-01 | Phase 4 | Complete |
| PAY-02 | Phase 4 | Complete |
| PAY-03 | Phase 4 | Complete |
| PAY-04 | Phase 4 | Complete |
| PAY-05 | Phase 4 | Complete |
| PAY-06 | Phase 4 | Complete |
| PAY-07 | Phase 4 | Complete |
| PAY-08 | Phase 4 | Complete |
| CLIENT-01 | Phase 5 | Complete |
| CLIENT-02 | Phase 5 | Complete |
| CLIENT-03 | Phase 5 | Complete |
| CLIENT-04 | Phase 5 | Complete |
| CLIENT-05 | Phase 5 | Complete |
| CLIENT-06 | Phase 5 | Complete |
| CLIENT-07 | Phase 5 | Complete |
| CLIENT-08 | Phase 5 | Complete |
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
| SEC-01 | Phase 1 | Complete |
| SEC-02 | Phase 4 | Complete |
| SEC-03 | Phase 6 | Complete |
| SEC-04 | Phase 5 | Complete |
| REVOLUT-01 | Phase 8 | Complete |
| REVOLUT-02 | Phase 8 | Complete |
| REVOLUT-03 | Phase 8 | Complete |
| REVOLUT-04 | Phase 8 | Complete |
| REVOLUT-05 | Phase 8 | Complete |
| REVOLUT-06 | Phase 8 | Complete |
| SQUARE-01 | Phase 9 | Complete |
| SQUARE-02 | Phase 9 | Complete |
| SQUARE-03 | Phase 9 | Complete |
| SQUARE-04 | Phase 9 | Complete |
| SQUARE-05 | Phase 9 | Complete |
| SQUARE-06 | Phase 9 | Complete |
| SQUARE-07 | Phase 9 | Complete |
| VIVA-01 | Phase 10 | Complete |
| VIVA-02 | Phase 10 | Complete |
| VIVA-03 | Phase 10 | Complete |
| VIVA-04 | Phase 10 | Complete |
| VIVA-05 | Phase 10 | Complete |
| VIVA-06 | Phase 10 | Complete |
| VIVA-07 | Phase 10 | Pending (needs live sandbox to confirm TransactionFailed event id) |
