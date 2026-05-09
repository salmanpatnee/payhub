# Phase 4: Payment Creation + Link Generation — Pattern Map

**Mapped:** 2026-05-05
**Files analyzed:** 11
**Analogs found:** 11 / 11

---

## File Classification

| New / Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---------------------|------|-----------|----------------|---------------|
| `database/migrations/XXXX_update_payments_table_add_phase4_columns.php` | migration | transform | `database/migrations/2026_05_05_052551_drop_brand_id_from_stripe_accounts_table.php` | exact |
| `database/factories/PaymentFactory.php` (UPDATE) | factory | transform | `database/factories/BrandFactory.php` | role-match |
| `database/seeders/DatabaseSeeder.php` (UPDATE) | seeder | CRUD | `database/seeders/DatabaseSeeder.php` (self) | exact |
| `app/Models/Payment.php` (UPDATE fillable) | model | CRUD | `app/Models/Payment.php` (self) | exact |
| `app/Http/Controllers/PaymentController.php` | controller | request-response | `app/Http/Controllers/Admin/BrandController.php` | exact |
| `app/Http/Requests/StorePaymentRequest.php` | request | request-response | `app/Http/Requests/Admin/StoreUserRequest.php` | role-match |
| `resources/js/pages/payments/Create.vue` | component | request-response | `resources/js/pages/admin/users/Create.vue` + `resources/js/pages/admin/brands/Create.vue` | exact |
| `resources/js/pages/payments/Index.vue` | component | request-response | `resources/js/pages/admin/brands/Index.vue` | exact |
| `resources/js/pages/payments/Show.vue` | component | request-response | `resources/js/pages/admin/stripe-accounts/Index.vue` (Badge pattern) | partial |
| `routes/web.php` (UPDATE) | route | request-response | `routes/web.php` (self — resource route pattern) | exact |
| `tests/Feature/PaymentCreationTest.php` | test | CRUD | `tests/Feature/PaymentAmountIntegrityTest.php` + `tests/Feature/ModelRelationshipsTest.php` | role-match |

---

## Pattern Assignments

### `database/migrations/XXXX_update_payments_table_add_phase4_columns.php` (migration, transform)

**Analog:** `database/migrations/2026_05_05_052551_drop_brand_id_from_stripe_accounts_table.php`

**File structure pattern** (lines 1–23 of analog — full file):
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stripe_accounts', function (Blueprint $table) {
            $table->dropForeign(['brand_id']);
            $table->dropColumn('brand_id');
        });
    }

    public function down(): void
    {
        Schema::table('stripe_accounts', function (Blueprint $table) {
            $table->foreignId('brand_id')->after('id')->constrained()->cascadeOnDelete();
        });
    }
};
```

**Apply for Phase 4:** The `up()` adds four columns and drops `description`. The `down()` reverses. Use `Schema::table('payments', ...)`. No `return new class extends Migration` wrapper difference — it is the same anonymous class form.

**Column order note:** Use `->after('client_email')` for `client_name`, then chain each subsequent column `->after('client_name')` etc., matching the `->change()`/`->after()` pattern seen in `2026_05_05_055534_fix_webhook_secret_nullable_on_stripe_accounts.php` line 12.

**Enum pattern** (from RESEARCH.md Pattern 1 — verified against existing DB schema):
```php
$table->enum('package', ['basic', 'standard', 'premium', 'platinum', 'diamond'])
      ->nullable()->after('service');
