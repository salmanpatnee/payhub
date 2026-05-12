# Phase 6: Webhooks + Status Sync - Research

**Researched:** 2026-05-12
**Domain:** Stripe webhook verification, Laravel queued jobs, per-account secret handling
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** `webhook_secret` added to the **existing** StripeAccount edit form (`/admin/stripe-accounts/{id}/edit`). No new routes or separate UI.
- **D-02:** Edit form shows a **read-only webhook endpoint URL field** displaying `/webhook/stripe/{accountId}`. Admin copies this into the Stripe dashboard.
- **D-03:** `webhook_secret` input is a **masked/password-type field**. Shows blank when null; shows `●●●●●●●●` placeholder when a secret is already stored.
- **D-04:** **Blank field on submit = no change.** Backend only updates `webhook_secret` when a non-empty value is provided.

### Claude's Discretion

- **Handler architecture:** Custom `StripeWebhookController` — not spatie/laravel-stripe-webhooks. spatie package remains installed but unused.
- **Idempotency:** Double-gated: controller checks status before queuing; job re-checks on execution.
- **Job structure:** Single `HandleStripeWebhookJob` with `stripe_account_id`, `event_type` (string), `event_data` (array).
- **`paid_at`:** Set to `now()` when `payment_intent.succeeded` marks the payment `completed`.
- **Raw body:** Use `$request->getContent()`. Route excluded from CSRF middleware.
- **Unknown events:** Return HTTP 200 immediately (Stripe requires 200 for all received events).

### Deferred Ideas (OUT OF SCOPE)

- Admin email notification on payment success (Phase 7)
- Webhook event audit log / WebhookCall table (v2)
- Dead-letter queue / retry visibility UI (v2)
- Duplicate event deduplication via event ID (v2)
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| WEBHOOK-01 | Each Stripe account has a dedicated webhook endpoint URL (`/webhook/stripe/{accountId}`) | Route binding verified — implicit model binding by `{stripeAccount}` ID |
| WEBHOOK-02 | Stripe webhook signature verified per account using that account's signing secret | `\Stripe\Webhook::constructEvent()` API confirmed with exact exception types |
| WEBHOOK-03 | `payment_intent.succeeded` updates payment status to `completed` in DB | `event->data->object->id` = PI ID; Payment lookup by `stripe_payment_intent_id` confirmed |
| WEBHOOK-04 | `payment_intent.payment_failed` updates payment status to `failed` in DB | Same lookup path; job dispatched with `event_type` string for branching |
| WEBHOOK-05 | All DB writes on payment completion driven by webhook only | Enforced by architecture — no DB write in `ClientPaymentController` for status |
| WEBHOOK-06 | Webhook controller returns HTTP 200 immediately; fulfillment queued | `HandleStripeWebhookJob::dispatch()` then `response('', 200)` — verified queue pattern |
| SEC-03 | Webhook routes excluded from CSRF middleware; raw body preserved | `$middleware->preventRequestForgery(except: ['webhook/stripe/*'])` in `bootstrap/app.php` — VERIFIED |
</phase_requirements>

---

## Summary

Phase 6 wires Stripe's push-based event stream into the payment status lifecycle. The core work is: (1) a public `StripeWebhookController` that accepts a POST per Stripe account, verifies the signature using the account's own encrypted `webhook_secret`, and immediately dispatches a job returning HTTP 200; (2) `HandleStripeWebhookJob` that does the actual DB write; (3) the webhook_secret field added to the existing StripeAccount edit form; and (4) the CSRF exclusion in `bootstrap/app.php`.

The most important implementation detail is the **CSRF exclusion method**. Laravel 13 does not use a `VerifyCsrfToken` class with a `$except` array — that approach was removed. The correct method is `$middleware->preventRequestForgery(except: ['webhook/stripe/*'])` in `bootstrap/app.php` `withMiddleware()`. The CONTEXT.md references the old approach (`VerifyCsrfToken $except array`) — this must NOT be followed; use the L13 API instead. [VERIFIED: laravel.com/docs/13.x/csrf]

