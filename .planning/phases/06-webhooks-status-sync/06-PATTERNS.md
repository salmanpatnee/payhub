# Phase 6: Webhooks + Status Sync - Pattern Map

**Mapped:** 2026-05-12
**Files analyzed:** 8 new/modified files
**Analogs found:** 8 / 8

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `app/Http/Controllers/StripeWebhookController.php` | controller | request-response | `app/Http/Controllers/ClientPaymentController.php` | role-match (both public, no auth) |
| `app/Jobs/HandleStripeWebhookJob.php` | job | event-driven | `app/Http/Controllers/ClientPaymentController.php` (DB write pattern) | partial (no jobs exist yet) |
| `routes/web.php` | config | request-response | existing public `/pay/{payment}` routes in same file (lines 51-55) | exact |
| `bootstrap/app.php` | config | request-response | existing `withMiddleware` block in same file (lines 16-30) | exact |
| `app/Http/Controllers/Admin/StripeAccountController.php` | controller | CRUD | itself — extend `edit()` + `update()` (lines 48-72) | exact |
| `app/Http/Requests/Admin/UpdateStripeAccountRequest.php` | request | request-response | itself — extend `rules()` + `secret_key` rule pattern (lines 31-46) | exact |
| `resources/js/pages/admin/stripe-accounts/Edit.vue` | component | request-response | itself — extend with new fields; copy clipboard from `resources/js/pages/payments/Show.vue` (lines 44-59) | exact |
| `tests/Feature/StripeWebhookTest.php` | test | request-response | `tests/Feature/ClientPaymentTest.php` | role-match (both are public route feature tests) |

---

## Pattern Assignments

### `app/Http/Controllers/StripeWebhookController.php` (controller, request-response)

**Analog:** `app/Http/Controllers/ClientPaymentController.php`

**Imports pattern** — mirror public controller with no auth imports, add Stripe webhook types (model from ClientPaymentController lines 1-11):
```php
namespace App\Http\Controllers;

use App\Jobs\HandleStripeWebhookJob;
use App\Models\Payment;
use App\Models\StripeAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
```

**No auth/middleware pattern** — same as ClientPaymentController: no `middleware()` call, no constructor guards. Exclusion is handled at the route level (outside all auth groups) and in `bootstrap/app.php`.

**Core pattern** — raw body + per-account secret + dispatch job + idempotency gate 1. Key details from RESEARCH.md Pattern 2 + Pattern 1:
```php
class StripeWebhookController extends Controller
{
    private const HANDLED_EVENTS = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
    ];

    public function handle(Request $request, StripeAccount $stripeAccount): Response
    {
        $payload   = $request->getContent();                          // raw body — NOT $request->all()
        $sigHeader = $request->header('Stripe-Signature', '');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $stripeAccount->webhook_secret);
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response('Invalid signature or payload', 400);
        }

        if (! in_array($event->type, self::HANDLED_EVENTS)) {
            return response('', 200);                                 // unknown events — always 200
        }

        $piId    = $event->data->object->id ?? null;
        $payment = $piId ? Payment::where('stripe_payment_intent_id', $piId)->first() : null;

        if ($payment && in_array($payment->status, ['completed', 'failed'])) {
            return response('', 200);                                 // idempotency gate 1
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

**Error handling pattern** — exceptions caught inline in the try/catch block (not a separate error handler). Return `response('...', 400)` for bad signatures; `response('', 200)` for all success paths. Mirrors the inline guard pattern in ClientPaymentController lines 19-24.

**Critical rule:** `$stripeAccount->webhook_secret` is auto-decrypted by the `'encrypted'` Eloquent cast (see `app/Models/StripeAccount.php` lines 21-22). Never call `getRawOriginal()`.

---

### `app/Jobs/HandleStripeWebhookJob.php` (job, event-driven)

**Analog:** No existing jobs in this project. Use RESEARCH.md Pattern 3 directly, which is based on Laravel 13 queue docs + project conventions.

**Imports pattern:**
```php
namespace App\Jobs;