```

---

### `database/factories/PaymentFactory.php` (UPDATE) (factory, transform)

**Analog:** `database/factories/PaymentFactory.php` (self — existing file, lines 1–31)

**Current definition to replace** (lines 14–30):
```php
public function definition(): array
{
    return [
        'uuid'                      => Str::uuid(),
        'brand_id'                  => Brand::factory(),
        'stripe_account_id'         => StripeAccount::factory(),
        'user_id'                   => User::factory(),
        'amount'                    => $this->faker->numberBetween(500, 100000),
        'currency'                  => $this->faker->randomElement(['usd', 'gbp']),
        'description'               => $this->faker->sentence(),  // <-- DROP THIS
        'status'                    => 'pending',
        'client_email'              => $this->faker->safeEmail(),
        'stripe_payment_intent_id'  => null,
        'expires_at'                => null,
        'paid_at'                   => null,
    ];
}
```

**Replacement definition** (remove `description`, add Phase 4 fields):
```php
public function definition(): array
{
    return [
        'uuid'                     => Str::uuid(),
        'brand_id'                 => Brand::factory(),
        'stripe_account_id'        => StripeAccount::factory(),
        'user_id'                  => User::factory(),
        'amount'                   => $this->faker->numberBetween(500, 100000),
        'currency'                 => $this->faker->randomElement(['usd', 'gbp']),
        'client_name'              => $this->faker->name(),
        'client_email'             => $this->faker->safeEmail(),
        'service'                  => $this->faker->sentence(3),
        'package'                  => $this->faker->randomElement([
                                         'basic', 'standard', 'premium',
                                         'platinum', 'diamond', null,
                                     ]),
        'note'                     => null,
        'status'                   => 'pending',
        'stripe_payment_intent_id' => null,
        'expires_at'               => null,
        'paid_at'                  => null,
    ];
}
```

**Critical:** `description` MUST be removed in the same wave as the migration or existing tests (`PaymentAmountIntegrityTest`, `ModelRelationshipsTest`) will fail when factory tries to insert into a dropped column.

---

### `database/seeders/DatabaseSeeder.php` (UPDATE) (seeder, CRUD)

**Analog:** `database/seeders/DatabaseSeeder.php` (self — lines 1–51)

**Existing pattern** (lines 20–39 — `firstOrCreate` idiom):
```php
$brand = Brand::firstOrCreate(
    ['slug' => 'demo-brand'],
    [
        'name'            => 'Demo Brand',
        'logo_path'       => null,
        'primary_color'   => '#3B82F6',
        'secondary_color' => '#EFF6FF',
    ]
);

StripeAccount::firstOrCreate(
    ['account_name' => 'Demo Stripe Account'],
    [
        // NOTE: brand_id removed from StripeAccount — do NOT include it here
        'publishable_key' => 'pk_test_placeholder_for_dev_only',
        'secret_key'      => 'sk_test_placeholder_for_dev_only',
        'webhook_secret'  => 'whsec_placeholder_for_dev_only',
        'is_active'       => true,
    ]
);
```

**Required change 1 — fix existing StripeAccount call:** Line 33 passes `'brand_id' => $brand->id` which will cause an error after Phase 3 dropped that column. Remove it.

**Required change 2 — add Payment seed record** (append after `$admin->syncRoles`):
```php
// Capture $stripeAccount from firstOrCreate above, then:
Payment::create([
    'brand_id'          => $brand->id,
    'stripe_account_id' => $stripeAccount->id,
    'user_id'           => $admin->id,
    'amount'            => 2500,           // $25.00 in cents
    'currency'          => 'usd',
    'client_name'       => 'Alice Smith',
    'client_email'      => 'alice@example.com',
    'service'           => 'Web Design',
    'package'           => 'standard',
    'note'              => 'Demo payment for dev testing',
    'status'            => 'pending',
    'expires_at'        => null,
]);
```

**Add `use App\Models\Payment;` to the imports block** (existing imports are lines 5–10).

---

### `app/Models/Payment.php` (UPDATE fillable) (model, CRUD)

**Analog:** `app/Models/Payment.php` (self — lines 1–60)

**Current `$fillable`** (lines 14–18):
```php
protected $fillable = [
    'uuid', 'brand_id', 'stripe_account_id', 'user_id',
    'amount', 'currency', 'description', 'status',
    'client_email', 'stripe_payment_intent_id', 'expires_at', 'paid_at',
];
```

**Replacement `$fillable`** (drop `description`, add `client_name`, `service`, `package`, `note`):
```php
protected $fillable = [
    'uuid', 'brand_id', 'stripe_account_id', 'user_id',
    'amount', 'currency', 'status',
    'client_email', 'client_name',
    'service', 'package', 'note',
    'stripe_payment_intent_id', 'expires_at', 'paid_at',
];
```

**No changes to `casts()` or relationships** — they are correct as-is (lines 37–59).

---

### `app/Http/Controllers/PaymentController.php` (controller, request-response)

**Analog:** `app/Http/Controllers/Admin/BrandController.php` (lines 1–114)

**Namespace + imports pattern** (lines 1–13 of BrandController):
```php
<?php

