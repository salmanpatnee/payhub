# Phase 7: Notifications + Dashboard - Pattern Map

**Mapped:** 2026-05-14
**Files analyzed:** 6 (2 new jobs, 1 new mailable, 1 new blade template, 1 modified controller, 1 modified Vue page)
**Analogs found:** 6 / 6

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `app/Jobs/SendPaymentNotification.php` | job | event-driven | `app/Jobs/HandleStripeWebhookJob.php` | exact |
| `app/Mail/PaymentSucceeded.php` | mailable | request-response | No existing mailable — use RESEARCH.md Pattern 3 | no-analog |
| `resources/views/emails/payment-succeeded.blade.php` | view | request-response | No existing email view — use RESEARCH.md Pattern 4 | no-analog |
| `app/Jobs/HandleStripeWebhookJob.php` | job (modify) | event-driven | self | self |
| `app/Http/Controllers/PaymentController.php` | controller (modify) | CRUD / request-response | self — `index()` extended | self |
| `resources/js/pages/payments/Index.vue` | component (modify) | request-response | `resources/js/pages/payments/Create.vue` (Select pattern) | role-match |

---

## Pattern Assignments

### `app/Jobs/SendPaymentNotification.php` (new job, event-driven)

**Analog:** `app/Jobs/HandleStripeWebhookJob.php`

**Imports pattern** (lines 1-9 of analog):
```php
<?php

namespace App\Jobs;

use App\Models\Payment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
```

**New job imports** — add Mail, User, PaymentSucceeded:
```php
<?php

namespace App\Jobs;

use App\Mail\PaymentSucceeded;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
```

**Class declaration + retry config pattern** (analog lines 10-16):
```php
class HandleStripeWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];
```

Copy this verbatim — `$tries = 3` and `$backoff = [1, 5, 10]` are the project-standard retry config.

**Constructor pattern** (analog lines 18-22):
```php
    public function __construct(
        public readonly int $stripeAccountId,
        public readonly string $eventType,
        public readonly array $eventData,
    ) {}
```

For `SendPaymentNotification`, constructor takes a single `Payment $payment`:
```php
    public function __construct(
        public readonly Payment $payment,
    ) {}
```

**Core handle() pattern** — loop admins and queue mail:
```php
    public function handle(): void
    {
        $admins = User::role('admin')->get();

        foreach ($admins as $admin) {
            Mail::to($admin)->queue(new PaymentSucceeded($this->payment));
        }
    }
```

**failed() pattern** (analog lines 42-51):
```php
    public function failed(?\Throwable $exception): void
    {
        Log::error('HandleStripeWebhookJob failed', [
            'stripe_account_id' => $this->stripeAccountId,
            'event_type' => $this->eventType,
            'pi_id' => $this->eventData['id'] ?? null,
            'error' => $exception?->getMessage(),
            // NEVER log full $this->eventData — may contain client_secret (CLAUDE.md rule)
        ]);
    }
```

For `SendPaymentNotification`, log `payment_uuid` and error only — never `stripe_payment_intent_id`:
```php
    public function failed(?\Throwable $exception): void
    {
        Log::error('SendPaymentNotification failed', [
            'payment_uuid' => $this->payment->uuid,
            'error' => $exception?->getMessage(),
        ]);
    }
```

---

### `app/Jobs/HandleStripeWebhookJob.php` (modify — add notification dispatch)

**Analog:** self (file is the modification target)

**Existing handle() body** (lines 24-40):
```php
    public function handle(): void
    {
        // eventData is $event->data->object->toArray() — a flat PaymentIntent array
        $piId = $this->eventData['id'] ?? null;

        // Atomic update: WHERE status = 'pending' acts as an idempotency guard.
        // Adding stripe_account_id scopes the update to the correct account.
        // If $updated === 0, the payment is already in a terminal state (or not found) — no-op.
        $updated = Payment::where('stripe_payment_intent_id', $piId)
            ->where('stripe_account_id', $this->stripeAccountId)
            ->where('status', 'pending')
            ->update(match ($this->eventType) {
                'payment_intent.succeeded' => ['status' => 'completed', 'paid_at' => now()],
                'payment_intent.payment_failed' => ['status' => 'failed'],
                default => [],
            });
    }
```

**Addition after the `->update()` call** — append inside `handle()`:
```php
        // Phase 7: dispatch notification only for succeeded events and only when update landed
        // (guard prevents duplicate notification on idempotent re-delivery)
        if ($updated > 0 && $this->eventType === 'payment_intent.succeeded') {
            $payment = Payment::with(['brand', 'stripeAccount'])
                ->where('stripe_payment_intent_id', $piId)
                ->first();

            if ($payment) {
                SendPaymentNotification::dispatch($payment);
            }
        }
```