use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
```

**Core pattern** — `ShouldQueue` + `Queueable` + constructor promotion with primitives (not Eloquent models) + idempotency gate 2 + `match` expression for status branching:
```php
class HandleStripeWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [1, 5, 10];

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
            return;  // idempotency gate 2
        }

        match ($this->eventType) {
            'payment_intent.succeeded'      => $payment->update(['status' => 'completed', 'paid_at' => now()]),
            'payment_intent.payment_failed' => $payment->update(['status' => 'failed']),
            default                         => null,
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
        // NEVER log full $eventData — may contain client_secret (CLAUDE.md rule)
    }
}
```

**`paid_at` pattern:** Only set on `payment_intent.succeeded` → `'paid_at' => now()`. The `paid_at` column uses `'datetime'` cast (see `app/Models/Payment.php` line 45). `status` and `paid_at` are both in `$fillable` (Payment.php lines 14-20), so `$payment->update([...])` works directly.

---

### `routes/web.php` (MODIFY — add webhook route)

**Analog:** existing public route block in `routes/web.php` lines 51-55.

**Pattern** — add OUTSIDE all middleware groups, after the existing public `/pay/{payment}` routes and before `require __DIR__.'/settings.php'`:
```php
// Public payment routes — no auth middleware (CLIENT-01)
Route::get('/pay/{payment}',         [ClientPaymentController::class, 'show'])->name('pay.show');
Route::get('/pay/{payment}/success', [ClientPaymentController::class, 'success'])->name('pay.success');
Route::get('/pay/{payment}/failed',  [ClientPaymentController::class, 'failed'])->name('pay.failed');

// Webhook routes — public, no auth middleware, no CSRF (SEC-03)
// {stripeAccount} resolves by integer id (default route key — no getRouteKeyName() override on StripeAccount)
Route::post('/webhook/stripe/{stripeAccount}', [StripeWebhookController::class, 'handle'])
    ->name('webhook.stripe');
```

**Import to add at top of file:**
```php
use App\Http\Controllers\StripeWebhookController;
```

---

### `bootstrap/app.php` (MODIFY — CSRF exclusion)

**Analog:** existing `withMiddleware` block in `bootstrap/app.php` lines 16-30.

**Pattern** — add `preventRequestForgery()` call inside the existing `withMiddleware` closure. This is the Laravel 13 API; there is no `VerifyCsrfToken.php` class in this project:
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

    // ADD THIS — must come before or after existing calls, within the same closure:
    $middleware->preventRequestForgery(except: [
        'webhook/stripe/*',
    ]);

    $middleware->web(append: [
        HandleAppearance::class,
        HandleInertiaRequests::class,
        AddLinkHeadersForPreloadedAssets::class,
    ]);

    $middleware->alias([
        'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
        'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
        'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
    ]);
})
```

**Warning:** Do NOT create a `VerifyCsrfToken.php` class or reference `$except` array. The `preventRequestForgery()` method is the only correct API in Laravel 13.

---

### `app/Http/Controllers/Admin/StripeAccountController.php` (MODIFY — edit + update)

**Analog:** itself. Extend the existing `edit()` (lines 48-58) and `update()` (lines 61-72).

**`edit()` extension** — add `has_webhook_secret` boolean and `webhook_endpoint_url` to the Inertia props. Never pass the raw or encrypted secret to the frontend:
```php
// CURRENT edit() lines 48-58 — replace with:
public function edit(StripeAccount $stripeAccount): Response
{
    return Inertia::render('admin/stripe-accounts/Edit', [
        'stripeAccount' => [
            'id'                   => $stripeAccount->id,
            'account_name'         => $stripeAccount->account_name,
            'publishable_key'      => $stripeAccount->publishable_key,
            'is_active'            => $stripeAccount->is_active,
            'has_webhook_secret'   => ! empty($stripeAccount->webhook_secret),
            'webhook_endpoint_url' => config('app.url').'/webhook/stripe/'.$stripeAccount->id,
            // secret_key: NEVER included — not even masked
        ],
    ]);
}
```

