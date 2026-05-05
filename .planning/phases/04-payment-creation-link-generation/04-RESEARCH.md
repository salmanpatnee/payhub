# Phase 4: Payment Creation + Link Generation — Research

**Researched:** 2026-05-05
**Domain:** Laravel resource controller + Inertia/Vue form + UUID link generation
**Confidence:** HIGH

---

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01:** Brand and Stripe account selected via independent dropdowns — no cascade filtering.
- **D-02:** Backend validation: brand must exist (FK check), Stripe account must exist and `is_active = true`. No cross-referencing between brand and Stripe account.
- **D-03:** Payment routes at `/payments` under `auth + verified` (NOT `/admin/`). Both Admin and User can create payments.
  - `GET  /payments`          → index (role-scoped list)
  - `GET  /payments/create`   → create form
  - `POST /payments`          → store
  - `GET  /payments/{uuid}`   → show page
- **D-04:** `/payments` index: Admin sees all (with brand/stripeAccount/user eager-loaded); User sees only `where('user_id', auth()->id())`.
- **D-05:** Form accepts decimal dollar/pound input. Server-side: `(int) round($amount * 100)`. Validation: `amount > 0` in dollars. No server-side maximum.
- **D-06:** After store, redirect to `/payments/{uuid}`. Show page: shareable link in copy box + payment summary + "Back to payments" link.
- **D-07:** Index table — columns: amount, currency, brand name, Stripe account name, status badge, created at, client email, copy-link action. No filtering in Phase 4.
- **D-08:** `client_name` (string, required).
- **D-09:** `client_email` (string, required).
- **D-10:** `service` (string, nullable) — free text, replaces `description`.
- **D-11:** `package` (enum, nullable) — Basic/Standard/Premium/Platinum/Diamond; stored lowercase.
- **D-12:** `note` (text, nullable) — internal only.
- **D-13:** `description` column dropped; `service` + `package` + `note` replace it. Migration adds client_name, service, package, note; removes description.
- **D-14:** Live fee breakdown panel on create form. Client-side only (Vue `computed`). USD: 2.9% + $0.30. GBP: 1.5% + £0.20. Three rows: Charge amount / Stripe fee / You receive. Only shown when amount > 0.
- **SEC-02 enforced:** Amount read exclusively from server-side `Payment` record. No client-supplied amount accepted at any stage.

### Claude's Discretion

- Exact shadcn-vue components in payment form and table (follow brands/Create.vue and brands/Index.vue patterns)
- Route naming conventions (suggest `payments.index`, `payments.create`, `payments.store`, `payments.show`)
- Status badge colors (pending = yellow/warning, completed = green/success, failed = red/destructive)
- Copy-to-clipboard implementation (native `navigator.clipboard.writeText` or a small utility)
- Amount display format (server-side in Inertia prop vs client-side `Intl.NumberFormat`)
- Visual layout of fee breakdown panel

### Deferred Ideas (OUT OF SCOPE)

- Payment cancellation/void (v2)
- Editing a payment (payments are write-once in Phase 4)
- Status filter on /payments index (Phase 7)
- PaymentIntent creation (Phase 5)
- Link expiry (v2 — `expires_at` set to null on creation)
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| PAY-01 | Admin or User can create a payment with amount, currency, brand, client name, client email, service, package, and optional note | PaymentController::store() + StorePaymentRequest + Payment model migration |
| PAY-02 | Admin or User can select a specific active Stripe account for the payment | StripeAccount::where('is_active', true)->get() passed as Inertia prop |
| PAY-03 | Client name and email captured when creating a payment | `client_name` added to payments table; already has `client_email` |
| PAY-04 | System generates a unique shareable UUID payment link on creation | UUID auto-generated in Payment::boot(); link is `/pay/{uuid}` |
| PAY-05 | Payment amount is stored server-side and cannot be modified via client request | Amount sourced from Payment record in DB; never from request body |
| PAY-06 | Currency selection restricted to USD and GBP only | Enum validation in StorePaymentRequest + Select with two options |
| PAY-07 | Payment links never expire | `expires_at` = null on creation |
| PAY-08 | Creation form shows live Stripe fee breakdown computed client-side | Vue `computed` from amount + currency inputs |
| SEC-02 | Payment amount read exclusively from server-side Payment record | Confirmed: amount never accepted from client request; stored at creation, read at payment time |
</phase_requirements>

---

## Summary

