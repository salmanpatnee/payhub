# Phase 5: Client Payment Page - Pattern Map

**Mapped:** 2026-05-09
**Files analyzed:** 9 (7 new, 2 modified)
**Analogs found:** 9 / 9

---

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `app/Http/Controllers/ClientPaymentController.php` | controller | request-response | `app/Http/Controllers/PaymentController.php` | role-match |
| `resources/js/layouts/PaymentLayout.vue` | layout | — | `resources/js/layouts/auth/AuthCardLayout.vue` | role-match |
| `resources/js/pages/ClientPayment/Pay.vue` | page/component | request-response | `resources/js/pages/admin/brands/Create.vue` | partial |
| `resources/js/pages/ClientPayment/Success.vue` | page/component | request-response | `resources/js/pages/payments/Show.vue` | partial |
| `resources/js/pages/ClientPayment/Failed.vue` | page/component | request-response | `resources/js/pages/payments/Show.vue` | partial |
| `resources/js/pages/ClientPayment/Unavailable.vue` | page/component | request-response | `resources/js/pages/payments/Show.vue` | partial |
| `resources/js/app.ts` | config | — | `resources/js/app.ts` (self — extend switch) | exact |
| `routes/web.php` | route | — | `routes/web.php` (self — replace stub at line 51) | exact |
| `tests/Feature/ClientPaymentTest.php` | test | — | `tests/Feature/PaymentCreationTest.php` | role-match |

---

## Pattern Assignments

### `app/Http/Controllers/ClientPaymentController.php` (controller, request-response)

**Analog:** `app/Http/Controllers/PaymentController.php` (Inertia prop shaping, eager-load pattern) + `app/Http/Controllers/Admin/StripeAccountController.php` (StripeClient instantiation pattern)

**Namespace and imports** (from `PaymentController.php` lines 1–14):
```php
<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\StripeClient;
```

**Eager-load pattern** (from `PaymentController.php` line 68):
```php
$payment->loadMissing(['brand', 'stripeAccount']);
```

**Inertia prop-shaping pattern** (from `PaymentController.php` lines 70–88):
```php
return Inertia::render('payments/Show', [
    'payment' => [
        'uuid'         => $payment->uuid,
        'amount'       => $payment->amount,
        'currency'     => $payment->currency,
        'status'       => $payment->status,
        // ... shaped array, never the full Eloquent model
    ],
]);
```

**StripeClient instantiation pattern** (from `StripeAccountController.php` lines 148–150):
```php
// NEVER call Stripe::setApiKey() globally — always new StripeClient($secretKey).
$stripe = new StripeClient($secretKey);
$stripe->balance->retrieve();
```

**Redirect response pattern** (from `PaymentController.php` line 63):
```php
return redirect()->route('payments.show', $payment);
```

**Core controller shape for `ClientPaymentController`:**
```php
// Guard before PI creation (D-03)
if ($payment->status !== 'pending') {
    return Inertia::render('ClientPayment/Unavailable', [
        'status' => $payment->status,
        'brand'  => $this->brandProps($payment->brand),
    ]);
}

// Per-account StripeClient — never global setApiKey()
$stripe = new StripeClient($payment->stripeAccount->secret_key);
$pi = $stripe->paymentIntents->create([
    'amount'                    => $payment->amount,   // integer cents from DB
    'currency'                  => $payment->currency,
    'automatic_payment_methods' => ['enabled' => true],
]);

// Store PI ID for Phase 6 webhook lookup (D-02)
$payment->update(['stripe_payment_intent_id' => $pi->id]);

return Inertia::render('ClientPayment/Pay', [
    'payment'       => $this->paymentProps($payment),
    'brand'         => $this->brandProps($payment->brand),
    'stripeAccount' => ['publishable_key' => $payment->stripeAccount->publishable_key],
    'clientSecret'  => $pi->client_secret,  // SEC-04: prop only, never URL
]);
```

