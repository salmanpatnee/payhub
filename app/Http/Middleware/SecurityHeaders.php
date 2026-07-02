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

        // Square Web Payments SDK: scripts + card-field iframes load from *.squarecdn.com;
        // tokenize / verifyBuyer (3DS/SCA) call *.squareup.com and the sandbox variant.
        $square = ' https://sandbox.web.squarecdn.com https://web.squarecdn.com https://*.squarecdn.com';
        $squareConnect = ' https://connect.squareup.com https://connect.squareupsandbox.com https://pci-connect.squareup.com https://pci-connect.squareupsandbox.com https://*.squarecdn.com';
        // Square card-field font loads from this CloudFront host; 3DS/SCA challenge renders in connect frames.
        $squareFonts = ' https://d1g145x70srn7h.cloudfront.net';
        $squareFrames = ' https://connect.squareup.com https://connect.squareupsandbox.com';

        $response->headers->set('Content-Security-Policy',
            "default-src 'self'; ".
            "script-src 'self' https://js.stripe.com{$revolut}{$square}{$vite}{$scriptInline}; ".
            "frame-src https://js.stripe.com{$revolut}{$square}{$squareFrames}; ".
            "connect-src 'self' https://api.stripe.com{$revolut}{$squareConnect}{$vite}{$viteWs}; ".
            "img-src 'self' data: blob: https:; ".
            "style-src 'self' 'unsafe-inline'{$square}{$vite}; ".
            "font-src 'self' data: https://*.squarecdn.com{$squareFonts}{$vite};"
        );

        return $response;
    }
}