Phase 4 is a standard Laravel CRUD resource phase with no Stripe API calls. The work splits cleanly into four areas: (1) a database migration to evolve the payments table schema, (2) a resource controller with role-scoped queries, (3) a StorePaymentRequest with amount conversion, and (4) three Vue/Inertia pages (Create, Index, Show).

The most critical correctness concern is SEC-02: the amount submitted in the create form must only be used to persist the Payment record — it must never be re-read from the request for any financial purpose. In Phase 4 there is no Stripe call, so the risk is limited to schema integrity, but the architecture must make this rule structurally impossible to violate in Phase 5.

The UUID-based shareable link pattern is already in place: `Payment::boot()` auto-generates a UUID on creation, and `getRouteKeyName()` returns `'uuid'`. Route model binding on `/payments/{payment}` will resolve by UUID automatically with no additional wiring.

**Primary recommendation:** Follow existing Phase 3 controller/request/Vue patterns exactly. The only genuinely new element is the client-side fee breakdown (a Vue `computed`) and the Show page with clipboard copy. All other pieces are straightforward applications of established project patterns.

---

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Payment creation (form + submit) | Frontend (Vue/Inertia) | API (Laravel controller) | Form lives in Vue; persistence in PHP |
| Amount conversion (decimal → cents) | API (FormRequest) | — | SEC-02: amount conversion must be server-side |
| Role-scoped payment list | API (Controller) | Frontend (display) | Scoping is a data-access concern; display in Vue |
| UUID generation | API (Model boot) | — | Already implemented in Payment::boot() |
| Fee breakdown calculation | Frontend (Vue computed) | — | D-14: client-side only, no server round-trip |
| Link construction (`/pay/{uuid}`) | Frontend (Show page) | — | `window.location.origin + '/pay/' + uuid` |
| Clipboard copy | Frontend (Show page) | — | `navigator.clipboard.writeText()` |
| is_active filter for Stripe accounts | API (Controller) | — | Filter applied in controller before passing to Inertia |

---

## Standard Stack

All packages are already installed — Phase 4 introduces no new dependencies. [VERIFIED: composer.json + package.json read in session]

### Core (Already Installed)

| Package | Version | Purpose |
|---------|---------|---------|
| laravel/framework | 13.7.0 | Resource controller, FormRequest, route model binding |
| inertiajs/inertia-laravel | ^3.0 | Inertia::render() for all three pages |
| @inertiajs/vue3 | ^3.0.0 | useForm(), Link, Head |
| spatie/laravel-permission | ^7.4 | `auth()->user()->hasRole('admin')` for index scoping |
| pestphp/pest | ^4.6 | Test suite |

### No New Packages Required

Phase 4 has no new package requirements. The Textarea component is not yet installed in shadcn-vue (no `/resources/js/components/ui/textarea/` directory exists). [VERIFIED: filesystem check] The note field requires either: (a) install shadcn-vue Textarea component via `npx shadcn-vue@latest add textarea`, or (b) use a plain `<textarea>` styled with Tailwind. Adding the shadcn-vue Textarea is the cleaner approach and follows the project's shadcn-vue-first pattern.

---

## Architecture Patterns

### System Architecture Diagram

```
Browser (Create.vue)
  │  useForm({ brand_id, stripe_account_id, currency, amount, ... })
  │  computed feeBreakdown ← watches amount + currency
  ▼
POST /payments  ──────────────────────────────────────────────►  StorePaymentRequest
                                                                    authorize(): auth check
                                                                    rules(): validate all fields
                                                                    amount → (int) round($x * 100)
                                                                    ▼
                                                                  PaymentController::store()
                                                                    user_id = auth()->id()
                                                                    expires_at = null
                                                                    Payment::create($validated)
                                                                    ▼
                                                                  redirect → /payments/{uuid}

GET /payments/{uuid}  ────────────────────────────────────────►  PaymentController::show()
                                                                    Inertia::render('payments/Show', [...])
                                                                    ▼
Browser (Show.vue)
  shareable link = window.location.origin + '/pay/' + uuid
  navigator.clipboard.writeText(shareableLink)

GET /payments  ────────────────────────────────────────────────►  PaymentController::index()
                                                                    if admin → Payment::with([...]) ->get()
                                                                    if user  → where('user_id', id)->get()
                                                                    ▼
Browser (Index.vue)
  table: amount | currency | brand | stripe account | status | created | email | copy link
```

### Recommended File Structure

