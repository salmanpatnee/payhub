<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function index(Request $request): Response
    {
        $user = auth()->user();
        $isAdmin = $user->hasRole('admin');

        $query = Payment::with(['brand', 'stripeAccount', 'user', 'relationshipManager'])
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
                'account_name' => $p->stripeAccount->account_name,
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
            if (! $user->stripe_account_id) {
                Inertia::flash('toast', ['type' => 'error', 'message' => 'No Stripe account assigned. Contact an admin.']);

                return redirect()->route('payments.index');
            }

            $stripeAccounts = StripeAccount::where('id', $user->stripe_account_id)->get(['id', 'account_name']);
            $isStripeAccountLocked = true;
        } else {
            $stripeAccounts = StripeAccount::where('is_active', true)
                ->orderBy('account_name')
                ->get(['id', 'account_name']);
            $isStripeAccountLocked = false;
        }

        return Inertia::render('payments/Create', [
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'stripeAccounts' => $stripeAccounts,
            'isStripeAccountLocked' => $isStripeAccountLocked,
            'relationshipManagers' => RelationshipManager::orderBy('name')->get(['id', 'name']),
        ]);
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

    public function show(Payment $payment): Response
    {
        $payment->loadMissing(['brand', 'stripeAccount', 'relationshipManager']);

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
