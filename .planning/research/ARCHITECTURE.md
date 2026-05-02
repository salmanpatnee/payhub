# Architecture Patterns: PayHub Multi-Brand Payment System

**Domain:** Multi-brand centralized payment infrastructure
**Researched:** 2026-05-02
**Overall confidence:** HIGH (patterns verified against Stripe docs, Laravel 12 docs, and community sources)

---

## System Overview

PayHub is a monolithic Laravel 12 application with two distinct user surfaces on a single domain:

1. **Admin/User Panel** — Authenticated, Inertia.js SPA feel, manages brands, Stripe accounts, and payments
2. **Client Payment Page** — Unauthenticated, UUID-addressed, brand-styled, Stripe Elements embedded

Both surfaces are served by the same Laravel application. The key architectural challenge is that each payment is scoped to a specific Stripe account with its own credentials, theming, and webhook secret.

---

## Recommended Architecture

### High-Level Component Map

```
Browser (Admin/User)
    |
    | [Inertia requests, authenticated]
    v
Laravel Router (web.php)
    |-- /admin/* --> AdminController group (auth middleware)
    |-- /payments/* --> UserController group (auth middleware)
    |
    v
HandleInertiaRequests middleware (shares auth user, flash)
    |
    v
Controllers --> Services --> Eloquent Models --> MySQL

Browser (Client)
    |
    | [Plain HTTP, unauthenticated]
    v
Laravel Router (web.php)
    |-- /pay/{uuid} --> ClientPaymentController (no auth, BrandResolution middleware)
    |
    v
BrandResolution Middleware
    |  Looks up Payment by UUID
    |  Resolves Brand + StripeAccount
    |  Attaches brand context to Request attributes
    v
ClientPaymentController
    |  Returns Inertia page (or Blade view) with:
    |    - brand theming (colors, logo)
    |    - stripe_publishable_key (from resolved StripeAccount)
    |    - payment client_secret (from PaymentIntent on Stripe)
    v
Client Browser renders Stripe Elements
    |
    v
Stripe.com (payment confirmation)
    |
    v
Stripe Webhook --> /webhook/stripe/{account_id} --> WebhookController
    |
    v
WebhookService --> verifies signature --> dispatches PaymentCompleted event
    |
    v
PaymentCompleted listener --> updates Payment status --> sends notifications
```

---

## Component Boundaries

### Models Layer

| Model | Responsibility | Key Fields | Relationships |
|-------|---------------|------------|---------------|
| `Brand` | Represents one client brand, owns theming config | `id`, `name`, `slug`, `logo_url`, `primary_color`, `secondary_color`, `stripe_account_id` | `hasOne(StripeAccount)`, `hasMany(Payment)` |
| `StripeAccount` | Stores credentials for one Stripe account, encrypted at rest | `id`, `brand_id`, `account_name`, `publishable_key`, `secret_key` (encrypted), `webhook_secret` (encrypted), `is_active` | `belongsTo(Brand)`, `hasMany(Payment)` |
| `Payment` | Core record linking a charge to a brand + stripe account + client | `id`, `uuid`, `brand_id`, `stripe_account_id`, `user_id`, `amount`, `currency`, `description`, `status` (pending/processing/succeeded/failed), `stripe_payment_intent_id`, `client_email`, `client_name`, `expires_at`, `paid_at` | `belongsTo(Brand)`, `belongsTo(StripeAccount)`, `belongsTo(User)` |
| `User` | Admin or staff user creating payments | `id`, `name`, `email`, `password`, `role` | `hasMany(Payment)` |

**Encryption on StripeAccount:**
Use Laravel 12's built-in `encrypted` cast on the `StripeAccount` model. This uses AES-256-CBC via the app's `APP_KEY`. The `secret_key` and `webhook_secret` columns must be `TEXT` type in migrations (encrypted values are longer than plain text).

```php
// App\Models\StripeAccount
protected function casts(): array
{
    return [
        'secret_key'     => 'encrypted',
        'webhook_secret' => 'encrypted',
        'is_active'      => 'boolean',
    ];
}
```

Encrypted attributes cannot be queried/searched in SQL. Design all lookups to go through Eloquent, never raw WHERE clauses on these columns.

---

### Services Layer

Services live in `app/Services/`. No repository layer — Eloquent is used directly inside services. This is the current Laravel community consensus for most projects.

#### `StripeService`

