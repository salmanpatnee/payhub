<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Models\Brand;
use App\Models\Payment;
use App\Models\StripeAccount;
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

        $query = Payment::with(['brand', 'stripeAccount', 'user'])
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
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v));

        return Inertia::render('payments/Index', [
            'payments' => $query->get()->map(fn (Payment $p) => [
                'id' => $p->id,
                'uuid' => $p->uuid,
                'amount' => $p->amount,
                'currency' => $p->currency,
                'brand_name' => $p->brand->name,
                'account_name' => $p->stripeAccount->account_name,
                'status' => $p->status,
                'created_at' => $p->created_at->toISOString(),
                'client_email' => $p->client_email,
                'client_name' => $p->client_name,
            ]),
            'filters' => $request->only(['brand_id', 'stripe_account_id', 'status', 'from', 'to']),
            'brands' => $isAdmin
                ? Brand::orderBy('name')->get(['id', 'name'])
                : [],
            'accounts' => $isAdmin
                ? StripeAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
                : [],
            'isAdmin' => $isAdmin,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('payments/Create', [
            'brands' => Brand::orderBy('name')->get(['id', 'name']),
            'stripeAccounts' => StripeAccount::where('is_active', true)
                ->orderBy('account_name')
                ->get(['id', 'account_name']),
        ]);
    }

    public function store(StorePaymentRequest $request): RedirectResponse
    {
        // $request->validated() contains amount already converted to integer cents
        // by StorePaymentRequest::passedValidation() — SEC-02 guarantee.
        // user_id and status are NEVER from the request.
        $payment = Payment::create([
            ...$request->validated(),
            'user_id' => auth()->id(),
            'status' => 'pending',
            'expires_at' => null,
        ]);

        return redirect()->route('payments.show', $payment);
    }

    public function show(Payment $payment): Response
    {
        $payment->loadMissing(['brand', 'stripeAccount']);

        return Inertia::render('payments/Show', [
            'payment' => [
                'uuid' => $payment->uuid,
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
                'created_at' => $payment->created_at->toISOString(),
                'stripe_payment_intent_id' => $payment->stripe_payment_intent_id,
                'paid_at' => $payment->paid_at?->toISOString(),
                'expires_at' => $payment->expires_at?->toISOString(),
            ],
        ]);
    }
}
