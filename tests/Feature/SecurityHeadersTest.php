<?php

it('allows blob images in the content security policy', function () {
    $response = $this->get('/login');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("img-src 'self' data: blob: https:");
});

it('allows same-origin framing so policy PDFs can embed', function () {
    $response = $this->get('/login');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("frame-src 'self' https://js.stripe.com");
    expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN');
});
