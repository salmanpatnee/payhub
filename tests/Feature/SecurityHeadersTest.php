<?php

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
