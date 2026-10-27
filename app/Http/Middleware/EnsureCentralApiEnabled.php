<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCentralApiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (blank(config('central.token_hash_current')) && blank(config('central.token_hash_next'))) {
            return response('', 404);
        }

        if (config('central.require_https') && ! $request->isSecure()) {
            return response()->json(['error' => 'https_required'], 403);
        }

        return $next($request);
    }
}