```
app/
├── Http/
│   ├── Controllers/
│   │   └── PaymentController.php           # index, create, store, show
│   └── Requests/
│       └── StorePaymentRequest.php         # validation + amount conversion
├── Models/
│   └── Payment.php                         # add fillable: client_name, service, package, note
database/
├── migrations/
│   └── 2026_05_05_XXXXXX_update_payments_table_add_phase4_columns.php
└── seeders/
    └── DatabaseSeeder.php                  # add Payment seed records
resources/js/pages/
└── payments/
    ├── Create.vue                          # payment creation form + fee breakdown
    ├── Index.vue                           # role-scoped payment list table
    └── Show.vue                            # shareable link + payment summary
routes/
└── web.php                                 # replace ComingSoon placeholder
tests/Feature/
└── PaymentCreationTest.php                 # PAY-01 through PAY-08, SEC-02
```

---

## Pattern 1: Migration — Schema Evolution

The payments table currently has `description` (string, nullable). Phase 4 adds four columns and removes one. [VERIFIED: read 2026_05_03_000003_create_payments_table.php]

**Strategy:** One new migration file (not modifying the original). The migration adds `client_name`, `service`, `package` (enum), `note`; drops `description`. Using `migrate:fresh --seed` during development means the drop does not need a down/up reversibility guard for local dev.

```php
// Source: verified from existing Phase 3 migration pattern
public function up(): void
{
    Schema::table('payments', function (Blueprint $table) {
        $table->string('client_name')->after('client_email');
        $table->string('service')->nullable()->after('client_name');
        $table->enum('package', ['basic', 'standard', 'premium', 'platinum', 'diamond'])
              ->nullable()->after('service');
        $table->text('note')->nullable()->after('package');
        $table->dropColumn('description');
    });
}

public function down(): void
{
    Schema::table('payments', function (Blueprint $table) {
        $table->dropColumn(['client_name', 'service', 'package', 'note']);
        $table->string('description')->nullable()->after('currency');
    });
}
```

**Enum casing note:** Store as lowercase (`basic`, `standard`, etc.). Display as title-case in Vue. Do not use PHP enum classes — Laravel's `enum()` column type for MySQL/SQLite is sufficient.

**`migrate:fresh --seed` implication:** Because Phase 4 uses `migrate:fresh --seed` to reset dev state, the PaymentFactory also needs updating to remove `description` and add the four new columns. Existing tests that create Payment records via factory will break if the factory is not updated in Wave 0.

---

## Pattern 2: Payment Model — Fillable Update

```php
// Source: verified from existing app/Models/Payment.php
protected $fillable = [
    'uuid', 'brand_id', 'stripe_account_id', 'user_id',
    'amount', 'currency', 'status',
    'client_email', 'client_name',           // client_name added
    'service', 'package', 'note',            // new Phase 4 fields
    'stripe_payment_intent_id', 'expires_at', 'paid_at',
    // 'description' removed
];
```

The `casts()` method needs no changes for the new columns — `package` is stored as a string (enum in DB, no PHP cast needed).

---

## Pattern 3: StorePaymentRequest

```php
// Source: verified from StoreUserRequest + BrandManagementTest patterns in codebase
class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Both Admin and User can create payments (D-03)
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'brand_id'          => ['required', 'integer', 'exists:brands,id'],
            'stripe_account_id' => ['required', 'integer', Rule::exists('stripe_accounts', 'id')
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
        // SEC-02: Convert decimal input to integer cents server-side
        // Amount from client is only used for this one-time conversion; DB record is the authority.
        $this->merge(['amount' => (int) round($this->amount * 100)]);
    }
}
```

**Key detail on `Rule::exists` with `where()`:** The `where('is_active', true)` clause on `stripe_account_id` ensures deactivated accounts cannot be submitted. [VERIFIED: Laravel docs pattern; cross-referenced with StripeAccountController::index() which filters `is_active` for dropdowns] This must match the filter applied on the Vue side — passing only `is_active = true` accounts in the Inertia prop.

**Amount conversion placement:** Using `passedValidation()` means the `amount` field is validated as a numeric decimal first (`min:0.01`), then converted. The controller receives `$request->validated()` and gets integer cents directly. This keeps the conversion in the FormRequest, not the controller.

---

## Pattern 4: PaymentController

