# PayHub — Agent Reference

## RBAC & Roles

Three roles exist: `admin`, `agent`, and `account`. There is no `user` role.

The `account` role is a **read-only Payments viewer** for finance staff (will own the
payments export feature in a future phase). It sees **all** payments (no `user_id` scope,
like admin) but cannot create, edit, delete, copy links, or open the Show page — index +
filters only. It has no Stripe account / brand / RM mappings (like admin).

The `account` role is read-only for Payments only. For **Bank Accounts** it has the same
full manage rights as admin (see the Bank Accounts section below).

### Nav access matrix

| Nav item        | Admin | Agent | Account |
|-----------------|-------|-------|---------|
| Brands          | ✓     | ✗     | ✗       |
| Stripe Accounts | ✓     | ✗     | ✗       |
| Square Accounts | ✓     | ✗     | ✗       |
| Users           | ✓     | ✗     | ✗       |
| Payments        | ✓     | ✓     | ✓ (read-only) |
| Bank Accounts   | ✓ (manage) | ✓ (own assigned, view only) | ✓ (manage) |
| Zelle Accounts  | ✓ (manage) | ✓ (own assigned, view only) | ✓ (manage) |
| Settings        | ✓     | ✗     | ✗       |

### Implementation rules

- Sidebar nav items must only render for roles that can access them
- `isAdmin` computed in `resources/js/components/AppSidebar.vue` gates admin-only links — **never render inaccessible links**
- Backend routes protected by `role:admin` middleware — frontend hiding is UX only, backend is the real gate
- Form request validation: `'role' => ['required', 'string', 'in:admin,agent,account']` — never `in:admin,user`
- Account read-only enforcement is server-side: `PaymentController::index` sends a `readOnly` prop and scopes data via `$canViewAll = isAdmin || isAccount`; `PaymentPolicy::create` (admin|agent) blocks create/store; `view`/`update`/`delete` already 403 for account (not admin, owns no payments)
- Seeder: `Role::firstOrCreate(['name' => 'account', 'guard_name' => 'web'])`
- Role badge display: use Tailwind `capitalize` class on the badge element

### Seeded users (dev)

| Email             | Role  | Password |
|-------------------|-------|----------|
| admin@payhub.test   | admin   | password |
| agent@payhub.test   | agent   | password |
| account@payhub.test | account | password |

---

## Bank Accounts

Bank details that agents share with clients for bank-transfer payments. Independent of the
payment providers.

- **Access**: `BankAccountPolicy` — `admin` and `account` can create, update, delete, activate/deactivate and view the activity log. `agent` can only view the index, limited to **active** accounts assigned to them (`bank_account_user` pivot); `bank_address` is hidden from agents. Nav item is visible to all three roles.
- **Assignment**: only `agent`-role users are offered in the Create/Edit dropdowns; already-assigned users stay listed on Edit.
- **Currency**: `App\Enums\BankAccountCurrency` — USD, GBP, PKR (Payments themselves stay USD/GBP only).
- **Validation**: `StoreBankAccountRequest` / `UpdateBankAccountRequest` — at least one of sort code, routing number, or IBAN is required.
- **Soft delete**: `bank_accounts.deleted_at`; deleted accounts drop out of the index and agents' assigned list, but stay visible in the activity log.
- **Activity log**: `App\Services\ActivityLogger` writes created / updated / deleted / activated / deactivated rows to `activity_logs` (subject = `BankAccount`, with field-level before/after and assigned-user changes). Viewed at `bank-accounts/activity-log` (`BankAccountActivityLogController`), which must be registered **before** the resource route.
- **Routes**: `bank-accounts` resource (no `show`), plus `PATCH bank-accounts/{id}/activate` and `/deactivate`.
- **Tests**: `tests/Feature/BankAccountManagementTest.php`, `tests/Feature/BankAccountActivityLogTest.php`.

---

## Zelle Accounts

Zelle details (holder name, email, optional mobile, USD/GBP) that agents share with clients. Independent of the payment providers. Spec: `docs/specs/0003-zelle-accounts-module/index.md`.

- **Access**: `ZelleAccountPolicy` — `admin` and `account` can create, update, delete, activate/deactivate. `agent` sees only **active**, non-deleted accounts assigned to them (`user_zelle_account` pivot) as read-only cards with copy buttons. Nav item is visible to all three roles.
- **Assignment**: only `agent`-role ids are accepted (server-side). An update with no `user_ids` key leaves assignments alone; `user_ids: []` clears them.
- **Validation**: `StoreZelleAccountRequest` / `UpdateZelleAccountRequest` — email lowercased and unique among non-deleted rows (app-level, no DB index); mobile allows digits, spaces, `+ - ( )`, max 20.
- **List**: admin/account list is paginated 15/page with search (email or mobile, punctuation ignored, `%`/`_` literal), currency and status filters.
- **No activity log**, by design.
- **Routes**: `zelle-accounts` resource (no `show`), plus `PATCH zelle-accounts/{id}/activate` and `/deactivate`.
- **Tests**: `tests/Feature/ZelleAccountManagementTest.php`.

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
