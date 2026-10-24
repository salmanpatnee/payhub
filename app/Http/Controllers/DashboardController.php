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
            'provider' => ['nullable', 'in:stripe,revolut,square,viva,clover,mpgs,authorizenet'],
            'account' => ['nullable', 'string'],
            'currency' => ['nullable', 'in:usd,gbp,pkr'],
        ]);

        $props = DashboardMetrics::for($filters);

        if ($request->user()->hasRole('admin')) {
            $props['lockedPayments'] = DashboardMetrics::lockedPayments();
        }

        return Inertia::render('Dashboard', $props);
    }
}
