<?php

namespace App\Http\Middleware;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // The MPGS 3DS return page loads inside the bank challenge frame on PayHub's own
        // pay page, so it is the one route allowed to be framed, and only by PayHub itself.
        $response->headers->set('X-Frame-Options', $request->routeIs('pay.mpgs.return') ? 'SAMEORIGIN' : 'DENY');
        // The Authorize.net communicator is framed by the hosted form (spec 0006, AC-5): drop
        // X-Frame-Options there and allow only PayHub and the two form hosts via frame-ancestors.
        $isAuthorizenetCommunicator = $request->routeIs('pay.authorizenet.communicator');
        if ($isAuthorizenetCommunicator) {
            $response->headers->remove('X-Frame-Options');
        }
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

        // Clover Hosted Iframe SDK: sdk.js loads from, and its card-field iframes +
        // createToken() calls stay on, the same checkout.*.clover.com host (sandbox +
        // prod) — scl-sandbox.dev.clover.com/scl.clover.com is the /v1/charges host
        // CloverClient calls server-to-server only, never from the browser.
        $clover = ' https://checkout.sandbox.dev.clover.com https://checkout.clover.com';

        // MPGS Hosted Session (spec 0005, AC-6): session.js, the 3DS library and the
        // card-field frames load from the configured gateway host, and the bank's 3DS
        // challenge frame can be any https origin. Widened ONLY on the pay page of an
        // MPGS payment; every other route keeps the strict policy.
        $route = $request->route();
        $routePayment = $route?->parameter('payment');
        $isMpgsPayPage = $route?->getName() === 'pay.show'
            && $routePayment instanceof Payment
            && $routePayment->provider === PaymentProvider::Mpgs;
        $mpgsHost = $isMpgsPayPage ? ' '.$routePayment->loadMissing('mpgsAccount')->mpgsAccount?->gatewayHost() : '';
        $mpgsFrames = $isMpgsPayPage ? ' https:' : '';

        // Authorize.net Accept Hosted (spec 0006, AC-5): only the pay page of an Authorize.net
        // payment may frame the hosted form and post to it, and only to that account's form host.
        $isAuthorizenetPayPage = $route?->getName() === 'pay.show'
            && $routePayment instanceof Payment
            && $routePayment->provider === PaymentProvider::Authorizenet;
        $authorizenetHost = $isAuthorizenetPayPage
            ? ' '.config('services.authorizenet.hosts.'.$routePayment->loadMissing('authorizenetAccount')->authorizenetAccount?->environment?->value.'.form')
            : '';
        $authorizenetFormAction = $isAuthorizenetPayPage ? " form-action 'self'{$authorizenetHost};" : '';
        $authorizenetAncestors = $isAuthorizenetCommunicator
            ? " frame-ancestors 'self' https://test.authorize.net https://accept.authorize.net;"
            : '';

        $response->headers->set('Content-Security-Policy',
            "default-src 'self'; ".
            "script-src 'self' https://js.stripe.com{$revolut}{$square}{$clover}{$mpgsHost}{$vite}{$scriptInline}; ".
            "frame-src https://js.stripe.com{$revolut}{$square}{$squareFrames}{$clover}{$mpgsHost}{$mpgsFrames}{$authorizenetHost}; ".
            "connect-src 'self' https://api.stripe.com{$revolut}{$squareConnect}{$clover}{$mpgsHost}{$vite}{$viteWs}; ".
            "img-src 'self' data: blob: https:; ".
            "style-src 'self' 'unsafe-inline'{$square}{$vite}; ".
            "font-src 'self' data: https://*.squarecdn.com{$squareFonts}{$vite};".
            $authorizenetFormAction.$authorizenetAncestors
        );

        return $response;
    }
}
