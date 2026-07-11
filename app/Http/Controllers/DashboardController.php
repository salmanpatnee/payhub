<?php

namespace App\Http\Controllers;

use App\Services\DashboardMetrics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * Render the payments analytics dashboard (admin + account only).
     */
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'brand_id' => ['nullable', 'integer'],
            'relationship_manager_id' => ['nullable', 'integer'],
            'provider' => ['nullable', 'in:stripe,revolut,square,viva'],
            'account' => ['nullable', 'string'],
            'currency' => ['nullable', 'in:usd,gbp'],
        ]);

        // Default to the last 30 days when no range is supplied.
        if (empty($filters['from']) && empty($filters['to'])) {
            $filters['from'] = now()->subDays(30)->toDateString();
        }

        return Inertia::render('Dashboard', DashboardMetrics::for($filters));
    }
}