**Import to add at top of file:**
```php
use App\Jobs\SendPaymentNotification;
```

**Key constraint:** The `$updated > 0 && $this->eventType === 'payment_intent.succeeded'` double guard is mandatory. `$updated > 0` alone is insufficient — failed payments also return `$updated > 0` from the `match` block.

---

### `app/Http/Controllers/PaymentController.php` (modify — filter scopes on index())

**Analog:** self — `index()` is the modification target

**Existing index()** (lines 15-39):
```php
    public function index(): Response
    {
        $user  = auth()->user();
        $query = Payment::with(['brand', 'stripeAccount', 'user'])
                        ->orderByDesc('created_at');

        if (! $user->hasRole('admin')) {
            $query->where('user_id', $user->id);
        }

        return Inertia::render('payments/Index', [
            'payments' => $query->get()->map(fn (Payment $p) => [
                'id'           => $p->id,
                'uuid'         => $p->uuid,
                'amount'       => $p->amount,
                'currency'     => $p->currency,
                'brand_name'   => $p->brand->name,
                'account_name' => $p->stripeAccount->account_name,
                'status'       => $p->status,
                'created_at'   => $p->created_at->toISOString(),
                'client_email' => $p->client_email,
                'client_name'  => $p->client_name,
            ]),
        ]);
    }
```

**Replacement signature and body** — add `Request $request` parameter, `->when()` filter chain, and extra Inertia props:
```php
    public function index(Request $request): Response
    {
        $user    = auth()->user();
        $isAdmin = $user->hasRole('admin');

        $query = Payment::with(['brand', 'stripeAccount', 'user'])
                        ->orderByDesc('created_at');

        if (! $isAdmin) {
            $query->where('user_id', $user->id);
        }

        $query
            ->when($request->brand_id, fn ($q, $v) => $q->where('brand_id', $v))
            ->when($request->stripe_account_id, fn ($q, $v) => $q->where('stripe_account_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));

        return Inertia::render('payments/Index', [
            'payments' => $query->get()->map(fn (Payment $p) => [
                'id'           => $p->id,
                'uuid'         => $p->uuid,
                'amount'       => $p->amount,
                'currency'     => $p->currency,
                'brand_name'   => $p->brand->name,
                'account_name' => $p->stripeAccount->account_name,
                'status'       => $p->status,
                'created_at'   => $p->created_at->toISOString(),
                'client_email' => $p->client_email,
                'client_name'  => $p->client_name,
            ]),
            'filters'  => $request->only(['brand_id', 'stripe_account_id', 'status', 'from', 'to']),
            'brands'   => $isAdmin ? Brand::orderBy('name')->get(['id', 'name']) : [],
            'accounts' => $isAdmin
                ? StripeAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
                : [],
            'isAdmin'  => $isAdmin,
        ]);
    }
```

**Imports to add** (Illuminate\Http\Request not currently imported in the controller):
```php
use Illuminate\Http\Request;
```

`Brand` and `StripeAccount` are already imported (lines 5-7 of the existing controller).

---

### `app/Mail/PaymentSucceeded.php` (new mailable)

**Analog:** No existing mailable in codebase. Generate via:
```bash
php artisan make:mail PaymentSucceeded --markdown=emails.payment-succeeded --no-interaction
```

**Mailable class pattern** (from RESEARCH.md Pattern 3 + mail.md skill):
```php
<?php

namespace App\Mail;

use App\Models\Payment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PaymentSucceeded extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Payment $payment,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Payment received — '.$this->payment->client_name
                     .' ('.$this->formatAmount($this->payment->amount, $this->payment->currency).')',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payment-succeeded',
        );
    }

    private function formatAmount(int $cents, string $currency): string
    {
        return number_format($cents / 100, 2).' '.strtoupper($currency);
    }
}
```

**Key:** `SerializesModels` is required because `Payment $payment` is an Eloquent model passed through the queue. Without it, the model is not properly serialized/deserialized between dispatch and handle.

**Note on `ShouldQueue`:** Do NOT add `ShouldQueue` to the mailable. `Mail::to($admin)->queue(new PaymentSucceeded(...))` in the job handles queueing. Adding `ShouldQueue` to the mailable itself is redundant in this pattern and adds complexity. See mail.md skill: "Makes queueing the default regardless of how the mailable is dispatched."

---

### `resources/views/emails/payment-succeeded.blade.php` (new markdown template)

**Analog:** No existing email view. Markdown mailable convention from RESEARCH.md Pattern 4.