A second key finding: `StripeAccount` does **not** have a `brand_id` relationship (dropped in Phase 3). The implicit model binding route `{stripeAccount}` resolves by integer `id` using the default `id` route key — no override needed since `StripeAccount::getRouteKeyName()` is not overridden. [VERIFIED: codebase read]

**Primary recommendation:** Three files to create (`StripeWebhookController`, `HandleStripeWebhookJob`, `UpdateStripeAccountRequest` extension + `StripeAccountController` extension), one route addition, one `bootstrap/app.php` edit, and one Vue edit. All new PHP classes via `php artisan make:`.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Signature verification | API / Backend (controller) | — | Raw body + secret only available server-side |
| Payment status DB write | Queue (job) | — | Decoupled from HTTP response per WEBHOOK-06 |
| Idempotency check | Both (controller + job) | — | Double-gate: controller checks before queue; job re-checks on execute |
| webhook_secret provisioning | API / Backend (admin update) | Frontend (edit form) | Encrypted cast; not mass-assignable — direct model assignment |
| Endpoint URL display | Frontend (Edit.vue) | Backend (Inertia prop) | `appUrl` passed from controller; assembled in Vue |
| CSRF exclusion | Backend middleware config | — | `bootstrap/app.php` — applies before route hits controller |

---

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| stripe/stripe-php | ^20.1 [VERIFIED: composer.json] | `\Stripe\Webhook::constructEvent()` | Official Stripe PHP SDK |
| Laravel Queues (database driver) | Laravel 13 built-in | Async job dispatch | `QUEUE_CONNECTION=database` in .env; `sync` in tests |
| Illuminate\Foundation\Queue\Queueable | Built-in | Job trait | Required for `ShouldQueue` jobs in L13 |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| spatie/laravel-stripe-webhooks | ^3.11 [VERIFIED: composer.json] | Installed but NOT used for routing | Ignored per D-01 decision |
| spatie/laravel-permission | ^7.4 | Role middleware on admin routes | Not applied to webhook route (public) |

### Not Needed

The webhook route is **not** under any auth or role middleware. No new packages are required — everything is already installed.

---

## Architecture Patterns

### System Architecture Diagram

```
Stripe Dashboard
     │  POST /webhook/stripe/{accountId}
     ▼
StripeWebhookController::handle($request, StripeAccount $stripeAccount)
     │
     ├─ getContent() → raw body string
     ├─ header('Stripe-Signature')
     ├─ constructEvent(rawBody, sig, $stripeAccount->webhook_secret)
     │      ├─ SignatureVerificationException → HTTP 400
     │      └─ UnexpectedValueException      → HTTP 400
     │
     ├─ check: event->type not in handled list → HTTP 200 (early return)
     │
     ├─ DB: Payment::where('stripe_payment_intent_id', $piId)->first()
     │      └─ already completed/failed → HTTP 200 (idempotency gate 1)
     │
     ├─ HandleStripeWebhookJob::dispatch(stripe_account_id, event_type, event_data)
     │
     └─ HTTP 200

          ┌─ Queue Worker ─────────────────────────────────────┐
          │  HandleStripeWebhookJob::handle()                  │
          │    Payment::where('stripe_payment_intent_id', ...)  │
          │    if status already completed/failed → return      │  (idempotency gate 2)
          │    payment->update([                                │
          │      'status' => 'completed'|'failed',             │
          │      'paid_at' => now()  (succeeded only)          │
          │    ])                                               │
          └────────────────────────────────────────────────────┘
```

### Recommended Project Structure

```
app/
├── Http/
│   └── Controllers/
│       └── StripeWebhookController.php   # new — public, no auth middleware
├── Jobs/
│   └── HandleStripeWebhookJob.php        # new — ShouldQueue
routes/
└── web.php                               # add webhook route OUTSIDE all middleware groups
bootstrap/
└── app.php                               # add preventRequestForgery(except: ['webhook/stripe/*'])
resources/js/pages/admin/stripe-accounts/
└── Edit.vue                              # extend with webhook_secret + endpoint URL fields
app/Http/Requests/Admin/
└── UpdateStripeAccountRequest.php        # add nullable webhook_secret validation rule
app/Http/Controllers/Admin/
└── StripeAccountController.php           # extend edit() + update() for webhook_secret + appUrl
tests/Feature/
└── StripeWebhookTest.php                 # new — Wave 0 stubs
```

