<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $viteHost = $request->getHost();
        $vite = app()->environment('local')
            ? " http://127.0.0.1:5173 http://localhost:5173 http://{$viteHost}:5173 https://{$viteHost}:5173"
            : '';
        $viteWs = app()->environment('local')
            ? " ws://127.0.0.1:5173 ws://localhost:5173 ws://{$viteHost}:5173 wss://{$viteHost}:5173"
            : '';
        $scriptInline = app()->environment('local') ? " 'unsafe-inline'" : '';

        // Revolut Merchant Web SDK: embed.js/version.js are loaded as scripts, the
        // Card Field + 3DS challenge render in an iframe, and the SDK makes XHRs —
        // all from the merchant host (sandbox + prod). Both are whitelisted so the
        // env can switch without a CSP change.
        $revolut = ' https://sandbox-merchant.revolut.com https://merchant.revolut.com';

        $response->headers->set('Content-Security-Policy',
            "default-src 'self'; ".
            "script-src 'self' https://js.stripe.com{$revolut}{$vite}{$scriptInline}; ".
            "frame-src https://js.stripe.com{$revolut}; ".
            "connect-src 'self' https://api.stripe.com{$revolut}{$vite}{$viteWs}; ".
            "img-src 'self' data: blob: https:; ".
            "style-src 'self' 'unsafe-inline'{$vite}; ".
            "font-src 'self'{$vite};"
        );

        return $response;
    }
}
