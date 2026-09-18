<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCloverAccountRequest;
use App\Http\Requests\Admin\UpdateCloverAccountRequest;
use App\Models\CloverAccount;
use App\Services\Clover\CloverClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CloverAccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/clover-accounts/Index', [
            'cloverAccounts' => CloverAccount::orderBy('account_name')
                ->get()
                ->map(fn (CloverAccount $account) => [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'prefix' => $account->prefix,
                    'merchant_id' => $account->merchant_id,
                    'api_access_key' => $account->api_access_key,
                    'environment' => $account->environment,
                    'is_active' => $account->is_active,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/clover-accounts/Create');
    }

    public function store(StoreCloverAccountRequest $request): RedirectResponse
    {
        $error = $this->validateCloverCredentials(
            $request->validated('merchant_id'),
            $request->validated('private_token'),
            $request->validated('environment'),
        );

        if ($error) {
            return back()->withErrors(['clover_api' => $error])->withInput();
        }

        $account = new CloverAccount($request->safe()->except(['private_token']));
        $account->private_token = $request->validated('private_token');
        $account->currency = 'usd';
        $account->save();

        return redirect()->route('admin.clover-accounts.index')
            ->with('success', 'Clover account saved.');
    }

    public function edit(CloverAccount $cloverAccount): Response
    {
        return Inertia::render('admin/clover-accounts/Edit', [
            'cloverAccount' => [
                'id' => $cloverAccount->id,
                'account_name' => $cloverAccount->account_name,
                'prefix' => $cloverAccount->prefix,
                'merchant_id' => $cloverAccount->merchant_id,
                'api_access_key' => $cloverAccount->api_access_key,
                'environment' => $cloverAccount->environment,
                'is_active' => $cloverAccount->is_active,
                'has_private_token' => ! empty($cloverAccount->private_token),
                // private_token: NEVER included — not even masked
            ],
        ]);
    }

    public function update(UpdateCloverAccountRequest $request, CloverAccount $cloverAccount): RedirectResponse
    {
        $privateToken = $request->filled('private_token') ? $request->validated('private_token') : $cloverAccount->private_token;

        $error = $this->validateCloverCredentials(
            $request->validated('merchant_id'),
            $privateToken,
            $request->validated('environment'),
        );

        if ($error) {
            return back()->withErrors(['clover_api' => $error])->withInput();
        }

        if ($request->filled('private_token')) {
            $cloverAccount->private_token = $request->validated('private_token');
        }

        $cloverAccount->fill($request->safe()->except(['private_token']));
        $cloverAccount->save();

        return redirect()->route('admin.clover-accounts.index')
            ->with('success', 'Clover account updated.');
    }

    public function deactivate(CloverAccount $cloverAccount): RedirectResponse
    {
        $cloverAccount->update(['is_active' => false]);

        return redirect()->route('admin.clover-accounts.index')
            ->with('success', 'Account deactivated.');
    }

    public function activate(CloverAccount $cloverAccount): RedirectResponse
    {
        $cloverAccount->update(['is_active' => true]);

        return redirect()->route('admin.clover-accounts.index')
            ->with('success', 'Account activated.');
    }

    public function destroy(CloverAccount $cloverAccount): RedirectResponse
    {
        if ($cloverAccount->payments()->exists()) {
            return redirect()->route('admin.clover-accounts.index')
                ->with('error', 'Cannot delete an account that has payments.');
        }

        $cloverAccount->delete();

        return redirect()->route('admin.clover-accounts.index')
            ->with('success', 'Clover account deleted.');
    }

    public function testKeyConnection(Request $request): RedirectResponse
    {
        $request->validate([
            'merchant_id' => 'required|string',
            'private_token' => 'required|string',
            'environment' => 'required|string|in:sandbox,production',
        ]);

        $error = $this->validateCloverCredentials(
            $request->input('merchant_id'),
            $request->input('private_token'),
            $request->input('environment'),
        );

        if ($error) {
            return back()->withErrors(['clover_api' => $error]);
        }

        return back()->with('success', 'Clover credentials verified.');
    }

    public function testStoredConnection(CloverAccount $cloverAccount): RedirectResponse
    {
        $error = $this->validateCloverCredentials(
            $cloverAccount->merchant_id,
            $cloverAccount->private_token,
            $cloverAccount->environment,
        );

        if ($error) {
            return back()->withErrors(['clover_api' => $error]);
        }

        return back()->with('success', 'Clover credentials verified.');
    }

    /**
     * NEVER use a global Clover client — always a per-account CloverClient.
     */
    private function validateCloverCredentials(
        string $merchantId,
        string $privateToken,
        string $environment,
    ): ?string {
        if (app()->environment('testing')) {
            return null;
        }

        try {
            $clover = app()->make(CloverClient::class, [
                'merchantId' => $merchantId,
                'privateToken' => $privateToken,
                'environment' => $environment,
            ]);
            $clover->verifyCredentials();

            return null;
        } catch (RequestException $e) {
            return 'The credentials could not be verified with Clover. Check that they are correct and try again.';
        } catch (\Throwable $e) {
            return 'Could not connect to Clover to validate the credentials. Check your network and try again.';
        }
    }
}
