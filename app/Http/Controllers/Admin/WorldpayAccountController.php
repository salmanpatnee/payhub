<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWorldpayAccountRequest;
use App\Http\Requests\Admin\UpdateWorldpayAccountRequest;
use App\Models\WorldpayAccount;
use App\Services\Worldpay\WorldpayClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WorldpayAccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/worldpay-accounts/Index', [
            'worldpayAccounts' => WorldpayAccount::orderBy('account_name')
                ->get()
                ->map(fn (WorldpayAccount $account) => [
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
        return Inertia::render('admin/worldpay-accounts/Create');
    }

    public function store(StoreWorldpayAccountRequest $request): RedirectResponse
    {
        $account = new WorldpayAccount($request->safe()->except(['api_username', 'api_password', 'webhook_secret']));
        $account->api_username = $request->validated('api_username');
        $account->api_password = $request->validated('api_password');
        $account->webhook_secret = $request->filled('webhook_secret') ? $request->validated('webhook_secret') : null;
        $account->webhook_key_id = $request->filled('webhook_key_id') ? $request->validated('webhook_key_id') : null;
        $account->save();

        return redirect()->route('admin.worldpay-accounts.index')
            ->with('success', 'Worldpay account saved.');
    }

    public function edit(WorldpayAccount $worldpayAccount): Response
    {
        return Inertia::render('admin/worldpay-accounts/Edit', [
            'worldpayAccount' => [
                'id' => $worldpayAccount->id,
                'account_name' => $worldpayAccount->account_name,
                'prefix' => $worldpayAccount->prefix,
                'environment' => $worldpayAccount->environment,
                'entity_ref' => $worldpayAccount->entity_ref,
                'webhook_key_id' => $worldpayAccount->webhook_key_id,
                'is_active' => $worldpayAccount->is_active,
                'has_api_username' => ! empty($worldpayAccount->api_username),
                'has_api_password' => ! empty($worldpayAccount->api_password),
                'has_webhook_secret' => ! empty($worldpayAccount->webhook_secret),
                // api_username, api_password, webhook_secret: NEVER included, only the has_* flags
            ],
        ]);
    }

    public function update(UpdateWorldpayAccountRequest $request, WorldpayAccount $worldpayAccount): RedirectResponse
    {
        if ($request->filled('api_username')) {
            $worldpayAccount->api_username = $request->validated('api_username');
        }

        if ($request->filled('api_password')) {
            $worldpayAccount->api_password = $request->validated('api_password');
        }

        if ($request->filled('webhook_secret')) {
            $worldpayAccount->webhook_secret = $request->validated('webhook_secret');
        }

        $worldpayAccount->fill($request->safe()->only(['account_name', 'prefix', 'environment', 'entity_ref']));
        $worldpayAccount->webhook_key_id = $request->filled('webhook_key_id') ? $request->validated('webhook_key_id') : null;

        if (! $request->filled('webhook_key_id')) {
            $worldpayAccount->webhook_secret = null;
        }

        $worldpayAccount->save();

        return redirect()->route('admin.worldpay-accounts.index')
            ->with('success', 'Worldpay account updated.');
    }

    public function deactivate(WorldpayAccount $worldpayAccount): RedirectResponse
    {
        $worldpayAccount->update(['is_active' => false]);

        return redirect()->route('admin.worldpay-accounts.index')
            ->with('success', 'Account deactivated.');
    }

    public function activate(WorldpayAccount $worldpayAccount): RedirectResponse
    {
        $worldpayAccount->update(['is_active' => true]);

        return redirect()->route('admin.worldpay-accounts.index')
            ->with('success', 'Account activated.');
    }

    public function destroy(WorldpayAccount $worldpayAccount): RedirectResponse
    {
        if ($worldpayAccount->payments()->exists()) {
            return redirect()->route('admin.worldpay-accounts.index')
                ->with('error', 'Cannot delete an account that has payments.');
        }

        $worldpayAccount->delete();

        return redirect()->route('admin.worldpay-accounts.index')
            ->with('success', 'Worldpay account deleted.');
    }

    /**
     * Test the values typed on the form before anything is saved. On Edit a blank username or
     * password is filled from the saved account (`account_id`).
     */
    public function testKeyConnection(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'api_username' => ['nullable', 'string', 'max:255'],
            'api_password' => ['nullable', 'string', 'max:255'],
            'environment' => ['required', Rule::in(WorldpayAccount::ENVIRONMENTS)],
            'account_id' => ['nullable', 'integer', 'exists:worldpay_accounts,id'],
        ]);

        $saved = isset($data['account_id']) ? WorldpayAccount::find($data['account_id']) : null;
        $username = $data['api_username'] ?? $saved?->api_username;
        $password = $data['api_password'] ?? $saved?->api_password;

        if (blank($username) || blank($password)) {
            return back()->withErrors(['worldpay_api' => 'Enter the API username and password to test the connection.']);
        }

        return $this->connectionResult($username, $password, $data['environment']);
    }

    public function testStoredConnection(WorldpayAccount $worldpayAccount): RedirectResponse
    {
        return $this->connectionResult($worldpayAccount->api_username, $worldpayAccount->api_password, $worldpayAccount->environment);
    }

    /**
     * One read only Worldpay call with a per account client, never a global login. It creates
     * nothing at Worldpay. Replies are mapped to plain words (spec 0014, AC-2).
     */
    private function connectionResult(string $username, string $password, string $environment): RedirectResponse
    {
        try {
            $status = app()->make(WorldpayClient::class, [
                'username' => $username,
                'password' => $password,
                'environment' => $environment,
            ])->ping();
        } catch (ConnectionException) {
            Log::warning('Worldpay test connection could not connect');

            return back()->withErrors(['worldpay_api' => 'Could not reach Worldpay. Try again later.']);
        }

        $error = match (true) {
            $status >= 200 && $status < 300 => null,
            $status === 401 => 'Worldpay did not accept this username and password. Check they belong to this environment (Try or Live).',
            $status === 403 => 'This Worldpay user may not look up payments. Ask Worldpay to enable Payment Queries.',
            default => 'Could not reach Worldpay. Try again later.',
        };

        if ($error !== null && ! in_array($status, [401, 403], true)) {
            Log::warning('Worldpay test connection failed', ['http_status' => $status]);
        }

        return $error === null ? back() : back()->withErrors(['worldpay_api' => $error]);
    }
}