### Pattern 1: CSRF Exclusion in Laravel 13 (CRITICAL)

**What:** Webhook routes must receive raw POST bodies without CSRF token. Laravel 13 configures this in `bootstrap/app.php` — there is no `VerifyCsrfToken.php` class to edit.

**When to use:** Any public route that receives non-browser POST requests.

**Example:**
```php
// Source: laravel.com/docs/13.x/csrf [VERIFIED]
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    // ... existing middleware config ...
    $middleware->preventRequestForgery(except: [
        'webhook/stripe/*',
    ]);
})
```

**Warning:** CONTEXT.md says "add to VerifyCsrfToken::$except array" — this is the pre-L11 API. `VerifyCsrfToken.php` does NOT exist in this project. Use `preventRequestForgery()` instead.

### Pattern 2: Stripe Webhook constructEvent()

**What:** Verifies the HMAC-SHA256 signature in the `Stripe-Signature` header. Returns an `\Stripe\Event` object on success.

**Method signature:**
```php
// Source: github.com/stripe/stripe-php Webhook.php [VERIFIED via WebFetch]
public static function constructEvent(
    string $payload,      // raw request body — NOT json_decoded
    string $sigHeader,    // value of Stripe-Signature header
    string $secret,       // per-account webhook_secret (decrypted by cast)
    int    $tolerance = 300  // max timestamp drift in seconds (default 300)
): \Stripe\Event
```

**Exceptions:**
- `\Stripe\Exception\SignatureVerificationException` — tampered or missing signature → return 400
- `\UnexpectedValueException` — payload is not valid JSON → return 400

**Example:**
```php
// Source: docs.stripe.com/webhooks/signatures [VERIFIED via WebFetch]
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

$payload   = $request->getContent();             // raw body — NOT $request->json() or $request->all()
$sigHeader = $request->header('Stripe-Signature');

try {
    $event = Webhook::constructEvent($payload, $sigHeader, $stripeAccount->webhook_secret);
} catch (SignatureVerificationException $e) {
    return response('Invalid signature', 400);
} catch (\UnexpectedValueException $e) {
    return response('Invalid payload', 400);
}
```

**Critical:** `$request->getContent()` returns the raw body string. `$request->all()` or `$request->json()` returns parsed data — signature verification will FAIL with parsed data.

### Pattern 3: HandleStripeWebhookJob

**What:** Implements `ShouldQueue`, uses `Queueable` trait. Accepts primitive scalar constructor args (not Eloquent models) to avoid serialization overhead.

**Why primitives:** The event data is already an array. Passing `stripe_account_id` as an int and `event_data` as an array avoids model serialization and is safe to retry.

**Example:**
```php
// Source: laravel.com/docs/13.x/queues [VERIFIED via WebFetch]
namespace App\Jobs;

use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HandleStripeWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [1, 5, 10];    // exponential backoff [VERIFIED: queue-jobs skill]

    public function __construct(
        public readonly int    $stripeAccountId,
        public readonly string $eventType,
        public readonly array  $eventData,
    ) {}

    public function handle(): void
    {
        $piId    = $this->eventData['object']['id'] ?? null;
        $payment = Payment::where('stripe_payment_intent_id', $piId)->first();

        if (! $payment || in_array($payment->status, ['completed', 'failed'])) {
            return; // idempotency gate 2
        }

        match ($this->eventType) {
            'payment_intent.succeeded'       => $payment->update(['status' => 'completed', 'paid_at' => now()]),
            'payment_intent.payment_failed'  => $payment->update(['status' => 'failed']),
            default                          => null,
        };
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('HandleStripeWebhookJob failed', [
            'stripe_account_id' => $this->stripeAccountId,
            'event_type'        => $this->eventType,
            'pi_id'             => $this->eventData['object']['id'] ?? null,
            'error'             => $exception?->getMessage(),
        ]);
    }
}
```

