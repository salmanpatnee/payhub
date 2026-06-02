<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Brand;
use App\Models\RelationshipManager;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users/Index', [
            'users' => User::with(['roles', 'stripeAccount'])
                ->orderBy('name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'roles' => $user->getRoleNames(),
                    'stripe_account_name' => $user->stripeAccount?->account_name,
                ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Create', [
            'roles' => Role::pluck('name'),
            'stripeAccounts' => StripeAccount::where('is_active', true)
                ->orderBy('account_name')
                ->get(['id', 'account_name']),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'relationshipManagers' => RelationshipManager::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->safe()->only('name', 'username', 'password');
        $data['stripe_account_id'] = $request->validated('role') === 'agent'
            ? $request->validated('stripe_account_id')
            : null;

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
                $user->only('id', 'name', 'username', 'stripe_account_id'),
                [
                    'roles' => $user->getRoleNames(),
                    'brand_ids' => $user->brands()->pluck('brands.id'),
                    'relationship_manager_ids' => $user->relationshipManagers()->pluck('relationship_managers.id'),
                ]
            ),
            'roles' => Role::pluck('name'),
            'stripeAccounts' => StripeAccount::where('is_active', true)
                ->orderBy('account_name')
                ->get(['id', 'account_name']),
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'relationshipManagers' => RelationshipManager::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->safe()->only('name', 'username');

        if ($request->filled('password')) {
            $data['password'] = $request->validated('password');
        }

        $data['stripe_account_id'] = $request->validated('role') === 'agent'
            ? $request->validated('stripe_account_id')
            : null;

        $user->update($data);
        $user->syncRoles([$request->validated('role')]);
        $this->syncMappings($user, $request);

        return redirect()->route('admin.users.index')
            ->with('success', 'User updated.');
    }

    /**
     * Sync an agent's brand and relationship-manager mappings.
     * Mappings are cleared for non-agent roles, mirroring stripe_account_id.
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