namespace App\Http\Controllers;          // NOTE: NOT Admin\ — payments are not admin-only (D-03)

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Brand;
use App\Models\Payment;
use App\Models\StripeAccount;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
```

**index() pattern** — role-scoped query with eager loading (adapt from BrandController lines 17–31):
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

**create() pattern** (BrandController line 33–35 as base):
```php
public function create(): Response
{
    return Inertia::render('payments/Create', [
        'brands'         => Brand::orderBy('name')->get(['id', 'name']),
        'stripeAccounts' => StripeAccount::where('is_active', true)
                                         ->orderBy('account_name')
                                         ->get(['id', 'account_name']),
    ]);
}
```

**store() pattern** (BrandController lines 38–50 as base — redirect to show, not index):
```php
public function store(StorePaymentRequest $request): RedirectResponse
{
    $payment = Payment::create([
        ...$request->validated(),
        'user_id'    => auth()->id(),      // NEVER from request
        'status'     => 'pending',
        'expires_at' => null,              // PAY-07: links never expire
    ]);

    return redirect()->route('payments.show', $payment);
    // Route model binding resolves via uuid (getRouteKeyName = 'uuid')
}
```

**show() pattern** (no direct analog — new for Phase 4):
```php
public function show(Payment $payment): Response
{
    return Inertia::render('payments/Show', [
        'payment' => [
            'uuid'         => $payment->uuid,
            'amount'       => $payment->amount,
            'currency'     => $payment->currency,
            'status'       => $payment->status,
            'client_name'  => $payment->client_name,
            'client_email' => $payment->client_email,
            'service'      => $payment->service,
            'package'      => $payment->package,
            'note'         => $payment->note,
            'brand_name'   => $payment->brand->name,
            'created_at'   => $payment->created_at->toISOString(),
        ],
    ]);
}
```

**Key difference from Admin controllers:** No `'admin.'` route prefix. No `role:admin` middleware. Namespace is `App\Http\Controllers` not `App\Http\Controllers\Admin`.

---

### `app/Http/Requests/StorePaymentRequest.php` (request, request-response)

**Analog:** `app/Http/Requests/Admin/StoreUserRequest.php` (lines 1–24)

**File structure pattern** (full StoreUserRequest):
```php
<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::default()],
            'role'     => ['required', 'string', 'in:admin,user'],
        ];
    }
}
```

**Adapt for PaymentRequest:**
- Namespace: `App\Http\Requests` (not `Admin\`)
- `authorize()`: return `$this->user() !== null` (both admin and user allowed — D-03)
- Add `passedValidation()` hook for amount conversion (not present in StoreUserRequest — new pattern)
- Import `Illuminate\Validation\Rule` for `Rule::exists()->where()` constraint

**Full rules + passedValidation pattern:**
```php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'brand_id'          => ['required', 'integer', 'exists:brands,id'],
            'stripe_account_id' => ['required', 'integer',
                                    Rule::exists('stripe_accounts', 'id')
                                        ->where('is_active', true)],
            'currency'          => ['required', 'string', 'in:usd,gbp'],
            'amount'            => ['required', 'numeric', 'min:0.01'],
            'client_name'       => ['required', 'string', 'max:255'],
            'client_email'      => ['required', 'email', 'max:255'],
            'service'           => ['nullable', 'string', 'max:255'],
            'package'           => ['nullable', 'string',
                                    'in:basic,standard,premium,platinum,diamond'],
            'note'              => ['nullable', 'string'],
        ];
    }

    protected function passedValidation(): void
    {
        // SEC-02: Convert decimal input to integer cents server-side.
        // This is the ONLY place the client-submitted amount is used.
        $this->merge(['amount' => (int) round($this->amount * 100)]);
    }
}
```

---

### `resources/js/pages/payments/Create.vue` (component, request-response)

**Primary analog:** `resources/js/pages/admin/users/Create.vue` (lines 1–122) — Select + useForm + Card pattern
**Secondary analog:** `resources/js/pages/admin/brands/Create.vue` (lines 1–206) — computed + form.post pattern

**Imports pattern** (admin/users/Create.vue lines 1–16 + admin/brands/Create.vue lines 1–17):
```typescript
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Plus } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Card, CardContent, CardDescription,
    CardFooter, CardHeader, CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import {
    Select, SelectContent, SelectItem,
    SelectTrigger, SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';  // requires Wave 0 install
```

**defineOptions breadcrumb pattern** (admin/users/Create.vue lines 19–25):
```typescript
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
            { title: 'New payment', href: '/payments/create' },
        ],
    },
});
```

**useForm initialization pattern** (admin/users/Create.vue lines 28–33):
```typescript
const form = useForm({
    brand_id:          '',
    stripe_account_id: '',
    currency:          'usd',
    amount:            '',
    client_name:       '',
    client_email:      '',
    service:           '',
    package:           '',
    note:              '',
});
```

**computed pattern for fee breakdown** (brands/Create.vue lines 47–49 as structural analog — hex validation computed):
```typescript
// D-14: Client-side fee breakdown — no server round-trip
const feeBreakdown = computed(() => {
    const amt = parseFloat(form.amount as string);
    if (!amt || amt <= 0) return null;

    const fee     = form.currency === 'gbp' ? amt * 0.015 + 0.20 : amt * 0.029 + 0.30;
    const receive = amt - fee;
    const locale  = form.currency === 'gbp' ? 'en-GB' : 'en-US';
    const curr    = form.currency.toUpperCase();
    const fmt     = (n: number) =>
        new Intl.NumberFormat(locale, { style: 'currency', currency: curr }).format(n);

    return { charge: fmt(amt), fee: fmt(fee), receive: fmt(receive) };
});
```

**form.post submit pattern** (brands/Create.vue line 51–54):
```typescript
function submit() {
    form.post('/payments');
}
```

**Select v-model pattern** (admin/users/Create.vue lines 101–109):
```vue
<Select v-model="form.role">
    <SelectTrigger id="role" class="w-full">
        <SelectValue placeholder="Select a role" />
    </SelectTrigger>
    <SelectContent>
        <SelectItem value="admin">Admin</SelectItem>
        <SelectItem value="user">User</SelectItem>
    </SelectContent>