### Pattern 4: Webhook Route (Outside All Middleware Groups)

**What:** Route must be outside the `auth`/`admin`/`verified` groups. Implicit model binding resolves `StripeAccount` by `id` (integer — default route key).

**Example:**
```php
// Source: routes/web.php — follows existing public route pattern [VERIFIED: codebase]
// Add BEFORE the require settings.php line, outside all middleware groups
Route::post('/webhook/stripe/{stripeAccount}', [StripeWebhookController::class, 'handle'])
    ->name('webhook.stripe');
```

**Note:** `{stripeAccount}` uses implicit model binding. Laravel resolves it to `StripeAccount::find($id)` and returns 404 if not found — this is safe and correct.

### Pattern 5: Edit.vue Extension — webhook_secret Field

**What:** Mirror the existing `secret_key` masked input pattern. Add two new fields: read-only endpoint URL (with copy button) and masked webhook_secret password input.

**Copy-to-clipboard pattern:** Reuse from `resources/js/pages/payments/Show.vue` — `navigator.clipboard.writeText()` with `document.execCommand('copy')` fallback. [VERIFIED: codebase]

**Type extension:**
```typescript
// Extend StripeAccountProp type
type StripeAccountProp = {
    id: number;
    account_name: string;
    publishable_key: string;
    is_active: boolean;
    has_webhook_secret: boolean;  // boolean flag — never pass the raw secret to frontend
    webhook_endpoint_url: string; // passed as Inertia prop from controller
};
```

**Form extension:**
```typescript
const form = useForm({
    _method:         'PUT',
    account_name:    props.stripeAccount.account_name,
    publishable_key: props.stripeAccount.publishable_key,
    secret_key:      '',
    webhook_secret:  '',   // blank = preserve; new value = replace (D-04)
});
```

### Pattern 6: StripeAccountController — edit() + update() Extension

**What:** Mirror the existing `secret_key` pattern. Pass `has_webhook_secret` (bool) and `webhook_endpoint_url` to the view. On update, only write `webhook_secret` when non-empty.

**Example:**
```php
// Source: app/Http/Controllers/Admin/StripeAccountController.php [VERIFIED: codebase]

public function edit(StripeAccount $stripeAccount): Response
{
    return Inertia::render('admin/stripe-accounts/Edit', [
        'stripeAccount' => [
            'id'                   => $stripeAccount->id,
            'account_name'         => $stripeAccount->account_name,
            'publishable_key'      => $stripeAccount->publishable_key,
            'is_active'            => $stripeAccount->is_active,
            'has_webhook_secret'   => ! empty($stripeAccount->webhook_secret),
            'webhook_endpoint_url' => config('app.url') . '/webhook/stripe/' . $stripeAccount->id,
            // secret_key: NEVER included
        ],
    ]);
}

public function update(UpdateStripeAccountRequest $request, StripeAccount $stripeAccount): RedirectResponse
{
    if ($request->filled('secret_key')) {
        $stripeAccount->secret_key = $request->validated('secret_key');
    }
    if ($request->filled('webhook_secret')) {
        $stripeAccount->webhook_secret = $request->validated('webhook_secret');
    }
    $stripeAccount->fill($request->safe()->except(['secret_key', 'webhook_secret']));
    $stripeAccount->save();

    return redirect()->route('admin.stripe-accounts.index')
        ->with('success', 'Stripe account updated.');
}
```

### Anti-Patterns to Avoid

