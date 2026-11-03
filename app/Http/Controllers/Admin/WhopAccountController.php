<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWhopAccountRequest;
use App\Http\Requests\Admin\UpdateWhopAccountRequest;
use App\Models\WhopAccount;
use App\Services\Whop\WhopClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WhopAccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/whop-accounts/Index', [
            'whopAccounts' => WhopAccount::orderBy('account_name')
                ->get()
                ->map(fn (WhopAccount $account) => [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'prefix' => $account->prefix,
                    'company_id' => $account->company_id,
                    'environment' => $account->environment,
                    'is_active' => $account->is_active,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/whop-accounts/Create');
    }

    public function store(StoreWhopAccountRequest $request): RedirectResponse
    {
        $account = new WhopAccount($request->safe()->except(['api_key', 'webhook_secret']));
        $account->api_key = $request->validated('api_key');

        if ($request->filled('webhook_secret')) {
            $account->webhook_secret = $request->validated('webhook_secret');
        }

        $account->save();

        return redirect()->route('admin.whop-accounts.index')
            ->with('success', 'Whop account saved.');
    }

    public function edit(WhopAccount $whopAccount): Response
    {
        return Inertia::render('admin/whop-accounts/Edit', [
            'whopAccount' => [
                'id' => $whopAccount->id,
                'account_name' => $whopAccount->account_name,
                'prefix' => $whopAccount->prefix,
                'company_id' => $whopAccount->company_id,
                'environment' => $whopAccount->environment,
                'is_active' => $whopAccount->is_active,
                'has_api_key' => ! empty($whopAccount->api_key),
                'has_webhook_secret' => ! empty($whopAccount->webhook_secret),
                'webhook_endpoint_url' => route('webhook.whop', $whopAccount),
                // api_key and webhook_secret: NEVER included, only the has_* flags
            ],
        ]);
    }

    public function update(UpdateWhopAccountRequest $request, WhopAccount $whopAccount): RedirectResponse
    {
        if ($request->filled('api_key')) {
            $whopAccount->api_key = $request->validated('api_key');
        }

        if ($request->filled('webhook_secret')) {
            $whopAccount->webhook_secret = $request->validated('webhook_secret');
        }

        $whopAccount->fill($request->safe()->except(['api_key', 'webhook_secret']));
        $whopAccount->save();

        return redirect()->route('admin.whop-accounts.index')
            ->with('success', 'Whop account updated.');
    }

    public function deactivate(WhopAccount $whopAccount): RedirectResponse
    {
        $whopAccount->update(['is_active' => false]);

        return redirect()->route('admin.whop-accounts.index')
            ->with('success', 'Account deactivated.');
    }

    public function activate(WhopAccount $whopAccount): RedirectResponse
    {
        $clash = WhopAccount::where('company_id', $whopAccount->company_id)
            ->where('is_active', true)
            ->whereKeyNot($whopAccount->id)
            ->exists();

        if ($clash) {
            return redirect()->route('admin.whop-accounts.index')
                ->with('error', 'Another active Whop account already uses this company ID.');
        }

        $whopAccount->update(['is_active' => true]);

        return redirect()->route('admin.whop-accounts.index')
            ->with('success', 'Account activated.');
    }

    public function destroy(WhopAccount $whopAccount): RedirectResponse
    {
        if ($whopAccount->payments()->exists()) {
            return redirect()->route('admin.whop-accounts.index')
                ->with('error', 'Cannot delete an account that has payments.');
        }

        $whopAccount->delete();

        return redirect()->route('admin.whop-accounts.index')
            ->with('success', 'Whop account deleted.');
    }

    /** Test the values typed on the Create form, before anything is saved. */
    public function testKeyConnection(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'api_key' => ['required', 'string', 'max:512'],
            'company_id' => ['required', 'string', 'max:255'],
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
        ]);

        return $this->connectionResult($data['api_key'], $data['company_id'], $data['environment']);
    }

    public function testStoredConnection(WhopAccount $whopAccount): RedirectResponse
    {
        return $this->connectionResult($whopAccount->api_key, $whopAccount->company_id, $whopAccount->environment);
    }

    /**
     * One read only Whop call with a per-account client, never a global key.
     * Replies are mapped to plain words (AC-1).
     */
    private function connectionResult(string $apiKey, string $companyId, string $environment): RedirectResponse
    {
        try {
            $status = app()->make(WhopClient::class, [
                'apiKey' => $apiKey,
                'companyId' => $companyId,
                'environment' => $environment,
            ])->companyStatus();
        } catch (ConnectionException) {
            return back()->withErrors(['whop_api' => 'Could not connect to Whop. Check your network and try again.']);
        }

        $error = match (true) {
            $status >= 200 && $status < 300 => null,
            $status === 401 => 'Wrong key. Whop did not accept this API key.',
            $status === 403 => 'Missing permission. This API key is not allowed to read the company.',
            $status === 404 => 'Wrong company ID. Whop could not find this company.',
            default => 'Whop returned an unexpected reply. Please try again.',
        };

        return $error === null ? back() : back()->withErrors(['whop_api' => $error]);
    }
}