**Private helper methods** (follow PaymentController shaping pattern):
```php
private function brandProps(Brand $brand): array
{
    return [
        'name'            => $brand->name,
        'slug'            => $brand->slug,
        'logo_url'        => $brand->logo_path ? '/storage/' . $brand->logo_path : null,
        'primary_color'   => $brand->primary_color,
        'secondary_color' => $brand->secondary_color,
    ];
}

private function paymentProps(Payment $payment): array
{
    return [
        'uuid'     => $payment->uuid,
        'amount'   => $payment->amount,
        'currency' => $payment->currency,
        'service'  => $payment->service,
        'package'  => $payment->package,
        // client_name, client_email, note NOT included (D-07)
    ];
}
```

**`success()` method pattern** (from CONTEXT.md D-04):
```php
public function success(Payment $payment): Response|RedirectResponse
{
    if (request('redirect_status') !== 'succeeded') {
        return redirect()->route('pay.failed', $payment->uuid);
    }

    $payment->loadMissing('brand');

    return Inertia::render('ClientPayment/Success', [
        'payment' => [
            'amount'   => $payment->amount,
            'currency' => $payment->currency,
            'service'  => $payment->service,
        ],
        'brand' => $this->brandProps($payment->brand),
    ]);
}
```

**`failed()` method pattern:**
```php
public function failed(Payment $payment): Response
{
    $payment->loadMissing('brand');

    return Inertia::render('ClientPayment/Failed', [
        'payment' => [
            'uuid'     => $payment->uuid,
            'amount'   => $payment->amount,
            'currency' => $payment->currency,
        ],
        'brand' => $this->brandProps($payment->brand),
    ]);
}
```

**Route model binding note:** `Payment::getRouteKeyName()` returns `'uuid'` (confirmed `app/Models/Payment.php` line 33). The route uses `{uuid}` wildcard and type-hints `Payment $payment` — Laravel binds automatically. No manual `Payment::where('uuid', ...)` needed.

---

### `resources/js/layouts/PaymentLayout.vue` (layout, standalone)

**Analog:** `resources/js/layouts/auth/AuthCardLayout.vue` (centered card layout, slot pattern, `min-h-svh`, `max-w-md`)

**Layout structure** (from `AuthCardLayout.vue` lines 1–50):
```vue
<script setup lang="ts">
// AuthCardLayout accepts props and passes to child — copy this prop-accept pattern
defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div class="flex min-h-svh flex-col items-center justify-center gap-6 bg-muted p-6 md:p-10">
        <div class="flex w-full max-w-md flex-col gap-6">
            <!-- logo slot area -->
            <!-- Card wraps <slot /> -->
        </div>
    </div>
</template>
```

**PaymentLayout adaptation** (brand props replace static logo, CSS vars injected on root):
```vue
<script setup lang="ts">
import { LockIcon } from 'lucide-vue-next'

defineProps<{
    brand: {
        name: string
        slug: string
        logo_url: string | null
        primary_color: string
        secondary_color: string
    }
}>()
</script>

<template>
    <div
        :data-brand="brand.slug"
        :style="`--brand-primary: ${brand.primary_color}; --brand-secondary: ${brand.secondary_color}`"
        class="min-h-svh flex flex-col items-center justify-center bg-muted/40 px-4 py-10"
    >
        <div class="mb-8 flex flex-col items-center gap-3">
            <img
                v-if="brand.logo_url"
                :src="brand.logo_url"
                :alt="brand.name"
                class="h-10 max-w-[180px] w-auto object-contain"
            />
            <span v-else class="font-semibold text-lg">{{ brand.name }}</span>
        </div>
        <div class="w-full max-w-md">
            <slot />
        </div>
        <p class="mt-8 text-xs text-muted-foreground flex items-center gap-2">
            <LockIcon class="size-3" />
            Secured by Stripe
        </p>
    </div>
</template>
```

**Key difference from AuthCardLayout:** No `Link` or `AppLogoIcon` (admin app logo). Brand logo is dynamic from props. CSS variable injection on root element for brand theming.

---

### `resources/js/app.ts` (config, modify — add ClientPayment/ case)

**Analog:** `resources/js/app.ts` lines 1–27 (the file itself — extend the switch)

**Current resolver** (lines 10–27, full file already in context):
```typescript
layout: (name) => {
    switch (true) {
        case name === 'Welcome':
            return null;
        case name.startsWith('auth/'):
            return AuthLayout;
        case name.startsWith('settings/'):
            return [AppLayout, SettingsLayout];
        default:
            return AppLayout;
    }
},
```