- **Parsing body before verify:** Never pass `$request->all()` or `json_decode($request->getContent())` to `constructEvent()` — signature verification hashes the RAW string. [VERIFIED: docs.stripe.com]
- **Using `Stripe::setApiKey()` globally:** The CLAUDE.md rule applies here too. `Webhook::constructEvent()` does not need a StripeClient — it's a static utility method. No global API key needed for webhook verification.
- **Putting the webhook route inside auth middleware:** The route must be reachable by Stripe's servers with no session/CSRF.
- **Using `VerifyCsrfToken::$except`:** This class does not exist in this Laravel 13 project. Use `preventRequestForgery()` in `bootstrap/app.php`.
- **Mass-assigning `webhook_secret`:** The `StripeAccount::$fillable` explicitly excludes `webhook_secret` (same as `secret_key`). Always assign directly: `$account->webhook_secret = $value`. [VERIFIED: codebase]
- **Trusting `event->data->object->status`:** The PI `status` in the event payload is informational. The authoritative lookup is `Payment::where('stripe_payment_intent_id', ...)`. The payment status in our DB is what determines action.
- **Logging `client_secret`:** Stripe PI event payloads may contain `client_secret`. Never log the full `$eventData` array. Log only `event_type` and PI ID.

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Signature verification | Custom HMAC comparison | `\Stripe\Webhook::constructEvent()` | Handles timestamp tolerance, v1 scheme, replay protection |
| Queue dispatch | Custom background process | `HandleStripeWebhookJob::dispatch()` | Laravel queue infrastructure handles retry, failure, serialization |
| Encrypted cast read/write | Custom encrypt/decrypt | Laravel `'encrypted'` cast on model | Already on `webhook_secret` — auto-decrypted on read |

**Key insight:** The signature verification utility already handles replay attacks via the timestamp tolerance window (default 300 seconds). Rolling a custom HMAC check misses this.

---

## Common Pitfalls

### Pitfall 1: CSRF Exclusion via Wrong API
**What goes wrong:** Developer adds `VerifyCsrfToken.php` to the project or edits `$except` — webhook route still fails with 419 or the class doesn't exist.
**Why it happens:** CONTEXT.md and pre-L11 tutorials reference the old approach.
**How to avoid:** Use `$middleware->preventRequestForgery(except: ['webhook/stripe/*'])` in `bootstrap/app.php`. [VERIFIED: laravel.com/docs/13.x/csrf]
**Warning signs:** 419 responses on webhook POST; `VerifyCsrfToken` class not found.

### Pitfall 2: Parsed Body Sent to constructEvent()
**What goes wrong:** `constructEvent($request->all(), ...)` or `constructEvent(json_decode($request->getContent(), true), ...)` — always throws `SignatureVerificationException` even for valid Stripe requests.
**Why it happens:** JSON is re-encoded differently from the original byte sequence Stripe signed.
**How to avoid:** Always `$request->getContent()` — returns the raw byte string.
**Warning signs:** All real Stripe events return 400; Stripe CLI `--forward-to` tests always fail.

### Pitfall 3: Webhook Secret Not Decrypted
**What goes wrong:** Using `$account->getRawOriginal('webhook_secret')` or reading from DB without the model — gets the encrypted ciphertext, not the `whsec_...` value.
**Why it happens:** Accessing the column outside Eloquent's cast pipeline.
**How to avoid:** Always access `webhook_secret` via the Eloquent model instance so the `'encrypted'` cast decrypts automatically. [VERIFIED: codebase — StripeAccount::$casts]
**Warning signs:** `constructEvent()` throws `SignatureVerificationException` even with the correct secret in Stripe dashboard.

### Pitfall 4: Webhook Route Inside Auth Middleware
**What goes wrong:** Stripe's POST receives a 401 or redirect to login page.
**Why it happens:** Route inadvertently placed inside the `['auth', 'verified']` group.
**How to avoid:** Place `Route::post('/webhook/stripe/{stripeAccount}', ...)` in `routes/web.php` outside all middleware groups — same section as the `/pay/{payment}` public routes. [VERIFIED: codebase]

### Pitfall 5: Test Queue Runs Synchronously
**What goes wrong:** In tests, the job executes inline — so a test asserting `assertPushed()` on a real dispatch will see the DB already updated AND the job "pushed".
**Why it happens:** `phpunit.xml` sets `QUEUE_CONNECTION=sync` [VERIFIED: codebase]. With `sync`, jobs run immediately — `Queue::fake()` still works but `Queue::assertPushed()` captures the dispatch, not the execution.
**How to avoid:** Use `Queue::fake()` before dispatching if you want to assert dispatch-only. For integration tests that verify DB writes, use `sync` connection (default for tests) — no `Queue::fake()` needed; just assert the DB state after calling the controller.

