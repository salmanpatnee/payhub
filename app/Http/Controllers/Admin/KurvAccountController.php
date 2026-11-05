<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreKurvAccountRequest;
use App\Http\Requests\Admin\UpdateKurvAccountRequest;
use App\Models\KurvAccount;
use App\Services\Kurv\KurvClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class KurvAccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/kurv-accounts/Index', [
            'kurvAccounts' => KurvAccount::orderBy('account_name')
                ->get()
                ->map(fn (KurvAccount $account) => [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'prefix' => $account->prefix,
                    'environment' => $account->environment,
                    'is_active' => $account->is_active,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/kurv-accounts/Create');
    }

    public function store(StoreKurvAccountRequest $request): RedirectResponse
    {
        $account = new KurvAccount($request->safe()->except(['api_key']));
        $account->api_key = $request->validated('api_key');
        $account->save();

        return redirect()->route('admin.kurv-accounts.index')
            ->with('success', 'Kurv account saved.');
    }

    public function edit(KurvAccount $kurvAccount): Response
    {
        return Inertia::render('admin/kurv-accounts/Edit', [
            'kurvAccount' => [
                'id' => $kurvAccount->id,
                'account_name' => $kurvAccount->account_name,
                'prefix' => $kurvAccount->prefix,
                'environment' => $kurvAccount->environment,
                'is_active' => $kurvAccount->is_active,
                'has_api_key' => ! empty($kurvAccount->api_key),
                // api_key: NEVER included, only the has_api_key flag
            ],
        ]);
    }

    public function update(UpdateKurvAccountRequest $request, KurvAccount $kurvAccount): RedirectResponse
    {
        if ($request->filled('api_key')) {
            $kurvAccount->api_key = $request->validated('api_key');
        }

        $kurvAccount->fill($request->safe()->except(['api_key']));
        $kurvAccount->save();

        return redirect()->route('admin.kurv-accounts.index')
            ->with('success', 'Kurv account updated.');
    }

    public function deactivate(KurvAccount $kurvAccount): RedirectResponse
    {
        $kurvAccount->update(['is_active' => false]);

        return redirect()->route('admin.kurv-accounts.index')
            ->with('success', 'Account deactivated.');
    }

    public function activate(KurvAccount $kurvAccount): RedirectResponse
    {
        $kurvAccount->update(['is_active' => true]);

        return redirect()->route('admin.kurv-accounts.index')
            ->with('success', 'Account activated.');
    }

    public function destroy(KurvAccount $kurvAccount): RedirectResponse
    {
        if ($kurvAccount->payments()->exists()) {
            return redirect()->route('admin.kurv-accounts.index')
                ->with('error', 'Cannot delete an account that has payments.');
        }

        $kurvAccount->delete();

        return redirect()->route('admin.kurv-accounts.index')
            ->with('success', 'Kurv account deleted.');
    }

    /** Test the values typed on the Create form, before anything is saved. */
    public function testKeyConnection(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:512'],
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
        ]);

        return $this->connectionResult($data['api_key'], $data['environment']);
    }

    public function testStoredConnection(KurvAccount $kurvAccount): RedirectResponse
    {
        return $this->connectionResult($kurvAccount->api_key, $kurvAccount->environment);
    }

    /**
     * One read only Kurv call with a per-account client, never a global key.
     * Replies are mapped to plain words (spec 0010, AC-1). Kurv answers a bad key with 403.
     */
    private function connectionResult(string $apiKey, string $environment): RedirectResponse
    {
        if (! KurvAccount::keyMatchesEnvironment($apiKey, $environment)) {
            return back()->withErrors(['kurv_api' => 'This key does not match the environment. Use a kp_test_ key with Sandbox and a kp_live_ key with Production.']);
        }

        try {
            $status = app()->make(KurvClient::class, [
                'apiKey' => $apiKey,
                'environment' => $environment,
            ])->ping();
        } catch (ConnectionException) {
            return back()->withErrors(['kurv_api' => 'Could not connect to Kurv. Check your network and try again.']);
        }

        $error = match (true) {
            $status >= 200 && $status < 300 => null,
            $status === 401 || $status === 403 => 'Wrong key or missing permission. Kurv did not accept this API key.',
            $status === 422 => 'Your Kurv plan does not allow this.',
            default => 'Kurv returned an unexpected reply. Please try again.',
        };

        return $error === null ? back() : back()->withErrors(['kurv_api' => $error]);
    }
}