**Required addition — insert before `default:`:**
```typescript
case name.startsWith('ClientPayment/'):
    return null;  // Each ClientPayment page imports PaymentLayout directly and passes brand props
```

**Import to add at top** (follow existing import pattern at lines 3–6):
```typescript
// No new import needed — PaymentLayout is imported directly in each ClientPayment page
// This is intentional: layout resolver returns null so brand props can flow from page to layout
```

**Why `return null`:** The resolver has no access to per-request props (brand colors, logo). Returning `null` and importing `PaymentLayout` directly in each page template allows brand props to be passed via normal Vue prop binding. Established precedent: `name === 'Welcome'` already returns `null`.

---

### `resources/js/pages/ClientPayment/Pay.vue` (page/component, request-response)

**Analog:** `resources/js/pages/admin/brands/Create.vue` (Card structure, `<script setup lang="ts">`, `defineProps`, computed refs)

**Script setup and imports pattern** (from `Create.vue` lines 1–14):
```typescript
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import { AlertCircle } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card, CardContent, CardDescription,
    CardFooter, CardHeader, CardTitle,
} from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { StripeElements, StripeElement } from 'vue-stripe-js';
import { loadStripe } from '@stripe/stripe-js';
import PaymentLayout from '@/layouts/PaymentLayout.vue';
```

**Props definition** (follow `Create.vue` TypeScript props pattern):
```typescript
const props = defineProps<{
    payment: {
        uuid: string
        amount: number
        currency: string
        service: string | null
        package: string | null
    }
    brand: {
        name: string
        slug: string
        logo_url: string | null
        primary_color: string
        secondary_color: string
    }
    stripeAccount: { publishable_key: string }
    clientSecret: string
}>()
```

**formatAmount utility** (copy from `Show.vue` lines 61–66 — same `Intl.NumberFormat` pattern):
```typescript
function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    ).format(cents / 100);
}
```

**Stripe loading and state refs:**
```typescript
const stripeLoaded  = ref(false)
const processing    = ref(false)
const errorMessage  = ref<string | null>(null)

onMounted(async () => {
    await loadStripe(props.stripeAccount.publishable_key)
    stripeLoaded.value = true
})
```

**Stripe Elements appearance computed** (must read CSS var at mount time — see Pitfall 4 in RESEARCH.md):
```typescript
const elementsOptions = computed(() => ({
    clientSecret: props.clientSecret,
    appearance: {
        theme: 'stripe' as const,
        variables: {
            colorPrimary: getComputedStyle(document.documentElement)
                .getPropertyValue('--brand-primary').trim()
                || props.brand.primary_color
                || '#000000',
            colorBackground: '#ffffff',
            colorText: 'hsl(0, 0%, 3.9%)',
            colorDanger: 'hsl(0, 84.2%, 60.2%)',
            fontFamily: '"Instrument Sans", ui-sans-serif, system-ui, sans-serif',
            borderRadius: '6px',
            spacingUnit: '4px',
        },
        rules: {
            '.Input': { border: '1px solid hsl(0, 0%, 92.8%)', boxShadow: 'none' },
            '.Input:focus': {
                border: '1px solid var(--brand-primary)',
                boxShadow: '0 0 0 3px color-mix(in srgb, var(--brand-primary) 20%, transparent)',
            },
            '.Label': { fontSize: '14px', fontWeight: '400', color: 'hsl(0, 0%, 3.9%)' },
            '.Error': { fontSize: '14px' },
        },
    },
}))
```

**confirmPayment submit handler:**
```typescript
async function submit(instance: any, elements: any) {
    processing.value = true
    errorMessage.value = null

    const { error } = await instance.confirmPayment({
        elements,
        confirmParams: {
            return_url: `${window.location.origin}/pay/${props.payment.uuid}/success`,
        },
    })

    if (error) {
        errorMessage.value = error.message ?? 'An unexpected error occurred. Please try again.'
        processing.value = false
    }
    // No else — Stripe redirects browser on success; processing stays true during redirect
}
```