**Template structure:**
```blade
<x-mail::message>
# Payment Received

A payment has been successfully processed.

| Field | Value |
|---|---|
| Client | {{ $payment->client_name }} |
| Email | {{ $payment->client_email }} |
| Amount | {{ number_format($payment->amount / 100, 2) }} {{ strtoupper($payment->currency) }} |
| Brand | {{ $payment->brand->name }} |
| Stripe Account | {{ $payment->stripeAccount->account_name }} |
| Service | {{ $payment->service }} |
| Package | {{ ucfirst($payment->package) }} |
@if($payment->note)
| Note | {{ $payment->note }} |
@endif

<x-mail::button :url="route('payments.show', $payment)">
View Payment
</x-mail::button>

Thanks,
{{ config('app.name') }}
</x-mail::message>
```

**Key:** `$payment->brand` and `$payment->stripeAccount` must be eager-loaded before this template renders. This is guaranteed by the re-fetch in `HandleStripeWebhookJob::handle()` using `Payment::with(['brand', 'stripeAccount'])->...->first()`.

---

### `resources/js/pages/payments/Index.vue` (modify — add filter bar)

**Analog:** `resources/js/pages/payments/Create.vue` (Select component usage)

**Existing script setup imports** (Index.vue lines 1-6):
```typescript
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Check, Copy, Eye, Plus } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
```

**New imports to add** — `router`, `reactive`, `watch`, Select components, route helper:
```typescript
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import { Check, Copy, Eye, Plus } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select, SelectContent, SelectItem,
    SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { route } from 'ziggy-js';
```

**Select component usage pattern** (Create.vue lines 91-103 — exact pattern to replicate in filter bar):
```vue
<Select v-model="form.brand_id">
    <SelectTrigger id="brand_id" class="w-full">
        <SelectValue placeholder="Select a brand" />
    </SelectTrigger>
    <SelectContent>
        <SelectItem
            v-for="brand in props.brands"
            :key="brand.id"
            :value="String(brand.id)"
        >{{ brand.name }}</SelectItem>
    </SelectContent>
</Select>
```

**Existing defineProps** (Index.vue line 21):
```typescript
defineProps<{ payments: PaymentRow[] }>();
```

**New type + defineProps** — add filter types and new props:
```typescript
type FilterState = {
    brand_id: string;
    stripe_account_id: string;
    status: string;
    from: string;
    to: string;
};

type BrandOption = { id: number; name: string };
type AccountOption = { id: number; account_name: string };

const props = defineProps<{
    payments: PaymentRow[];
    filters: FilterState;
    brands: BrandOption[];
    accounts: AccountOption[];
    isAdmin: boolean;
}>();
```

**Filter reactive state + auto-submit watcher** (append after defineProps):
```typescript
const filters = reactive<FilterState>({
    brand_id: props.filters.brand_id ?? '',
    stripe_account_id: props.filters.stripe_account_id ?? '',
    status: props.filters.status ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

watch(filters, (newFilters) => {
    router.get(
        route('payments.index'),
        Object.fromEntries(Object.entries(newFilters).filter(([, v]) => v !== '')),
        { preserveState: true, replace: true },
    );
}, { deep: true });
```

**Filter bar template** — insert above the existing `<div class="rounded-lg border ...">` table wrapper:
```vue
<!-- Filter bar -->
<div class="flex flex-wrap items-center gap-3">
    <!-- Brand filter (admin only) -->
    <Select v-if="isAdmin" v-model="filters.brand_id">
        <SelectTrigger class="w-44">
            <SelectValue placeholder="All brands" />
        </SelectTrigger>
        <SelectContent>
            <SelectItem value="">All brands</SelectItem>
            <SelectItem
                v-for="brand in props.brands"
                :key="brand.id"
                :value="String(brand.id)"
            >{{ brand.name }}</SelectItem>
        </SelectContent>
    </Select>

    <!-- Stripe Account filter (admin only) -->
    <Select v-if="isAdmin" v-model="filters.stripe_account_id">
        <SelectTrigger class="w-52">
            <SelectValue placeholder="All accounts" />
        </SelectTrigger>
        <SelectContent>
            <SelectItem value="">All accounts</SelectItem>
            <SelectItem
                v-for="account in props.accounts"
                :key="account.id"
                :value="String(account.id)"
            >{{ account.account_name }}</SelectItem>
        </SelectContent>
    </Select>

    <!-- Status filter (all roles) -->
    <Select v-model="filters.status">
        <SelectTrigger class="w-36">
            <SelectValue placeholder="All statuses" />
        </SelectTrigger>
        <SelectContent>
            <SelectItem value="">All statuses</SelectItem>
            <SelectItem value="pending">Pending</SelectItem>
            <SelectItem value="completed">Completed</SelectItem>
            <SelectItem value="failed">Failed</SelectItem>
            <SelectItem value="cancelled">Cancelled</SelectItem>
        </SelectContent>
    </Select>

    <!-- Date range (all roles) -->
    <input
        v-model="filters.from"
        type="date"
        class="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
        placeholder="From"
    />
    <input
        v-model="filters.to"
        type="date"
        class="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm"
        placeholder="To"
    />
</div>
```

