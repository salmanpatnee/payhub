<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreVivaAccountRequest;
use App\Http\Requests\Admin\UpdateVivaAccountRequest;
use App\Models\VivaAccount;
use App\Services\Viva\VivaClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VivaAccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/viva-accounts/Index', [
            'vivaAccounts' => VivaAccount::orderBy('account_name')
                ->get()
                ->map(fn (VivaAccount $account) => [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'client_id_preview' => substr($account->client_id, 0, 8).'••••••••',
                    'environment' => $account->environment,
                    'is_active' => $account->is_active,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/viva-accounts/Create');
    }

    public function store(StoreVivaAccountRequest $request): RedirectResponse
    {
        $error = $this->validateVivaCredentials(
            $request->validated('client_id'),
            $request->validated('client_secret'),
            $request->validated('merchant_id'),
            $request->validated('api_key'),
            $request->validated('environment'),
        );

        if ($error) {
            return back()->withErrors(['viva_api' => $error])->withInput();
        }

        $account = new VivaAccount($request->safe()->except(['client_secret', 'api_key']));
        $account->client_secret = $request->validated('client_secret');
        $account->api_key = $request->validated('api_key');
        $account->save();

        return redirect()->route('admin.viva-accounts.index')
            ->with('success', 'Viva account saved.');
    }

    public function edit(VivaAccount $vivaAccount): Response
    {
        return Inertia::render('admin/viva-accounts/Edit', [
            'vivaAccount' => [
                'id' => $vivaAccount->id,
                'account_name' => $vivaAccount->account_name,
                'prefix' => $vivaAccount->prefix,
                'client_id' => $vivaAccount->client_id,
                'merchant_id' => $vivaAccount->merchant_id,
                'source_code' => $vivaAccount->source_code,
                'environment' => $vivaAccount->environment,
                'is_active' => $vivaAccount->is_active,
                'has_client_secret' => ! empty($vivaAccount->client_secret),
                'has_api_key' => ! empty($vivaAccount->api_key),
                'has_webhook_verification_key' => ! empty($vivaAccount->webhook_verification_key),
                'webhook_verify_url' => route('webhook.viva.verify', $vivaAccount),
                'webhook_endpoint_url' => route('webhook.viva', $vivaAccount),
                // client_secret / api_key / webhook_verification_key: NEVER included — not even masked
            ],
        ]);
    }

    public function update(UpdateVivaAccountRequest $request, VivaAccount $vivaAccount): RedirectResponse
    {
        $clientSecret = $request->filled('client_secret') ? $request->validated('client_secret') : $vivaAccount->client_secret;
        $apiKey = $request->filled('api_key') ? $request->validated('api_key') : $vivaAccount->api_key;

        $error = $this->validateVivaCredentials(
            $request->validated('client_id'),
            $clientSecret,
            $request->validated('merchant_id'),
            $apiKey,
            $request->validated('environment'),
        );

        if ($error) {
            return back()->withErrors(['viva_api' => $error])->withInput();
        }

        if ($request->filled('client_secret')) {
            $vivaAccount->client_secret = $request->validated('client_secret');
        }

        if ($request->filled('api_key')) {
            $vivaAccount->api_key = $request->validated('api_key');
        }

        $vivaAccount->fill($request->safe()->except(['client_secret', 'api_key']));
        $vivaAccount->save();

        return redirect()->route('admin.viva-accounts.index')
            ->with('success', 'Viva account updated.');
    }

    public function deactivate(VivaAccount $vivaAccount): RedirectResponse
    {
        $vivaAccount->update(['is_active' => false]);

        return redirect()->route('admin.viva-accounts.index')
            ->with('success', 'Account deactivated.');
    }

    public function activate(VivaAccount $vivaAccount): RedirectResponse
    {
        $vivaAccount->update(['is_active' => true]);

        return redirect()->route('admin.viva-accounts.index')
            ->with('success', 'Account activated.');
    }

    public function destroy(VivaAccount $vivaAccount): RedirectResponse
    {
        if ($vivaAccount->payments()->exists()) {
            return redirect()->route('admin.viva-accounts.index')
                ->with('error', 'Cannot delete an account that has payments.');
        }

        $vivaAccount->delete();

        return redirect()->route('admin.viva-accounts.index')
            ->with('success', 'Viva account deleted.');
    }

    public function testKeyConnection(Request $request): RedirectResponse
    {
        $request->validate([
            'client_id' => 'required|string',
            'client_secret' => 'required|string',
            'merchant_id' => 'required|string',
            'api_key' => 'required|string',
            'environment' => 'required|string|in:demo,production',
        ]);

        $error = $this->validateVivaCredentials(
            $request->input('client_id'),
            $request->input('client_secret'),
            $request->input('merchant_id'),
            $request->input('api_key'),
            $request->input('environment'),
        );

        if ($error) {
            return back()->withErrors(['viva_api' => $error]);
        }

        return back()->with('success', 'Viva credentials verified.');
    }

    public function testStoredConnection(VivaAccount $vivaAccount): RedirectResponse
    {
        $error = $this->validateVivaCredentials(
            $vivaAccount->client_id,
            $vivaAccount->client_secret,
            $vivaAccount->merchant_id,
            $vivaAccount->api_key,
            $vivaAccount->environment,
        );

        if ($error) {
            return back()->withErrors(['viva_api' => $error]);
        }

        return back()->with('success', 'Viva credentials verified.');
    }

    /**
     * NEVER use a global Viva client — always a per-account VivaClient.
     * Viva has no Stripe-style balance endpoint, so an OAuth2 token fetch
     * itself (which fails on bad client id/secret) is the connectivity check.
     */
    private function validateVivaCredentials(
        string $clientId,
        string $clientSecret,
        string $merchantId,
        string $apiKey,
        string $environment,
    ): ?string {
        if (app()->environment('testing')) {
            return null;
        }

        try {
            $viva = app()->make(VivaClient::class, [
                'clientId' => $clientId,
                'clientSecret' => $clientSecret,
                'merchantId' => $merchantId,
                'apiKey' => $apiKey,
                'environment' => $environment,
            ]);
            $viva->verifyCredentials();

            return null;
        } catch (RequestException $e) {
            return 'The credentials could not be verified with Viva. Check that they are correct and try again.';
        } catch (\Throwable $e) {
            return 'Could not connect to Viva to validate the credentials. Check your network and try again.';
        }
    }
}