```php
// Source: verified from BrandController.php pattern in codebase
class PaymentController extends Controller
{
    public function index(): Response
    {
        $user = auth()->user();

        $query = Payment::with(['brand', 'stripeAccount', 'user'])
                        ->orderByDesc('created_at');

        if (! $user->hasRole('admin')) {
            $query->where('user_id', $user->id);
        }

        return Inertia::render('payments/Index', [
            'payments' => $query->get()->map(fn (Payment $p) => [
                'id'           => $p->id,
                'uuid'         => $p->uuid,
                'amount'       => $p->amount,               // integer cents
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

    public function create(): Response
    {
        return Inertia::render('payments/Create', [
            'brands'         => Brand::orderBy('name')->get(['id', 'name']),
            'stripeAccounts' => StripeAccount::where('is_active', true)
                                             ->orderBy('account_name')
                                             ->get(['id', 'account_name']),
        ]);
    }

    public function store(StorePaymentRequest $request): RedirectResponse
    {
        $payment = Payment::create([
            ...$request->validated(),
            'user_id'    => auth()->id(),
            'status'     => 'pending',
            'expires_at' => null,       // PAY-07: links never expire
        ]);

        return redirect()->route('payments.show', $payment);
        // Route model binding resolves by uuid (getRouteKeyName = 'uuid')
    }

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
}
```

**Route model binding with UUID:** Because `Payment::getRouteKeyName()` returns `'uuid'`, `redirect()->route('payments.show', $payment)` automatically resolves the route key as the UUID. No manual UUID extraction needed. [VERIFIED: app/Models/Payment.php line 31]

**Namespace note:** Controller goes in `App\Http\Controllers\` (not `Admin\`) — payments are not admin-only. [VERIFIED: D-03]

---

## Pattern 5: Route Registration

```php
// Replace existing placeholder in routes/web.php (lines 16-17)
// Before:
//   Route::inertia('/payments', 'placeholders/ComingSoon')->name('payments.index');
// After:
Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');

    Route::resource('payments', PaymentController::class)
         ->only(['index', 'create', 'store', 'show']);
});
```

Named routes generated: `payments.index`, `payments.create`, `payments.store`, `payments.show`. [VERIFIED: Laravel resource routing convention]

**Important:** The `only(['index', 'create', 'store', 'show'])` constraint enforces immutability — no `edit`, `update`, or `destroy` routes exist for payments in Phase 4.

---

## Pattern 6: Vue Create Form (payments/Create.vue)

The form follows the exact pattern of `resources/js/pages/admin/users/Create.vue` (Card + useForm + Select + InputError + breadcrumbs). [VERIFIED: read Create.vue in session]

```vue
<!-- Source: verified pattern from admin/users/Create.vue + admin/brands/Create.vue -->
<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import InputError from '@/components/InputError.vue';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import Textarea from '@/components/ui/textarea/Textarea.vue'; // requires install

type BrandOption = { id: number; name: string };
type AccountOption = { id: number; account_name: string };

const props = defineProps<{ brands: BrandOption[]; stripeAccounts: AccountOption[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Payments', href: '/payments' },
            { title: 'New payment', href: '/payments/create' },
        ],
    },
});

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

// D-14: Fee breakdown — client-side only, no server round-trip
const feeBreakdown = computed(() => {
    const amt = parseFloat(form.amount as string);
    if (!amt || amt <= 0) return null;

    const fee = form.currency === 'gbp'
        ? amt * 0.015 + 0.20
        : amt * 0.029 + 0.30;

    const receive = amt - fee;
    const locale  = form.currency === 'gbp' ? 'en-GB' : 'en-US';
    const curr    = form.currency.toUpperCase();
    const fmt     = (n: number) => new Intl.NumberFormat(locale, {
        style: 'currency', currency: curr,
    }).format(n);

    return { charge: fmt(amt), fee: fmt(fee), receive: fmt(receive) };
});

function submit() {
    form.post('/payments');
}
</script>
```

**Select binding pattern:** `<Select v-model="form.brand_id">` — the `v-model` on a shadcn-vue Select binds directly to the form field. [VERIFIED: admin/users/Create.vue line 101]

**Amount field:** Use `<Input type="number" min="0.01" step="0.01" />` — the `v-model` gives a string; `parseFloat()` converts it for the fee computation.

**Textarea:** No Textarea component is currently installed. [VERIFIED: filesystem check — no `resources/js/components/ui/textarea/`]. Wave 0 must either install it via `npx shadcn-vue@latest add textarea` or stub it as a plain styled textarea.

---

## Pattern 7: Vue Show Page (payments/Show.vue)

```vue
<!-- Source: pattern derived from established project conventions -->
<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';

