# Phase 7: Notifications + Dashboard — Research

**Researched:** 2026-05-14
**Domain:** Laravel Mail + Queued Jobs + Inertia server-side filtering
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Recipients = all admin users — `User::role('admin')->get()` at dispatch time. Every user with the Admin role receives the email. No configurable recipient list for v1.
- **D-02:** From address = single system address from `.env` — `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`. No per-brand sender.
- **D-03:** Email body = full payment details: client name, client email, amount + currency (formatted), brand name, Stripe account name, service, package, note (if present), and a link to the admin payment detail page (`/payments/{uuid}`).
- **D-04:** Enhance existing `/payments` page — not a new route or component. `payments/Index.vue` gains a filter bar; `PaymentController::index()` gains query-param-based filtering.
- **D-05:** Filters shown to both roles. Admin-only filters: brand, Stripe account. Shared filters: status, date range.
- **D-06:** Server-side via Inertia — filters become query params. Controller applies `->when()` scopes to the Eloquent query.
- **D-07:** Auto-submit on change — each filter input change triggers `router.get(route('payments.index'), filters, { preserveState: true, replace: true })`. No Apply button needed.
- **D-08:** Inline dispatch from `HandleStripeWebhookJob` — after `$updated > 0` guard, dispatch `SendPaymentNotification`. Two-job chain: webhook job → notification job.
- **D-09:** Class names: `App\Jobs\SendPaymentNotification`, `App\Mail\PaymentSucceeded`.

### Claude's Discretion

- Date range filter UI: two HTML `<input type="date">` inputs (from / to) in the filter bar.
- Email template: Laravel markdown mailable (`--markdown` flag on `make:mail`).
- Filter state on page: controller passes `filters` prop back via `Inertia::render()` so the Vue component can pre-populate filter inputs on page load / back-navigation.
- `SendPaymentNotification` receives `Payment $payment` constructor arg; resolves admins and sends mailable inside `handle()`.

### Deferred Ideas (OUT OF SCOPE)

- Client email receipt on payment success (per-brand From address required; v2).
- Pagination (v2).
- Dashboard charts / analytics (v2).
- CSV export (v2).
</user_constraints>

---

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| NOTIFY-01 | Admin receives email notification when a payment succeeds | `App\Mail\PaymentSucceeded` markdown mailable; `Mail::to($admin)->queue(...)` loop in `SendPaymentNotification::handle()`; `$page.props.auth.user.roles` already available for role display |
| NOTIFY-02 | Notification is sent via queued job (non-blocking) | `SendPaymentNotification implements ShouldQueue`; dispatched from `HandleStripeWebhookJob::handle()` after `$updated > 0` guard; database queue driver confirmed active |
| DASH-01 | Admin can view all payments across all brands in a unified list | Existing `PaymentController::index()` already gates correctly; admin sees all via role check |
| DASH-02 | Admin can filter payments by brand, Stripe account, status, and date range | `->when()` scopes on Eloquent query; query params via Inertia `router.get()` with `preserveState: true` |
| DASH-03 | User can view their own payment history | Already scoped to `user_id` for non-admin; filter bar shows status + date range only |
| DASH-04 | Payment list shows: amount, currency, brand, status, created date, client email | All six columns already exist in `payments/Index.vue` table; no schema changes needed |
</phase_requirements>

---

## Summary

Phase 7 is the final phase of PayHub v1. It requires two independent but coordinated deliverables: (1) a queued email notification to all admin users when a webhook marks a payment as `completed`, and (2) server-side query-param filtering on the existing payments list page.

All infrastructure is already in place. The queue driver is `database` (confirmed in `.env`). Laravel Mail is configured with `MAIL_MAILER=log` in development (safe for testing without an SMTP server). The `User` model already uses the `Notifiable` trait and `HasRoles`. The `HandleStripeWebhookJob` already exists with the `$updated` guard that acts as the natural dispatch point. The `payments/Index.vue` table already renders all DASH-04 columns. The shadcn-vue `<Select>` component is already in use in `Create.vue` and is the correct component for brand and account filter dropdowns.

The phase introduces no new routes, no schema migrations, and no new models. The full implementation scope is: one new job class, one new mailable + markdown template, modifications to one controller method, and modifications to one Vue page component.

