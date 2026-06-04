<?php

namespace App\Http\Controllers;

use App\Exports\PaymentsExport;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = auth()->user();
        $isAdmin = $user->hasRole('admin');
        $isAccount = $user->hasRole('account');
        $canViewAll = $isAdmin || $isAccount;

        $query = $this->filteredPaymentsQuery($request, $user);

        return Inertia::render('payments/Index', [
            'payments' => $query->paginate(20)->through(fn (Payment $p) => [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'reference_code' => $p->reference_code,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'brand_name' => $p->brand->name,
                'account_name' => $p->stripeAccount?->account_name,
                'relationship_manager_name' => $p->relationshipManager?->name,
                'status' => $p->status,
                'created_at' => $p->created_at->toISOString(),
                'client_email' => $p->client_email,
                'client_name' => $p->client_name,
            ]),
            'filters' => $request->only(['brand_id', 'stripe_account_id', 'relationship_manager_id', 'status', 'from', 'to', 'search']),
            'brands' => $canViewAll
                ? Brand::orderBy('name')->get(['id', 'name'])
                : [],
            'accounts' => $isAdmin
                ? StripeAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
                : [],
            'isAdmin' => $isAdmin,
            'readOnly' => $isAccount,
            'canExport' => $canViewAll,
            'relationshipManagers' => RelationshipManager::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Build the role-scoped, filtered payments query shared by the index listing
     * and the Excel export so both always return an identical set of rows.
     *
     * The non-admin user_id scope is applied unconditionally, so even if a
     * non-admin sends brand_id/stripe_account_id in the URL they can only ever
     * see their own payments (T-07-01, ASVS V4 horizontal privilege escalation).
     */
    private function filteredPaymentsQuery(Request $request, User $user): Builder
    {
        $canViewAll = $user->hasRole('admin') || $user->hasRole('account');

        $query = Payment::with(['brand', 'stripeAccount', 'user', 'relationshipManager'])
            ->orderByDesc('created_at');

        if (! $canViewAll) {
            $query->where('user_id', $user->id);
        }

        return $query
            ->when($request->brand_id, fn ($q, $v) => $q->where('brand_id', $v))
            ->when($request->stripe_account_id, fn ($q, $v) => $q->where('stripe_account_id', $v))
            ->when($request->relationship_manager_id, fn ($q, $v) => $q->where('relationship_manager_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($request->search, fn ($q, $v) => $q->where(function ($inner) use ($v): void {
                $inner->where('client_name', 'LIKE', "%{$v}%")
                    ->orWhere('client_email', 'LIKE', "%{$v}%")
                    ->orWhere('uuid', 'LIKE', strtolower($v).'%');

                $refSearch = ltrim(ltrim(trim($v), '#'), '0');
                if ($refSearch !== '' && ctype_digit($refSearch)) {
                    $inner->orWhere('reference_code', (int) $refSearch);
                }
            }));
    }

    public function export(Request $request): BinaryFileResponse
    {
        Gate::authorize('export', Payment::class);

        $query = $this->filteredPaymentsQuery($request, auth()->user());

        return Excel::download(
            new PaymentsExport($query),
            'payments-'.now()->format('Y-m-d').'.xlsx'
        );
    }

    public function create(): Response|RedirectResponse
    {
        Gate::authorize('create', Payment::class);

        /** @var User $user */
        $user = auth()->user();

        $options = $this->formOptions($user);

        if ($options instanceof RedirectResponse) {
            return $options;
        }

        return Inertia::render('payments/Create', $options);
    }

    /**
     * Load the brand / Stripe account / relationship manager options for the
     * create and edit forms. Agents are restricted to their assigned resources;
     * returns a RedirectResponse if the agent has no Stripe account, brands, or RMs.
     *
     * Inactive relationship managers are hidden from selection, except the
     * payment's currently-assigned RM ($currentRmId) which is always included
     * so historical records remain editable.
     *
     * @return array{brands: mixed, stripeAccounts: mixed, isStripeAccountLocked: bool, relationshipManagers: mixed}|RedirectResponse
     */
    private function formOptions(User $user, ?int $currentRmId = null): array|RedirectResponse
    {
        if ($user->hasRole('agent')) {
            if (! $user->stripe_account_id) {
                Inertia::flash('toast', ['type' => 'error', 'message' => 'No Stripe account assigned. Contact an admin.']);

                return redirect()->route('payments.index');
            }

            $brands = $user->brands()->orderBy('name')->get(['brands.id', 'name']);
            $relationshipManagers = $user->relationshipManagers()
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('relationship_managers.id', $currentRmId))
                ->orderBy('name')
                ->get(['relationship_managers.id', 'name']);

            if ($brands->isEmpty() || $relationshipManagers->isEmpty()) {
                Inertia::flash('toast', ['type' => 'error', 'message' => 'No brands or relationship managers assigned. Contact an admin.']);

                return redirect()->route('payments.index');
            }

            $stripeAccounts = StripeAccount::where('id', $user->stripe_account_id)->get(['id', 'account_name']);
            $isStripeAccountLocked = true;
        } else {
            $stripeAccounts = StripeAccount::where('is_active', true)
                ->orderBy('account_name')
                ->get(['id', 'account_name']);
            $isStripeAccountLocked = false;
            $brands = Brand::orderBy('name')->get(['id', 'name']);
            $relationshipManagers = RelationshipManager::where(fn ($q) => $q->where('is_active', true)->orWhere('id', $currentRmId))
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        return [
            'brands' => $brands,
            'stripeAccounts' => $stripeAccounts,
            'isStripeAccountLocked' => $isStripeAccountLocked,
            'relationshipManagers' => $relationshipManagers,
        ];
    }

    public function store(StorePaymentRequest $request): RedirectResponse
    {
        // SEC-02: amount is integer cents from StorePaymentRequest::validated().
        // user_id and status are NEVER from the request.
        $data = $request->validated();

        /** @var User $user */
        $user = auth()->user();

        if ($user->hasRole('agent')) {
            $data['stripe_account_id'] = $user->stripe_account_id;
        }

        $payment = Payment::create([
            ...$data,
            'user_id' => $user->id,
            'status' => 'pending',
            'expires_at' => null,
        ]);

        return redirect()->route('payments.show', $payment);
    }

    public function edit(Payment $payment): Response|RedirectResponse
    {
        Gate::authorize('update', $payment);

        /** @var User $user */
        $user = auth()->user();

        $options = $this->formOptions($user, $payment->relationship_manager_id);

        if ($options instanceof RedirectResponse) {
            return $options;
        }

        return Inertia::render('payments/Edit', [
            ...$options,
            'payment' => [
                'uuid' => $payment->uuid,
                'brand_id' => $payment->brand_id,
                'stripe_account_id' => $payment->stripe_account_id,
                'relationship_manager_id' => $payment->relationship_manager_id,
                'currency' => $payment->currency,
                // Cents → decimal string for the amount input.
                'amount' => number_format($payment->amount / 100, 2, '.', ''),
                'client_name' => $payment->client_name,
                'client_email' => $payment->client_email,
                'service' => $payment->service,
                'package' => $payment->package,
                'note' => $payment->note,
            ],
        ]);
    }

    public function update(UpdatePaymentRequest $request, Payment $payment): RedirectResponse
    {
        // PaymentPolicy::update gates this to admin|creator AND status='pending'.
        Gate::authorize('update', $payment);

        // SEC-02: amount is integer cents from UpdatePaymentRequest::validated().
        $data = $request->validated();

        /** @var User $user */
        $user = auth()->user();

        if ($user->hasRole('agent')) {
            $data['stripe_account_id'] = $user->stripe_account_id;
        }

        // status, user_id, and stripe_payment_intent_id are never updated here.
        $payment->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payment updated.']);

        return redirect()->route('payments.show', $payment);
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        Gate::authorize('delete', $payment);

        $payment->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payment deleted.']);

        return redirect()->route('payments.index');
    }

    public function show(Payment $payment): Response
    {
        Gate::authorize('view', $payment);
        $payment->loadMissing(['brand', 'stripeAccount', 'relationshipManager']);

        return Inertia::render('payments/Show', [
            'isAdmin' => auth()->user()->hasRole('admin'),
            'payment' => [
                'uuid' => $payment->uuid,
                'reference_code' => $payment->reference_code,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'client_name' => $payment->client_name,
                'client_email' => $payment->client_email,
                'service' => $payment->service,
                'package' => $payment->package,
                'note' => $payment->note,
                'brand_name' => $payment->brand->name,
                'account_name' => $payment->stripeAccount->account_name,
                'relationship_manager_name' => $payment->relationshipManager?->name,
                'created_at' => $payment->created_at->toISOString(),
                'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
                'paid_at' => $payment->paid_at?->toISOString(),
                'expires_at' => $payment->expires_at?->toISOString(),
            ],
        ]);
    }
}
