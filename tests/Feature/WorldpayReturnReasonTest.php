<?php

use App\Models\Payment;
use App\Models\User;
use App\Models\WorldpayPage;
use App\Models\WorldpayPageReturn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Http::fake(['*paymentQueries/payments*' => Http::response(['_embedded' => ['payments' => []]], 200)]);
});

function wrPayment(array $overrides = []): Payment
{
    return Payment::factory()->worldpay()->create(array_merge(['amount' => 1234, 'status' => 'pending'], $overrides));
}

function wrPage(Payment $payment, int $sequence = 1): WorldpayPage
{
    return WorldpayPage::create([
        'payment_id' => $payment->id, 'sequence' => $sequence, 'transaction_reference' => $payment->uuid.'-'.$sequence,
        'url' => 'https://hpp.worldpay.com/a', 'expires_at' => now()->addDay(),
    ]);
}

it('saves the word, error reference and text of a failure return on the newest page', function () {
    $payment = wrPayment();
    wrPage($payment, 1);
    $newest = wrPage($payment, 2);

    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=ERR-123&errors=Card%20refused")->assertOk();

    $row = WorldpayPageReturn::sole();
    expect($row->page_id)->toBe($newest->id)
        ->and($row->word)->toBe('failure')
        ->and($row->error_ref)->toBe('ERR-123')
        ->and($row->error_text)->toBe('Card refused')
        ->and($payment->fresh()->status)->toBe('pending');
});

it('shows the return on the admin payment page', function () {
    $payment = wrPayment();
    $page = wrPage($payment);
    $this->get("/pay/{$payment->uuid}?worldpay_return=error&errorRefNumber=REF9&errors=Something%20went%20wrong");

    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->syncRoles(['admin']);

    $this->actingAs($admin)->get("/payments/{$payment->uuid}")->assertInertia(fn (Assert $p) => $p
        ->where('attempts.rows', fn ($rows) => collect($rows)->contains(fn ($row) => $row['source'] === 'client return'
            && $row['status'] === 'returned'
            && $row['status_detail'] === 'error · REF9'
            && $row['reason'] === 'Something went wrong'
            && $row['reference'] === $page->transaction_reference)));
});

it('saves a return with no error details and logs the word without the error text', function () {
    Log::spy();
    $payment = wrPayment();
    wrPage($payment);

    $this->get("/pay/{$payment->uuid}?worldpay_return=cancel")->assertOk();
    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=R1&errors=SECRETTEXT");

    expect(WorldpayPageReturn::count())->toBe(2)
        ->and(WorldpayPageReturn::where('word', 'cancel')->sole()->error_ref)->toBeNull();

    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'Worldpay client returned'
        && ! str_contains(json_encode($context), 'SECRETTEXT'));
});

it('does not repeat a row for the same return within a minute, but saves a new reference', function () {
    $payment = wrPayment();
    wrPage($payment);

    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=SAME");
    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=SAME");
    expect(WorldpayPageReturn::count())->toBe(1);

    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=OTHER");
    expect(WorldpayPageReturn::count())->toBe(2);

    $this->travel(2)->minutes();
    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=SAME");
    expect(WorldpayPageReturn::count())->toBe(3);
});

it('saves nothing for success, pending, an unknown word, a paid payment or a payment with no usable page', function () {
    $payment = wrPayment();
    wrPage($payment);

    foreach (['success', 'pending', 'nonsense'] as $word) {
        $this->get("/pay/{$payment->uuid}?worldpay_return={$word}&errorRefNumber=X1");
    }

    $paid = wrPayment(['status' => 'completed']);
    wrPage($paid);
    $this->get("/pay/{$paid->uuid}?worldpay_return=failure&errorRefNumber=X2");

    $none = wrPayment();
    $this->get("/pay/{$none->uuid}?worldpay_return=failure&errorRefNumber=X3");

    expect(WorldpayPageReturn::count())->toBe(0);
});

