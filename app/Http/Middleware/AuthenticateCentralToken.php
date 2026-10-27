<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCentralToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = hrtime(true);
        $token = (string) $request->bearerToken();
        $slot = $token === '' ? null : $this->matchingSlot(hash('sha256', $token));

        if ($slot === null) {
            $this->audit($request, 'unauthenticated', null, $startedAt);

            return response()->json(['error' => 'unauthenticated'], 401);
        }

        // Keyed on a hash of the token, never the token itself. Done here, not as a
        // throttle middleware, because Laravel sorts throttle ahead of this middleware.
        $limiterKey = 'central-token:'.hash('sha256', 'central-limit|'.$token);

        if (RateLimiter::tooManyAttempts($limiterKey, 6)) {
            return response()->json(['message' => 'Too Many Attempts.'], 429, [
                'Retry-After' => RateLimiter::availableIn($limiterKey),
            ]);
        }

        RateLimiter::hit($limiterKey, 60);

        $response = $next($request);

        $this->audit($request, 'ok', $slot, $startedAt);

        return $response;
    }

    private function matchingSlot(string $hash): ?string
    {
        $matched = null;

        foreach (['current' => 'token_hash_current', 'next' => 'token_hash_next'] as $slot => $key) {
            $stored = strtolower((string) config("central.{$key}"));

            if ($stored !== '' && hash_equals($stored, $hash) && $matched === null) {
                $matched = $slot;
            }
        }

        return $matched;
    }

    private function audit(Request $request, string $result, ?string $slot, int $startedAt): void
    {
        Log::channel('central_audit')->info('central summary call', [
            'result' => $result,
            'slot' => $slot,
            'ip' => $request->ip(),
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
        ]);
    }
}
