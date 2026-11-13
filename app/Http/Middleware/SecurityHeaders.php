<?php

namespace App\Http\Middleware;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // A per request nonce lets PayHub's own inline script (the dark mode check in app.blade.php)
        // run without 'unsafe-inline'. Vite adds the same nonce to its tags. Local dev keeps
        // 'unsafe-inline' instead, because browsers ignore 'unsafe-inline' once a nonce is present.
        $nonce = app()->environment('local') ? null : Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // The MPGS 3DS return page loads inside the bank challenge frame on PayHub's own
        // pay page, so it is the one route allowed to be framed, and only by PayHub itself.
        $response->headers->set('X-Frame-Options', $request->routeIs('pay.mpgs.return') ? 'SAMEORIGIN' : 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $viteHost = $request->getHost();
        $vite = app()->environment('local')
            ? " http://127.0.0.1:5173 http://localhost:5173 http://{$viteHost}:5173 https://{$viteHost}:5173"
            : '';
        $viteWs = app()->environment('local')
            ? " ws://127.0.0.1:5173 ws://localhost:5173 ws://{$viteHost}:5173 wss://{$viteHost}:5173"
            : '';
        $scriptInline = $nonce === null ? " 'unsafe-inline'" : " 'nonce-{$nonce}'";

        // Sentry browser SDK posts error envelopes to the ingest host. Its session replay (and the
        // Whop embed) start a web worker from a blob: URL, allowed through worker-src below.
        $sentryConnect = ' https://*.ingest.sentry.io https://*.ingest.us.sentry.io https://*.ingest.de.sentry.io';

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
        $isMpgsPayPage = in_array($route?->getName(), ['pay.show', 'pay.show.ref'], true)
            && $routePayment instanceof Payment
            && $routePayment->provider === PaymentProvider::Mpgs;
        $mpgsHost = $isMpgsPayPage ? ' '.$routePayment->loadMissing('mpgsAccount')->mpgsAccount?->gatewayHost() : '';
        $mpgsFrames = $isMpgsPayPage ? ' https:' : '';

        // Authorize.net Accept.js (spec 0007, AC-14): only the pay page of an Authorize.net
        // payment loads Accept.js and lets it post the card, and only to that account's
        // environment hosts. No unsafe-inline or unsafe-eval is added.
        $isAuthorizenetPayPage = in_array($route?->getName(), ['pay.show', 'pay.show.ref'], true)
            && $routePayment instanceof Payment
            && $routePayment->provider === PaymentProvider::Authorizenet;
        $authorizenetEnv = $isAuthorizenetPayPage
            ? $routePayment->loadMissing('authorizenetAccount')->authorizenetAccount?->environment?->value
            : null;
        $authorizenetScript = $authorizenetEnv ? ' '.config("services.authorizenet.hosts.{$authorizenetEnv}.script") : '';
        $authorizenetConnect = $authorizenetEnv ? ' '.config("services.authorizenet.hosts.{$authorizenetEnv}.connect") : '';

        // Whop Checkout embed (spec 0009, AC-14): only the pay page of a Whop payment whose account is in
        // embed mode gets the Whop hosts. Redirect mode adds none. No unsafe-inline or unsafe-eval.
        $isWhopPayPage = in_array($route?->getName(), ['pay.show', 'pay.show.ref'], true)
            && $routePayment instanceof Payment
            && $routePayment->provider === PaymentProvider::Whop;
        $whopAccount = $isWhopPayPage ? $routePayment->loadMissing('whopAccount')->whopAccount : null;
        $whopScript = $whopConnect = $whopMethodScript = $whopMethodFrame = $whopMethodConnect = $whopMethodFont = '';
        if ($whopAccount?->checkout_mode === 'embed') {
            $whopScript = ' '.config("services.whop.hosts.{$whopAccount->environment}.script");
            $whopConnect = ' '.config("services.whop.hosts.{$whopAccount->environment}.connect");
            // PayPal, Google Pay, Apple Pay and ACH bank linking run inside the embed.
            $whopMethodScript = ' '.implode(' ', config('services.whop.payment_method_hosts.script'));
            $whopMethodFrame = ' '.implode(' ', config('services.whop.payment_method_hosts.frame'));
            $whopMethodConnect = ' '.implode(' ', config('services.whop.payment_method_hosts.connect'));
            $whopMethodFont = ' '.implode(' ', config('services.whop.payment_method_hosts.font'));
        }

        // NMI Payment Component (spec 0012, AC-16): only the pay page of an NMI payment gets the NMI hosts
        // (script, style, connect and frame), the environment's host from config plus the production host the
        // component's frames may still load from, and the 3DS challenge hosts when 3DS is on. Google Pay hosts
        // come from the wallet block below. No unsafe-inline or unsafe-eval is added.
        $isNmiPayPage = in_array($route?->getName(), ['pay.show', 'pay.show.ref'], true)
            && $routePayment instanceof Payment
            && $routePayment->provider === PaymentProvider::Nmi;
        $nmiAccount = $isNmiPayPage ? $routePayment->loadMissing('nmiAccount')->nmiAccount : null;
        $nmi = '';
        if ($nmiAccount !== null) {
            $nmiHosts = array_unique(array_filter([
                (string) config("services.nmi.hosts.{$nmiAccount->environment->value}"),
                'https://secure.nmi.com',
                ...($nmiAccount->three_ds_enabled ? (array) config('services.nmi.three_ds_hosts', []) : []),
            ]));
            $nmi = ' '.implode(' ', $nmiHosts);
        }

        // One tap wallets (Google Pay, Apple Pay, Cash App Pay, Revolut Pay): only the pay page of a
        // Square or Revolut payment gets the extra wallet hosts. No unsafe-inline or unsafe-eval.
        $walletProvider = in_array($route?->getName(), ['pay.show', 'pay.show.ref'], true) && $routePayment instanceof Payment
            ? match ($routePayment->provider) {
                PaymentProvider::Square => 'square',
                PaymentProvider::Revolut => 'revolut',
                PaymentProvider::Nmi => $nmiAccount?->google_pay_enabled ? 'google_pay' : null,
                default => null,
            }
        : null;
        $walletHosts = fn (string $kind): string => $walletProvider
            ? implode('', array_map(fn (string $host): string => " {$host}", config("services.wallet_hosts.{$walletProvider}.{$kind}", [])))
            : '';
        $walletScript = $walletHosts('script');
        $walletFrame = $walletHosts('frame');
        $walletConnect = $walletHosts('connect');
        $walletFont = $walletHosts('font');

        $response->headers->set('Content-Security-Policy',
            "default-src 'self'; ".
            "script-src 'self' https://js.stripe.com{$revolut}{$square}{$clover}{$mpgsHost}{$authorizenetScript}{$nmi}{$whopScript}{$whopMethodScript}{$walletScript}{$vite}{$scriptInline}; ".
            "frame-src https://js.stripe.com{$revolut}{$square}{$squareFrames}{$clover}{$mpgsHost}{$mpgsFrames}{$nmi}{$whopScript}{$whopMethodFrame}{$walletFrame}; ".
            "connect-src 'self' https://api.stripe.com{$sentryConnect}{$revolut}{$squareConnect}{$clover}{$mpgsHost}{$authorizenetConnect}{$nmi}{$whopConnect}{$whopMethodConnect}{$walletConnect}{$vite}{$viteWs}; ".
            "img-src 'self' data: blob: https:; ".
            "style-src 'self' 'unsafe-inline'{$square}{$nmi}{$vite}; ".
            "font-src 'self' data: https://*.squarecdn.com{$squareFonts}{$whopMethodFont}{$walletFont}{$vite}; ".
            "worker-src 'self' blob:"
        );

        return $response;
    }
}