**Template pattern** (Card structure from `Create.vue` lines 72–164, adapted):
```vue
<template>
    <PaymentLayout :brand="props.brand">
        <Head :title="`Pay ${brand.name}`" />
        <Card class="rounded-xl shadow-sm">
            <CardHeader>
                <CardTitle class="text-xl">Complete your payment</CardTitle>
                <CardDescription>Review your order and enter payment details below.</CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
                <!-- Payment summary block -->
                <div class="rounded-lg border border-border bg-[--color-brand-secondary]/10 px-6 py-4">
                    <div class="flex items-baseline gap-3">
                        <span class="text-3xl font-semibold font-mono">
                            {{ formatAmount(payment.amount, payment.currency) }}
                        </span>
                        <Badge variant="outline" class="text-xs uppercase tracking-wide">
                            {{ payment.currency.toUpperCase() }}
                        </Badge>
                    </div>
                    <div class="mt-2 space-y-1 text-sm text-muted-foreground">
                        <p v-if="payment.service">{{ payment.service }}</p>
                        <p v-if="payment.package" class="capitalize">{{ payment.package }} package</p>
                    </div>
                </div>

                <Separator />

                <!-- Stripe Elements mount point -->
                <template v-if="stripeLoaded">
                    <StripeElements
                        :stripe-key="stripeAccount.publishable_key"
                        :elements-options="elementsOptions"
                    >
                        <template #default="{ instance, elements }">
                            <form @submit.prevent="submit(instance, elements)">
                                <StripeElement type="payment" :elements="elements" />
                                <Alert v-if="errorMessage" variant="destructive" class="mt-4">
                                    <AlertCircle class="size-4" />
                                    <AlertDescription>{{ errorMessage }}</AlertDescription>
                                </Alert>
                                <CardFooter class="px-0 pt-6">
                                    <Button
                                        type="submit"
                                        size="lg"
                                        class="w-full bg-[--color-brand-primary] text-white hover:bg-[--color-brand-primary]/90"
                                        :disabled="processing"
                                    >
                                        <Spinner v-if="processing" class="size-4" />
                                        <span>{{ processing ? 'Processing...' : `Pay ${formatAmount(payment.amount, payment.currency)}` }}</span>
                                    </Button>
                                </CardFooter>
                            </form>
                        </template>
                    </StripeElements>
                </template>
                <template v-else>
                    <div class="h-32 flex items-center justify-center">
                        <Spinner class="size-5 text-muted-foreground" />
                    </div>
                </template>
            </CardContent>
        </Card>
    </PaymentLayout>
</template>
```

---

### `resources/js/pages/ClientPayment/Success.vue` (page/component, request-response)

**Analog:** `resources/js/pages/payments/Show.vue` (Head, Card structure, `formatAmount`, `defineProps`)

**Script setup:**
```typescript
import { Head } from '@inertiajs/vue3';
import { CheckCircle2 } from 'lucide-vue-next';
import {
    Card, CardContent, CardHeader, CardTitle, CardDescription,
} from '@/components/ui/card';
import PaymentLayout from '@/layouts/PaymentLayout.vue';

// formatAmount — copy from Show.vue lines 61-66
function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    ).format(cents / 100);
}

const props = defineProps<{
    payment: { amount: number; currency: string; service: string | null }
    brand: { name: string; slug: string; logo_url: string | null; primary_color: string; secondary_color: string }
}>()
```

**Template pattern** (centered card, terminal state — no actions):
```vue
<template>
    <PaymentLayout :brand="props.brand">
        <Head :title="`Payment received — ${brand.name}`" />
        <Card class="rounded-xl shadow-sm text-center">
            <CardHeader class="pb-0">
                <div class="flex flex-col items-center gap-2 py-2">
                    <div class="flex h-14 w-14 items-center justify-center rounded-full bg-green-50">
                        <CheckCircle2 class="size-7 text-green-600" />
                    </div>
                </div>
                <CardTitle class="text-xl">Payment received</CardTitle>
                <CardDescription>Thank you — your payment has been processed successfully.</CardDescription>
            </CardHeader>
            <CardContent class="pt-6 space-y-4">
                <p class="text-xl font-semibold font-mono">
                    {{ formatAmount(payment.amount, payment.currency) }}
                </p>
                <p v-if="payment.service" class="text-sm text-muted-foreground">
                    for {{ payment.service }}
                </p>
            </CardContent>
        </Card>
    </PaymentLayout>
</template>
```