**Existing defineOptions** (Index.vue lines 25-30 — keep unchanged):
```typescript
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
        ],
    },
});
```

---

## Shared Patterns

### Job Structure (applies to SendPaymentNotification)
**Source:** `app/Jobs/HandleStripeWebhookJob.php`
```php
class ExampleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [1, 5, 10];

    public function __construct(public readonly ModelType $model) {}

    public function handle(): void { /* ... */ }

    public function failed(?\Throwable $exception): void
    {
        Log::error('JobName failed', [
            'model_uuid' => $this->model->uuid,
            'error' => $exception?->getMessage(),
            // NEVER log sensitive Stripe data
        ]);
    }
}
```

### Inertia Role-Gating in Controller (applies to PaymentController::index())
**Source:** `app/Http/Controllers/PaymentController.php` (lines 17-23)
```php
$user  = auth()->user();
$query = Payment::with([...]);

if (! $user->hasRole('admin')) {
    $query->where('user_id', $user->id);
}
```

### Test Setup for Role-Based Tests (applies to NotificationTest, PaymentDashboardTest)
**Source:** `tests/Feature/StripeWebhookTest.php` (lines 14-20) and `tests/Feature/PaymentCreationTest.php` (lines 12-17)
```php
uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
});
```

### Queue::fake() + Queue::assertPushed() (applies to NotificationTest)
**Source:** `tests/Feature/StripeWebhookTest.php` (lines 92-112)
```php
it('description', function () {
    Queue::fake();

    // setup ...

    // action ...

    Queue::assertPushed(HandleStripeWebhookJob::class);
});
```

### Inertia assertion pattern (applies to PaymentDashboardTest)
**Source:** `tests/Feature/StripeWebhookTest.php` (lines 224-237) — `assertInertia` with closure
```php
$this->actingAs($user)
    ->get(route('payments.index'))
    ->assertOk()
    ->assertInertia(fn (AssertableInertia $page) => $page
        ->has('payments', 1)
        ->where('payments.0.status', 'completed')
    );
```

### stripePost() helper (applies to NotificationTest webhook integration)
**Source:** `tests/Feature/StripeWebhookTest.php` (lines 33-44)
```php
function stripePost(string $url, string $payload, ?string $sig = null): TestResponse
{
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($sig !== null) {
        $server['HTTP_STRIPE_SIGNATURE'] = $sig;
    }
    return test()->call('POST', $url, [], [], [], $server, $payload);
}
```

And `fakeStripeSignature()` helper (lines 22-29):
```php
function fakeStripeSignature(string $payload, string $secret, int $timestamp = 0): string
{
    $timestamp = $timestamp ?: time();
    $signedPayload = $timestamp.'.'.$payload;
    $signature = hash_hmac('sha256', $signedPayload, $secret);
    return "t={$timestamp},v1={$signature}";
}
```

These helpers are defined in `StripeWebhookTest.php` — they will need to be accessible from `NotificationTest.php`. Move them to `tests/Helpers/StripeHelpers.php` or define them again (Pest allows function redeclaration across files if namespaced separately via `uses()` but global functions conflict). Check if an `autoload-dev` helpers file exists before re-declaring.

---

## No Analog Found

| File | Role | Data Flow | Reason |
|---|---|---|---|
| `app/Mail/PaymentSucceeded.php` | mailable | request-response | No existing mailable in codebase — first Mail class |
| `resources/views/emails/payment-succeeded.blade.php` | blade view | request-response | No existing email views — `resources/views/vendor/mail/` not published either |

For these two files, use RESEARCH.md Patterns 3 and 4 as the implementation reference.

---

## Metadata

**Analog search scope:** `app/Jobs/`, `app/Http/Controllers/`, `resources/js/pages/payments/`, `tests/Feature/`, `app/Mail/`, `resources/views/emails/`
**Files scanned:** `HandleStripeWebhookJob.php`, `PaymentController.php`, `payments/Index.vue`, `payments/Create.vue`, `StripeWebhookTest.php`, `PaymentCreationTest.php`, mail.md skill, queue-jobs.md skill
**Pattern extraction date:** 2026-05-14
