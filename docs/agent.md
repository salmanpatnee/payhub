# PayHub — Agent Reference

## RBAC & Roles

Three roles exist: `admin`, `agent`, and `account`. There is no `user` role.

The `account` role is a **read-only Payments viewer** for finance staff (will own the
payments export feature in a future phase). It sees **all** payments (no `user_id` scope,
like admin) but cannot create, edit, delete, copy links, or open the Show page — index +
filters only. It has no Stripe account / brand / RM mappings (like admin).

### Nav access matrix

| Nav item        | Admin | Agent | Account |
|-----------------|-------|-------|---------|
| Brands          | ✓     | ✗     | ✗       |
| Stripe Accounts | ✓     | ✗     | ✗       |
| Users           | ✓     | ✗     | ✗       |
| Payments        | ✓     | ✓     | ✓ (read-only) |
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
