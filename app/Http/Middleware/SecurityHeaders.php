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

        $response->headers->set('Content-Security-Policy',
            "default-src 'self'; ".
            "script-src 'self' https://js.stripe.com{$vite}{$scriptInline}; ".
            'frame-src https://js.stripe.com; '.
            "connect-src 'self' https://api.stripe.com{$vite}{$viteWs}; ".
            "img-src 'self' data: https:; ".
            "style-src 'self' 'unsafe-inline'{$vite}; ".
            "font-src 'self'{$vite};"
        );

        return $response;
    }
}