**`update()` extension** — mirror the existing `secret_key` conditional write pattern (line 63-65), apply same logic to `webhook_secret`. Exclude both from `fill()`:
```php
// CURRENT update() lines 61-72 — replace with:
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

**Key:** `webhook_secret` is not in `$fillable` (StripeAccount.php line 13-16), so direct assignment is required — same as `secret_key`.

---

### `app/Http/Requests/Admin/UpdateStripeAccountRequest.php` (MODIFY — add webhook_secret rule)

**Analog:** itself. Mirror the existing nullable `secret_key` rule pattern (lines 31-46) for `webhook_secret`.

**Extension** — add `webhook_secret` to `rules()`, following the same nullable + format-check closure structure. The format prefix for webhook secrets is `whsec_`:
```php
// Add to rules() array after the secret_key block:
'webhook_secret' => [
    'nullable',
    'string',
    function (string $attribute, mixed $value, \Closure $fail): void {
        if ($value === null || $value === '') {
            return; // blank = keep existing; valid (D-04)
        }
        if (! str_starts_with($value, 'whsec_')) {
            $fail('The webhook secret must begin with whsec_.');
        }
    },
],
```

**Full `rules()` after extension:**
```php
public function rules(): array
{
    $isProduction = app()->environment('production');

    return [
        'account_name'    => ['required', 'string', 'max:255'],
        'publishable_key' => [ /* existing rules unchanged */ ],
        'secret_key'      => [ /* existing rules unchanged */ ],
        'webhook_secret'  => [
            'nullable',
            'string',
            function (string $attribute, mixed $value, \Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }
                if (! str_starts_with($value, 'whsec_')) {
                    $fail('The webhook secret must begin with whsec_.');
                }
            },
        ],
    ];
}
```

---

### `resources/js/pages/admin/stripe-accounts/Edit.vue` (MODIFY — add two fields)

**Analog:** itself for the masked input pattern (lines 123-137); `resources/js/pages/payments/Show.vue` lines 44-59 for the copy-to-clipboard pattern.

**Type extension** — extend `StripeAccountProp` (currently lines 18-23):
```typescript
type StripeAccountProp = {
    id: number;
    account_name: string;
    publishable_key: string;
    is_active: boolean;
    has_webhook_secret: boolean;        // boolean — never the raw secret
    webhook_endpoint_url: string;       // assembled server-side, passed as Inertia prop
};
```

**Form extension** — add `webhook_secret: ''` to `useForm` (currently lines 36-41):
```typescript
const form = useForm({
    _method:         'PUT',
    account_name:    props.stripeAccount.account_name,
    publishable_key: props.stripeAccount.publishable_key,
    secret_key:      '',
    webhook_secret:  '',   // blank = preserve existing (D-04)
});
```

**Copy-to-clipboard ref and function** — copy from `Show.vue` lines 44-59:
```typescript
const copiedEndpoint = ref(false);

async function copyEndpointUrl(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.stripeAccount.webhook_endpoint_url);
    } catch {
        const el = document.createElement('textarea');
        el.value = props.stripeAccount.webhook_endpoint_url;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }
    copiedEndpoint.value = true;
    setTimeout(() => { copiedEndpoint.value = false; }, 2000);
}
```

**New template fields** — insert inside the `<form>` block after the existing `secret_key` field (after line 137), before the test status div. Two fields: read-only endpoint URL with copy button, and masked webhook_secret input:

```html
<!-- Webhook Endpoint URL (read-only + copy) -->
<div class="grid gap-2">
    <Label>Webhook Endpoint URL</Label>
    <div class="flex items-center gap-2 rounded-lg border border-border bg-muted/40 px-4 py-3">
        <span class="flex-1 text-sm font-mono truncate select-all">{{ stripeAccount.webhook_endpoint_url }}</span>
        <Button type="button" variant="outline" size="sm" @click="copyEndpointUrl">
            <Check v-if="copiedEndpoint" class="size-4 text-green-600" />
            <Copy v-else class="size-4" />
            {{ copiedEndpoint ? 'Copied!' : 'Copy' }}
        </Button>
    </div>
    <p class="text-xs text-muted-foreground">
        Paste this URL into the Stripe dashboard when creating a webhook endpoint.
    </p>
