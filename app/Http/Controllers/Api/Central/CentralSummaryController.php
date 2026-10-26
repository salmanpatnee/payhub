<?php

namespace App\Http\Controllers\Api\Central;

use App\Http\Controllers\Controller;
use App\Services\CentralSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CentralSummaryController extends Controller
{
    public function __invoke(CentralSummary $summary): JsonResponse
    {
        try {
            $body = Cache::remember(
                'central:summary:v1',
                max(0, (int) config('central.cache_seconds')),
                fn () => $summary->build(),
            );
        } catch (Throwable $e) {
            report($e);

            return response()->json(['error' => 'summary_unavailable'], 503);
        }

        return response()->json($body);
    }
}
