<?php

use App\Jobs\SendPaymentNotification;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Bus::fake([SendPaymentNotification::class]);
});

function nmiPagePayment(array $account = [], array $attributes = []): Payment
{
    $payment = Payment::factory()->nmi()->create(array_merge(['amount' => 1234, 'currency' => 'usd', 'status' => 'pending'], $attributes));

    if ($account !== []) {
        $payment->nmiAccount->forceFill($account)->save();
    }

    return $payment->fresh();
}

it('renders the NMI pay page with only public values', function () {
    $payment = nmiPagePayment(['webhook_signing_key' => 'whsec_secret_value', 'google_pay_enabled' => true]);

    $response = $this->get("/pay/{$payment->uuid}");

    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('ClientPayment/PayNmi')
        ->where('nmiAccount.tokenization_key', $payment->nmiAccount->public_tokenization_key)
        ->where('nmiAccount.three_ds_enabled', false)
        ->where('nmiAccount.google_pay_enabled', true)
        ->where('payment.amount', 1234)
        ->where('confirming', false));

    expect($response->getContent())
        ->not->toContain('placeholder_private_key_for_dev_only')
        ->not->toContain('whsec_secret_value')
        ->not->toContain('private_key');
});

it('adds the NMI hosts to the CSP of the NMI pay page only', function () {
    $nmi = nmiPagePayment();
    $stripe = Payment::factory()->create(['status' => 'pending']);

    $csp = $this->get("/pay/{$nmi->uuid}")->headers->get('Content-Security-Policy');
    expect($csp)->toContain('https://sandbox.nmi.com')
        ->and($csp)->toContain('https://secure.nmi.com')
        ->and($csp)->not->toContain('https://pay.google.com'); // Google Pay is off for this account

    expect($this->get("/pay/{$stripe->uuid}")->headers->get('Content-Security-Policy'))->not->toContain('nmi.com');
});

it('adds the Google Pay hosts only when the account has Google Pay on', function () {
    $payment = nmiPagePayment(['google_pay_enabled' => true]);

    expect($this->get("/pay/{$payment->uuid}")->headers->get('Content-Security-Policy'))->toContain('https://pay.google.com');
});

it('adds the 3DS challenge hosts from config only when 3DS is on', function () {
    config(['services.nmi.three_ds_hosts' => ['https://3ds.example.test']]);

    $off = nmiPagePayment();
    $on = nmiPagePayment(['three_ds_enabled' => true]);

    expect($this->get("/pay/{$off->uuid}")->headers->get('Content-Security-Policy'))->not->toContain('3ds.example.test')
        ->and($this->get("/pay/{$on->uuid}")->headers->get('Content-Security-Policy'))->toContain('3ds.example.test');
});

it('never adds unsafe-eval for NMI', function () {
    $payment = nmiPagePayment(['google_pay_enabled' => true, 'three_ds_enabled' => true]);

    expect($this->get("/pay/{$payment->uuid}")->headers->get('Content-Security-Policy'))->not->toContain('unsafe-eval');
});

it('shows the unavailable page for an inactive account', function () {
    $payment = nmiPagePayment(['is_active' => false]);

    $this->get("/pay/{$payment->uuid}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('ClientPayment/Unavailable'));
});

it('tells the page a charge is still being confirmed', function () {
    $payment = nmiPagePayment(attributes: ['nmi_first_attempt_at' => now()]);
    Cache::put("nmi:pause:{$payment->id}", 1, 120);
    Http::fake(['*' => Http::response('<nm_response></nm_response>')]);

    $this->get("/pay/{$payment->uuid}")->assertInertia(fn (AssertableInertia $page) => $page->where('confirming', true));
});

it('redirects to the success page when the page load lookup finds the payment paid', function () {
    $payment = nmiPagePayment(attributes: ['nmi_order_id' => '88888888-8888-4888-8888-888888888888', 'nmi_first_attempt_at' => now()]);
    $xml = '<nm_response><transaction><transaction_id>5</transaction_id><condition>complete</condition>'
        .'<order_id>'.$payment->nmi_order_id.'</order_id><currency>USD</currency>'
        .'<action><amount>12.34</amount><action_type>sale</action_type><success>1</success></action></transaction></nm_response>';
    Http::fake(['*' => Http::response($xml)]);

    $this->get("/pay/{$payment->uuid}")->assertRedirect("/pay/{$payment->uuid}/success");
    expect($payment->fresh()->status)->toBe('completed');
});