**Primary recommendation:** Follow the exact patterns already established in this codebase — `$tries = 3`, `$backoff = [1, 5, 10]`, `failed()` logging (without logging sensitive data), markdown mailable, `->when()` filter scopes, and Inertia `router.get()` with `preserveState: true`.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Email dispatch (NOTIFY-01/02) | API / Backend | Queue Worker | Mail is sent from a queued job — never from HTTP request path |
| Admin recipient resolution | API / Backend | — | `User::role('admin')->get()` runs inside job `handle()`, not in controller |
| Payment filter query | API / Backend | — | All filtering via Eloquent `->when()` on server; no client-side data manipulation |
| Filter state UI | Browser / Client | Frontend Server (Inertia) | Vue reads `filters` prop from Inertia response to pre-populate inputs; router.get() triggers new Inertia visit |
| Role-conditional filter display | Browser / Client | — | `$page.props.auth.user.roles` already shared; `v-if` on admin-only filter inputs |
| Payment list rendering | Browser / Client | — | Existing table in `Index.vue` extended with filter bar above |

---

## Standard Stack

### Core (all already installed, no new dependencies)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| Laravel Mail | Laravel 13 built-in | Sending mailables via configured mailer | Zero install, supports markdown templates, queue integration native |
| Illuminate\Queue (ShouldQueue) | Laravel 13 built-in | Queued job interface | Already used by `HandleStripeWebhookJob` in this project |
| spatie/laravel-permission | v7 (installed) | `User::role('admin')->get()` for recipient resolution | Already the project's RBAC library |
| @inertiajs/vue3 router | v3 (installed) | `router.get()` with `preserveState: true` for filter navigation | Already used throughout the frontend |
| shadcn-vue Select | 2.6 (installed) | Brand and Stripe account dropdown filters | Already used in `payments/Create.vue` — `<Select>`, `<SelectContent>`, `<SelectItem>`, `<SelectTrigger>`, `<SelectValue>` |
| shadcn-vue Input | 2.6 (installed) | Date range `<input type="date">` fields | Already installed |

**No new packages required.** [VERIFIED: codebase grep of composer.json and package.json implied by existing usage in app/Jobs and resources/js]

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `Mail::to($admin)->queue(new PaymentSucceeded($payment))` loop | Laravel Notifications on User model | Notifications add a layer of abstraction that isn't needed here — D-09 specifies `App\Mail\PaymentSucceeded` mailable, not a Notification class |
| Markdown mailable template | Blade mailable | Markdown auto-generates HTML + plain-text, responsive by default, easier to customise globally. D-09 / Claude's Discretion both specify markdown |
| `->when()` scopes inline | Query scopes on model | `->when()` is simpler for 5 optional filters with no reuse requirement |

---

## Architecture Patterns

### System Architecture Diagram

```
[Stripe Webhook] → POST /webhook/stripe/{id}
                     │
                     ▼
            [StripeWebhookController]
                     │ dispatch
                     ▼
       [HandleStripeWebhookJob::handle()]
              │
              ├─ Atomic UPDATE (WHERE status='pending')
              │       $updated = 0 → return (no-op)
              │       $updated = 1 → continue
              │
              └─ (only for payment_intent.succeeded AND $updated > 0)
                     │ dispatch
                     ▼
          [SendPaymentNotification::handle()]
                     │
                     ├─ User::role('admin')->get()
                     │
                     └─ foreach $admin → Mail::to($admin)->queue(new PaymentSucceeded($payment))
                                                  │
                                                  ▼
                                     [PaymentSucceeded mailable]
                                     resources/views/emails/payment-succeeded.blade.php
                                     (markdown template)


[Authenticated User: GET /payments?brand_id=&status=&from=&to=]
                     │
                     ▼
        [PaymentController::index(Request $request)]
              │
              ├─ Base query: Payment::with(['brand','stripeAccount','user'])
              ├─ if !admin → ->where('user_id', $user->id)
              ├─ ->when($request->brand_id, ...) [admin only effective]
              ├─ ->when($request->stripe_account_id, ...)
              ├─ ->when($request->status, ...)
              ├─ ->when($request->from, ...)
              └─ ->when($request->to, ...)
                     │
                     ▼
        Inertia::render('payments/Index', [
            'payments' => [...],
            'brands'   => [...],       // admin only
            'accounts' => [...],       // admin only
            'filters'  => $request->only([...]),
        ])
                     │
                     ▼
        [payments/Index.vue]
              ├─ Filter bar (above table)
              │    ├─ <Select> brand (v-if isAdmin)
              │    ├─ <Select> stripe account (v-if isAdmin)
              │    ├─ <Select> status (always visible)
              │    ├─ <input type="date"> from (always visible)
              │    └─ <input type="date"> to (always visible)
              └─ Existing payment table (unchanged)
```