</Select>
<InputError class="mt-2" :message="form.errors.role" />
```

**Apply the same `v-model` pattern for `brand_id`, `stripe_account_id`, `currency`, `package`.**

**Card + form layout pattern** (admin/users/Create.vue lines 54–120):
```vue
<Card>
    <CardHeader>
        <CardTitle>New payment</CardTitle>
        <CardDescription>...</CardDescription>
    </CardHeader>
    <CardContent>
        <form id="create-payment-form" class="grid grid-cols-2 gap-4" @submit.prevent="submit">
            <!-- fields here, each: <div class="grid gap-2"> + Label + Input/Select + InputError -->
        </form>
    </CardContent>
    <CardFooter class="flex justify-end">
        <Button type="submit" form="create-payment-form" :disabled="form.processing">
            <Plus class="size-4 mr-1" />
            Create payment
        </Button>
    </CardFooter>
</Card>
```

**Back button pattern** (admin/users/Create.vue lines 46–50):
```vue
<Button variant="ghost" size="sm" as-child class="-ml-2 mb-4">
    <Link href="/payments">
        <ArrowLeft class="size-4 mr-1" />
        Back to payments
    </Link>
</Button>
```

---

### `resources/js/pages/payments/Index.vue` (component, request-response)

**Analog:** `resources/js/pages/admin/brands/Index.vue` (lines 1–152)

**TypeScript type + defineProps pattern** (brands/Index.vue lines 8–17):
```typescript
type PaymentRow = {
    id: number;
    uuid: string;
    amount: number;        // integer cents — format in template with Intl.NumberFormat
    currency: string;
    brand_name: string;
    account_name: string;
    status: string;        // 'pending' | 'completed' | 'failed' | 'cancelled'
    created_at: string;
    client_email: string;
    client_name: string;
};