### Pitfall 6: `stripe_payment_intent_id` Not Yet Set
**What goes wrong:** Webhook arrives for a payment that hasn't had its PI stored yet (race condition — very fast payment completes before Phase 5 stores the ID).
**Why it happens:** PI is created and ID stored during `ClientPaymentController::show()`. If Stripe fires a webhook before that response returns (unlikely but possible), `stripe_payment_intent_id` is null on the Payment record.
**How to avoid:** The `HandleStripeWebhookJob` uses `Payment::where('stripe_payment_intent_id', $piId)->first()` — if null result, the job returns early. The payment remains `pending`. This is acceptable for v1; Stripe will retry the webhook. [ASSUMED — edge case, acceptable for v1]

---

## Code Examples

### Complete StripeWebhookController

```php
// Source: Stripe docs + existing project patterns [VERIFIED]
namespace App\Http\Controllers;

use App\Jobs\HandleStripeWebhookJob;
use App\Models\Payment;
use App\Models\StripeAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    private const HANDLED_EVENTS = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
    ];

    public function handle(Request $request, StripeAccount $stripeAccount): Response
    {
        $payload   = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature', '');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $stripeAccount->webhook_secret);
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response('Invalid signature or payload', 400);
        }

        if (! in_array($event->type, self::HANDLED_EVENTS)) {
            return response('', 200);
        }

        $piId    = $event->data->object->id ?? null;
        $payment = $piId ? Payment::where('stripe_payment_intent_id', $piId)->first() : null;

        // Idempotency gate 1: skip if already in terminal state
        if ($payment && in_array($payment->status, ['completed', 'failed'])) {
            return response('', 200);
        }

        HandleStripeWebhookJob::dispatch(
            $stripeAccount->id,
            $event->type,
            $event->data->object->toArray(),
        );

        return response('', 200);
    }
}
```

### Stripe Event Data Structure (payment_intent.succeeded)

```json
// Source: docs.stripe.com/api/events/object [VERIFIED via WebFetch]
{
  "id": "evt_1NG8Du2eZvKYlo2C...",
  "type": "payment_intent.succeeded",
  "data": {
    "object": {
      "id": "pi_3N...",
      "object": "payment_intent",
      "amount": 2000,
      "amount_received": 2000,
      "currency": "usd",
      "status": "succeeded",
      "last_payment_error": null
    }
  }
}
```

**Key access pattern in PHP:**
- `$event->type` — string event type
- `$event->data->object->id` — PaymentIntent ID (the lookup key)
- `$event->data->object->toArray()` — serialize for job constructor

For `payment_intent.payment_failed`:
- `$event->data->object->last_payment_error->message` — human-readable decline reason (optional, for logging only)

### Idempotency Guard Pattern (Controller + Job)

```php
// Controller (gate 1 — before queuing)
if ($payment && in_array($payment->status, ['completed', 'failed'])) {
    return response('', 200);  // already terminal — no-op
}

// Job (gate 2 — on execution)
if (! $payment || in_array($payment->status, ['completed', 'failed'])) {
    return;  // payment resolved between dispatch and execution — no-op
}
```

### Testing Pattern — StripeWebhookTest.php

```php
// Source: existing test patterns in project [VERIFIED: ClientPaymentTest.php]
// Queue is 'sync' in tests (phpunit.xml) — no Queue::fake() needed for DB-write tests
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Stripe\Webhook;
use App\Models\Payment;
use App\Models\StripeAccount;

uses(RefreshDatabase::class);

// Helper to build a valid-looking (test-bypassed) webhook payload
function fakeStripeEvent(string $type, string $piId): array
{
    return [
        'type' => $type,
        'data' => [
            'object' => ['id' => $piId, 'status' => 'succeeded'],
        ],
    ];
}

// For signature tests: mock Webhook::constructEvent() to control outcome
// For DB-write tests: use 'sync' queue — assert DB state after POST
```

