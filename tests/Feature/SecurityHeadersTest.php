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