---

### `resources/js/pages/ClientPayment/Failed.vue` (page/component, request-response)

**Analog:** `resources/js/pages/payments/Show.vue` (Head, Card, Button patterns)

**Script setup:**
```typescript
import { Head } from '@inertiajs/vue3';
import { XCircle } from 'lucide-vue-next';
import {
    Card, CardContent, CardHeader, CardTitle, CardDescription,
} from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import PaymentLayout from '@/layouts/PaymentLayout.vue';

const props = defineProps<{
    payment: { uuid: string; amount: number; currency: string }
    brand: { name: string; slug: string; logo_url: string | null; primary_color: string; secondary_color: string }
}>()
```

**Template pattern** (retry link — D-05):
```vue
<template>
    <PaymentLayout :brand="props.brand">
        <Head :title="`Payment unsuccessful — ${brand.name}`" />
        <Card class="rounded-xl shadow-sm text-center">
            <CardHeader class="pb-0">
                <div class="flex h-14 w-14 items-center justify-center rounded-full bg-red-50 mx-auto">
                    <XCircle class="size-7 text-destructive" />
                </div>
                <CardTitle class="text-xl">Payment unsuccessful</CardTitle>
                <CardDescription>
                    We weren't able to process your payment. Please check your card details and try again.
                </CardDescription>
            </CardHeader>
            <CardContent class="pt-6">
                <Button
                    as-child
                    size="lg"
                    class="w-full bg-[--color-brand-primary] text-white hover:bg-[--color-brand-primary]/90"
                >
                    <a :href="`/pay/${payment.uuid}`">Try again</a>
                </Button>
            </CardContent>
        </Card>
    </PaymentLayout>
</template>
```

**Note:** `Button as-child` wrapping an `<a>` tag — this pattern is established in `Show.vue` (lines 98–103) where `Button as-child` wraps `Link`.

---

### `resources/js/pages/ClientPayment/Unavailable.vue` (page/component, request-response)

**Analog:** `resources/js/pages/payments/Show.vue` (`statusClass()` pattern for status-driven rendering)

**Script setup:**
```typescript
import { Head } from '@inertiajs/vue3';
import { CheckCircle2, XCircle, Ban } from 'lucide-vue-next';
import {
    Card, CardContent, CardHeader, CardTitle, CardDescription,
} from '@/components/ui/card';
import PaymentLayout from '@/layouts/PaymentLayout.vue';
import { computed } from 'vue';

const props = defineProps<{
    status: 'completed' | 'failed' | 'cancelled'
    brand: { name: string; slug: string; logo_url: string | null; primary_color: string; secondary_color: string }
}>()

// Status-to-content map (follow Show.vue statusClass() pattern — lines 68-74)
const content = computed(() => {
    const map = {
        completed: {
            title: 'Already paid',
            description: 'This payment has already been completed. No further action is needed.',
            pageTitle: `Already paid — ${props.brand.name}`,
            icon: 'check',
        },
        failed: {
            title: 'Payment link unavailable',
            description: 'Payment was unsuccessful. Please contact us to arrange a new payment.',
            pageTitle: `Payment unavailable — ${props.brand.name}`,
            icon: 'x',
        },
        cancelled: {
            title: 'Link no longer active',
            description: 'This payment link has been cancelled. Please contact us if you need assistance.',
            pageTitle: `Link no longer active — ${props.brand.name}`,
            icon: 'ban',
        },
    }
    return map[props.status] ?? map.cancelled
})
```

**Template pattern** (status-aware icon + copy, no form, no actions):
```vue
<template>
    <PaymentLayout :brand="props.brand">
        <Head :title="content.pageTitle" />
        <Card class="rounded-xl shadow-sm text-center">
            <CardHeader>
                <!-- completed -->
                <div v-if="status === 'completed'"
                     class="flex h-14 w-14 items-center justify-center rounded-full bg-green-50 mx-auto">
                    <CheckCircle2 class="size-7 text-green-600" />
                </div>
                <!-- failed -->
                <div v-else-if="status === 'failed'"
                     class="flex h-14 w-14 items-center justify-center rounded-full bg-red-50 mx-auto">
                    <XCircle class="size-7 text-destructive" />
                </div>
                <!-- cancelled -->
                <div v-else
                     class="flex h-14 w-14 items-center justify-center rounded-full bg-muted mx-auto">
                    <Ban class="size-7 text-muted-foreground" />
                </div>
                <CardTitle class="text-xl">{{ content.title }}</CardTitle>
                <CardDescription>{{ content.description }}</CardDescription>
            </CardHeader>
            <CardContent />
        </Card>
    </PaymentLayout>
</template>
```

