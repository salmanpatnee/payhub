<?php

use App\Models\Payment;
use App\Models\RevolutAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('allows blob images in the content security policy', function () {
    $response = $this->get('/login');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("img-src 'self' data: blob: https:");
});

it('denies framing and restricts frame-src to Stripe', function () {
    $response = $this->get('/login');

    // Policy docs are now rendered as native HTML (no PDF iframe), so framing is
    // locked back down: only Stripe (3DS) may be framed, and the app refuses being framed.
    expect($response->headers->get('Content-Security-Policy'))
        ->toContain('frame-src https://js.stripe.com')
        ->not->toContain("frame-src 'self'");
    expect($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

// Regression: the Clover Hosted Iframe SDK (spec 0002) was built without ever
// being added to the CSP, unlike Stripe/Revolut/Square — the browser silently
// blocked sdk.js from loading, surfacing as "Payment system is unavailable" on
// the pay page with no CSP violation shown to the user. checkout.*.clover.com
// serves the SDK script, its card-field iframes, and createToken() itself;
// scl.*.clover.com is the /v1/charges host CloverClient calls server-to-server
// only, so it belongs on neither list.
it('allows the clover hosted iframe sdk in the content security policy', function () {
    $csp = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($csp)
        ->toContain('script-src')
        ->toContain('https://checkout.sandbox.dev.clover.com')
        ->toContain('https://checkout.clover.com')
        ->not->toContain('scl-sandbox.dev.clover.com')
        ->not->toContain('scl.clover.com');

    $scriptSrc = collect(explode(';', $csp))->first(fn ($directive) => str_starts_with(trim($directive), 'script-src'));
    $frameSrc = collect(explode(';', $csp))->first(fn ($directive) => str_starts_with(trim($directive), 'frame-src'));
    $connectSrc = collect(explode(';', $csp))->first(fn ($directive) => str_starts_with(trim($directive), 'connect-src'));

    expect($scriptSrc)->toContain('checkout.sandbox.dev.clover.com');
    expect($frameSrc)->toContain('checkout.sandbox.dev.clover.com');
    expect($connectSrc)->toContain('checkout.sandbox.dev.clover.com');
});

// One tap wallets: Google Pay, Apple Pay and Cash App Pay hosts are added only on the pay
// page of a Square payment, Google Pay and Revolut Pay hosts only on a Revolut pay page.
function cspDirective(string $csp, string $name): string
{
    return (string) collect(explode(';', $csp))->first(fn ($directive) => str_starts_with(trim($directive), $name));
}

it('adds the square wallet hosts only on a square pay page', function () {
    $payment = Payment::factory()->square()->create(['status' => 'pending']);

    $csp = $this->get("/pay/{$payment->uuid}")->headers->get('Content-Security-Policy');

    expect(cspDirective($csp, 'script-src'))->toContain('https://pay.google.com')->toContain('https://kit.cash.app');
    expect(cspDirective($csp, 'frame-src'))->toContain('https://pay.google.com')->toContain('https://*.cash.app');
    expect(cspDirective($csp, 'connect-src'))->toContain('https://api.cash.app');
    expect(cspDirective($csp, 'font-src'))->toContain('https://applepay.cdn-apple.com');
});

it('adds the revolut wallet hosts only on a revolut pay page', function () {
    Http::fake([
        '*/api/orders' => Http::response(['id' => 'ord_w', 'token' => 'tok_w', 'state' => 'pending'], 201),
    ]);
    $account = RevolutAccount::factory()->create();
    $payment = Payment::factory()->revolut()->create([
        'revolut_account_id' => $account->id,
        'revolut_order_id' => null,
        'status' => 'pending',
    ]);

    $csp = $this->get("/pay/{$payment->uuid}")->headers->get('Content-Security-Policy');

    expect(cspDirective($csp, 'script-src'))->toContain('https://pay.google.com')->not->toContain('kit.cash.app');
    expect(cspDirective($csp, 'frame-src'))->toContain('https://checkout.revolut.com');
    // Apple Pay in Chrome builds a PaymentRequest for https://apple.com/apple-pay, which needs connect-src.
    expect(cspDirective($csp, 'connect-src'))->toContain('https://apple.com');
    // The Apple Pay QR code window outside Safari is a frame from applepay.cdn-apple.com.
    expect(cspDirective($csp, 'frame-src'))->toContain('https://applepay.cdn-apple.com');
});

it('adds no wallet hosts outside the square and revolut pay pages', function () {
    $stripePayment = Payment::factory()->create(['status' => 'pending']);

    foreach (['/login', "/pay/{$stripePayment->uuid}"] as $url) {
        $csp = $this->get($url)->headers->get('Content-Security-Policy');

        expect($csp)->not->toContain('kit.cash.app')
            ->not->toContain('checkout.revolut.com')
            ->not->toContain('https://pay.google.com');
    }
});
