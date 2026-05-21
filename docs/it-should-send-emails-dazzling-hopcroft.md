# Plan: Send Emails to Real Gmail Inbox

## Context

Currently `.env` uses Mailtrap sandbox SMTP — all emails are intercepted, never reach a real inbox. User wants payment notification emails delivered to `salmanabdulghani.cis@gmail.com`.

The `SendPaymentNotification` job queries `User::role('admin')` and sends `PaymentSucceeded` mailable to each admin's `email` attribute. Two things must change:
1. Mail driver → Gmail SMTP so emails actually deliver
2. Admin user's `email` in DB → `salmanabdulghani.cis@gmail.com` so the mailable targets the right inbox

---

## Prerequisites (manual — user must do this first)

1. Enable **2-Step Verification** on `salmanabdulghani.cis@gmail.com` Google account
2. Go to [Google Account → Security → App Passwords](https://myaccount.google.com/apppasswords)
3. Generate an App Password for "Mail" → copy the 16-char password
4. Provide that password for step 1 below

---

## Step 1 — Update `.env` mail settings

**File:** `.env`

Replace current Mailtrap block:
```
MAIL_MAILER=smtp
MAIL_HOST=sandbox.smtp.mailtrap.io
MAIL_PORT=2525
MAIL_USERNAME=<mailtrap-user>
MAIL_PASSWORD=<mailtrap-pass>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=admin@payhub.com
MAIL_FROM_NAME="${APP_NAME}"
```

With Gmail SMTP:
```
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=salmanabdulghani.cis@gmail.com
MAIL_PASSWORD=<16-char-app-password>
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=salmanabdulghani.cis@gmail.com
MAIL_FROM_NAME="${APP_NAME}"
```

---

## Step 2 — Update admin user email in DB

Run via tinker (no migration needed — just a DB row update):

```bash
php artisan tinker --execute 'App\Models\User::where("email", "admin@payhub.test")->update(["email" => "salmanabdulghani.cis@gmail.com"]);'
```

---

## Step 3 — Update seeder so `migrate:fresh --seed` stays consistent

**File:** `database/seeders/DatabaseSeeder.php` line 42

Change:
```php
['email' => 'admin@payhub.test'],
```
To:
```php
['email' => 'salmanabdulghani.cis@gmail.com'],
```

---

## Verification

Follow CLAUDE.md E2E flow:
1. Start queue worker: `php artisan queue:work --verbose`
2. Start Stripe CLI: `stripe listen --forward-to localhost:8000/webhook/stripe/1`
3. Set webhook secret on Stripe account
4. Create a payment at `/payments/create`, open `/pay/{uuid}`, submit test card `4242 4242 4242 4242`
5. Check Gmail inbox for `Payment received — ...` email
6. Terminal 1 should show `SendPaymentNotification` processed; Terminal 2 should show `<-- 200 POST /webhook/stripe/1`

No test changes needed — existing `NotificationTest.php` mocks `Mail::fake()` and is unaffected by SMTP config.