</div>

<!-- Webhook Secret (masked, blank = preserve) -->
<div class="grid gap-2">
    <Label for="webhook_secret">Webhook signing secret</Label>
    <div
        v-if="stripeAccount.has_webhook_secret"
        class="flex h-9 items-center rounded-md border border-input bg-muted/30 px-3 text-sm font-mono tracking-widest text-muted-foreground"
    >
        ••••••••••••••••••••
    </div>
    <Input
        id="webhook_secret"
        v-model="form.webhook_secret"
        type="password"
        placeholder="Paste new webhook secret to replace"
        autocomplete="new-password"
    />
    <p class="text-xs text-muted-foreground">
        Leave blank to keep the current secret. Paste a new value (starts with whsec_) to replace it.
    </p>
    <InputError :message="form.errors.webhook_secret" />
</div>
```

**Imports to add** — `Copy` from lucide-vue-next (already has `Check` imported at line 4; add `Copy`):
```typescript
import { ArrowLeft, Check, CheckCircle2, Copy, Loader2, XCircle, Zap } from 'lucide-vue-next';
```

---

### `tests/Feature/StripeWebhookTest.php` (test, request-response)

**Analog:** `tests/Feature/ClientPaymentTest.php`

**File structure pattern** — Pest test file (not PHPUnit class). Note that `ClientPaymentTest.php` uses global Pest functions (`uses()`, `it()`, `beforeEach()`) while `tests/Feature/Admin/StripeAccountManagementTest.php` uses PHPUnit class style. New webhook test must use Pest style to match `ClientPaymentTest.php`.

**Setup pattern** (from ClientPaymentTest.php lines 1-18):
```php
<?php

use App\Models\Payment;
use App\Models\StripeAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Stripe\Webhook;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
});
```

**Signature helper function** — build a valid `Stripe-Signature` header for integration tests (no static mocking needed). Matches RESEARCH.md Open Question 2 recommendation:
```php
function fakeStripeSignature(string $payload, string $secret): string
{
    $timestamp = time();
    $signedPayload = $timestamp . '.' . $payload;
    $signature = hash_hmac('sha256', $signedPayload, $secret);
    return "t={$timestamp},v1={$signature}";
}
```

**HTTP assertion pattern** — mirror ClientPaymentTest.php line 64 style (`$this->post(...)`) but note webhook tests POST raw JSON, not form data. Use `withHeaders` for the Stripe-Signature:
```php
it('returns 400 for missing Stripe-Signature header', function () {
    $account = StripeAccount::factory()->create();
    $this->postJson("/webhook/stripe/{$account->id}", ['type' => 'payment_intent.succeeded'])
         ->assertStatus(400);
});