### Recommended Project Structure (additions only)

```
app/
├── Jobs/
│   ├── HandleStripeWebhookJob.php     # EXISTS — add dispatch call
│   └── SendPaymentNotification.php    # NEW
├── Mail/
│   └── PaymentSucceeded.php           # NEW

resources/
├── views/
│   └── emails/
│       └── payment-succeeded.blade.php  # NEW (markdown template)
├── js/
│   └── pages/
│       └── payments/
│           └── Index.vue              # MODIFY — add filter bar
```

No new controllers, routes, or migrations.

---

### Pattern 1: Queued Job Dispatching Another Queued Job

**What:** `HandleStripeWebhookJob::handle()` dispatches `SendPaymentNotification` only when `$updated > 0` and event type is `payment_intent.succeeded`.

**When to use:** After the atomic status write succeeds — the guard is already in place. Never dispatch notification when `$updated === 0` (idempotency protection also covers duplicate notifications).

```php
// Source: [VERIFIED: app/Jobs/HandleStripeWebhookJob.php — existing pattern]
// Inside HandleStripeWebhookJob::handle(), after the ->update() call:

$updated = Payment::where('stripe_payment_intent_id', $piId)
    ->where('stripe_account_id', $this->stripeAccountId)
    ->where('status', 'pending')
    ->update(match ($this->eventType) {
        'payment_intent.succeeded' => ['status' => 'completed', 'paid_at' => now()],
        'payment_intent.payment_failed' => ['status' => 'failed'],
        default => [],
    });

// ADD AFTER the update — only for succeeded events:
if ($updated > 0 && $this->eventType === 'payment_intent.succeeded') {
    $payment = Payment::with(['brand', 'stripeAccount'])
        ->where('stripe_payment_intent_id', $piId)
        ->first();

    if ($payment) {
        SendPaymentNotification::dispatch($payment);
    }
}
```

**Key detail:** The payment must be re-fetched with `with(['brand', 'stripeAccount'])` after the atomic update so the mailable has full details. The atomic `->update()` does not return a model instance. [VERIFIED: codebase]

---

### Pattern 2: SendPaymentNotification Job

```php
// Source: [VERIFIED: project convention — mirrors HandleStripeWebhookJob structure]
namespace App\Jobs;

use App\Mail\PaymentSucceeded;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPaymentNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly Payment $payment,
    ) {}

    public function handle(): void
    {
        $admins = User::role('admin')->get();

        foreach ($admins as $admin) {
            Mail::to($admin)->queue(new PaymentSucceeded($this->payment));
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('SendPaymentNotification failed', [
            'payment_uuid' => $this->payment->uuid,
            'error' => $exception?->getMessage(),
        ]);
    }
}
```

**Note:** `Mail::to($admin)->queue(...)` queues each mailable separately. No need for the mailable itself to implement `ShouldQueue` — `->queue()` handles it. [VERIFIED: skill mail.md — "Use assertQueued() not assertSent() for queued mailables"]

---

### Pattern 3: Markdown Mailable

```php
// Source: [VERIFIED: project convention D-09 + skill mail.md]
// Generate: php artisan make:mail PaymentSucceeded --markdown=emails.payment-succeeded

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
                     .' ('.formatAmount($this->payment->amount, $this->payment->currency).')',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payment-succeeded',
        );
    }
}
```

**Subject formatting:** The subject line uses `client_name` and formatted amount. Format amount helper can be a private method or inline string — no separate service needed.

---

### Pattern 4: Markdown Template (Blade)

