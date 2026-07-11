<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Brand;
use App\Models\RelationshipManager;
use App\Models\RevolutAccount;
use App\Models\SquareAccount;
use App\Models\StripeAccount;
use App\Models\User;
use App\Models\VivaAccount;
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
        return Inertia::render('admin/users/Index', [
            'users' => User::with(['roles', 'stripeAccount', 'revolutAccount', 'squareAccount', 'vivaAccount'])
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'roles' => $user->getRoleNames(),
                    'account_name' => $user->stripeAccount?->account_name
                        ?? $user->revolutAccount?->account_name
                        ?? $user->squareAccount?->account_name
                        ?? $user->vivaAccount?->account_name,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Create', [
            'roles' => Role::pluck('name'),
            'accounts' => $this->activeAccountOptions(),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'relationshipManagers' => RelationshipManager::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->safe()->only('name', 'username', 'password');
        $data = [...$data, ...$this->resolveAccountColumns($request)];

        $user = User::create($data);
        $user->syncRoles([$request->validated('role')]);
        $this->syncMappings($user, $request);

        return redirect()->route('admin.users.index')
            ->with('success', 'User created.');
    }

    public function edit(User $user): Response
    {
        return Inertia::render('admin/users/Edit', [
            'user' => array_merge(
                $user->only('id', 'name', 'username'),
                [
                    'provider' => $user->stripe_account_id ? 'stripe'
                        : ($user->revolut_account_id ? 'revolut'
                            : ($user->square_account_id ? 'square'
                                : ($user->viva_account_id ? 'viva' : null))),
                    'account_id' => $user->stripe_account_id ?? $user->revolut_account_id ?? $user->square_account_id ?? $user->viva_account_id,
                    'roles' => $user->getRoleNames(),
                    'brand_ids' => $user->brands()->pluck('brands.id'),
                    'relationship_manager_ids' => $user->relationshipManagers()->pluck('relationship_managers.id'),
                ]
            ),
            'roles' => Role::pluck('name'),
            'accounts' => $this->activeAccountOptions(),
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

        $data = [...$data, ...$this->resolveAccountColumns($request)];

        $user->update($data);
        $user->syncRoles([$request->validated('role')]);
        $this->syncMappings($user, $request);

        return redirect()->route('admin.users.index')
            ->with('success', 'User updated.');
    }

    /**
     * Union of active Stripe + Revolut + Square accounts as
     * { id, account_name, provider } for the agent payment-account selector.
     *
     * @return Collection<int, array{id: int, account_name: string, provider: string}>
     */
    private function activeAccountOptions(): Collection
    {
        $stripe = StripeAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
            ->map(fn (StripeAccount $a) => ['id' => $a->id, 'account_name' => $a->account_name, 'provider' => 'stripe']);

        $revolut = RevolutAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
            ->map(fn (RevolutAccount $a) => ['id' => $a->id, 'account_name' => $a->account_name, 'provider' => 'revolut']);

        $square = SquareAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
            ->map(fn (SquareAccount $a) => ['id' => $a->id, 'account_name' => $a->account_name, 'provider' => 'square']);

        $viva = VivaAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
            ->map(fn (VivaAccount $a) => ['id' => $a->id, 'account_name' => $a->account_name, 'provider' => 'viva']);

        return $stripe->concat($revolut)->concat($square)->concat($viva)->values();
    }

    /**
     * Resolve the payment-account FK columns from the request. Only agents carry
     * an account; for other roles all columns are cleared.
     *
     * @return array{stripe_account_id: ?int, revolut_account_id: ?int, square_account_id: ?int, viva_account_id: ?int}
     */
    private function resolveAccountColumns(StoreUserRequest|UpdateUserRequest $request): array
    {
        if ($request->validated('role') !== 'agent') {
            return ['stripe_account_id' => null, 'revolut_account_id' => null, 'square_account_id' => null, 'viva_account_id' => null];
        }

        $provider = $request->validated('provider');
        $accountId = (int) $request->validated('account_id');

        return [
            'stripe_account_id' => $provider === 'stripe' ? $accountId : null,
            'revolut_account_id' => $provider === 'revolut' ? $accountId : null,
            'square_account_id' => $provider === 'square' ? $accountId : null,
            'viva_account_id' => $provider === 'viva' ? $accountId : null,
        ];
    }

    /**
     * Sync an agent's brand and relationship-manager mappings.
     * Mappings are cleared for non-agent roles, mirroring the payment account.
     */
    private function syncMappings(User $user, StoreUserRequest|UpdateUserRequest $request): void
    {
        $isAgent = $request->validated('role') === 'agent';

        $user->brands()->sync($isAgent ? $request->validated('brand_ids', []) : []);
        $user->relationshipManagers()->sync($isAgent ? $request->validated('relationship_manager_ids', []) : []);
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
