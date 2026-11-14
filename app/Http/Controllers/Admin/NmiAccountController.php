<?php

namespace App\Http\Controllers\Admin;

use App\Enums\NmiEnvironment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreNmiAccountRequest;
use App\Http\Requests\Admin\UpdateNmiAccountRequest;
use App\Models\NmiAccount;
use App\Services\Nmi\NmiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin CRUD for NMI accounts (spec 0012, AC-1, AC-2). The private key and the webhook signing key are
 * encrypted, never mass assigned and never sent to the browser: only has_* flags.
 */
class NmiAccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/nmi-accounts/Index', [
            'nmiAccounts' => NmiAccount::orderBy('account_name')
                ->get()
                ->map(fn (NmiAccount $account) => [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'prefix' => $account->prefix,
                    'environment' => $account->environment,
                    'currency' => $account->currency,
                    'three_ds_enabled' => $account->three_ds_enabled,
                    'google_pay_enabled' => $account->google_pay_enabled,
                    'is_active' => $account->is_active,
                    'has_webhook_signing_key' => ! empty($account->webhook_signing_key),
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/nmi-accounts/Create');
    }

    public function store(StoreNmiAccountRequest $request): RedirectResponse
    {
        $account = new NmiAccount($request->safe()->except(['private_key', 'webhook_signing_key']));
        $account->private_key = $request->validated('private_key');
        $account->webhook_signing_key = $request->filled('webhook_signing_key') ? $request->validated('webhook_signing_key') : null;
        $account->save();

        return redirect()->route('admin.nmi-accounts.index')
            ->with('success', 'NMI account saved.');
    }

    public function edit(NmiAccount $nmiAccount): Response
    {
        return Inertia::render('admin/nmi-accounts/Edit', [
            'nmiAccount' => [
                'id' => $nmiAccount->id,
                'account_name' => $nmiAccount->account_name,
                'prefix' => $nmiAccount->prefix,
                'environment' => $nmiAccount->environment,
                'currency' => $nmiAccount->currency,
                'public_tokenization_key' => $nmiAccount->public_tokenization_key,
                'three_ds_enabled' => $nmiAccount->three_ds_enabled,
                'google_pay_enabled' => $nmiAccount->google_pay_enabled,
                'is_active' => $nmiAccount->is_active,
                'has_private_key' => ! empty($nmiAccount->private_key),
                'has_webhook_signing_key' => ! empty($nmiAccount->webhook_signing_key),
                // private_key and webhook_signing_key: NEVER included, only the has_* flags
            ],
        ]);
    }

    public function update(UpdateNmiAccountRequest $request, NmiAccount $nmiAccount): RedirectResponse
    {
        // Blank keeps the stored value.
        if ($request->filled('private_key')) {
            $nmiAccount->private_key = $request->validated('private_key');
        }

        if ($request->filled('webhook_signing_key')) {
            $nmiAccount->webhook_signing_key = $request->validated('webhook_signing_key');
        }

        $nmiAccount->fill($request->safe()->except(['private_key', 'webhook_signing_key']));
        $nmiAccount->save();

        return redirect()->route('admin.nmi-accounts.index')
            ->with('success', 'NMI account updated.');
    }

    public function deactivate(NmiAccount $nmiAccount): RedirectResponse
    {
        $nmiAccount->update(['is_active' => false]);

        return redirect()->route('admin.nmi-accounts.index')
            ->with('success', 'Account deactivated.');
    }

    public function activate(NmiAccount $nmiAccount): RedirectResponse
    {
        $nmiAccount->update(['is_active' => true]);

        return redirect()->route('admin.nmi-accounts.index')
            ->with('success', 'Account activated.');
    }

    public function destroy(NmiAccount $nmiAccount): RedirectResponse
    {
        if ($nmiAccount->payments()->exists()) {
            return redirect()->route('admin.nmi-accounts.index')
                ->with('error', 'Cannot delete an account that has payments.');
        }

        $nmiAccount->delete();

        return redirect()->route('admin.nmi-accounts.index')
            ->with('success', 'NMI account deleted.');
    }

    /** Test the values typed on the Create form, before anything is saved. */
    public function testKeyConnection(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'private_key' => ['required', 'string', 'max:512'],
            'environment' => ['required', Rule::in(NmiEnvironment::values())],
        ]);

        return $this->connectionResult($data['private_key'], $data['environment']);
    }

    public function testStoredConnection(NmiAccount $nmiAccount): RedirectResponse
    {
        return $this->connectionResult($nmiAccount->private_key, $nmiAccount->environment->value);
    }

    /**
     * One read only lookup of an order id that cannot exist, with a per account client (never a global
     * key). It never creates a transaction. NMI's own error is shown in plain words (AC-2).
     */
    private function connectionResult(string $privateKey, string $environment): RedirectResponse
    {
        $client = new NmiClient($privateKey, (string) config("services.nmi.hosts.{$environment}"));

        try {
            $result = $client->ping();
        } catch (ConnectionException) {
            return back()->withErrors(['nmi_api' => 'Could not connect to NMI. Check your network and try again.']);
        }

        return $result['ok'] ? back() : back()->withErrors(['nmi_api' => $result['message']]);
    }
}