it('returns 200 and dispatches job for payment_intent.succeeded', function () {
    Queue::fake();
    $account = StripeAccount::factory()->create(['webhook_secret' => 'whsec_test123']);
    $payment = Payment::factory()->create([
        'status'                   => 'pending',
        'stripe_payment_intent_id' => 'pi_test_abc',
        'stripe_account_id'        => $account->id,
    ]);

    $payload = json_encode([
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_test_abc']],
    ]);
    $sig = fakeStripeSignature($payload, 'whsec_test123');

    $this->withHeaders(['Stripe-Signature' => $sig])
         ->call('POST', "/webhook/stripe/{$account->id}", [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)
         ->assertStatus(200);

    Queue::assertPushed(\App\Jobs\HandleStripeWebhookJob::class);
});
```

**DB-write integration test pattern** — with `QUEUE_CONNECTION=sync` (phpunit.xml verified), no `Queue::fake()` needed; just assert DB state:
```php
it('payment_intent.succeeded sets status to completed and paid_at', function () {
    $account = StripeAccount::factory()->create(['webhook_secret' => 'whsec_test123']);
    $payment = Payment::factory()->create([
        'status'                   => 'pending',
        'stripe_payment_intent_id' => 'pi_test_abc',
        'stripe_account_id'        => $account->id,
    ]);

    $payload = json_encode([
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_test_abc']],
    ]);
    $sig = fakeStripeSignature($payload, 'whsec_test123');

    $this->withHeaders(['Stripe-Signature' => $sig])
         ->call('POST', "/webhook/stripe/{$account->id}", [], [], [], ['CONTENT_TYPE' => 'application/json'], $payload)
         ->assertStatus(200);

    expect($payment->fresh()->status)->toBe('completed');
    expect($payment->fresh()->paid_at)->not->toBeNull();
});
```

---

## Shared Patterns

### Per-Account Encrypted Secret Access
**Source:** `app/Models/StripeAccount.php` lines 18-25
**Apply to:** `StripeWebhookController` (reads `webhook_secret`), `StripeAccountController::edit()` (reads for `has_webhook_secret` bool)
```php
protected function casts(): array
{
    return [
        'secret_key'     => 'encrypted',
        'webhook_secret' => 'encrypted',
        'is_active'      => 'boolean',
    ];
}
```
Pattern: always access via Eloquent model property — the cast decrypts automatically. Never use `getRawOriginal()` or access DB column outside the model.

### Blank-means-preserve Write Pattern
**Source:** `app/Http/Controllers/Admin/StripeAccountController.php` lines 63-65
**Apply to:** `StripeAccountController::update()` extension for `webhook_secret`, mirroring `secret_key`
```php
if ($request->filled('secret_key')) {
    $stripeAccount->secret_key = $request->validated('secret_key');
}
```
Pattern: `$request->filled()` returns false for empty string and null. Direct model property assignment (not `fill()`) because column is not mass-assignable.

### Copy-to-Clipboard
**Source:** `resources/js/pages/payments/Show.vue` lines 44-59
**Apply to:** `Edit.vue` webhook endpoint URL field
```typescript
const copied = ref(false);

async function copyLink(): Promise<void> {
    try {
        await navigator.clipboard.writeText(shareableLink);
    } catch {
        const el = document.createElement('textarea');
        el.value = shareableLink;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        document.body.removeChild(el);
    }
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
}
```

### Inertia Response Shape — Never Expose Secrets
**Source:** `app/Http/Controllers/Admin/StripeAccountController.php` line 56 comment + `app/Http/Controllers/ClientPaymentController.php` line 65 comment
**Apply to:** `StripeAccountController::edit()` extension
Pattern: pass `has_webhook_secret: bool` to frontend, never the raw decrypted value or the encrypted ciphertext. Comment `// secret_key: NEVER included` already in place — extend with `// webhook_secret: NEVER included — use has_webhook_secret`.

### Pest Test Structure for Public Routes
**Source:** `tests/Feature/ClientPaymentTest.php` lines 1-18
**Apply to:** `tests/Feature/StripeWebhookTest.php`
```php
uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
});
```

---

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `app/Jobs/HandleStripeWebhookJob.php` | job | event-driven | No jobs exist in this project yet — first job. Use RESEARCH.md Pattern 3 directly (Laravel 13 queue docs pattern). |

---

## Metadata

**Analog search scope:** `app/Http/Controllers/`, `app/Http/Requests/Admin/`, `app/Jobs/`, `app/Models/`, `bootstrap/`, `routes/`, `resources/js/pages/admin/stripe-accounts/`, `resources/js/pages/payments/`, `tests/Feature/`
**Files scanned:** 12 source files read
**Pattern extraction date:** 2026-05-12