**Responsibility:** All Stripe API interactions. Instantiates `\Stripe\StripeClient` dynamically per account rather than using a global key.

**Key methods:**

```php
// Creates a per-account Stripe client
public function clientFor(StripeAccount $account): \Stripe\StripeClient

// Creates a PaymentIntent under a specific account's secret key
public function createPaymentIntent(Payment $payment, StripeAccount $account): string // returns client_secret

// Retrieves PaymentIntent status from Stripe
public function retrievePaymentIntent(string $intentId, StripeAccount $account): \Stripe\PaymentIntent
```

**Critical pattern:** Never use `\Stripe\Stripe::setApiKey()` globally. Always instantiate `new \Stripe\StripeClient($decryptedSecretKey)` per call. This is the correct multi-account pattern for non-Connect scenarios where each brand owns a completely separate Stripe account.

```php
public function clientFor(StripeAccount $account): \Stripe\StripeClient
{
    // $account->secret_key is auto-decrypted by the 'encrypted' cast
    return new \Stripe\StripeClient($account->secret_key);
}

public function createPaymentIntent(Payment $payment, StripeAccount $account): string
{
    $stripe = $this->clientFor($account);
    $intent = $stripe->paymentIntents->create([
        'amount'      => $payment->amount, // in cents
        'currency'    => $payment->currency,
        'description' => $payment->description,
        'metadata'    => ['payment_uuid' => $payment->uuid],
    ]);
    return $intent->client_secret;
}
```

#### `WebhookService`

**Responsibility:** Verifies Stripe webhook signatures and routes event types.

**Key pattern:** Each Stripe account has its own registered webhook endpoint (URL includes account identifier) and its own webhook signing secret stored in `StripeAccount.webhook_secret`.

```php
public function verify(Request $request, StripeAccount $account): \Stripe\Event
{
    // $account->webhook_secret auto-decrypted via cast
    return \Stripe\Webhook::constructEvent(
        $request->getContent(),         // raw body
        $request->header('Stripe-Signature'),
        $account->webhook_secret
    );
}
```

The webhook route must be excluded from CSRF middleware (`VerifyCsrfToken` exclusions or using the `api` middleware group equivalent). Raw body must reach the controller unmodified.

#### `BrandThemeService` (optional, inline if simple)

**Responsibility:** Resolves brand theming data into a structure suitable for passing to the frontend. If theming data is a simple JSON column on `Brand`, this can be an accessor rather than a service.

---

### Controllers Layer

#### Admin Controllers (`app/Http/Controllers/Admin/`)

| Controller | Routes | Purpose |
|------------|--------|---------|
| `BrandController` | `GET/POST /admin/brands` | CRUD for brands |
| `StripeAccountController` | `GET/POST /admin/brands/{brand}/stripe` | Manage Stripe credentials per brand |
| `DashboardController` | `GET /admin/dashboard` | Overview stats, recent payments |

#### User Controllers (`app/Http/Controllers/User/`)

| Controller | Routes | Purpose |
|------------|--------|---------|
| `PaymentController` | `GET /payments/create`, `POST /payments` | Payment builder: select brand + account, set amount, generate link |
| `PaymentHistoryController` | `GET /payments` | List all payments with status |

#### Client Controllers (`app/Http/Controllers/Client/`)

| Controller | Routes | Purpose |
|------------|--------|---------|
| `PaymentPageController` | `GET /pay/{uuid}` | Public payment page, brand-styled |
| `PaymentIntentController` | `POST /pay/{uuid}/intent` | Creates PaymentIntent server-side, returns `client_secret` and `publishable_key` |

**Design decision on the client payment flow:**
The initial page load (`GET /pay/{uuid}`) renders the brand-themed shell (logo, colors) via Inertia. It does NOT create the PaymentIntent at page load. The `PaymentIntentController` is called via an Inertia `router.post` or `fetch` when the page mounts, creating the intent lazily. This avoids abandoned intents from URL clicks that never complete.

Alternatively (simpler): Create the intent at page load, store `stripe_payment_intent_id` on the `Payment` record, and render it directly in the Inertia page props. This is acceptable for a non-high-volume internal tool.

#### Webhook Controller (`app/Http/Controllers/`)

| Controller | Routes | Purpose |
|------------|--------|---------|
| `StripeWebhookController` | `POST /webhook/stripe/{accountId}` | Receives per-account webhooks |