defineProps<{ payments: PaymentRow[] }>();
```

**defineOptions breadcrumbs** (brands/Index.vue lines 19–24):
```typescript
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
        ],
    },
});
```

**Table structure pattern** (brands/Index.vue lines 60–141):
```vue
<div class="rounded-lg border border-border bg-card overflow-hidden">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-muted/40 border-b border-border">
                <th class="text-left px-4 py-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                    Client
                </th>
                <!-- repeat pattern for each column -->
                <th class="text-right px-4 py-3 ...">Actions</th>
            </tr>
        </thead>
        <tbody>
            <tr
                v-for="payment in payments"
                :key="payment.id"
                class="border-b border-border last:border-0 hover:bg-muted/50 transition-colors"
            >
                <!-- cells -->
            </tr>
            <tr v-if="payments.length === 0">
                <td colspan="9" class="px-4 py-12 text-center text-muted-foreground text-sm">
                    No payments yet.
                </td>
            </tr>
        </tbody>
    </table>
</div>
```

**Status badge pattern** (stripe-accounts/Index.vue lines 108–112 — Badge import required):
```vue
<!-- pending = yellow, completed = green, failed/cancelled = destructive -->
<Badge variant="outline">{{ payment.status }}</Badge>
```
Use `Badge` from `@/components/ui/badge` (already installed — verified in stripe-accounts/Index.vue line 6).

**No ConfirmDeleteDialog** — payments are immutable. No delete action. Copy-link action instead:
```typescript
function copyLink(uuid: string) {
    navigator.clipboard.writeText(`${window.location.origin}/pay/${uuid}`);
}
```

**Page header + "New payment" button pattern** (brands/Index.vue lines 51–58):
```vue
<div class="flex items-center justify-between">
    <h1 class="text-xl font-semibold">Payments</h1>
    <Button as-child>
        <Link href="/payments/create">
            <Plus class="size-4 mr-1" />
            New payment
        </Link>
    </Button>
</div>
```

---

### `resources/js/pages/payments/Show.vue` (component, request-response)

**No direct Show page analog exists in the codebase.** Derive from established patterns:

**Imports pattern** (closest: brands/Create.vue lines 1–17 for Card imports):
```typescript
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ArrowLeft, Copy, Check } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card, CardContent, CardDescription,
    CardHeader, CardTitle,
} from '@/components/ui/card';
```

**TypeScript type + defineProps pattern** (same shape as the controller's Inertia prop):
```typescript
type PaymentDetail = {
    uuid: string;
    amount: number;           // integer cents
    currency: string;
    status: string;
    client_name: string;
    client_email: string;
    service: string | null;
    package: string | null;
    note: string | null;
    brand_name: string;
    created_at: string;
};

const props = defineProps<{ payment: PaymentDetail }>();
```

**defineOptions breadcrumbs** (consistent with other pages):
```typescript
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
            { title: 'Payment link', href: '#' },
        ],
    },
});
```

**Clipboard copy pattern** (navigator.clipboard — no analog, standard browser API):
```typescript
const shareableLink = `${window.location.origin}/pay/${props.payment.uuid}`;
const copied = ref(false);

async function copyLink() {
    await navigator.clipboard.writeText(shareableLink);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
}
```

**Amount formatting pattern** (Intl.NumberFormat — same as fee breakdown in Create.vue):
```typescript
function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    ).format(cents / 100);
}
```

**Back button pattern** (admin/users/Create.vue lines 46–50):
```vue
<Button variant="ghost" size="sm" as-child class="-ml-2 mb-4">
    <Link href="/payments">
        <ArrowLeft class="size-4 mr-1" />
        Back to payments
    </Link>
