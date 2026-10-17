<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMpgsAccountRequest;
use App\Http\Requests\Admin\UpdateMpgsAccountRequest;
use App\Models\MpgsAccount;
use App\Services\Mpgs\MpgsClient;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MpgsAccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/mpgs-accounts/Index', [
            'mpgsAccounts' => MpgsAccount::orderBy('account_name')
                ->get()
                ->map(fn (MpgsAccount $account) => [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'prefix' => $account->prefix,
                    'merchant_id' => $account->merchant_id,
                    'api_version' => $account->api_version,
                    'has_webhook_secret' => ! empty($account->webhook_secret),
                    'is_active' => $account->is_active,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/mpgs-accounts/Create', [
            'defaultApiVersion' => (int) config('services.mpgs.api_version'),
        ]);
    }

    public function store(StoreMpgsAccountRequest $request): RedirectResponse
    {
        $error = $this->validateMpgsCredentials(
            $request->validated('merchant_id'),
            $request->validated('integration_password'),
            (int) $request->validated('api_version'),
        );

        if ($error) {
            return back()->withErrors(['mpgs_api' => $error])->withInput();
        }

        $account = new MpgsAccount($request->safe()->except(['integration_password', 'webhook_secret']));
        $account->integration_password = $request->validated('integration_password');
        if ($request->filled('webhook_secret')) {
            $account->webhook_secret = $request->validated('webhook_secret');
        }
        $account->save();

        return redirect()->route('admin.mpgs-accounts.index')
            ->with('success', 'MPGS account saved.');
    }

    public function edit(MpgsAccount $mpgsAccount): Response
    {
        return Inertia::render('admin/mpgs-accounts/Edit', [
            'mpgsAccount' => [
                'id' => $mpgsAccount->id,
                'account_name' => $mpgsAccount->account_name,
                'prefix' => $mpgsAccount->prefix,
                'merchant_id' => $mpgsAccount->merchant_id,
                'api_version' => $mpgsAccount->api_version,
                'is_active' => $mpgsAccount->is_active,
                'has_integration_password' => ! empty($mpgsAccount->integration_password),
                'has_webhook_secret' => ! empty($mpgsAccount->webhook_secret),
                // integration_password / webhook_secret: NEVER included — not even masked
            ],
            // Shown for copying into the gateway's notification settings. Plain url() until
            // the webhook route lands (build plan step 6), then switch to route('webhook.mpgs').
            'webhookUrl' => url("/webhook/mpgs/{$mpgsAccount->id}"),
        ]);
    }

    public function update(UpdateMpgsAccountRequest $request, MpgsAccount $mpgsAccount): RedirectResponse
    {
        $integrationPassword = $request->filled('integration_password')
            ? $request->validated('integration_password')
            : $mpgsAccount->integration_password;

        $error = $this->validateMpgsCredentials(
            $request->validated('merchant_id'),
            $integrationPassword,
            (int) $request->validated('api_version'),
        );

        if ($error) {
            return back()->withErrors(['mpgs_api' => $error])->withInput();
        }

        if ($request->filled('integration_password')) {
            $mpgsAccount->integration_password = $request->validated('integration_password');
        }

        if ($request->filled('webhook_secret')) {
            $mpgsAccount->webhook_secret = $request->validated('webhook_secret');
        }

        $mpgsAccount->fill($request->safe()->except(['integration_password', 'webhook_secret']));
        $mpgsAccount->save();

        return redirect()->route('admin.mpgs-accounts.index')
            ->with('success', 'MPGS account updated.');
    }

    public function deactivate(MpgsAccount $mpgsAccount): RedirectResponse
    {
        $mpgsAccount->update(['is_active' => false]);

        return redirect()->route('admin.mpgs-accounts.index')
            ->with('success', 'Account deactivated.');
    }

    public function activate(MpgsAccount $mpgsAccount): RedirectResponse
    {
        $mpgsAccount->update(['is_active' => true]);

        return redirect()->route('admin.mpgs-accounts.index')
            ->with('success', 'Account activated.');
    }

    public function destroy(MpgsAccount $mpgsAccount): RedirectResponse
    {
        if ($mpgsAccount->payments()->exists()) {
            return redirect()->route('admin.mpgs-accounts.index')
                ->with('error', 'Cannot delete an account that has payments.');
        }

        $mpgsAccount->delete();

        return redirect()->route('admin.mpgs-accounts.index')
            ->with('success', 'MPGS account deleted.');
    }

    public function testKeyConnection(Request $request): RedirectResponse
    {
        $request->validate([
            'merchant_id' => ['required', 'string'],
            'integration_password' => ['required', 'string'],
            'api_version' => ['required', 'integer', 'min:1', 'max:999'],
        ]);

        $error = $this->validateMpgsCredentials(
            $request->input('merchant_id'),
            $request->input('integration_password'),
            (int) $request->input('api_version'),
        );

        if ($error) {
            return back()->withErrors(['mpgs_api' => $error]);
        }

        return back()->with('success', 'MPGS credentials verified.');
    }

    public function testStoredConnection(MpgsAccount $mpgsAccount): RedirectResponse
    {
        $error = $this->validateMpgsCredentials(
            $mpgsAccount->merchant_id,
            $mpgsAccount->integration_password,
            $mpgsAccount->api_version,
        );

        if ($error) {
            return back()->withErrors(['mpgs_api' => $error]);
        }

        return back()->with('success', 'MPGS credentials verified.');
    }

    /**
     * NEVER use a global MPGS client — always a per-account MpgsClient.
     */
    private function validateMpgsCredentials(
        string $merchantId,
        string $integrationPassword,
        int $apiVersion,
    ): ?string {
        if (app()->environment('testing')) {
            return null;
        }

        try {
            $mpgs = app()->make(MpgsClient::class, [
                'merchantId' => $merchantId,
                'integrationPassword' => $integrationPassword,
                'apiVersion' => $apiVersion,
            ]);
            $mpgs->verifyCredentials();

            return null;
        } catch (RequestException $e) {
            return 'The credentials could not be verified with the MPGS gateway. Check that they are correct and try again.';
        } catch (\Throwable $e) {
            return 'Could not connect to the MPGS gateway to validate the credentials. Check your network and try again.';
        }
    }
}
