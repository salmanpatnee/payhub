<?php

namespace App\Http\Controllers;

use App\Enums\SupportedCurrency;
use App\Http\Requests\StoreZelleAccountRequest;
use App\Http\Requests\UpdateZelleAccountRequest;
use App\Models\User;
use App\Models\ZelleAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ZelleAccountController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $canManage = $user->hasRole('admin') || $user->hasRole('account');
        $isAgent = $user->hasRole('agent');

        $search = trim((string) $request->input('search'));
        $currency = in_array($request->input('currency'), SupportedCurrency::values(), true)
            ? $request->input('currency')
            : null;
        $status = in_array($request->input('status'), ['active', 'inactive'], true)
            ? $request->input('status')
            : null;

        return Inertia::render('zelle-accounts/Index', [
            'canManage' => $canManage,
            'isAgent' => $isAgent,
            'zelleAccounts' => $canManage
                ? ZelleAccount::withCount('assignedUsers')
                    ->when($search !== '', fn (Builder $q) => $this->applySearch($q, $search))
                    ->when($currency, fn (Builder $q, string $v) => $q->where('currency', $v))
                    ->when($status, fn (Builder $q, string $v) => $q->where('is_active', $v === 'active'))
                    ->orderBy('account_name')
                    ->orderBy('id')
                    ->paginate(15)
                    ->withQueryString()
                    ->through(fn (ZelleAccount $account) => $this->rowData($account))
                : null,
            'myAccounts' => $isAgent
                ? $user->zelleAccounts()
                    ->where('is_active', true)
                    ->orderBy('account_name')
                    ->get()
                    ->map(fn (ZelleAccount $account) => $this->accountData($account))
                : [],
            'filters' => [
                'search' => $search === '' ? null : $search,
                'currency' => $currency,
                'status' => $status,
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', ZelleAccount::class);

        return Inertia::render('zelle-accounts/Create', [
            'users' => User::role('agent')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreZelleAccountRequest $request): RedirectResponse
    {
        Gate::authorize('create', ZelleAccount::class);

        DB::transaction(function () use ($request): void {
            $zelleAccount = ZelleAccount::create($request->safe()->except('user_ids'));
            $zelleAccount->assignedUsers()->sync($request->validated('user_ids', []));
        });

        return redirect()->route('zelle-accounts.index')
            ->with('success', 'Zelle account saved.');
    }

    public function edit(ZelleAccount $zelleAccount): Response
    {
        Gate::authorize('update', $zelleAccount);

        return Inertia::render('zelle-accounts/Edit', [
            'zelleAccount' => array_merge(
                $this->accountData($zelleAccount),
                ['user_ids' => $zelleAccount->assignedUsers()->pluck('users.id')],
            ),
            'users' => User::role('agent')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateZelleAccountRequest $request, ZelleAccount $zelleAccount): RedirectResponse
    {
        Gate::authorize('update', $zelleAccount);

        DB::transaction(function () use ($request, $zelleAccount): void {
            $zelleAccount->update($request->safe()->except('user_ids'));

            // A missing key leaves assignments alone; an empty array clears them.
            if ($request->has('user_ids')) {
                $zelleAccount->assignedUsers()->sync($request->validated('user_ids', []));
            }
        });

        return redirect()->route('zelle-accounts.index')
            ->with('success', 'Zelle account updated.');
    }

    public function destroy(ZelleAccount $zelleAccount): RedirectResponse
    {
        Gate::authorize('delete', $zelleAccount);

        $zelleAccount->delete();

        return redirect()->route('zelle-accounts.index')
            ->with('success', 'Zelle account deleted.');
    }

    public function deactivate(ZelleAccount $zelleAccount): RedirectResponse
    {
        Gate::authorize('update', $zelleAccount);

        $zelleAccount->update(['is_active' => false]);

        return redirect()->back()->with('success', 'Account deactivated.');
    }

    public function activate(ZelleAccount $zelleAccount): RedirectResponse
    {
        Gate::authorize('update', $zelleAccount);

        $zelleAccount->update(['is_active' => true]);

        return redirect()->back()->with('success', 'Account activated.');
    }

    /**
     * Match part of the email, or part of the mobile number ignoring spaces, dashes and brackets.
     * LIKE wildcards in the search text are escaped so they match literally.
     *
     * @param  Builder<ZelleAccount>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        $escape = fn (string $v): string => preg_replace('/[!%_]/', '!$0', $v);
        $emailTerm = '%'.$escape(mb_strtolower($search)).'%';
        $mobileTerm = $escape(str_replace([' ', '-', '(', ')'], '', $search));

        $query->where(function (Builder $q) use ($emailTerm, $mobileTerm): void {
            $q->whereRaw("email LIKE ? ESCAPE '!'", [$emailTerm]);

            if ($mobileTerm !== '') {
                $q->orWhereRaw(
                    "REPLACE(REPLACE(REPLACE(REPLACE(mobile_number, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ? ESCAPE '!'",
                    ["%{$mobileTerm}%"],
                );
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function rowData(ZelleAccount $account): array
    {
        return array_merge($this->accountData($account), [
            'assigned_users_count' => $account->assigned_users_count,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function accountData(ZelleAccount $account): array
    {
        return [
            'id' => $account->id,
            'account_name' => $account->account_name,
            'email' => $account->email,
            'mobile_number' => $account->mobile_number,
            'currency' => $account->currency->value,
            'is_active' => $account->is_active,
        ];
    }
}