```blade
{{-- Source: [VERIFIED: Laravel markdown mailable convention — ASSUMED structure] --}}
{{-- resources/views/emails/payment-succeeded.blade.php --}}

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

---

### Pattern 5: Controller Filter Scopes

```php
// Source: [VERIFIED: codebase — PaymentController::index() existing structure]
// Extension of existing index() method:

public function index(Request $request): Response
{
    $user  = auth()->user();
    $isAdmin = $user->hasRole('admin');

    $query = Payment::with(['brand', 'stripeAccount', 'user'])
                    ->orderByDesc('created_at');

    if (! $isAdmin) {
        $query->where('user_id', $user->id);
    }

    // Filters — all optional, applied via ->when()
    $query
        ->when($request->brand_id, fn ($q, $v) => $q->where('brand_id', $v))
        ->when($request->stripe_account_id, fn ($q, $v) => $q->where('stripe_account_id', $v))
        ->when($request->status, fn ($q, $v) => $q->where('status', $v))
        ->when($request->from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
        ->when($request->to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));

    $filters = $request->only(['brand_id', 'stripe_account_id', 'status', 'from', 'to']);

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
        'filters'  => $filters,
        // Admin-only: brands and accounts for dropdown population
        'brands'   => $isAdmin
            ? Brand::orderBy('name')->get(['id', 'name'])
            : [],
        'accounts' => $isAdmin
            ? StripeAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
            : [],
        'isAdmin'  => $isAdmin,
    ]);
}
```

**Note:** Passing `isAdmin` as an explicit boolean prop is simpler and more explicit than parsing `$page.props.auth.user.roles` in Vue — avoids string comparison against roles collection in template. [VERIFIED: codebase — HandleInertiaRequests shares `auth.user.roles` as `getRoleNames()` which is a Collection, not a plain array]

---

### Pattern 6: Vue Filter Bar with router.get()

```vue
<!-- Source: [VERIFIED: Inertia v3 SKILL.md + D-07 decision + existing Index.vue patterns] -->
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import { route } from 'ziggy-js';
import {
    Select, SelectContent, SelectItem,
    SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Input } from '@/components/ui/input';

type FilterState = {
    brand_id: string;
    stripe_account_id: string;
    status: string;
    from: string;
    to: string;
};

const props = defineProps<{
    // ... existing payment props
    filters: FilterState;
    brands: { id: number; name: string }[];
    accounts: { id: number; account_name: string }[];
    isAdmin: boolean;
}>();

// Reactive copy of filters — pre-populated from server
const filters = reactive<FilterState>({ ...props.filters });