type PaymentDetail = {
    uuid: string;
    amount: number;       // integer cents
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

// CONTEXT.md specifics: /pay/{uuid} is the client-facing route
const shareableLink = `${window.location.origin}/pay/${props.payment.uuid}`;

const copied = ref(false);

async function copyLink() {
    await navigator.clipboard.writeText(shareableLink);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
}

// Format integer cents to display string
function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat(currency === 'gbp' ? 'en-GB' : 'en-US', {
        style: 'currency',
        currency: currency.toUpperCase(),
    }).format(cents / 100);
}
</script>
```

**Link distinction:** The Show page URL is `/payments/{uuid}` (admin view). The shareable link displayed in the copy box is `/pay/{uuid}` (client-facing, Phase 5). These are different routes — the Show page constructs the client link but does not serve it. [VERIFIED: D-06 + routes/web.php Phase 5 stub]

---

## Pattern 8: Index Table (payments/Index.vue)

Follows `resources/js/pages/admin/brands/Index.vue` pattern exactly. [VERIFIED: read Index.vue] No ConfirmDeleteDialog needed — payments are immutable. The "copy link" action is an inline button that calls `navigator.clipboard.writeText(window.location.origin + '/pay/' + payment.uuid)`.

```typescript
// TypeScript type for payment row (Index.vue)
type PaymentRow = {
    id: number;
    uuid: string;
    amount: number;       // cents — format in template
    currency: string;
    brand_name: string;
    account_name: string;
    status: string;       // 'pending' | 'completed' | 'failed' | 'cancelled'
    created_at: string;
    client_email: string;
    client_name: string;
};
```

**Amount formatting in template:** Use `Intl.NumberFormat` inline computed or a helper function — do not pass pre-formatted strings from the controller (keeps the DB value as integer for future use in Phase 5/6).

---

## Pattern 9: DatabaseSeeder Update

The existing `DatabaseSeeder.php` has no Payment records. [VERIFIED: read DatabaseSeeder.php] Phase 4 must add seeded Payment records so the index table is non-empty for manual testing.

```php
// Source: verified pattern from DatabaseSeeder.php
// Add after the existing StripeAccount seed:
$user = User::firstOrCreate(
    ['email' => 'user@payhub.test'],
    ['name' => 'PayHub User', 'password' => Hash::make('password')]
);
$user->syncRoles(['user']);

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

**Note on StripeAccount fillable:** `StripeAccount` model does not have `brand_id` in fillable (removed in Phase 3 migration). The seeder still passes `brand_id` — this must be removed. [VERIFIED: read StripeAccountFactory.php — `brand_id` is not in the factory definition, confirming it was removed in Phase 3]

---

## Pattern 10: PaymentFactory Update

The existing `PaymentFactory.php` references `description` which will be dropped. [VERIFIED: read PaymentFactory.php line 22]