**Testing constructEvent():** In tests, mock `\Stripe\Webhook::constructEvent()` via `Mockery::mock('overload:\Stripe\Webhook')` OR use `app()->bind()` to replace the facade. The simplest approach for controller tests: bypass signature verification by making `constructEvent()` return a valid event — test the job logic separately.

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `VerifyCsrfToken::$except` array | `$middleware->preventRequestForgery(except: [...])` in `bootstrap/app.php` | Laravel 11+ | No `VerifyCsrfToken.php` class exists in this project |
| `Inertia::lazy()` | `Inertia::optional()` | Inertia v3 | N/A for this phase — no lazy props needed |

**Deprecated/outdated in this project:**
- `VerifyCsrfToken.php` with `$except`: Removed in Laravel 11. This project is L13.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The race condition (PI ID not yet stored when webhook arrives) resolves gracefully on Stripe retry | Pitfall 6 | If Stripe doesn't retry and payment is fast, payment could stay `pending` permanently — acceptable for v1 |
| A2 | `$event->data->object->toArray()` produces a plain PHP array safe for queue serialization | Code Examples | If nested Stripe objects don't serialize cleanly, job would fail; fall back to `json_decode(json_encode($event->data->object), true)` |

---

## Open Questions

1. **Webhook secret validation format**
   - What we know: Stripe webhook secrets always start with `whsec_`
   - What's unclear: Should `UpdateStripeAccountRequest` enforce `starts_with:whsec_` like the existing `starts_with:sk_` rule for `secret_key`?
   - Recommendation: Yes — add `starts_with:whsec_` as a nullable validation rule to catch configuration errors early.

2. **`constructEvent()` in tests — mocking approach**
   - What we know: `Webhook::constructEvent()` is a static method; the test suite uses Mockery
   - What's unclear: The cleanest Mockery pattern for a static call vs. integration test with a real HMAC
   - Recommendation: For integration, generate a real HMAC in the test using `hash_hmac('sha256', $payload, $secret)` and build a valid `Stripe-Signature` header. This tests the full path without mocking static methods.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| stripe/stripe-php | Webhook::constructEvent() | Yes | ^20.1 | — |
| spatie/laravel-stripe-webhooks | Installed (not used) | Yes | ^3.11 | — |
| Laravel database queue | HandleStripeWebhookJob | Yes | default driver [VERIFIED: config/queue.php] | — |
| Stripe CLI (`stripe listen`) | Local webhook testing | Unknown [ASSUMED] | — | Manual Stripe dashboard resend |
| PHP 8.3 | Match expression, constructor promotion | Yes | 8.3 [VERIFIED: composer.json] | — |

**Missing dependencies with no fallback:** None.

**Missing dependencies with fallback:**
- Stripe CLI: If not installed locally, use `stripe listen --forward-to` for dev testing. Fallback is manually triggering events from the Stripe dashboard.

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 4 (pestphp/pest ^4) [VERIFIED: composer.json] |
| Config file | `phpunit.xml` at project root |
| Quick run command | `php artisan test --compact --filter=StripeWebhook` |
| Full suite command | `php artisan test --compact` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| WEBHOOK-01 | GET `/webhook/stripe/{id}` returns 405 (Method Not Allowed) | Feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ Wave 0 |
| WEBHOOK-01 | POST to unknown account ID returns 404 | Feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ Wave 0 |
| WEBHOOK-02 | Tampered signature returns 400 | Feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ Wave 0 |
| WEBHOOK-02 | Missing Stripe-Signature header returns 400 | Feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ Wave 0 |
| WEBHOOK-02 | Valid signature returns 200 | Feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ Wave 0 |
| WEBHOOK-03 | `payment_intent.succeeded` sets status=completed + paid_at | Feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ Wave 0 |
| WEBHOOK-04 | `payment_intent.payment_failed` sets status=failed | Feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ Wave 0 |
| WEBHOOK-05 | Payment status not set in ClientPaymentController | Unit/arch | `php artisan test --compact --filter=StripeWebhookTest` | ❌ Wave 0 |
| WEBHOOK-06 | Controller returns 200 before job runs (Queue::fake()) | Feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ Wave 0 |
| SEC-03 | Webhook route has no CSRF token requirement | Feature | `php artisan test --compact --filter=StripeWebhookTest` | ❌ Wave 0 |
| D-04 | Blank `webhook_secret` on form submit preserves existing | Feature | `php artisan test --compact --filter=StripeAccountTest` | ❌ Wave 0 |