The `{accountId}` route parameter is the `StripeAccount.id` in the PayHub database (not the Stripe account ID). This lets the controller look up the correct `StripeAccount` and retrieve its decrypted `webhook_secret` for signature verification.

---

### Middleware Layer

#### `BrandResolution` middleware

Applied only to `/pay/{uuid}` routes. Resolves brand context from the payment UUID.

```
Request: GET /pay/550e8400-e29b-41d4-a716-446655440000

BrandResolution::handle():
  1. Extract UUID from route parameter
  2. Load Payment::where('uuid', $uuid)->with(['brand', 'stripeAccount'])->firstOrFail()
  3. Validate payment is not expired, not already completed
  4. Attach to request: $request->attributes->set('payment', $payment)
  5. Attach to request: $request->attributes->set('brand', $payment->brand)
  6. Attach to request: $request->attributes->set('stripeAccount', $payment->stripeAccount)
  7. Pass to next
```

The controller reads from `$request->attributes` — no second DB query needed.

#### `HandleInertiaRequests` middleware

The standard Inertia middleware. Shares auth user data and flash messages globally. Does NOT share brand data — that is client-route-specific and handled by `BrandResolution`.

---

### Events and Jobs

#### `PaymentCompleted` event

Fired from `WebhookService` after a verified `payment_intent.succeeded` event.

```php
// App\Events\PaymentCompleted
public function __construct(public readonly Payment $payment) {}
```

#### `SendPaymentConfirmation` listener

Listens to `PaymentCompleted`. Sends an email (or other notification) to the client. Implements `ShouldQueue` so it does not block webhook response. Webhook handler must return HTTP 200 promptly — Stripe retries if response is slow.

**Job queue:** Use the database queue driver for simplicity in an internal tool. Redis is better at scale but adds infrastructure.

---

## Data Flow

### Flow 1: Admin Creates a Payment Link

```
Admin UI (Inertia page)
  --> POST /payments { brand_id, stripe_account_id, amount, currency, description, client_name, client_email }
  --> UserPaymentController@store
      --> validates input
      --> creates Payment model (status = pending, uuid = Str::uuid())
      --> stores stripe_payment_intent_id = null (intent created lazily)
      --> returns Inertia redirect to /payments/{id} with shareable link = /pay/{uuid}
  --> Admin copies link, sends to client
```

### Flow 2: Client Opens Payment Page

```
Client browser: GET pay.agency.com/pay/{uuid}
  --> BrandResolution middleware:
      --> loads Payment + Brand + StripeAccount (1 query with eager loading)
      --> validates not expired, not succeeded
      --> attaches to request attributes
  --> ClientPaymentController@show
      --> calls StripeService::createPaymentIntent(payment, stripeAccount)
          --> decrypts secret_key via cast
          --> new StripeClient(secret_key)->paymentIntents->create(...)
          --> saves stripe_payment_intent_id on Payment
          --> returns client_secret
      --> returns Inertia page with props:
          {
            brand: { name, logo_url, primary_color, ... },
            publishable_key: stripeAccount.publishable_key,
            client_secret: "pi_xxx_secret_yyy",
            payment: { amount, currency, description }
          }
  --> Inertia page renders:
      --> applies brand CSS variables from props
      --> loadStripe(publishable_key)
      --> stripe.elements({ clientSecret })
      --> mounts PaymentElement
  --> Client submits payment
      --> stripe.confirmPayment({ return_url: '/pay/{uuid}/complete' })
      --> Stripe handles card processing
```

### Flow 3: Webhook Updates Payment Status

```
Stripe --> POST /webhook/stripe/{accountId}
  --> StripeWebhookController@handle
      --> loads StripeAccount by {accountId}
      --> WebhookService::verify(request, stripeAccount)
          --> Webhook::constructEvent(rawBody, signature, decrypted_webhook_secret)
          --> throws SignatureVerificationException if invalid (returns 400)
      --> dispatches based on event type:
          payment_intent.succeeded -->
              --> loads Payment by stripe_payment_intent_id
              --> updates status = 'succeeded', paid_at = now()
              --> fires PaymentCompleted event
              --> SendPaymentConfirmation job queued
  --> returns HTTP 200 immediately
```

---

## Route Structure

