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
- **Currency**: `App\Enums\BankAccountCurrency` — USD, GBP, PKR (Payments are USD/GBP, plus PKR only on MPGS accounts flagged `supports_pkr`; admins pick it freely, agents need a PKR slot assigned by an admin).
- **Validation**: `StoreBankAccountRequest` / `UpdateBankAccountRequest` — at least one of sort code, routing number, or IBAN is required.
- **Soft delete**: `bank_accounts.deleted_at`; deleted accounts drop out of the index and agents' assigned list, but stay visible in the activity log.
- **Activity log**: `App\Services\ActivityLogger` writes created / updated / deleted / activated / deactivated rows to `activity_logs` (subject = `BankAccount`, with field-level before/after and assigned-user changes). Viewed at `bank-accounts/activity-log` (`BankAccountActivityLogController`), which must be registered **before** the resource route.
- **Routes**: `bank-accounts` resource (no `show`), plus `PATCH bank-accounts/{id}/activate` and `/deactivate`.
- **Tests**: `tests/Feature/BankAccountManagementTest.php`, `tests/Feature/BankAccountActivityLogTest.php`.

---

## Zelle Accounts

Zelle details (holder name, email, optional mobile; currency is not an input, new accounts default to USD) that agents share with clients. Independent of the payment providers. Spec: `docs/specs/0003-zelle-accounts-module/index.md`.

- **Access**: `ZelleAccountPolicy` — `admin` and `account` can create, update, delete, activate/deactivate. `agent` sees only **active**, non-deleted accounts assigned to them (`user_zelle_account` pivot) as read-only cards with copy buttons. Nav item is visible to all three roles.
- **Assignment**: only `agent`-role ids are accepted (server-side). `user_ids` is required with at least one agent, on both create and update.
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

---

## Authorize.net — Accept.js Provider

Seventh provider (`payments.provider = authorizenet`). Spec: `docs/specs/0007-authorizenet-acceptjs-own-card-fields/` (0006, the hosted iframe, is superseded).

### Flow

1. The pay page (`PayAuthorizenet.vue` + `AuthorizenetCardForm.vue`) loads Accept.js from the per-environment `script` host in `config/services.php`.
2. Accept.js turns the typed card into a one time code (`opaqueData`). Card values live only in component refs.
3. The browser posts the code, ZIP and the consent-gated request to `POST /pay/{payment}/authorizenet/charge` (CSRF on, limiter `authorizenet-charge`). Amount and currency come from the `Payment` row, never the request.
4. `AuthorizenetPaymentController::charge` takes a per payment lock, calls `createTransaction` (invoice number from `AuthorizenetInvoice::for($payment)`, 300 second `duplicateWindow`), reads the transaction back, and writes status only through `AuthorizenetChargeResolver`.
5. Outcomes: `approved`, `held`, `declined`, `check_details`, `unclear`, `error` (mapped in `AuthorizenetChargeReply`, the only place that reads reply fields). Bad card (error 6) and expired card (error 8) come back as response 3 and map to `check_details`; a duplicate (error 11) maps to `unclear`.
6. A held charge stays `pending` with a transaction id; the pay page gets `held: true` on reload and keeps polling `GET /pay/{payment}/authorizenet/status`. An unclear charge pauses new charges for 120 seconds and queues `ResolveAuthorizenetCharge`.
7. Safety nets: `AuthorizenetStalePaymentSweep` runs every 10 minutes (`authorizenet:sweep-stale-payments`), and the webhook (`webhook/authorizenet/{authorizenetAccount}`, signature header `X-ANET-Signature`, idempotency in `processed_authorizenet_events`) only triggers a lookup.

### Accounts

- Admin CRUD at `admin/authorizenet-accounts` (plus test connection and activate/deactivate). `transaction_key` and `signature_key` use the `encrypted` cast and are not mass-assignable.
- `public_client_key` (a public Accept.js identifier) is fetched from `getMerchantDetails` on create, update and test connection. An account without one is treated as unavailable (the pay page 404s).
- Currency: single-currency per account (`authorizenet_accounts.currency`, like Square). `StorePaymentRequest` rejects a payment whose currency differs from the account's ("This Authorize.net account only accepts {currency} payments.").

### Sandbox testing notes

- Approved test card `4111 1111 1111 1111`, any future expiry, any CVV and ZIP.
- The sandbox account is not in Test Mode, so the "amount equals error code" trick does not work. Card code `901` gives error 65 (`check_details`), ZIP `46282` gives error 2 (`declined`), a card that fails the Luhn check gives error 6, an old expiry gives error 8.
- Webhooks need a public URL (a tunnel or the staging server). Local `*.test` addresses cannot be reached by Authorize.net.

### Upgrading a database from the iframe version

Run `php artisan migrate`. Migration `2026_10_21_000005_upgrade_authorizenet_to_accept_js` adds `public_client_key`, copies the old token timestamps into `authorizenet_first_attempt_at` / `authorizenet_last_attempt_at` and drops the old columns. It is safe to run on a fresh database. Never `migrate:fresh` a database with real data.

---

## Serving PayHub from a folder (e.g. `https://example.com/payhub/`)

PayHub works at the top of a domain and from a folder. One build serves both: the root view (`resources/views/app.blade.php`) writes the folder into `<meta name="app-base">` from `request()->getBasePath()`, and `resources/js/lib/basePath.ts` reads it. At the top of a domain the value is empty and nothing changes.

What the front end does when the value is set (e.g. `/payhub`):

- Adds the folder to every Inertia request (`http.onRequest`), every `fetch('/...')` call and every `<a href="/...">` (including `Link`). Wayfinder routes and hard-coded `/pay/...` strings keep working unchanged.
- Script chunks load through `window.__assetUrl()` (set in `basePath.ts`, wired by `experimental.renderBuiltUrl` in `vite.config.ts`), and the root view rewrites the `@fonts` addresses.
- `useCurrentUrl` strips the folder before comparing with sidebar links, so active highlighting still works.
- Code that sets `window.location.href` by hand must wrap the path in `withBase()`.

What the server does: Laravel works out the folder from the script path, so `route()`, redirects, `asset()` and the Authorize.net webhook URL all include it. This only works when the web server runs PayHub's `public/index.php` **from that folder**, so do not point `/payhub` at the project root with a rewrite rule.

### cPanel setup (symlink, recommended)

1. Keep the Laravel project outside the web folder, e.g. `~/payhub-app` (not inside `public_html`, so `.env` and the code cannot be reached from the web).
2. Link the web folder to the app's `public` directory: `ln -s ~/payhub-app/public ~/public_html/payhub` (use the real document root of the domain). Remove any old `payhub` folder or custom `.htaccess` rewrite first.
3. In `.env` set `APP_URL=https://example.com/payhub`. `ASSET_URL` is not needed.
4. `php artisan migrate --force`, then `php artisan config:clear` and `php artisan view:clear`, and reset opcache.
5. Open `https://example.com/payhub/login`.
6. Set payment-provider webhook URLs to include the folder, e.g. `https://example.com/payhub/webhook/authorizenet/{account id}`.

If the host does not allow symlinks, copy the contents of `public/` into `public_html/payhub/` instead and edit its `index.php` so the two `__DIR__.'/../...'` paths point at the app directory (`~/payhub-app`). Copy `public/build` again after every deploy.

### Testing a folder locally

Run a router script with PHP's built-in server that maps `/payhub/*` to `public/` and sets `SCRIPT_NAME` to `/payhub/index.php` (this is what Apache does with the symlink). Hide `public/hot` first so the built assets are used, and put it back afterwards.