---

### `routes/web.php` (route, modify — replace stub at line 51)

**Analog:** `routes/web.php` lines 15–48 (existing public/protected route patterns in this same file)

**Current stub to replace** (line 51):
```php
Route::get('/pay/{uuid}', fn () => abort(404))->name('pay.show');
```

**Replacement pattern** (follow the public route block style — no middleware group, outside `auth` group):
```php
use App\Http\Controllers\ClientPaymentController;

// Public payment routes — no auth middleware (CLIENT-01)
Route::get('/pay/{uuid}',         [ClientPaymentController::class, 'show'])->name('pay.show');
Route::get('/pay/{uuid}/success', [ClientPaymentController::class, 'success'])->name('pay.success');
Route::get('/pay/{uuid}/failed',  [ClientPaymentController::class, 'failed'])->name('pay.failed');
```

**Import placement:** Add `ClientPaymentController` to the `use` block at the top of the file (lines 3–7), following the existing import pattern.

---

### `tests/Feature/ClientPaymentTest.php` (test, feature/HTTP)

**Analog:** `tests/Feature/PaymentCreationTest.php` (Pest syntax, `uses(RefreshDatabase::class)`, factory setup, `beforeEach`, assertions)

**Test file structure** (from `PaymentCreationTest.php` lines 1–17):
```php
<?php

use App\Models\Brand;
use App\Models\Payment;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'user',  'guard_name' => 'web']);
});
```

**Stripe mock pattern** (from RESEARCH.md — needed for any test that hits `show()`):
```php
function mockStripeClient(): void
{
    $mockPi              = new PaymentIntent();
    $mockPi->id          = 'pi_test_mock123';
    $mockPi->client_secret = 'pi_test_mock123_secret_xyz';

    $mockPaymentIntents = Mockery::mock();
    $mockPaymentIntents->shouldReceive('create')->andReturn($mockPi);

    $mockStripe = Mockery::mock(StripeClient::class);
    $mockStripe->paymentIntents = $mockPaymentIntents;

    app()->bind(StripeClient::class, fn () => $mockStripe);
}
```

**HTTP assertion pattern** (from `PaymentCreationTest.php` lines 35–51 and `BrandManagementTest.php`):
```php
// Guest access (no actingAs)
it('guest can access pay route', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);
    mockStripeClient();
    $this->get("/pay/{$payment->uuid}")->assertStatus(200);
});

// Inertia prop assertions
it('passes brand props to Pay page', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);
    mockStripeClient();
    $this->get("/pay/{$payment->uuid}")
         ->assertInertia(fn ($page) => $page
             ->component('ClientPayment/Pay')
             ->has('brand.name')
             ->has('brand.primary_color')
             ->has('clientSecret')
             ->missing('brand.secret_key')
         );
});

// SEC-04 — clientSecret not in URL
it('client secret is not present in any route URL', function () {
    $payment = Payment::factory()->create(['status' => 'pending']);
    // Routes must not accept or expose client_secret as a path/query segment
    // The success controller only reads redirect_status, discards client_secret param
    $this->get("/pay/{$payment->uuid}/success?redirect_status=succeeded&payment_intent_client_secret=pi_xxx_secret")
         ->assertStatus(200); // reads redirect_status only
});
```

**Non-pending guard test** (D-03):
```php
it('completed payment renders Unavailable page', function () {
    $payment = Payment::factory()->create(['status' => 'completed']);
    $this->get("/pay/{$payment->uuid}")
         ->assertInertia(fn ($page) => $page->component('ClientPayment/Unavailable'));
});
```

**Note on test style:** `PaymentCreationTest.php` uses top-level Pest function syntax (`it()`, `uses()`, `beforeEach()`). `BrandManagementTest.php` uses class-based PHPUnit style. For `ClientPaymentTest.php`, follow the Pest function syntax matching the Phase 4 payment test (`PaymentCreationTest.php`) — this is the preferred pattern for new test files.

