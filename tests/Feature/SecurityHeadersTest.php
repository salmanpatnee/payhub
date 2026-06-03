<?php

it('allows blob images in the content security policy', function () {
    $response = $this->get('/login');

    $csp = $response->headers->get('Content-Security-Policy');

    expect($csp)->toContain("img-src 'self' data: blob: https:");
});
