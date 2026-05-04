<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStripeAccountRequest;
use App\Http\Requests\Admin\UpdateStripeAccountRequest;
use App\Models\Brand;
use App\Models\StripeAccount;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\StripeClient;

class StripeAccountController extends Controller
{
    public function index(Brand $brand): Response
    {
        return Inertia::render('admin/brands/stripe-accounts/Index', [
            'brand'          => $brand->only('id', 'name'),
            'stripeAccounts' => $brand->stripeAccounts()
                ->orderBy('account_name')
                ->get()
                ->map(fn (StripeAccount $account) => [
                    'id'                      => $account->id,
                    'account_name'            => $account->account_name,
                    'publishable_key'         => $account->publishable_key,
                    'publishable_key_preview' => substr($account->publishable_key, 0, 12) . '••••••••',
                    'is_active'               => $account->is_active,
                    // secret_key is intentionally omitted — never sent to frontend
                ]),
        ]);
    }

    public function create(Brand $brand): Response
    {
        return Inertia::render('admin/brands/stripe-accounts/Create', [
            'brand' => $brand->only('id', 'name'),
        ]);
    }

    public function store(StoreStripeAccountRequest $request, Brand $brand): RedirectResponse
    {
        // Format validation already passed via FormRequest.
        // Now validate the key pair against the live Stripe API.
        $validationError = $this->validateStripeKeyPair($request->validated('secret_key'));
        if ($validationError) {
            return back()->withErrors(['stripe_api' => $validationError]);
        }

        // Use safe()->except('secret_key') so secret_key is not mass-assigned.
        $account           = new StripeAccount($request->safe()->except('secret_key'));
        $account->brand_id = $brand->id;  // from route binding, never from request
        $account->secret_key = $request->validated('secret_key');  // explicit encrypted assignment
        $account->save();

        return redirect()->route('admin.brands.stripe-accounts.index', $brand)
            ->with('success', 'Stripe account saved.');
    }

    public function edit(Brand $brand, StripeAccount $stripeAccount): Response
    {
        return Inertia::render('admin/brands/stripe-accounts/Edit', [
            'brand'         => $brand->only('id', 'name'),
            'stripeAccount' => [
                'id'              => $stripeAccount->id,
                'account_name'    => $stripeAccount->account_name,
                'publishable_key' => $stripeAccount->publishable_key,
                'is_active'       => $stripeAccount->is_active,
                // secret_key: NEVER included in props — not even masked
            ],
        ]);
    }

    public function update(UpdateStripeAccountRequest $request, Brand $brand, StripeAccount $stripeAccount): RedirectResponse
    {
        // Only re-validate and re-store secret_key if a new value was provided.
        if ($request->filled('secret_key')) {
            $validationError = $this->validateStripeKeyPair($request->validated('secret_key'));
            if ($validationError) {
                return back()->withErrors(['stripe_api' => $validationError]);
            }
            $stripeAccount->secret_key = $request->validated('secret_key');
        }

        $stripeAccount->fill($request->safe()->except('secret_key'));
        $stripeAccount->save();

        return redirect()->route('admin.brands.stripe-accounts.index', $brand)
            ->with('success', 'Stripe account updated.');
    }

    public function deactivate(Brand $brand, StripeAccount $stripeAccount): RedirectResponse
    {
        // Belt-and-suspenders: the scoped route binding enforces this, but be explicit.
        abort_if($stripeAccount->brand_id !== $brand->id, 403);

        $stripeAccount->update(['is_active' => false]);

        return redirect()->route('admin.brands.stripe-accounts.index', $brand)
            ->with('success', 'Account deactivated.');
    }

    /**
     * Validate a Stripe secret key by making a lightweight API call.
     * Returns null if valid, or an error message string if invalid.
     *
     * NEVER call Stripe::setApiKey() globally — always new StripeClient($secretKey).
     *
     * Skip live API validation in test environment — validation is done manually per STRIPE-03.
     * In local/staging/production, validation always runs.
     */
    private function validateStripeKeyPair(string $secretKey): ?string
    {
        // Skip live API validation in test environment — validation is done manually per STRIPE-03
        if (app()->environment('testing')) {
            return null;
        }

        try {
            $stripe = new StripeClient($secretKey);
            $stripe->balance->retrieve();

            return null;
        } catch (AuthenticationException $e) {
            return 'The secret key could not be verified with Stripe. Check that it is correct and try again.';
        } catch (ApiConnectionException $e) {
            return 'Could not connect to Stripe to validate the key. Check your network and try again.';
        }
    }
}