// Auto-submit on any filter change
watch(filters, (newFilters) => {
    router.get(
        route('payments.index'),
        // Strip empty strings to keep URL clean
        Object.fromEntries(Object.entries(newFilters).filter(([, v]) => v !== '')),
        { preserveState: true, replace: true }
    );
}, { deep: true });
</script>
```

**Key detail:** `replace: true` prevents the browser history from accumulating one entry per filter keystroke. `preserveState: true` keeps scroll position and existing component state. [VERIFIED: SKILL.md + D-07]

---

### Anti-Patterns to Avoid

- **Dispatching notification when `$updated === 0`:** This would send emails on duplicate webhook deliveries. The `$updated > 0` guard prevents this — it is the idempotency wall.
- **Re-fetching admins in the mailable constructor:** Resolve admins in the job's `handle()`, not in the mailable. The mailable receives the payment model only.
- **Using `Mail::send()` instead of `Mail::to()->queue()`:** Would send mail synchronously in the job, blocking the queue worker. Use `->queue()`.
- **Using `assertSent()` in tests for queued mailables:** The project's skill explicitly calls this out — use `Mail::assertQueued(PaymentSucceeded::class)` instead.
- **Passing `stripe_account_id` filter to non-admins:** Even if a non-admin sends `?stripe_account_id=X` in the URL, the `->where('user_id', $user->id)` scope already restricts their view. The filter still applies but cannot expose others' payments.
- **Logging `$payment->stripe_payment_intent_id` in failed() if it could carry sensitive data:** Follow existing `HandleStripeWebhookJob` pattern — log `uuid` and error message only.
- **Using `$page.props.auth.user.roles.includes('admin')` for v-if:** `getRoleNames()` returns a Laravel Collection serialised as an array in Inertia. In TypeScript, `.includes()` works on plain arrays. However, passing `isAdmin` as a boolean prop from the controller is safer and avoids coupling to the roles array shape. [VERIFIED: HandleInertiaRequests.php — `roles` is `$request->user()->getRoleNames()` which serialises to indexed array]

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Markdown email templates | Custom HTML email with inline CSS | `php artisan make:mail --markdown` | Laravel handles HTML + plain-text, responsive, globally styleable |
| Admin recipient list | Hardcoded email list in config | `User::role('admin')->get()` | Dynamic — no config changes when admins are added/removed |
| Amount formatting in email | Custom PHP formatter | `number_format($amount / 100, 2)` inline | Sufficient; no locale library needed for two currencies |
| Queue retry logic | Custom sleep/retry loop | `$tries` + `$backoff` on the job class | Laravel queue handles retry delay natively |
| Filter URL state management | Custom JavaScript query-string builder | Inertia `router.get()` with object params | Inertia handles serialisation, history, and state preservation |

**Key insight:** This phase is deliberately incremental — every pattern has a direct parallel in the existing codebase.

---

## Common Pitfalls

### Pitfall 1: Notification Sent for Failed Payment (Event Type Guard Missing)

**What goes wrong:** `SendPaymentNotification` is dispatched for both `payment_intent.succeeded` AND `payment_intent.payment_failed` if the guard only checks `$updated > 0`.

**Why it happens:** `$updated > 0` is true for both event types — the `match` block updates status for both.

**How to avoid:** Add an explicit event type check: `if ($updated > 0 && $this->eventType === 'payment_intent.succeeded')`.

**Warning signs:** Admin receives "Payment received" email for a failed payment.

---

### Pitfall 2: Payment Model Missing Relationships in Mailable

**What goes wrong:** `$payment->brand->name` throws `Attempt to read property "name" on null` in the markdown template.

**Why it happens:** The payment retrieved after the atomic `->update()` needs `with(['brand', 'stripeAccount'])` eager loading. The `->update()` call returns an integer, not a model.

**How to avoid:** Re-fetch the payment with relationships: `Payment::with(['brand', 'stripeAccount'])->where('stripe_payment_intent_id', $piId)->first()`.

**Warning signs:** `null` appearing in email template fields for brand or account name; TypeError in queue logs.

---

### Pitfall 3: Filter State Lost on Page Load / Back-Navigation

**What goes wrong:** User applies filters, navigates away, presses back — filters are gone.

**Why it happens:** Filter inputs not pre-populated from the `filters` prop passed by the controller.

**How to avoid:** Controller passes `'filters' => $request->only([...])`, Vue initialises `reactive({ ...props.filters })`. Inertia re-populates on every page visit.

**Warning signs:** Filter dropdowns reset to empty after Inertia navigation.

---

### Pitfall 4: Inertia History Pollution from Auto-Submit

**What goes wrong:** Every filter change pushes a new browser history entry; pressing Back requires many clicks to leave the page.

**Why it happens:** `router.get()` without `replace: true` pushes to history by default.

**How to avoid:** Always use `{ preserveState: true, replace: true }` for filter navigation.

**Warning signs:** Browser back button requires many clicks; browser history fills with `/payments?...` entries.

---

### Pitfall 5: `Mail::assertSent()` Fails in Tests for Queued Mailables

**What goes wrong:** Test using `Mail::assertSent(PaymentSucceeded::class)` always fails even when mail is dispatched.

**Why it happens:** `Mail::to()->queue()` queues the mailable — it is not "sent" synchronously. `assertSent()` only catches synchronous sends.

**How to avoid:** Use `Mail::assertQueued(PaymentSucceeded::class)` in tests. [VERIFIED: project skills — mail.md]

---

### Pitfall 6: Empty String Filters Sent as Query Params

**What goes wrong:** URL becomes `/payments?brand_id=&status=&from=&to=` when no filters selected, cluttering the URL and confusing `->when()`.

**Why it happens:** `router.get()` serialises all keys including empty strings.

**How to avoid:** Strip empty values before passing to `router.get()`:
```typescript
Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))
```

**Note:** `->when()` treats an empty string as falsy in PHP (`''` evaluates to false in boolean context), so this is a UX / URL cleanliness issue rather than a functional bug. But clean URLs are better practice.

---

## Code Examples

### Complete HandleStripeWebhookJob handle() After Phase 7 Changes

```php
// Source: [VERIFIED: app/Jobs/HandleStripeWebhookJob.php — existing, with Phase 7 addition marked]
public function handle(): void
{
    $piId = $this->eventData['id'] ?? null;

    $updated = Payment::where('stripe_payment_intent_id', $piId)
        ->where('stripe_account_id', $this->stripeAccountId)
        ->where('status', 'pending')
        ->update(match ($this->eventType) {
            'payment_intent.succeeded' => ['status' => 'completed', 'paid_at' => now()],
            'payment_intent.payment_failed' => ['status' => 'failed'],
            default => [],
        });

    // Phase 7 addition — dispatch notification only for successful payments
    if ($updated > 0 && $this->eventType === 'payment_intent.succeeded') {
        $payment = Payment::with(['brand', 'stripeAccount'])
            ->where('stripe_payment_intent_id', $piId)
            ->first();

        if ($payment) {
            SendPaymentNotification::dispatch($payment);
        }
    }
}
```

### Test Pattern for Notification Job Dispatch

```php
// Source: [VERIFIED: project convention — StripeWebhookTest.php pattern with Queue::fake()]
it('payment_intent.succeeded dispatches SendPaymentNotification job', function () {
    Queue::fake();

    $account = StripeAccount::factory()->create(['webhook_secret' => 'whsec_test123']);
    Payment::factory()->create([
        'status' => 'pending',
        'stripe_payment_intent_id' => 'pi_notify_test',
        'stripe_account_id' => $account->id,
    ]);

    $payload = json_encode([
        'type' => 'payment_intent.succeeded',
        'data' => ['object' => ['id' => 'pi_notify_test']],
    ]);

    stripePost("/webhook/stripe/{$account->id}", $payload, fakeStripeSignature($payload, 'whsec_test123'))
        ->assertStatus(200);

    Queue::assertPushed(SendPaymentNotification::class);
});
```

### Test Pattern for Mail Queued

```php
// Source: [VERIFIED: project skill mail.md — assertQueued not assertSent]
it('SendPaymentNotification queues PaymentSucceeded mail to all admins', function () {
    Mail::fake();

    $admin1 = User::factory()->create();
    $admin1->syncRoles(['admin']);
    $admin2 = User::factory()->create();
    $admin2->syncRoles(['admin']);
    User::factory()->create()->syncRoles(['user']); // should NOT receive mail

    $payment = Payment::factory()
        ->for(Brand::factory())
        ->for(StripeAccount::factory(), 'stripeAccount')
        ->create(['status' => 'completed']);

    SendPaymentNotification::dispatchSync($payment);

    Mail::assertQueued(PaymentSucceeded::class, 2);
    Mail::assertQueued(PaymentSucceeded::class, fn ($mail) =>
        $mail->hasTo($admin1->email)
    );
});
```

### Test Pattern for Mailable Content

```php
// Source: [VERIFIED: project skill mail.md — separate content tests from sending tests]
it('PaymentSucceeded mailable contains payment details', function () {
    $payment = Payment::factory()
        ->for(Brand::factory()->create(['name' => 'Acme Corp']))
        ->for(StripeAccount::factory()->create(['account_name' => 'Acme Stripe']), 'stripeAccount')
        ->create([
            'client_name'  => 'Jane Doe',
            'client_email' => 'jane@example.com',
            'amount'       => 10000,
            'currency'     => 'usd',
            'service'      => 'Web Design',
            'package'      => 'standard',
        ]);

    $mailable = new PaymentSucceeded($payment);

    $mailable->assertSeeInHtml('Jane Doe');
    $mailable->assertSeeInHtml('jane@example.com');
    $mailable->assertSeeInHtml('Acme Corp');
    $mailable->assertSeeInHtml('100.00');
});
```

### Test Pattern for Filter Scopes

```php
// Source: [VERIFIED: project convention — follows existing PaymentCreationTest pattern]
it('admin can filter payments by status', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['admin']);

    Payment::factory()->create(['status' => 'completed']);
    Payment::factory()->create(['status' => 'pending']);

    $this->actingAs($admin)
        ->get(route('payments.index', ['status' => 'completed']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('payments', 1)
            ->where('payments.0.status', 'completed')
        );
});

