<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSquareAccountRequest;
use App\Http\Requests\Admin\UpdateSquareAccountRequest;
use App\Models\SquareAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Square\Environments;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;
use Square\Locations\Requests\GetLocationsRequest;
use Square\SquareClient;

class SquareAccountController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/square-accounts/Index', [
            'squareAccounts' => SquareAccount::orderBy('account_name')
                ->get()
                ->map(fn (SquareAccount $account) => [
                    'id' => $account->id,
                    'account_name' => $account->account_name,
                    'prefix' => $account->prefix,
                    'application_id_preview' => substr($account->application_id, 0, 14).'••••••••',
                    'environment' => $account->environment,
                    'currency' => $account->currency,
                    'is_active' => $account->is_active,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/square-accounts/Create');
    }

    public function store(StoreSquareAccountRequest $request): RedirectResponse
    {
        $square = $this->makeSquareClient($request->validated('access_token'), $request->validated('environment'));

        try {
            $currency = $this->fetchLocationCurrency($square, $request->validated('location_id'));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['square_api' => $e->getMessage()])->withInput();
        }

        $account = new SquareAccount($request->safe()->except(['access_token', 'webhook_signature_key']));
        $account->access_token = $request->validated('access_token');
        $account->currency = $currency;

        if ($request->filled('webhook_signature_key')) {
            $account->webhook_signature_key = $request->validated('webhook_signature_key');
        }

        $account->save();

        return redirect()->route('admin.square-accounts.index')
            ->with('success', 'Square account saved.');
    }

    public function edit(SquareAccount $squareAccount): Response
    {
        return Inertia::render('admin/square-accounts/Edit', [
            'squareAccount' => [
                'id' => $squareAccount->id,
                'account_name' => $squareAccount->account_name,
                'prefix' => $squareAccount->prefix,
                'application_id' => $squareAccount->application_id,
                'location_id' => $squareAccount->location_id,
                'environment' => $squareAccount->environment,
                'currency' => $squareAccount->currency,
                'is_active' => $squareAccount->is_active,
                'has_webhook_signature_key' => ! empty($squareAccount->webhook_signature_key),
                'webhook_endpoint_url' => route('webhook.square', $squareAccount),
                // access_token: NEVER included — not even masked
                // webhook_signature_key: NEVER included — use has_webhook_signature_key (bool) only
            ],
        ]);
    }

    public function update(UpdateSquareAccountRequest $request, SquareAccount $squareAccount): RedirectResponse
    {
        $accessToken = $request->filled('access_token') ? $request->validated('access_token') : $squareAccount->access_token;
        $locationId = $request->validated('location_id');

        $square = $this->makeSquareClient($accessToken, $request->validated('environment'));

        try {
            $currency = $this->fetchLocationCurrency($square, $locationId);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['square_api' => $e->getMessage()])->withInput();
        }

        if ($request->filled('access_token')) {
            $squareAccount->access_token = $request->validated('access_token');
        }

        if ($request->filled('webhook_signature_key')) {
            $squareAccount->webhook_signature_key = $request->validated('webhook_signature_key');
        }

        $squareAccount->fill($request->safe()->except(['access_token', 'webhook_signature_key']));
        $squareAccount->currency = $currency;
        $squareAccount->save();

        return redirect()->route('admin.square-accounts.index')
            ->with('success', 'Square account updated.');
    }

    public function deactivate(SquareAccount $squareAccount): RedirectResponse
    {
        $squareAccount->update(['is_active' => false]);

        return redirect()->route('admin.square-accounts.index')
            ->with('success', 'Account deactivated.');
    }

    public function activate(SquareAccount $squareAccount): RedirectResponse
    {
        $squareAccount->update(['is_active' => true]);

        return redirect()->route('admin.square-accounts.index')
            ->with('success', 'Account activated.');
    }

    public function destroy(SquareAccount $squareAccount): RedirectResponse
    {
        if ($squareAccount->payments()->exists()) {
            return redirect()->route('admin.square-accounts.index')
                ->with('error', 'Cannot delete an account that has payments.');
        }

        $squareAccount->delete();

        return redirect()->route('admin.square-accounts.index')
            ->with('success', 'Square account deleted.');
    }

    public function testKeyConnection(Request $request): RedirectResponse
    {
        $request->validate([
            'access_token' => 'required|string',
            'environment' => 'required|string|in:sandbox,production',
            'location_id' => 'required|string',
        ]);

        $error = $this->validateSquareAccessToken($request->input('access_token'), $request->input('environment'));

        if ($error) {
            return back()->withErrors(['square_api' => $error]);
        }

        try {
            $square = $this->makeSquareClient($request->input('access_token'), $request->input('environment'));
            $currency = $this->fetchLocationCurrency($square, $request->input('location_id'));
        } catch (\RuntimeException $e) {
            return back()->withErrors(['square_api' => $e->getMessage()]);
        }

        Inertia::flash('detected_currency', strtoupper($currency));

        return back();
    }

    public function testStoredConnection(SquareAccount $squareAccount): RedirectResponse
    {
        $error = $this->validateSquareAccessToken($squareAccount->access_token, $squareAccount->environment);

        if ($error) {
            return back()->withErrors(['square_api' => $error]);
        }

        try {
            $square = $this->makeSquareClient($squareAccount->access_token, $squareAccount->environment);
            $currency = $this->fetchLocationCurrency($square, $squareAccount->location_id);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['square_api' => $e->getMessage()]);
        }

        Inertia::flash('detected_currency', strtoupper($currency));

        return back();
    }

    /**
     * NEVER use a global Square token — always a per-account SquareClient.
     */
    private function validateSquareAccessToken(string $accessToken, string $environment): ?string
    {
        if (app()->environment('testing')) {
            return null;
        }

        try {
            $square = $this->makeSquareClient($accessToken, $environment);
            $square->locations->list();

            return null;
        } catch (SquareApiException $e) {
            return 'The access token could not be verified with Square. Check that it is correct and try again.';
        } catch (SquareException $e) {
            return 'Could not connect to Square to validate the token. Check your network and try again.';
        }
    }

    /**
     * Square locations are single-currency; fetch the location's currency
     * from Square so it can be persisted and used to lock payment creation.
     */
    private function fetchLocationCurrency(SquareClient $square, string $locationId): string
    {
        if (app()->environment('testing')) {
            return 'usd';
        }

        try {
            $location = $square->locations->get(new GetLocationsRequest(['locationId' => $locationId]))->getLocation();
        } catch (SquareApiException $e) {
            throw new \RuntimeException('Could not retrieve the location currency from Square. Check the location ID and try again.');
        } catch (SquareException $e) {
            throw new \RuntimeException('Could not connect to Square to retrieve the location currency. Check your network and try again.');
        }

        $currency = strtolower($location?->getCurrency() ?? '');

        if (! in_array($currency, ['usd', 'gbp'], true)) {
            $label = $currency !== '' ? strtoupper($currency) : 'an unknown currency';

            throw new \RuntimeException("This Square location processes in {$label}. PayHub only supports USD and GBP.");
        }

        return $currency;
    }

    private function makeSquareClient(string $accessToken, string $environment): SquareClient
    {
        return new SquareClient(
            $accessToken,
            options: ['baseUrl' => $environment === 'production'
                ? Environments::Production->value
                : Environments::Sandbox->value],
        );
    }
}
