<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuthorizenetEnvironment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAuthorizenetAccountRequest;
use App\Http\Requests\Admin\UpdateAuthorizenetAccountRequest;
use App\Models\AuthorizenetAccount;
use App\Services\Authorizenet\AuthorizenetClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AuthorizenetAccountController extends Controller
{
    private const USD_WARNING = ' Note: this Authorize.net account does not list USD as a currency, so USD payments may fail.';

    /** Set to USD_WARNING by validateCredentials() when the account's currency list lacks USD. */
    private string $usdWarning = '';

    public function index(): Response
    {
        return Inertia::render('admin/authorizenet-accounts/Index', [
            'authorizenetAccounts' => AuthorizenetAccount::orderBy('account_name')
                ->get()
                ->map(fn (AuthorizenetAccount $account) => [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'prefix' => $account->prefix,
                    'api_login_id' => $account->api_login_id,
                    'environment' => $account->environment->value,
                    'currency' => $account->currency,
                    'has_signature_key' => ! empty($account->signature_key),
                    'is_active' => $account->is_active,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/authorizenet-accounts/Create');
    }

    public function store(StoreAuthorizenetAccountRequest $request): RedirectResponse
    {
        $error = $this->validateCredentials(
            $request->validated('api_login_id'),
            $request->validated('transaction_key'),
            $request->validated('environment'),
        );

        if ($error) {
            return back()->withErrors(['authorizenet_api' => $error])->withInput();
        }

        $account = new AuthorizenetAccount($request->safe()->except(['transaction_key', 'signature_key']));
        $account->currency = 'usd';
        $account->transaction_key = $request->validated('transaction_key');
        if ($request->filled('signature_key')) {
            $account->signature_key = $request->validated('signature_key');
        }
        $account->save();

        return redirect()->route('admin.authorizenet-accounts.index')
            ->with('success', 'Authorize.net account saved.'.$this->usdWarning);
    }

    public function edit(AuthorizenetAccount $authorizenetAccount): Response
    {
        return Inertia::render('admin/authorizenet-accounts/Edit', [
            'authorizenetAccount' => [
                'id' => $authorizenetAccount->id,
                'account_name' => $authorizenetAccount->account_name,
                'prefix' => $authorizenetAccount->prefix,
                'api_login_id' => $authorizenetAccount->api_login_id,
                'environment' => $authorizenetAccount->environment->value,
                'currency' => $authorizenetAccount->currency,
                'is_active' => $authorizenetAccount->is_active,
                'has_transaction_key' => ! empty($authorizenetAccount->transaction_key),
                'has_signature_key' => ! empty($authorizenetAccount->signature_key),
                // transaction_key / signature_key: NEVER included — not even masked
            ],
            // Shown for reference: the admin adds this URL in the Authorize.net Merchant Interface.
            'webhookUrl' => url("/webhook/authorizenet/{$authorizenetAccount->id}"),
        ]);
    }

    public function update(UpdateAuthorizenetAccountRequest $request, AuthorizenetAccount $authorizenetAccount): RedirectResponse
    {
        $transactionKey = $request->filled('transaction_key')
            ? $request->validated('transaction_key')
            : $authorizenetAccount->transaction_key;

        $error = $this->validateCredentials(
            $request->validated('api_login_id'),
            $transactionKey,
            $request->validated('environment'),
        );

        if ($error) {
            return back()->withErrors(['authorizenet_api' => $error])->withInput();
        }

        if ($request->filled('transaction_key')) {
            $authorizenetAccount->transaction_key = $request->validated('transaction_key');
        }

        if ($request->filled('signature_key')) {
            $authorizenetAccount->signature_key = $request->validated('signature_key');
        }

        $authorizenetAccount->fill($request->safe()->except(['transaction_key', 'signature_key']));
        $authorizenetAccount->save();

        return redirect()->route('admin.authorizenet-accounts.index')
            ->with('success', 'Authorize.net account updated.'.$this->usdWarning);
    }

    public function deactivate(AuthorizenetAccount $authorizenetAccount): RedirectResponse
    {
        $authorizenetAccount->update(['is_active' => false]);

        return redirect()->route('admin.authorizenet-accounts.index')
            ->with('success', 'Account deactivated.');
    }

    public function activate(AuthorizenetAccount $authorizenetAccount): RedirectResponse
    {
        $authorizenetAccount->update(['is_active' => true]);

        return redirect()->route('admin.authorizenet-accounts.index')
            ->with('success', 'Account activated.');
    }

    public function destroy(AuthorizenetAccount $authorizenetAccount): RedirectResponse
    {
        if ($authorizenetAccount->payments()->exists()) {
            return redirect()->route('admin.authorizenet-accounts.index')
                ->with('error', 'Cannot delete an account that has payments.');
        }

        $authorizenetAccount->delete();

        return redirect()->route('admin.authorizenet-accounts.index')
            ->with('success', 'Authorize.net account deleted.');
    }

    public function testKeyConnection(Request $request): RedirectResponse
    {
        $request->validate([
            'api_login_id' => ['required', 'string'],
            'transaction_key' => ['required', 'string'],
            'environment' => ['required', Rule::enum(AuthorizenetEnvironment::class)],
        ]);

        $error = $this->validateCredentials(
            $request->input('api_login_id'),
            $request->input('transaction_key'),
            $request->input('environment'),
        );

        if ($error) {
            return back()->withErrors(['authorizenet_api' => $error]);
        }

        return back()->with('success', 'Authorize.net credentials verified.'.$this->usdWarning);
    }

    public function testStoredConnection(AuthorizenetAccount $authorizenetAccount): RedirectResponse
    {
        $error = $this->validateCredentials(
            $authorizenetAccount->api_login_id,
            $authorizenetAccount->transaction_key,
            $authorizenetAccount->environment->value,
        );

        if ($error) {
            return back()->withErrors(['authorizenet_api' => $error]);
        }

        return back()->with('success', 'Authorize.net credentials verified.'.$this->usdWarning);
    }

    /**
     * NEVER use a global Authorize.net client — always a per-account AuthorizenetClient.
     * One authenticated getMerchantDetails call; never touches a payment.
     */
    private function validateCredentials(string $apiLoginId, string $transactionKey, string $environment): ?string
    {
        try {
            $client = app()->make(AuthorizenetClient::class, [
                'apiLoginId' => $apiLoginId,
                'transactionKey' => $transactionKey,
                'environment' => AuthorizenetEnvironment::from($environment),
            ]);
            $result = $client->getMerchantDetails();
        } catch (\Throwable $e) {
            return 'Could not connect to Authorize.net to validate the credentials. Check your network and try again.';
        }

        if (! AuthorizenetClient::isOk($result)) {
            return 'The credentials could not be verified with Authorize.net. Check that they are correct and try again.';
        }

        $currencies = $result['body']['currencies'] ?? null;
        if (is_array($currencies) && $currencies !== [] && ! in_array('USD', $currencies, true)) {
            $this->usdWarning = self::USD_WARNING;
        }

        return null;
    }
}