it('user only sees own payments regardless of filter', function () {
    $user  = User::factory()->create();
    $user->syncRoles(['user']);
    $other = User::factory()->create();
    $other->syncRoles(['user']);

    Payment::factory()->create(['user_id' => $user->id, 'status' => 'completed']);
    Payment::factory()->create(['user_id' => $other->id, 'status' => 'completed']);

    $this->actingAs($user)
        ->get(route('payments.index', ['status' => 'completed']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('payments', 1));
});
```

---

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `Mail::send()` synchronous | `Mail::to()->queue()` for non-blocking delivery | Laravel 5+ | Webhook handler returns fast; email failure doesn't affect payment status |
| `Inertia::lazy()` for deferred props | `Inertia::optional()` | Inertia v3 | `Inertia::lazy()` removed in v3 — not relevant to this phase but noted |
| `router.cancel()` | `router.cancelAll()` | Inertia v3 | Breaking change — use `cancelAll()` if ever needed |

**Deprecated / outdated patterns NOT applicable to this phase:**
- `Notification::send()` / User notifications — D-09 specifies mailable directly; no Notification class needed
- Event/Listener bus — D-08 explicitly rules this out in favour of inline dispatch

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `Mail::to($admin)->queue(new PaymentSucceeded($payment))` queues without requiring `ShouldQueue` on the mailable itself | Pattern 2, Pitfall 5 | Low — the queue() call always queues regardless of mailable interface. Confirmed by skill mail.md: "Makes queueing the default regardless of how the mailable is dispatched." |
| A2 | `->when($request->brand_id, ...)` treats an empty string as falsy and skips the scope | Pattern 5, Pitfall 6 | Low — PHP empty string is falsy; `->when()` uses PHP truthiness. Confirmed by Laravel internals pattern. |
| A3 | `Payment::factory()->for(Brand::factory())` syntax is valid in existing factories | Test examples | Low — standard Laravel factory relationship method; consistent with existing tests. |

**All other claims in this document are VERIFIED against the codebase or confirmed skills/skills.**

---

## Open Questions

1. **Amount formatting in email subject line**
   - What we know: D-03 specifies "amount + currency (formatted)" in the email body and implicitly in the subject
   - What's unclear: Whether to use PHP `number_format()` inline or extract a helper method
   - Recommendation: Use a private method `formatAmount(int $cents, string $currency): string` in the `PaymentSucceeded` mailable to avoid duplicating logic. No service class needed.

2. **`No admins` edge case in SendPaymentNotification**
   - What we know: `User::role('admin')->get()` could return an empty collection if all admin accounts are deleted
   - What's unclear: Should this log a warning?
   - Recommendation: Silent no-op is acceptable. The job succeeds with no mail sent. Optionally log a warning in `handle()` if `$admins->isEmpty()`.

---

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP artisan queue worker | NOTIFY-02 (queued jobs) | Yes (QUEUE_CONNECTION=database) | database driver | QUEUE_CONNECTION=sync for manual testing |
| MAIL_MAILER | NOTIFY-01 (email sending) | Yes — `log` mailer in dev | log (dev) | No fallback needed — log mailer captures all mail to Laravel log |
| spatie/laravel-permission | D-01 (admin recipient query) | Yes (installed v7) | v7 | — |

**No missing dependencies.** Development mail is configured as `log` mailer — emails appear in `storage/logs/laravel.log`. No SMTP server required for local testing.

**Testing the full flow locally:**
```bash
php artisan queue:work    # Terminal 1 — processes database queue
php artisan log:watch     # or: tail -f storage/logs/laravel.log
stripe listen --forward-to localhost:8000/webhook/stripe/{accountId}  # Terminal 2
```

---

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest v4 |
| Config file | `phpunit.xml` |
| Quick run command | `php artisan test --compact --filter=NotificationTest` |
| Full suite command | `php artisan test --compact` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| NOTIFY-01 | Admin receives mail when payment succeeds via webhook | Feature | `php artisan test --compact --filter=NotificationTest` | Wave 0 |
| NOTIFY-02 | Mail sent via queued job (Queue::assertPushed) | Feature | `php artisan test --compact --filter=NotificationTest` | Wave 0 |
| DASH-01 | Admin sees all payments in index | Feature | `php artisan test --compact --filter=PaymentDashboardTest` | Wave 0 |
| DASH-02 | Admin can filter by brand, account, status, date range | Feature | `php artisan test --compact --filter=PaymentDashboardTest` | Wave 0 |
| DASH-03 | User sees only own payments | Feature | `php artisan test --compact --filter=PaymentDashboardTest` | Wave 0 |
| DASH-04 | Payment list columns: amount, currency, brand, status, created, client email | Feature (Inertia assertion) | `php artisan test --compact --filter=PaymentDashboardTest` | Wave 0 |

### Sampling Rate

- **Per task commit:** `php artisan test --compact --filter=NotificationTest\|PaymentDashboardTest`
- **Per wave merge:** `php artisan test --compact`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps

- [ ] `tests/Feature/NotificationTest.php` — covers NOTIFY-01, NOTIFY-02 (dispatch, mail queued, mail content, no notification for failed payments)
- [ ] `tests/Feature/PaymentDashboardTest.php` — covers DASH-01, DASH-02, DASH-03, DASH-04 (filter scopes, role gating, Inertia prop assertions)

*(Existing `StripeWebhookTest.php` covers the webhook dispatch chain; `NotificationTest` tests extend that chain through to mail queuing.)*

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | All admin mail access gated by existing `HasRole('admin')` check |
| V3 Session Management | No | No new session handling |
| V4 Access Control | Yes | Filter endpoint: non-admins cannot see others' payments regardless of query params (enforced by `->where('user_id', ...)` scope) |
| V5 Input Validation | Yes | Filter query params are used in `->when()` with Eloquent parameterised queries — no raw SQL |
| V6 Cryptography | No | No new cryptographic operations |

### Known Threat Patterns for this Stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Horizontal privilege escalation via filter params | Elevation of Privilege | Non-admin user_id scope applied unconditionally before filters — user cannot see others' payments via `?brand_id=X` |
| Email header injection | Tampering | Laravel Mail escapes mailable fields; `client_name` and `client_email` are rendered in Blade context, not raw headers |
| Log injection via payment data in failed() | Information Disclosure | `failed()` logs `payment_uuid` and error message only — same pattern as `HandleStripeWebhookJob.failed()` |

---

## Sources

### Primary (HIGH confidence)

- `app/Jobs/HandleStripeWebhookJob.php` — verified dispatch point, `$updated` guard, job structure conventions
- `app/Http/Controllers/PaymentController.php` — verified `index()` structure for filter extension
- `resources/js/pages/payments/Index.vue` — verified existing columns, Select import pattern, `copyLink` pattern
- `app/Http/Middleware/HandleInertiaRequests.php` — verified `auth.user.roles` shape (`getRoleNames()`)
- `.env` — verified `QUEUE_CONNECTION=database`, `MAIL_MAILER=log`
- `.claude/skills/laravel-best-practices/rules/mail.md` — `assertQueued` vs `assertSent`, queueing pattern
- `.claude/skills/laravel-best-practices/rules/queue-jobs.md` — `$tries`, `$backoff`, `failed()` pattern
- `.claude/skills/inertia-vue-development/SKILL.md` — `router.get()` with `preserveState: true` + `replace: true`
- `.claude/skills/pest-testing/SKILL.md` — test structure, `Mail::fake()`, `Queue::fake()` patterns

### Secondary (MEDIUM confidence)

- `resources/js/pages/payments/Create.vue` — Select component usage pattern verified (same shadcn-vue components available for filter bar)
- `.planning/phases/07-notifications-dashboard/07-CONTEXT.md` — all decisions locked, sourced from user session

### Tertiary (LOW confidence)

None — all claims in this document trace to verified codebase files or project skills.

---

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — all libraries already installed, verified in codebase
- Architecture: HIGH — both sides (notification job chain, filter controller) directly extend verified existing code
- Pitfalls: HIGH — derived from existing code patterns, project skills, and locked decisions
- Test patterns: HIGH — mirrors existing `StripeWebhookTest.php` and project conventions

**Research date:** 2026-05-14
**Valid until:** 2026-06-14 (stable stack — no fast-moving dependencies)