</Button>
```

---

### `routes/web.php` (UPDATE) (route, request-response)

**Analog:** `routes/web.php` (self — lines 19–46, resource route pattern)

**Existing resource route pattern** (lines 23–30):
```php
Route::resource('users', AdminUserController::class)
    ->except(['show']);

Route::resource('brands', BrandController::class)
    ->except(['show']);
```

**Replace lines 16–17** (the ComingSoon placeholder):
```php
// BEFORE (lines 16–17):
Route::inertia('/payments', 'placeholders/ComingSoon')->name('payments.index');

// AFTER — inside the existing auth+verified group at line 14:
Route::resource('payments', PaymentController::class)
     ->only(['index', 'create', 'store', 'show']);
```

**Add import at top of file** (lines 3–5 pattern):
```php
use App\Http\Controllers\PaymentController;
```

**Named routes generated:** `payments.index`, `payments.create`, `payments.store`, `payments.show` — matches D-03 and nav link already pointing to `/payments`.

---

### `tests/Feature/PaymentCreationTest.php` (test, CRUD)

**Analog:** `tests/Feature/PaymentAmountIntegrityTest.php` (lines 1–30) + `tests/Feature/ModelRelationshipsTest.php` (lines 1–39)

**File header pattern** (PaymentAmountIntegrityTest.php lines 1–6):
```php
<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
```

**Factory usage pattern** (ModelRelationshipsTest.php lines 12–18):
```php
$brand         = Brand::factory()->create();
$stripeAccount = StripeAccount::factory()->create();
$user          = User::factory()->create();

$payment = Payment::factory()->create([
    'brand_id'          => $brand->id,
    'stripe_account_id' => $stripeAccount->id,
    'user_id'           => $user->id,
]);
```

**Integer assertion pattern** (PaymentAmountIntegrityTest.php lines 8–14):
```php
it('stores amount as integer cents', function () {
    $payment = Payment::factory()->create(['amount' => 999]);
    $fresh   = Payment::find($payment->id);

    expect($fresh->amount)->toBe(999);
    expect($fresh->amount)->toBeInt();
});
```

**HTTP test structure** (Pest functional style — adapt from existing test style):
```php
it('authenticated user can create a payment', function () {
    $user          = User::factory()->create();
    $user->assignRole('user');
    $brand         = Brand::factory()->create();
    $stripeAccount = StripeAccount::factory()->create(['is_active' => true]);

    $response = $this->actingAs($user)->post('/payments', [
        'brand_id'          => $brand->id,
        'stripe_account_id' => $stripeAccount->id,
        'currency'          => 'usd',
        'amount'            => '25.00',
        'client_name'       => 'Alice Smith',
        'client_email'      => 'alice@example.com',
        'service'           => 'Web Design',
        'package'           => 'standard',
        'note'              => null,
    ]);

    $payment = Payment::first();
    $response->assertRedirect("/payments/{$payment->uuid}");
});

