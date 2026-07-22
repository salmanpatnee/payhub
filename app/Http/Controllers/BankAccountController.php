<?php

namespace App\Http\Controllers;

use App\Enums\SupportedCurrency;
use App\Http\Requests\StoreBankAccountRequest;
use App\Http\Requests\UpdateBankAccountRequest;
use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BankAccountController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canManage = $user->hasRole('admin') || $user->hasRole('account');
        $isAgent = $user->hasRole('agent');

        $sort = $request->input('sort') === 'account_name' ? 'account_name' : 'bank_name';
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $search = $request->input('search');
        $currency = in_array($request->input('currency'), SupportedCurrency::values(), true)
            ? $request->input('currency')
            : null;

        return Inertia::render('bank-accounts/Index', [
            'canManage' => $canManage,
            'isAgent' => $isAgent,
            'bankAccounts' => $canManage
                ? BankAccount::withCount('assignedUsers')
                    ->when($search, fn ($q, $v) => $q->where(fn ($q2) => $q2
                        ->where('bank_name', 'LIKE', "%{$v}%")
                        ->orWhere('account_name', 'LIKE', "%{$v}%")))
                    ->when($currency, fn ($q, $v) => $q->where('currency', $v))
                    ->orderBy($sort, $direction)
                    ->get()
                    ->map(fn (BankAccount $account) => $this->rowData($account))
                : [],
            'myAccounts' => $isAgent
                ? $user->bankAccounts()
                    ->where('is_active', true)
                    ->orderBy('bank_name')
                    ->get()
                    ->map(fn (BankAccount $account) => $this->accountData($account))
                : [],
            'filters' => [
                'search' => $search,
                'currency' => $currency,
                'sort' => $sort,
                'direction' => $direction,
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', BankAccount::class);

        return Inertia::render('bank-accounts/Create', [
            'users' => User::role('agent')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreBankAccountRequest $request): RedirectResponse
    {
        Gate::authorize('create', BankAccount::class);

        $bankAccount = BankAccount::create($request->safe()->except('user_ids'));
        $bankAccount->assignedUsers()->sync($request->validated('user_ids', []));

        return redirect()->route('bank-accounts.index')
            ->with('success', 'Bank account saved.');
    }

    public function edit(BankAccount $bankAccount): Response
    {
        Gate::authorize('update', $bankAccount);

        $assignedUserIds = $bankAccount->assignedUsers()->pluck('users.id');

        return Inertia::render('bank-accounts/Edit', [
            'bankAccount' => array_merge(
                $this->accountData($bankAccount),
                ['user_ids' => $assignedUserIds],
            ),
            'users' => User::role('agent')
                ->orWhereIn('id', $assignedUserIds)
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    public function update(UpdateBankAccountRequest $request, BankAccount $bankAccount): RedirectResponse
    {
        Gate::authorize('update', $bankAccount);

        $bankAccount->fill($request->safe()->except('user_ids'));
        $bankAccount->save();
        $bankAccount->assignedUsers()->sync($request->validated('user_ids', []));

        return redirect()->route('bank-accounts.index')
            ->with('success', 'Bank account updated.');
    }

    public function destroy(BankAccount $bankAccount): RedirectResponse
    {
        Gate::authorize('delete', $bankAccount);

        $bankAccount->delete();

        return redirect()->route('bank-accounts.index')
            ->with('success', 'Bank account deleted.');
    }

    public function deactivate(BankAccount $bankAccount): RedirectResponse
    {
        Gate::authorize('update', $bankAccount);

        $bankAccount->update(['is_active' => false]);

        return redirect()->route('bank-accounts.index')
            ->with('success', 'Account deactivated.');
    }

    public function activate(BankAccount $bankAccount): RedirectResponse
    {
        Gate::authorize('update', $bankAccount);

        $bankAccount->update(['is_active' => true]);

        return redirect()->route('bank-accounts.index')
            ->with('success', 'Account activated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function rowData(BankAccount $account): array
    {
        return array_merge($this->accountData($account), [
            'assigned_users_count' => $account->assigned_users_count,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function accountData(BankAccount $account): array
    {
        return [
            'id' => $account->id,
            'bank_name' => $account->bank_name,
            'account_name' => $account->account_name,
            'account_number' => $account->account_number,
            'currency' => $account->currency->value,
            'sort_code' => $account->sort_code,
            'routing_number' => $account->routing_number,
            'iban' => $account->iban,
            'swift_bic' => $account->swift_bic,
            'bank_address' => $account->bank_address,
            'bank_country' => $account->bank_country,
            'is_active' => $account->is_active,
        ];
    }
}