```php
// Replace 'description' with Phase 4 fields
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

---

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| UUID generation | Custom UUID logic | `Str::uuid()` in `Payment::boot()` (already implemented) | Already working, uses RFC 4122 |
| Route model binding by UUID | Manual `Payment::where('uuid', $uuid)` | `getRouteKeyName()` returning `'uuid'` (already implemented) | Laravel does this automatically — already wired |
| Amount cents conversion | Complex float handling | `(int) round($amount * 100)` in FormRequest | PHP `round()` handles .005 correctly; bcmath not needed for this |
| Currency formatting | Custom sprintf | `Intl.NumberFormat` (browser native) | Handles locale, symbol, decimal separator correctly for USD/GBP |
| Clipboard copy | Flash message or third-party | `navigator.clipboard.writeText()` | Available in all modern browsers; no library needed |
| Stripe account active filter | Complex query | `Rule::exists('stripe_accounts', 'id')->where('is_active', true)` | Single validation rule handles both existence and active check |

---

## Common Pitfalls

### Pitfall 1: Amount Re-Read from Request in Phase 5
**What goes wrong:** Controller stores `$request->amount * 100` in the DB, but a Phase 5 developer then reads `$request->amount` again when creating the PaymentIntent.
**Why it happens:** It feels natural to use the request data that's already "there."
**How to avoid:** The PaymentController::store() must only use `$request->validated()` (which already has integer cents after `passedValidation()`). The PaymentIntent in Phase 5 must load `$payment->amount` from the DB record, never from any request variable.
**Warning sign:** Any `$request->input('amount')` or `$request->amount` reference outside of FormRequest validation.

### Pitfall 2: Stripe Account Active Filter Mismatch
**What goes wrong:** Controller passes all StripeAccounts to the Create form but FormRequest only validates `is_active = true` accounts. A deactivated account appears in the dropdown, user selects it, gets a validation error with no clear message.
**Why it happens:** The dropdown query and the validation rule are written independently.
**How to avoid:** Both must use the same `is_active = true` filter. In the controller: `StripeAccount::where('is_active', true)->get()`. In the FormRequest: `Rule::exists('stripe_accounts', 'id')->where('is_active', true)`.

### Pitfall 3: `description` in Factory Causes Test Failures After Migration
**What goes wrong:** `migrate:fresh --seed` runs fine, but Pest tests using `Payment::factory()->create()` fail with a DB column error because the factory still references `description`.
**Why it happens:** The factory is not updated in the same wave as the migration.
**How to avoid:** Update `PaymentFactory.php` in Wave 0 (same wave as the migration and schema stubs). Tests break immediately if factory uses a dropped column.

### Pitfall 4: Route Naming Conflict
**What goes wrong:** The placeholder route `Route::inertia('/payments', ...)` uses `.name('payments.index')`. If the resource route also tries to register `payments.index`, there will be a route name collision.
**Why it happens:** The placeholder route in `routes/web.php` explicitly names itself `payments.index` (line 16). [VERIFIED: routes/web.php]
**How to avoid:** Remove the entire `Route::inertia('/payments', ...)` line before adding the resource route. Do not try to add the resource route alongside the existing placeholder.

### Pitfall 5: `client_name` Not in `$fillable`
**What goes wrong:** `Payment::create($validated)` silently ignores `client_name` because it is not in `$fillable`. The column is null in the DB and the required validation seems to do nothing.
**Why it happens:** The `$fillable` array was not updated when the migration added `client_name`.
**How to avoid:** Update `$fillable` on `Payment` model in the same wave as the migration.

### Pitfall 6: Select v-model with Numeric ID vs String
**What goes wrong:** shadcn-vue Select passes string values via v-model. `brand_id` from the Select will be the string `"1"` not the integer `1`. The Laravel FormRequest validates `exists:brands,id` which works (SQL coerces), but strict type checks may fail in tests.
**Why it happens:** HTML/JS Select values are always strings.
**How to avoid:** Treat `brand_id` and `stripe_account_id` as strings in the Vue form, let Laravel handle the cast. The `integer` validation rule in FormRequest will coerce string `"1"` to integer `1` before the `exists` check. [ASSUMED — standard Laravel behavior, but verify if using strict integer type rules]

### Pitfall 7: Textarea Component Missing
**What goes wrong:** Import of `Textarea` from `@/components/ui/textarea` throws a module-not-found error at build time.
**Why it happens:** Textarea is not currently installed in the project's shadcn-vue setup. [VERIFIED: filesystem check]
**How to avoid:** Wave 0 task installs Textarea: `npx shadcn-vue@latest add textarea` before any Create.vue references it.

---

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes (routes under auth middleware) | Laravel Fortify session auth — already in place |
| V3 Session Management | yes | Laravel session — existing infrastructure |
| V4 Access Control | yes | `auth + verified` middleware on payment routes; role-scoped queries in controller |
| V5 Input Validation | yes | StorePaymentRequest with explicit rules for all fields |
| V6 Cryptography | no | No new encryption in Phase 4; StripeAccount credentials unchanged |

### SEC-02 Enforcement Architecture

SEC-02 ("amount read exclusively from server-side Payment record") is enforced structurally in this phase:

1. **At creation:** `StorePaymentRequest::passedValidation()` converts the client-submitted decimal to integer cents. The converted integer is stored in the DB.
2. **At payment time (Phase 5):** PaymentController::show() passes `$payment->amount` (from DB) to the Inertia prop. The Phase 5 controller that creates the PaymentIntent must read `$payment->amount` from the loaded Payment model — never from `$request->input('amount')`.
3. **Structural guarantee:** No route in Phase 4 accepts an `amount` parameter outside of the create/store flow. The show route is `GET /payments/{uuid}` — no POST body.

### Known Threat Patterns

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Amount tampering (client submits modified amount) | Tampering | Amount only accepted in StorePaymentRequest; never re-read at payment time |
| IDOR on payment show page | Information Disclosure | Phase 4 show page is admin/user-only (auth middleware); Phase 5 client page is different route |
| Mass assignment of user_id or status | Tampering | `user_id` and `status` not in FormRequest rules; set explicitly in controller |
| Inactive Stripe account selection | Tampering | `Rule::exists(...)->where('is_active', true)` in FormRequest |

---

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 4.6 (PHPUnit class-style used in existing Feature tests) |
| Config file | `tests/Pest.php` — `uses(Tests\TestCase::class)->in('Feature', 'Unit')` |
| Quick run command | `php artisan test --filter PaymentCreationTest` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| PAY-01 | Admin can create payment with all fields; record saved to DB | Feature | `php artisan test --filter PaymentCreationTest` | ❌ Wave 0 |
| PAY-01 | User (non-admin) can also create payment | Feature | `php artisan test --filter PaymentCreationTest` | ❌ Wave 0 |
| PAY-02 | Only active Stripe accounts accepted (inactive rejected) | Feature | `php artisan test --filter PaymentCreationTest` | ❌ Wave 0 |
| PAY-03 | client_name and client_email stored on Payment record | Feature | `php artisan test --filter PaymentCreationTest` | ❌ Wave 0 |
| PAY-04 | UUID is generated; show page accessible at `/payments/{uuid}` | Feature | `php artisan test --filter PaymentCreationTest` | ❌ Wave 0 |
| PAY-05 | Amount in DB equals `round(input * 100)` cents | Feature | `php artisan test --filter PaymentCreationTest` | ❌ Wave 0 |
| PAY-06 | Currency `usd` and `gbp` accepted; others rejected | Feature | `php artisan test --filter PaymentCreationTest` | ❌ Wave 0 |
| PAY-07 | `expires_at` is null after creation | Feature | `php artisan test --filter PaymentCreationTest` | ❌ Wave 0 |
| PAY-08 | Fee breakdown rendering | Manual only | — | N/A (client-side computed) |
| SEC-02 | Controller store() uses `$request->validated()`, not raw `$request->amount` | Feature | `php artisan test --filter PaymentCreationTest` | ❌ Wave 0 |

**PAY-08 note:** The fee breakdown is a Vue `computed` — no server round-trip, no backend state. There is no automated backend test for it. Manual verification: load the create form, enter amount + currency, confirm three-row breakdown appears. A basic Pest test can verify the FormRequest rules but not the Vue computation.

**SEC-02 test pattern:** The test for SEC-02 POSTs with a manipulated `amount` value (e.g., `0.01`) and then reads the Payment record to confirm the amount stored matches `round(0.01 * 100) = 1` cent — not some other value. The test does not attempt to inject an already-converted cents value.

### Sampling Rate
- **Per task commit:** `php artisan test --filter PaymentCreationTest`
- **Per wave merge:** `php artisan test`
- **Phase gate:** Full suite green before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/PaymentCreationTest.php` — covers PAY-01 through PAY-07, SEC-02
- [ ] Update `tests/Feature/PaymentAmountIntegrityTest.php` — existing tests may fail if factory still has `description` column after migration

