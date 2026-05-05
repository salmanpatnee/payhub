<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStripeAccountRequest;
use App\Http\Requests\Admin\UpdateStripeAccountRequest;
use App\Models\StripeAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\StripeClient;

class StripeAccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/stripe-accounts/Index', [
            'stripeAccounts' => StripeAccount::orderBy('account_name')
                ->get()
                ->map(fn (StripeAccount $account) => [
                    'id'                      => $account->id,
                    'account_name'            => $account->account_name,
                    'publishable_key_preview' => substr($account->publishable_key, 0, 12) . '••••••••',
                    'is_active'               => $account->is_active,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/stripe-accounts/Create');
    }

    public function store(StoreStripeAccountRequest $request): RedirectResponse
    {
        $account             = new StripeAccount($request->safe()->except('secret_key'));
        $account->secret_key = $request->validated('secret_key');
        $account->save();

        return redirect()->route('admin.stripe-accounts.index')
            ->with('success', 'Stripe account saved.');
    }

    public function edit(StripeAccount $stripeAccount): Response
    {
        return Inertia::render('admin/stripe-accounts/Edit', [
            'stripeAccount' => [
                'id'              => $stripeAccount->id,
                'account_name'    => $stripeAccount->account_name,
                'publishable_key' => $stripeAccount->publishable_key,
                'is_active'       => $stripeAccount->is_active,
                // secret_key: NEVER included — not even masked
            ],
        ]);
    }

    public function update(UpdateStripeAccountRequest $request, StripeAccount $stripeAccount): RedirectResponse
    {
        if ($request->filled('secret_key')) {
            $stripeAccount->secret_key = $request->validated('secret_key');
        }

        $stripeAccount->fill($request->safe()->except('secret_key'));
        $stripeAccount->save();

        return redirect()->route('admin.stripe-accounts.index')
            ->with('success', 'Stripe account updated.');
    }

    public function deactivate(StripeAccount $stripeAccount): RedirectResponse
    {
        $stripeAccount->update(['is_active' => false]);

        return redirect()->route('admin.stripe-accounts.index')
            ->with('success', 'Account deactivated.');
    }

    public function destroy(StripeAccount $stripeAccount): RedirectResponse
    {
        if ($stripeAccount->payments()->exists()) {
            return redirect()->route('admin.stripe-accounts.index')
                ->with('error', 'Cannot delete an account that has payments.');
        }

        $stripeAccount->delete();

        return redirect()->route('admin.stripe-accounts.index')
            ->with('success', 'Stripe account deleted.');
    }

    public function testKeyConnection(Request $request): RedirectResponse
    {
        $request->validate([
            'secret_key'      => 'required|string',
            'publishable_key' => 'required|string',
        ]);

        $error = $this->validatePublishableKeyFormat($request->input('publishable_key'), $request->input('secret_key'))
            ?? $this->validateStripeSecretKey($request->input('secret_key'));

        if ($error) {
            return back()->withErrors(['stripe_api' => $error]);
        }
        return back();
    }

    public function testStoredConnection(StripeAccount $stripeAccount): RedirectResponse
    {
        $error = $this->validatePublishableKeyFormat($stripeAccount->publishable_key, $stripeAccount->secret_key)
            ?? $this->validateStripeSecretKey($stripeAccount->secret_key);

        if ($error) {
            return back()->withErrors(['stripe_api' => $error]);
        }
        return back();
    }

    private function validatePublishableKeyFormat(string $publishableKey, string $secretKey): ?string
    {
        if (! preg_match('/^pk_(live|test)_/', $publishableKey)) {
            return 'The publishable key is invalid. It must start with pk_live_ or pk_test_.';
        }

        $secretMode      = str_contains($secretKey, '_live_') ? 'live' : 'test';
        $publishableMode = str_contains($publishableKey, '_live_') ? 'live' : 'test';

        if ($secretMode !== $publishableMode) {
            return "Key mode mismatch: secret key is {$secretMode} but publishable key is {$publishableMode}. Both must be the same mode.";
        }

        return null;
    }

    /**
     * NEVER call Stripe::setApiKey() globally — always new StripeClient($secretKey).
     */
    private function validateStripeSecretKey(string $secretKey): ?string
    {
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