it('never puts the saved error reference or text in the client page props', function () {
    $payment = wrPayment();
    wrPage($payment);

    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=HIDDENREF&errors=HIDDENTEXT");
    $response = $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=HIDDENREF2");

    $response->assertInertia(fn (Assert $p) => $p->component('ClientPayment/PayWorldpay')->where('returnStatus', 'failure'));
    // The address itself is echoed in the page url, so only the props are checked.
    $props = json_encode($response->viewData('page')['props']);
    expect($props)->not->toContain('HIDDENTEXT')->not->toContain('HIDDENREF');
});

it('cleans the error reference and text that come from the address', function () {
    expect(WorldpayPageReturn::cleanRef('ab-12_3.x<script>'))->toBe('ab-12_3.xscript')
        ->and(WorldpayPageReturn::cleanRef(str_repeat('a', 100)))->toHaveLength(64)
        ->and(WorldpayPageReturn::cleanRef('<>!!'))->toBeNull()
        ->and(WorldpayPageReturn::cleanRef(['x']))->toBeNull()
        ->and(WorldpayPageReturn::cleanRef(null))->toBeNull();

    expect(WorldpayPageReturn::cleanText("<b>Card</b>\n\tnot   accepted\x00"))->toBe('Card not accepted')
        ->and(WorldpayPageReturn::cleanText(['Invalid CVC', 'Try again', ['nested']]))->toBe('Invalid CVC; Try again')
        ->and(WorldpayPageReturn::cleanText(str_repeat('é', 400)))->toHaveLength(255)
        ->and(WorldpayPageReturn::cleanText('   '))->toBeNull()
        ->and(WorldpayPageReturn::cleanText(null))->toBeNull();
});

it('reads the error text from messages, falls back to errors, and prefers messages', function () {
    $payment = wrPayment();
    wrPage($payment);

    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=M1&messages=Card%20refused,Try%20again");
    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=M2&errors=Old%20style");
    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=M3&messages=From%20messages&errors=From%20errors");

    expect(WorldpayPageReturn::where('error_ref', 'M1')->sole()->error_text)->toBe('Card refused,Try again')
        ->and(WorldpayPageReturn::where('error_ref', 'M2')->sole()->error_text)->toBe('Old style')
        ->and(WorldpayPageReturn::where('error_ref', 'M3')->sole()->error_text)->toBe('From messages');
});

it('logs whether the text parameters arrived empty, by length only', function () {
    Log::spy();
    $payment = wrPayment();
    wrPage($payment);

    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=EMPTY1&errors=");

    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => $message === 'Worldpay client returned'
        && ($context['text_lengths'] ?? null) === ['errors' => 0]
        && $context['text_saved'] === false);

    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=FULL1&messages=Declined");

    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context = []) => ($context['text_lengths'] ?? null) === ['messages' => 8]
        && $context['text_saved'] === true);
});

it('tells the admin that Worldpay gave no reason when a failure has a reference but no text', function () {
    $payment = wrPayment();
    wrPage($payment);
    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=D261120-T9&errors=");
    $this->get("/pay/{$payment->uuid}?worldpay_return=cancel");

    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->syncRoles(['admin']);

    $this->actingAs($admin)->get("/payments/{$payment->uuid}")->assertInertia(fn (Assert $p) => $p
        ->where('attempts.rows', function ($rows) {
            $rows = collect($rows);
            $failure = $rows->firstWhere('status_detail', 'failure · D261120-T9');
            $cancel = $rows->firstWhere('status_detail', 'cancel');

            return $failure !== null
                && $failure['reason'] === 'Worldpay gave no reason. Give this reference to Worldpay support.'
                && $cancel !== null && $cancel['reason'] === null;
        }));
});

it('logs the names of the return parameters and never their values', function () {
    Log::spy();
    $payment = wrPayment();
    wrPage($payment);

    $this->get("/pay/{$payment->uuid}?worldpay_return=failure&errorRefNumber=K1&messages=SECRETVALUE&other_param=SECRETVALUE2");

    Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) {
        return $message === 'Worldpay client returned'
            && in_array('messages', $context['query_keys'], true)
            && in_array('other_param', $context['query_keys'], true)
            && in_array('errorRefNumber', $context['query_keys'], true)
            && ! in_array('worldpay_return', $context['query_keys'], true)
            && ! str_contains(json_encode($context), 'SECRETVALUE');
    });
});
