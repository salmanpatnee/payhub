<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreRevolutAccountRequest;
use App\Http\Requests\Admin\UpdateRevolutAccountRequest;
use App\Models\RevolutAccount;
use App\Services\Revolut\RevolutClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RevolutAccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/revolut-accounts/Index', [
            'revolutAccounts' => RevolutAccount::orderBy('account_name')
                ->get()
                ->map(fn (RevolutAccount $account) => [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'public_key_preview' => $account->public_key
                        ? substr($account->public_key, 0, 12).'••••••••'
                        : '—',
                    'is_active' => $account->is_active,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/revolut-accounts/Create');
    }

    public function store(StoreRevolutAccountRequest $request): RedirectResponse
    {
        $account = new RevolutAccount($request->safe()->except('secret_key'));
        $account->secret_key = $request->validated('secret_key');
        $account->save();

        return redirect()->route('admin.revolut-accounts.index')
            ->with('success', 'Revolut account saved.');
    }

    public function edit(RevolutAccount $revolutAccount): Response
    {
        return Inertia::render('admin/revolut-accounts/Edit', [
            'revolutAccount' => [
                'id' => $revolutAccount->id,
                'account_name' => $revolutAccount->account_name,
                'public_key' => $revolutAccount->public_key,
                'is_active' => $revolutAccount->is_active,
                'has_webhook_secret' => ! empty($revolutAccount->webhook_secret),
                'webhook_endpoint_url' => route('webhook.revolut', $revolutAccount),
                // secret_key: NEVER included — not even masked
                // webhook_secret: NEVER included — use has_webhook_secret (bool) only
            ],
        ]);
    }

    public function update(UpdateRevolutAccountRequest $request, RevolutAccount $revolutAccount): RedirectResponse
    {
        if ($request->filled('secret_key')) {
            $revolutAccount->secret_key = $request->validated('secret_key');
        }

        if ($request->filled('webhook_secret')) {
            $revolutAccount->webhook_secret = $request->validated('webhook_secret');
        }

        $revolutAccount->fill($request->safe()->except(['secret_key', 'webhook_secret']));
        $revolutAccount->save();

        return redirect()->route('admin.revolut-accounts.index')
            ->with('success', 'Revolut account updated.');
    }

    public function deactivate(RevolutAccount $revolutAccount): RedirectResponse
    {
        $revolutAccount->update(['is_active' => false]);

        return redirect()->route('admin.revolut-accounts.index')
            ->with('success', 'Account deactivated.');
    }

    public function activate(RevolutAccount $revolutAccount): RedirectResponse
    {
        $revolutAccount->update(['is_active' => true]);

        return redirect()->route('admin.revolut-accounts.index')
            ->with('success', 'Account activated.');
    }

    public function destroy(RevolutAccount $revolutAccount): RedirectResponse
    {
        if ($revolutAccount->payments()->exists()) {
            return redirect()->route('admin.revolut-accounts.index')
                ->with('error', 'Cannot delete an account that has payments.');
        }

        $revolutAccount->delete();

        return redirect()->route('admin.revolut-accounts.index')
            ->with('success', 'Revolut account deleted.');
    }

    public function testKeyConnection(Request $request): RedirectResponse
    {
        $request->validate([
            'secret_key' => 'required|string',
        ]);

        if ($error = $this->validateRevolutSecretKey($request->input('secret_key'))) {
            return back()->withErrors(['revolut_api' => $error]);
        }

        return back();
    }

    public function testStoredConnection(RevolutAccount $revolutAccount): RedirectResponse
    {
        if ($error = $this->validateRevolutSecretKey($revolutAccount->secret_key)) {
            return back()->withErrors(['revolut_api' => $error]);
        }

        return back();
    }

    /**
     * NEVER use a global/shared key — always a per-account RevolutClient.
     */
    private function validateRevolutSecretKey(string $secretKey): ?string
    {
        if (app()->environment('testing')) {
            return null;
        }

        try {
            app()->make(RevolutClient::class, ['secretKey' => $secretKey])->verifyKey();

            return null;
        } catch (RequestException $e) {
            if ($e->response->status() === 401) {
                return 'The secret key could not be verified with Revolut. Check that it is correct and try again.';
            }

            return 'Revolut returned an unexpected error: '.$e->getMessage();
        } catch (ConnectionException) {
            return 'Could not connect to Revolut to validate the key. Check your network and try again.';
        }
    }
}