*(Existing test infrastructure covers the test runner setup — only PaymentCreationTest.php needs to be created)*

---

## Environment Availability

This phase is code/config-only (no external tools beyond existing dev server). No new external dependencies.

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP 8.3 | Laravel 13 | ✓ | 8.3.19 | — |
| Laravel 13 | All backend | ✓ | 13.7.0 | — |
| Node / npm | Vue build | ✓ | (project active) | — |
| shadcn-vue Textarea | Note field UI | ✗ (not installed) | — | Plain `<textarea>` with Tailwind classes |

**Missing dependencies with no fallback:** None that block execution.

**Missing dependencies with fallback:**
- shadcn-vue Textarea: Install via `npx shadcn-vue@latest add textarea` (recommended, 30 seconds) OR use a plain `<textarea class="...">` with Tailwind (acceptable fallback). Install is preferred for visual consistency.

---

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `passedValidation()` is the correct hook for amount conversion in Laravel FormRequest | Pattern 3: StorePaymentRequest | If wrong, use `prepareForValidation()` instead — same outcome, different hook name |
| A2 | `Rule::exists('stripe_accounts', 'id')->where('is_active', true)` works with boolean cast column | Pattern 3 | If wrong (SQLite might store 0/1 as integer), use `->where('is_active', 1)` instead |
| A3 | shadcn-vue Select v-model passes string values even for numeric IDs | Pitfall 6 | If wrong (passes int), no issue — validation still passes |