it('converts decimal amount to integer cents (SEC-02)', function () {
    // Post 25.00; expect 2500 stored
    ...
    expect(Payment::first()->amount)->toBe(2500);
});
```

**Role-setup pattern:** Use `$user->assignRole('admin')` or `$user->assignRole('user')` — Spatie permission. Roles must exist; use `Role::firstOrCreate` or run `DatabaseSeeder` with `RefreshDatabase`.

---

## Shared Patterns

### Authentication / Authorization Guard
**Source:** `routes/web.php` lines 14–17 (middleware group)
**Apply to:** `PaymentController` (route-level guard — no per-method check needed)
```php
Route::middleware(['auth', 'verified'])->group(function () {
    // payment routes go here — NOT under admin prefix/role:admin
});
```

### Role-Scoped Query
**Source:** `app/Http/Controllers/Admin/StripeAccountController.php` lines 19–29 (index pattern)
**Apply to:** `PaymentController::index()` — admin sees all, user sees own
```php
$user  = auth()->user();
$query = Payment::with([...])->orderByDesc('created_at');
if (! $user->hasRole('admin')) {
    $query->where('user_id', $user->id);
}
```

### Inertia Response + Data Map
**Source:** `app/Http/Controllers/Admin/BrandController.php` lines 17–31
**Apply to:** All three controller methods (index, create, show)
```php
return Inertia::render('payments/Index', [
    'key' => Model::query()->get()->map(fn ($m) => [...]),
]);
```

### Flash Redirect (store → show)
**Source:** `app/Http/Controllers/Admin/BrandController.php` lines 49–50 (redirect pattern)
**Apply to:** `PaymentController::store()` — redirect to show with route model binding
```php
return redirect()->route('payments.show', $payment);
// No ->with('success') flash needed — show page IS the success confirmation
```

### `useForm` + Card Form Submit
**Source:** `resources/js/pages/admin/users/Create.vue` lines 28–37, 115–119
**Apply to:** `payments/Create.vue`
```typescript
const form = useForm({ ... });
function submit() { form.post('/payments'); }
// Template: <form @submit.prevent="submit"> + <Button :disabled="form.processing">
```

### Table Row Structure
**Source:** `resources/js/pages/admin/brands/Index.vue` lines 72–130
**Apply to:** `payments/Index.vue`
```vue
<tr v-for="item in items" :key="item.id"
    class="border-b border-border last:border-0 hover:bg-muted/50 transition-colors">
    <td class="px-4 py-3">...</td>
</tr>
```

### Badge Status Display
**Source:** `resources/js/pages/admin/stripe-accounts/Index.vue` lines 107–112
**Apply to:** `payments/Index.vue` and `payments/Show.vue` status column
```vue
import { Badge } from '@/components/ui/badge';
// Usage:
<Badge variant="outline">{{ payment.status }}</Badge>
// For color variants: add computed class binding based on status value
```

### `Schema::table()` Migration Pattern
**Source:** `database/migrations/2026_05_05_052551_drop_brand_id_from_stripe_accounts_table.php` lines 9–23
**Apply to:** Phase 4 payments migration
```php
return new class extends Migration {
    public function up(): void {
        Schema::table('payments', function (Blueprint $table) { ... });
    }
    public function down(): void {
        Schema::table('payments', function (Blueprint $table) { ... });
    }
};
```

---

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `resources/js/pages/payments/Show.vue` | component | request-response | No show/detail page pattern exists yet in the codebase — closest is Index pages with Badge. Patterns derived from Create.vue (Card, breadcrumbs, back button) + clipboard API (browser native). |

---

## Critical Warnings for Planner

1. **Wave 0 must update factory and model in same commit as migration.** The `description` column disappears; any test using `Payment::factory()->create()` will break until the factory is updated.

2. **`brand_id` in DatabaseSeeder StripeAccount call must be removed.** Line 33 of the current seeder passes `brand_id` to `StripeAccount::firstOrCreate()` — that column was dropped in Phase 3. Fix in same wave.

3. **Textarea component not installed.** `resources/js/components/ui/textarea/` does not exist. Wave 0 must run `npx shadcn-vue@latest add textarea` before Create.vue imports it, or use a plain `<textarea>` with Tailwind classes.

4. **PaymentController namespace is `App\Http\Controllers`, not `App\Http\Controllers\Admin`.** D-03 explicitly places payments outside the admin prefix.

5. **Route placeholder conflict.** Remove `Route::inertia('/payments', 'placeholders/ComingSoon')->name('payments.index')` (web.php line 16) entirely before adding the resource route — duplicate `payments.index` name causes a routing error.

---

## Metadata

**Analog search scope:** `app/Http/Controllers/`, `app/Http/Requests/`, `app/Models/`, `resources/js/pages/`, `database/migrations/`, `database/factories/`, `database/seeders/`, `routes/`, `tests/Feature/`
**Files scanned:** 15 source files read directly
**Pattern extraction date:** 2026-05-05