```
// web.php

// Admin routes (authenticated)
Route::middleware(['auth', 'verified'])->prefix('admin')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::resource('/brands', BrandController::class);
    Route::resource('/brands.stripe-accounts', StripeAccountController::class);
});

// User routes (authenticated)
Route::middleware(['auth'])->prefix('payments')->group(function () {
    Route::get('/', [PaymentHistoryController::class, 'index']);
    Route::get('/create', [PaymentController::class, 'create']);
    Route::post('/', [PaymentController::class, 'store']);
});

// Client payment page (unauthenticated, brand-resolved)
Route::middleware([BrandResolution::class])->prefix('pay')->group(function () {
    Route::get('/{uuid}', [PaymentPageController::class, 'show']);
    Route::get('/{uuid}/complete', [PaymentPageController::class, 'complete']);
});

// Webhooks (no CSRF, no auth)
Route::post('/webhook/stripe/{accountId}', [StripeWebhookController::class, 'handle'])
    ->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
```

---

## Suggested Build Order

Dependencies flow upward — each layer depends on what is below it.

### Phase 1: Foundation (Database + Models)

Build first because everything depends on the schema.

- Migrations: `users`, `brands`, `stripe_accounts`, `payments`
- Models with relationships: `Brand`, `StripeAccount` (with encrypted casts), `Payment`, `User`
- Factories and seeders for local development
- No services or controllers yet

**Reason:** Services and controllers can't exist without the data layer. Encrypted casts must be verified before any Stripe credentials are stored.

### Phase 2: Encryption and StripeService

Build second because it is a dependency of both admin flows and the client payment page.

- `StripeService` with per-account `StripeClient` initialization
- Manual test: create a payment intent against a live Stripe test account
- Verify encrypted cast round-trips (write secret, read it back, confirm it decrypts correctly)

**Reason:** Until you confirm that `secret_key` survives an encrypt/decrypt cycle and produces a working Stripe client, every downstream feature is at risk.

### Phase 3: Admin Panel

Build third because it populates the data that later phases depend on.

- Admin auth (use Laravel Breeze/Jetstream Inertia scaffold)
- `BrandController` CRUD
- `StripeAccountController` CRUD (write-only for secret_key in forms — never display decrypted key)
- `DashboardController` (stub initially)

**Reason:** You need real `Brand` and `StripeAccount` records before you can build the payment creation and client flows.

### Phase 4: User Payment Builder

- `PaymentController` (create + store)
- `PaymentHistoryController`
- Payment link generation (UUID stored on Payment, shareable URL constructed from UUID)

**Reason:** Depends on Phase 3 data (brands + stripe accounts must exist to select from).

### Phase 5: Client Payment Page

- `BrandResolution` middleware (UUID → Payment + Brand + StripeAccount lookup)
- `PaymentPageController` (renders brand-styled Inertia page with publishable_key + client_secret)
- Inertia Vue/React page: CSS variables for theming, Stripe Elements mounting
- Payment completion page (`/pay/{uuid}/complete`)

**Reason:** Depends on all prior phases. Most frontend work happens here.

### Phase 6: Webhooks and Status Updates

- Register webhook endpoints in Stripe Dashboard (one per `StripeAccount`)
- `StripeWebhookController` with per-account signature verification
- `WebhookService::verify()`
- `PaymentCompleted` event + `SendPaymentConfirmation` listener
- `DashboardController` updated with real payment status data

**Reason:** Webhooks depend on payments having `stripe_payment_intent_id` set (Phase 5). Build and test webhooks last using Stripe CLI local forwarding (`stripe listen --forward-to`).

---

## Anti-Patterns to Avoid

### Anti-Pattern 1: Global Stripe::setApiKey()
**What goes wrong:** Sets the API key globally for the PHP process. In a web server with request concurrency or queue workers, a later request can overwrite the key before an earlier request finishes its Stripe call.
**Instead:** Always use `new \Stripe\StripeClient($secretKey)` scoped to a single service method call.

### Anti-Pattern 2: Storing Plain-Text Stripe Secrets
**What goes wrong:** A database breach or accidental log exposure reveals live Stripe secret keys that can be used to initiate charges or retrieve customer data.
**Instead:** Use Laravel's `encrypted` cast. The `APP_KEY` becomes critical — back it up securely. Consider a separate encryption key (custom `CastsAttributes`) if you want to isolate Stripe key encryption from the rest of the app's encrypted data.

