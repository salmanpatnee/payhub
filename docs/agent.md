# PayHub — Agent Reference

## RBAC & Roles

Two roles exist: `admin` and `agent`. There is no `user` role.

### Nav access matrix

| Nav item        | Admin | Agent |
|-----------------|-------|-------|
| Brands          | ✓     | ✗     |
| Stripe Accounts | ✓     | ✗     |
| Square Accounts | ✓     | ✗     |
| Users           | ✓     | ✗     |
| Payments        | ✓     | ✓     |
| Settings        | ✓     | ✓     |

### Implementation rules

- Sidebar nav items must only render for roles that can access them
- `isAdmin` computed in `resources/js/components/AppSidebar.vue` gates admin-only links — **never render inaccessible links**
- Backend routes protected by `role:admin` middleware — frontend hiding is UX only, backend is the real gate
- Form request validation: `'role' => ['required', 'string', 'in:admin,agent']` — never `in:admin,user`
- Seeder: `Role::firstOrCreate(['name' => 'agent', 'guard_name' => 'web'])`
- Role badge display: use Tailwind `capitalize` class on the badge element

### Seeded users (dev)

| Email             | Role  | Password |
|-------------------|-------|----------|
| admin@payhub.test | admin | password |
| user@payhub.test  | agent | password |

---

## Reference / Order Code Format

Payments have a `reference_code` integer column (auto-incremented, stored as integer cents, nullable).

**Display format everywhere:** `#` prefix + 6-digit zero-padded integer.

- PHP: `'#' . str_pad((string) $code, 6, '0', STR_PAD_LEFT)` — see `ClientPaymentController::formatReferenceCode()`
- Vue/JS: `'#' + String(payment.reference_code).padStart(6, '0')`
- Examples: `#000001`, `#000042`, `#001337`
- Null guard: show `—` (em dash) when `reference_code` is `null`

---

## Local E2E Testing — Notifications + Webhooks

**Prerequisites:** Mailtrap SMTP credentials in `.env` (`MAIL_MAILER=smtp`, `MAIL_HOST=sandbox.smtp.mailtrap.io`).

**Step 1 — Terminal 1: start queue worker**
```bash
php artisan queue:work --verbose
```

**Step 2 — Terminal 2: start Stripe CLI listener**
```bash
stripe listen --forward-to localhost:8000/webhook/stripe/1
```
Copy the `whsec_...` signing secret printed at startup.

**Step 3 — Set webhook secret on the Stripe account**

Go to `http://payhub.test/admin/stripe-accounts/1/edit` and paste the `whsec_...` into the Webhook secret field, then save.

Or via tinker (one-off):
```bash
php artisan tinker --execute 'App\Models\StripeAccount::find(1)->update(["webhook_secret" => "whsec_..."]);'
```

**Step 4 — Create a payment and pay it**

1. Go to `http://payhub.test/payments/create` and create a payment
2. Open the `/pay/{uuid}` link
3. Submit test card `4242 4242 4242 4242`, any expiry/CVC

Stripe fires `payment_intent.succeeded` → CLI forwards to the webhook endpoint → `HandleStripeWebhookJob` updates payment to `completed` → `SendPaymentNotification` dispatched → `PaymentSucceeded` mailable sent to all admin users → appears in Mailtrap inbox.

**Verification**
- Terminal 2 shows `<-- 200 POST /webhook/stripe/1`
- Terminal 1 shows `HandleStripeWebhookJob` and `SendPaymentNotification` processed
- Mailtrap inbox receives email with subject `Payment received — {client_name} (...)`
- DB: `payments` row has `status = completed`, `paid_at` set

---

## Square — Second Payment Rail (failover)

Square mirrors the Stripe architecture additively. It exists so that when a Stripe account is
closed (chargeback-driven), admins can immediately issue new payment links on a Square account.
Stripe code paths are untouched; `payments.provider` (`stripe`|`square`) discriminates.

### Per-account isolation

Each `square_accounts` row is one Square Developer **application** (its own `application_id`,
`access_token`, `location_id`, and webhook signature key). Square webhook subscriptions are
application-owned and cannot use OAuth tokens, so one app per account keeps signature keys
isolated — exactly like multi-Stripe. Never use a global Square token; the code always builds a
per-account `new SquareClient($account->access_token, options: ['baseUrl' => ...])`.

### Setup per Square account (sandbox first)

1. Create a Square Developer app → copy **Application ID** and **Sandbox Access Token**.
2. Open the app's **Locations** → copy a **Location ID** (required on every charge).
3. In PayHub: `http://payhub.test/admin/square-accounts/create` — enter account name,
   environment = `sandbox`, the Application ID, Location ID, and Access Token. "Test connection"
   calls `locations->list()` to validate the token.
4. Create a **webhook subscription** in the Square app for the `payment.updated` event, with the
   notification URL set to the **exact** value shown on the account Edit page
   (`/webhook/square/{id}`). It must byte-match or signature verification fails (403).
5. Copy the subscription's **Signature Key** into the account Edit page → save.
6. Validate end-to-end in sandbox, then add **production** credentials (a separate account row or
   switch environment + tokens). Sandbox credentials are rejected when `APP_ENV=production`.

### Payment flow (embedded, mirrors Stripe Elements)

1. Create a payment selecting the Square account in the merged **Payment Account** dropdown.
2. `/pay/{uuid}` renders the Square Web Payments SDK card form (env-correct CDN).
3. On submit the SDK tokenizes the card (`card.tokenize()`), runs `verifyBuyer` for SCA (UK),
   then POSTs `{ source_id, verification_token }` to `POST /pay/{uuid}/square`.
4. The server charges via `$square->payments->create(...)` using the **DB amount** (never the
   client's) and stores the returned Square payment id in `payments.square_payment_id`.
   **Status stays `pending`** — the charge response is never trusted for the DB status write.
5. Square fires `payment.updated` → `SquareWebhookController` verifies the HMAC-SHA256 signature
   (`x-square-hmacsha256-signature`), records the `event_id` in `processed_square_events` for
   idempotency, and dispatches `HandleSquareWebhookJob`, which maps `COMPLETED`→`completed`
   (+`paid_at`), `FAILED`→`failed`, `CANCELED`→`cancelled`, then reuses `SendPaymentNotification`.

### Sandbox test card

`4111 1111 1111 1111`, any future expiry, CVC `111`, ZIP `94103`.

### Local webhook testing

Square has no local CLI forwarder like Stripe. Use a tunnel (e.g. ngrok) so the subscription's
notification URL is publicly reachable, and set that exact URL both in the Square subscription and
as `APP_URL` (the controller derives the verification URL from `route('webhook.square', ...)`).