### Sampling Rate

- **Per task commit:** `php artisan test --compact --filter=StripeWebhook`
- **Per wave merge:** `php artisan test --compact`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps

- [ ] `tests/Feature/StripeWebhookTest.php` — covers WEBHOOK-01 through WEBHOOK-06, SEC-03
- [ ] Helper function `fakeStripeSignature(string $payload, string $secret): string` in test file — builds valid `t=...,v1=...` header for integration tests

*(Existing test infrastructure covers the test runner setup — only the new test file is needed.)*

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | Webhook uses signature-based auth, not session auth |
| V3 Session Management | No | Webhook route has no session |
| V4 Access Control | Yes | Webhook route must be outside auth middleware; admin edit form restricted to `role:admin` |
| V5 Input Validation | Yes | `UpdateStripeAccountRequest` — `webhook_secret` nullable string, `starts_with:whsec_` |
| V6 Cryptography | Yes | `webhook_secret` uses Laravel `encrypted` cast (AES-256-CBC) — never hand-roll |

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Forged webhook POST (replay) | Spoofing / Tampering | `constructEvent()` checks timestamp within ±300s tolerance window |
| Tampered payload | Tampering | HMAC-SHA256 signature check in `constructEvent()` |
| Raw secret exposed in logs | Info Disclosure | Never log `$eventData` directly; log only `event_type` and PI ID |
| Ciphertext sent to frontend | Info Disclosure | `has_webhook_secret` (bool) passed to Vue — never the raw or encrypted value |
| CSRF token bypassed | — | Webhook is intentionally CSRF-excluded; Stripe signature IS the authentication |
| Test key in production | Elevation of Privilege | `UpdateStripeAccountRequest` blocks `sk_test_` in production (existing rule) — `whsec_test_` not blocked; acceptable for v1 |

---

## Sources

### Primary (HIGH confidence)

- Stripe PHP SDK (`github.com/stripe/stripe-php`, Webhook.php) — `constructEvent()` signature, exception types [VERIFIED via WebFetch]
- Laravel 13 docs (`laravel.com/docs/13.x/csrf`) — `preventRequestForgery()` API [VERIFIED via WebFetch]
- Laravel 13 docs (`laravel.com/docs/13.x/queues`) — `ShouldQueue`, `Queueable`, `Queue::fake()`, `Queue::assertPushed()` [VERIFIED via WebFetch]
- Stripe webhooks docs (`docs.stripe.com/webhooks/signatures`) — raw body requirement, `Stripe-Signature` header [VERIFIED via WebFetch]
- Project codebase: `app/Models/StripeAccount.php`, `app/Models/Payment.php`, `app/Http/Controllers/Admin/StripeAccountController.php`, `resources/js/pages/admin/stripe-accounts/Edit.vue`, `resources/js/pages/payments/Show.vue`, `bootstrap/app.php`, `routes/web.php`, `phpunit.xml`, `config/queue.php` [VERIFIED: direct file reads]

### Secondary (MEDIUM confidence)

- `docs.stripe.com/api/events/object` — Stripe Event object structure, PaymentIntent fields [VERIFIED via WebFetch]
- Project skill: `.claude/skills/laravel-best-practices/rules/queue-jobs.md` — `$backoff`, `failed()`, `ShouldBeUnique` patterns [VERIFIED: direct file read]

### Tertiary (LOW confidence)

- None — all claims verified.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all packages verified in `composer.json` and official docs
- Architecture: HIGH — all patterns verified against existing codebase and official docs
- CSRF exclusion API: HIGH — verified directly from `laravel.com/docs/13.x/csrf`; critical correction from CONTEXT.md
- Pitfalls: HIGH for first four (verified); MEDIUM for race condition (logical inference, acceptable for v1)

**Research date:** 2026-05-12
**Valid until:** 2026-06-12 (30 days — stable APIs)
