<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\SquareAccount;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = auth()->user();
        $isAdmin = $user->hasRole('admin');

        $query = Payment::with(['brand', 'stripeAccount', 'squareAccount', 'user', 'relationshipManager'])
            ->orderByDesc('created_at');

        if (! $isAdmin) {
            $query->where('user_id', $user->id);
        }

        // Optional filters — all applied via ->when() with Eloquent parameterised queries.
        // Non-admin user_id scope is applied unconditionally above, so even if a non-admin
        // sends brand_id or stripe_account_id in the URL, they can only ever see their own
        // payments (T-07-01, ASVS V4 horizontal privilege escalation prevention).
        $query
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

        return Inertia::render('payments/Index', [
            'payments' => $query->paginate(20)->through(fn (Payment $p) => [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'reference_code' => $p->reference_code,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'brand_name' => $p->brand->name,
                'provider' => $p->provider,
                'account_name' => $p->account_name,
                'status' => $p->status,
                'created_at' => $p->created_at->toISOString(),
                'client_email' => $p->client_email,
                'client_name' => $p->client_name,
            ]),
            'filters' => $request->only(['brand_id', 'stripe_account_id', 'relationship_manager_id', 'status', 'from', 'to', 'search']),
            'brands' => $isAdmin
                ? Brand::orderBy('name')->get(['id', 'name'])
                : [],
            'accounts' => $isAdmin
                ? StripeAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
                : [],
            'isAdmin' => $isAdmin,
            'relationshipManagers' => RelationshipManager::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(): Response|RedirectResponse
    {
        /** @var User $user */
        $user = auth()->user();

        if ($user->hasRole('agent')) {
            // Agents are locked to a single assigned account (Stripe OR Square) for failover.
            $lockedAccount = $this->resolveAgentLockedAccount($user);

            if ($lockedAccount === null) {
                Inertia::flash('toast', ['type' => 'error', 'message' => 'No payment account assigned. Contact an admin.']);

                return redirect()->route('payments.index');
            }

            $brands = $user->brands()->orderBy('name')->get(['brands.id', 'name']);
            $relationshipManagers = $user->relationshipManagers()->orderBy('name')->get(['relationship_managers.id', 'name']);

            if ($brands->isEmpty() || $relationshipManagers->isEmpty()) {
                Inertia::flash('toast', ['type' => 'error', 'message' => 'No brands or relationship managers assigned. Contact an admin.']);

                return redirect()->route('payments.index');
            }

            $paymentAccounts = [$lockedAccount];
            $isAccountLocked = true;
        } else {
            $paymentAccounts = $this->paymentAccountOptions();
            $isAccountLocked = false;
            $brands = Brand::orderBy('name')->get(['id', 'name']);
            $relationshipManagers = RelationshipManager::orderBy('name')->get(['id', 'name']);
        }

        return Inertia::render('payments/Create', [
            'brands' => $brands,
            'paymentAccounts' => $paymentAccounts,
            'isAccountLocked' => $isAccountLocked,
            'relationshipManagers' => $relationshipManagers,
        ]);
    }

    public function store(StorePaymentRequest $request): RedirectResponse
    {
        // SEC-02: amount is integer cents from StorePaymentRequest::validated().
        // provider + the correct account FK are resolved in StorePaymentRequest
        // (agents are locked server-side there). user_id and status are NEVER from the request.
        $data = $request->validated();

        /** @var User $user */
        $user = auth()->user();

        $payment = Payment::create([
            ...$data,
            'user_id' => $user->id,
            'status' => 'pending',
            'expires_at' => null,
        ]);

        return redirect()->route('payments.show', $payment);
    }

    /**
     * Merged dropdown options: active Stripe + active Square accounts, each provider-labeled.
     *
     * @return array<int, array{value: string, label: string, provider: string}>
     */
    private function paymentAccountOptions(): array
    {
        $stripe = StripeAccount::where('is_active', true)
            ->orderBy('account_name')
            ->get(['id', 'account_name'])
            ->map(fn (StripeAccount $a) => [
                'value' => 'stripe:'.$a->id,
                'label' => $a->account_name.' (Stripe)',
                'provider' => 'stripe',
            ]);

        $square = SquareAccount::where('is_active', true)
            ->orderBy('account_name')
            ->get(['id', 'account_name'])
            ->map(fn (SquareAccount $a) => [
                'value' => 'square:'.$a->id,
                'label' => $a->account_name.' (Square)',
                'provider' => 'square',
            ]);

        return $stripe->concat($square)->values()->all();
    }

    /**
     * Resolve an agent's single assigned account (Stripe takes precedence) as a dropdown option.
     *
     * @return array{value: string, label: string, provider: string}|null
     */
    private function resolveAgentLockedAccount(User $user): ?array
    {
        if ($user->stripe_account_id && ($account = StripeAccount::find($user->stripe_account_id))) {
            return [
                'value' => 'stripe:'.$account->id,
                'label' => $account->account_name.' (Stripe)',
                'provider' => 'stripe',
            ];
        }

        if ($user->square_account_id && ($account = SquareAccount::find($user->square_account_id))) {
            return [
                'value' => 'square:'.$account->id,
                'label' => $account->account_name.' (Square)',
                'provider' => 'square',
            ];
        }

        return null;
    }

    public function show(Payment $payment): Response
    {
        Gate::authorize('view', $payment);
        $payment->loadMissing(['brand', 'stripeAccount', 'squareAccount', 'relationshipManager']);

        return Inertia::render('payments/Show', [
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
                'provider' => $payment->provider,
                'account_name' => $payment->account_name,
                'relationship_manager_name' => $payment->relationshipManager?->name,
                'created_at' => $payment->created_at->toISOString(),
                'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
                'square_payment_id' => $payment->square_payment_id,
                'paid_at' => $payment->paid_at?->toISOString(),
                'expires_at' => $payment->expires_at?->toISOString(),
            ],
        ]);
    }
}