### Anti-Pattern 3: Displaying Decrypted Secret Keys in Forms
**What goes wrong:** Admin UI shows `sk_live_xxx` in an edit form, exposing it to browser developer tools, logs, and Inertia's page props payload.
**Instead:** On the edit form for `StripeAccount`, only show the last 4 characters (e.g., `...kxyz`) or a "key set" indicator. Secret key is write-only from the UI perspective.

### Anti-Pattern 4: Single Webhook Endpoint for All Accounts
**What goes wrong:** You can only register one webhook signing secret per endpoint. With multiple accounts, you cannot verify signatures without knowing which account sent the event.
**Instead:** Register a separate webhook URL per `StripeAccount` (`/webhook/stripe/{accountId}`). Each URL carries the `accountId` needed to load the correct `webhook_secret` for verification.

### Anti-Pattern 5: Creating PaymentIntent at URL Generation Time
**What goes wrong:** Payment links may be generated and never opened, or opened and abandoned. Stripe PaymentIntents that are never confirmed still count toward rate limits and clutter the Stripe Dashboard.
**Instead:** Create the PaymentIntent when the client actually loads the `/pay/{uuid}` page (or lazily via a follow-up AJAX call after page load). Store `stripe_payment_intent_id` on the `Payment` record at that point.

### Anti-Pattern 6: Querying Encrypted Columns in SQL
**What goes wrong:** `WHERE secret_key = ?` against an encrypted column always returns zero results because the encrypted ciphertext is unique per encryption call (includes a random IV).
**Instead:** All lookups on `StripeAccount` must use non-encrypted columns (`id`, `brand_id`, `is_active`). Never design a flow that requires finding a `StripeAccount` by its secret key value.

---

## Scalability Considerations

This is an internal agency tool. The following is calibrated for that context.

| Concern | Current scale (internal tool) | If traffic grows |
|---------|-------------------------------|-----------------|
| Database | Single MySQL instance is sufficient | Add read replica for dashboard queries |
| Queue | Database queue driver (simple) | Switch to Redis queue if webhook volume increases |
| Session | File/cookie session for admin panel | No change needed |
| Webhook delivery | Stripe retries for 3 days on failure | Ensure webhook handler is idempotent (check payment status before updating) |
| Key management | APP_KEY in .env | Move to AWS Secrets Manager or similar if compliance requires |
| Stripe rate limits | 100 req/s on test, 25-50 on live | Not a concern at agency scale |

---

## Security Boundary Summary

| Boundary | Protection |
|----------|------------|
| Admin/User panel | Laravel auth middleware, session-based |
| Client payment page | No auth required; Payment UUID is the access token (keep UUID unguessable — use `Str::uuid()` which is UUID v4, 122 bits of randomness) |
| Stripe secret keys at rest | `encrypted` cast (AES-256-CBC via APP_KEY) |
| Stripe webhook verification | `Stripe\Webhook::constructEvent()` per-account signing secret |
| CSRF on webhook routes | Excluded from CSRF (webhook is server-to-server) |
| Client secret exposure | Never log, never embed in URL; pass only in Inertia page props (served over HTTPS) |
| Payment expiry | `expires_at` field on Payment; `BrandResolution` middleware rejects expired payments |

---

## Sources

- Stripe PaymentIntents API (PHP): https://docs.stripe.com/api/payment_intents/create?lang=php
- Stripe Webhook Signature Verification: https://docs.stripe.com/webhooks
- Stripe multiple accounts per-StripeClient pattern: https://medium.com/nerd-for-tech/stripe-multiple-accounts-with-laravel-cashier-31573f86ee4e
- Laravel 12 Encrypted Cast: https://laravel.com/docs/12.x/eloquent-mutators
- Laravel 12 Encryption (AES-256-CBC, Crypt facade): https://laravel.com/docs/12.x/encryption
- Laravel Middleware data passing via request attributes: https://laravel.com/docs/12.x/middleware
- Inertia HandleInertiaRequests middleware and shared data: https://inertiajs.com/server-side-setup
- Service layer vs repository pattern in Laravel (community consensus): https://rawbinn.com/blog/repository-and-service-design-pattern-in-laravel
- Webhook architecture pattern (Verify-Queue-Respond): https://medium.com/@prevailexcellent/how-to-handle-webhook-in-laravel-two-ways-and-the-best-way-90abfa7e1a39
- Multi-webhook secret handling (Spatie approach): https://github.com/spatie/laravel-stripe-webhooks
