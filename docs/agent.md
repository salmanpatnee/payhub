# PayHub — Agent Reference

## RBAC & Roles

Two roles exist: `admin` and `agent`. There is no `user` role.

### Nav access matrix

| Nav item        | Admin | Agent |
|-----------------|-------|-------|
| Brands          | ✓     | ✗     |
| Stripe Accounts | ✓     | ✗     |
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