---

## Open Questions

1. **Textarea installation ownership**
   - What we know: No Textarea component exists at `resources/js/components/ui/textarea/`
   - What's unclear: Should the Wave 0 plan install it, or should Create.vue use a plain `<textarea>` until explicitly installed?
   - Recommendation: Wave 0 installs Textarea via shadcn-vue CLI. Takes 30 seconds and maintains visual consistency.

2. **Amount display on Index and Show pages**
   - What we know: D-07 column "amount (formatted)" — the format is left to Claude's discretion
   - What's unclear: Format in controller (pass pre-formatted `"$25.00"` as string) or format in Vue with `Intl.NumberFormat`?
   - Recommendation: Pass integer cents from controller; format in Vue with `Intl.NumberFormat`. Keeps the DB value accessible for future Phase 5/6 use without re-formatting.

3. **Seeder: `brand_id` removed from StripeAccount**
   - What we know: Phase 3 migration dropped `brand_id` from `stripe_accounts` table. The existing seeder still passes `brand_id` in `StripeAccount::firstOrCreate()`.
   - What's unclear: Does `firstOrCreate` with an extra key cause a DB error or silently ignore it?
   - Recommendation: Remove `brand_id` from the seeder's StripeAccount creation in Wave 0. [ASSUMED — should be verified by running the seeder after the migration]

---

## Sources

### Primary (HIGH confidence)
- `app/Models/Payment.php` — UUID boot, getRouteKeyName, fillable, casts [VERIFIED: read in session]
- `app/Models/StripeAccount.php` — is_active boolean cast, fillable (no brand_id), encrypted casts [VERIFIED: read in session]
- `app/Models/Brand.php` — fillable, payments() HasMany [VERIFIED: read in session]
- `app/Http/Controllers/Admin/BrandController.php` — resource controller pattern, Inertia::render, redirect with flash [VERIFIED: read in session]
- `app/Http/Controllers/Admin/StripeAccountController.php` — StripeClient pattern, index/create/store [VERIFIED: read in session]
- `app/Http/Requests/Admin/StoreUserRequest.php` — FormRequest authorize + rules pattern [VERIFIED: read in session]
- `resources/js/pages/admin/users/Create.vue` — Select v-model + useForm + Card + InputError pattern [VERIFIED: read in session]
- `resources/js/pages/admin/brands/Create.vue` — computed + Intl + breadcrumbs + form.post pattern [VERIFIED: read in session]
- `resources/js/pages/admin/brands/Index.vue` — table pattern, type definitions, action buttons [VERIFIED: read in session]
- `resources/js/pages/admin/stripe-accounts/Index.vue` — status badge pattern (Badge + inline Active) [VERIFIED: read in session]
- `database/migrations/2026_05_03_000003_create_payments_table.php` — current schema [VERIFIED: read in session]
- `database/seeders/DatabaseSeeder.php` — seeder pattern [VERIFIED: read in session]
- `database/factories/PaymentFactory.php` — factory definition (has description, needs update) [VERIFIED: read in session]
- `routes/web.php` — existing placeholder route names and location [VERIFIED: read in session]
- `composer.json` + `package.json` — installed package versions [VERIFIED: read in session]
- `.planning/phases/04-payment-creation-link-generation/04-CONTEXT.md` — locked decisions [VERIFIED: read in session]

### Secondary (MEDIUM confidence)
- `.planning/research/SUMMARY.md` — architecture patterns, pitfall inventory [VERIFIED: read in session]
- `.planning/phases/03-brand-stripe-account-management/03-CONTEXT.md` — brand_id removed from StripeAccounts, webhook_secret deferred [VERIFIED: read in session]

---

## Metadata

**Confidence breakdown:**
- Migration strategy: HIGH — existing schema and migration files read directly
- Controller/FormRequest patterns: HIGH — read actual Phase 3 controller code
- Vue form patterns: HIGH — read actual Create.vue/Index.vue files
- Fee breakdown formula: HIGH — confirmed in CONTEXT.md D-14 (exact rates specified)
- Test patterns: HIGH — read BrandManagementTest.php and StripeAccountManagementTest.php
- Textarea availability: HIGH — filesystem verified, component not present

**Research date:** 2026-05-05
**Valid until:** 2026-06-05 (stable stack, no moving parts)
