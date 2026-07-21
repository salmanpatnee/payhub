<?php

namespace App\Http\Controllers\Admin;

use App\Enums\SupportedCurrency;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Brand;
use App\Models\RelationshipManager;
use App\Models\RevolutAccount;
use App\Models\SquareAccount;
use App\Models\StripeAccount;
use App\Models\User;
use App\Models\UserPaymentAccount;
use App\Models\VivaAccount;
use App\Support\CurrencySupportResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): Response
    {
        $accountNames = $this->accountNameLookup();

        return Inertia::render('admin/users/Index', [
            'users' => User::with(['roles', 'paymentAccounts'])
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'roles' => $user->getRoleNames(),
                    'payment_accounts' => $user->paymentAccounts
                        ->map(fn (UserPaymentAccount $account) => [
                            'currency' => $account->currency->value,
                            'account_name' => $accountNames[$account->provider->value][$account->account_id] ?? null,
                        ])
                        ->values(),
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Create', [
            'roles' => Role::pluck('name'),
            'accountsByCurrency' => $this->activeAccountOptionsByCurrency(),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'relationshipManagers' => RelationshipManager::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->safe()->only('name', 'username', 'password');

        $user = User::create($data);
        $user->syncRoles([$request->validated('role')]);
        $this->syncMappings($user, $request);
        $this->syncPaymentAccounts($user, $request);

        return redirect()->route('admin.users.index')
            ->with('success', 'User created.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('admin/users/Edit', [
            'user' => array_merge(
                $user->only('id', 'name', 'username'),
                [
                    'payment_accounts' => $user->paymentAccounts
                        ->map(fn (UserPaymentAccount $account) => [
                            'currency' => $account->currency->value,
                            'provider' => $account->provider->value,
                            'account_id' => $account->account_id,
                        ])
                        ->values(),
                    'roles' => $user->getRoleNames(),
                    'brand_ids' => $user->brands()->pluck('brands.id'),
                    'relationship_manager_ids' => $user->relationshipManagers()->pluck('relationship_managers.id'),
                ]
            ),
            'roles' => Role::pluck('name'),
            'accountsByCurrency' => $this->activeAccountOptionsByCurrency(),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'relationshipManagers' => RelationshipManager::where('is_active', true)
                ->orWhereIn('id', $user->relationshipManagers()->pluck('relationship_managers.id'))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->safe()->only('name', 'username');

        if ($request->filled('password')) {
            $data['password'] = $request->validated('password');
        }

        $user->update($data);
        $user->syncRoles([$request->validated('role')]);
        $this->syncMappings($user, $request);
        $this->syncPaymentAccounts($user, $request);

        return redirect()->route('admin.users.index')
            ->with('success', 'User updated.');
    }

    /**
     * account_name per provider/account_id, so the users index can display a
     * per-currency payment-account summary without an N+1 per row.
     *
     * @return array<string, array<int, string>>
     */
    private function accountNameLookup(): array
    {
        return [
            'stripe' => StripeAccount::pluck('account_name', 'id')->all(),
            'revolut' => RevolutAccount::pluck('account_name', 'id')->all(),
            'square' => SquareAccount::pluck('account_name', 'id')->all(),
            'viva' => VivaAccount::pluck('account_name', 'id')->all(),
        ];
    }

    /**
     * Union of active Stripe + Revolut + Square + Viva accounts as
     * { id, account_name, provider, currency } for the agent payment-account selector.
     *
     * @return Collection<int, array{id: int, account_name: string, provider: string, currency: ?string}>
     */
    private function activeAccountOptions(): Collection
    {
        $stripe = StripeAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
            ->map(fn (StripeAccount $a) => ['id' => $a->id, 'account_name' => $a->account_name, 'provider' => 'stripe', 'currency' => null]);

        $revolut = RevolutAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
            ->map(fn (RevolutAccount $a) => ['id' => $a->id, 'account_name' => $a->account_name, 'provider' => 'revolut', 'currency' => null]);

        $square = SquareAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name', 'currency'])
            ->map(fn (SquareAccount $a) => ['id' => $a->id, 'account_name' => $a->account_name, 'provider' => 'square', 'currency' => $a->currency]);

        $viva = VivaAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
            ->map(fn (VivaAccount $a) => ['id' => $a->id, 'account_name' => $a->account_name, 'provider' => 'viva', 'currency' => null]);

        return $stripe->concat($revolut)->concat($square)->concat($viva)->values();
    }

    /**
     * Active accounts grouped by SupportedCurrency, for the two independent
     * per-currency payment-account selectors on the agent create/edit forms.
     *
     * @return array<string, array<int, array{id: int, account_name: string, provider: string}>>
     */
    private function activeAccountOptionsByCurrency(): array
    {
        $accounts = $this->activeAccountOptions();

        return collect(SupportedCurrency::cases())
            ->mapWithKeys(fn (SupportedCurrency $currency) => [
                $currency->value => $accounts
                    ->filter(fn (array $a) => CurrencySupportResolver::supports($a['provider'], $a['currency'], $currency->value))
                    ->map(fn (array $a) => ['id' => $a['id'], 'account_name' => $a['account_name'], 'provider' => $a['provider']])
                    ->values(),
            ])
            ->all();
    }

    /**
     * Sync an agent's brand and relationship-manager mappings.
     * Mappings are cleared for non-agent roles, mirroring the payment accounts.
     */
    private function syncMappings(User $user, StoreUserRequest|UpdateUserRequest $request): void
    {
        $isAgent = $request->validated('role') === 'agent';

        $user->brands()->sync($isAgent ? $request->validated('brand_ids', []) : []);
        $user->relationshipManagers()->sync($isAgent ? $request->validated('relationship_manager_ids', []) : []);
    }

    /**
     * Sync an agent's per-currency payment account assignments. Cleared for
     * non-agent roles, mirroring syncMappings(). Zero-currency agents are
     * allowed — this simply leaves no rows.
     */
    private function syncPaymentAccounts(User $user, StoreUserRequest|UpdateUserRequest $request): void
    {
        $isAgent = $request->validated('role') === 'agent';
        $entries = $isAgent ? $request->validated('payment_accounts', []) : [];

        $user->paymentAccounts()->delete();

        foreach ($entries as $entry) {
            $user->paymentAccounts()->create([
                'currency' => $entry['currency'],
                'provider' => $entry['provider'],
                'account_id' => $entry['account_id'],
            ]);
        }
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'Cannot delete your own account.']);
        }

        $user->delete();

        return redirect()->route('admin.users.index')
            ->with('success', 'User deleted.');
    }
}