---

## Shared Patterns

### CSS Variable Brand Theming
**Source:** `resources/js/layouts/auth/AuthCardLayout.vue` (pattern), `resources/css/brand-theme.css` (already wired), UI-SPEC.md
**Apply to:** `PaymentLayout.vue` root element, `Pay.vue` button + summary block, Stripe Elements appearance object
```html
<!-- On layout root -->
<div
  :data-brand="brand.slug"
  :style="`--brand-primary: ${brand.primary_color}; --brand-secondary: ${brand.secondary_color}`"
>
<!-- CSS vars then usable anywhere in subtree -->
<!-- Tailwind arbitrary value access: bg-[--color-brand-primary] -->
<!-- (brand-theme.css forwards --brand-primary to --color-brand-primary via [data-brand] rule) -->
```

### Inertia Head Tag
**Source:** `resources/js/pages/payments/Show.vue` line 95, `resources/js/pages/admin/brands/Create.vue` line 58
**Apply to:** All four ClientPayment pages
```vue
<Head title="Page Title" />
<!-- or with dynamic title: -->
<Head :title="`Pay ${brand.name}`" />
```

### formatAmount Utility
**Source:** `resources/js/pages/payments/Show.vue` lines 61–66
**Apply to:** `Pay.vue`, `Success.vue`
```typescript
function formatAmount(cents: number, currency: string): string {
    return new Intl.NumberFormat(
        currency === 'gbp' ? 'en-GB' : 'en-US',
        { style: 'currency', currency: currency.toUpperCase() }
    ).format(cents / 100);
}
```

### Card Component Structure
**Source:** `resources/js/pages/admin/brands/Create.vue` lines 72–164, `resources/js/pages/payments/Show.vue` lines 107–165
**Apply to:** All four ClientPayment pages
```vue
<Card class="rounded-xl shadow-sm">
    <CardHeader>
        <CardTitle class="text-xl">...</CardTitle>
        <CardDescription>...</CardDescription>
    </CardHeader>
    <CardContent class="space-y-6">
        <!-- content -->
    </CardContent>
    <CardFooter>
        <!-- actions -->
    </CardFooter>
</Card>
```

### StripeClient Instantiation (backend)
**Source:** `app/Http/Controllers/Admin/StripeAccountController.php` lines 139–150
**Apply to:** `ClientPaymentController::show()` — the only place Stripe API is called in Phase 5
```php
// NEVER Stripe::setApiKey() globally
$stripe = new StripeClient($payment->stripeAccount->secret_key);
// secret_key auto-decrypted by Laravel encrypted cast on StripeAccount model
```

### RefreshDatabase + Role Setup in Tests
**Source:** `tests/Feature/PaymentCreationTest.php` lines 1–17
**Apply to:** `tests/Feature/ClientPaymentTest.php`
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

All files have analogs. No codebase gaps requiring RESEARCH.md patterns as primary source.

The following are new patterns WITH strong documentation from RESEARCH.md (no existing codebase analog — use documented patterns verbatim):

| Pattern | Source | Used By |
|---------|--------|---------|
| `StripeElements` + `StripeElement` (vue-stripe-js v2) | RESEARCH.md Pattern 2 + `05-UI-SPEC.md` | `Pay.vue` |
| `loadStripe()` → `stripeLoaded` gate | RESEARCH.md Pitfall 1 | `Pay.vue` onMounted |
| Stripe Elements appearance object | RESEARCH.md Pattern 3 + `05-UI-SPEC.md` Stripe Elements section | `Pay.vue` elementsOptions computed |
| `confirmPayment()` with `return_url` | RESEARCH.md Pattern 2 code example | `Pay.vue` submit handler |
| `?redirect_status=succeeded` check | RESEARCH.md Pattern/D-04 | `ClientPaymentController::success()` |

---

## Metadata

**Analog search scope:** `app/Http/Controllers/`, `resources/js/layouts/`, `resources/js/pages/`, `tests/Feature/`, `routes/`
**Files scanned:** 14 source files read directly
**Pattern extraction date:** 2026-05-09
