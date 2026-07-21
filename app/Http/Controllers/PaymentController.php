<?php

namespace App\Http\Controllers;

use App\Enums\PaymentProvider;
use App\Enums\SupportedCurrency;
use App\Exports\PaymentsExport;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\UpdatePaymentRequest;
use App\Models\Brand;
use App\Models\Payment;
use App\Models\RelationshipManager;
use App\Models\RevolutAccount;
use App\Models\SquareAccount;
use App\Models\StripeAccount;
use App\Models\User;
use App\Models\VivaAccount;
use App\Support\ProviderAccountTable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
                'reference_code' => $p->formattedReferenceCode(),
                'amount' => $p->amount,
                'currency' => $p->currency,
                'brand_name' => $p->brand->name,
                'provider' => $p->provider->value,
                'account_name' => $canViewAll ? $p->providerAccountName() : null,
                'relationship_manager_name' => $p->relationshipManager?->name,
                'status' => $p->status,
                'created_at' => $p->created_at->toISOString(),
                'client_email' => $p->client_email,
                'client_name' => $p->client_name,
            ]),
            'filters' => $request->only(['brand_id', 'stripe_account_id', 'provider', 'relationship_manager_id', 'status', 'from', 'to', 'search']),
            'brands' => $canViewAll
                ? Brand::orderBy('name')->get(['id', 'name'])
                : [],
            'accounts' => $canViewAll
                ? StripeAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
                : [],
            'isAdmin' => $isAdmin,
            'readOnly' => $isAccount,
            'canExport' => $canViewAll,
            'canViewStripeAccount' => $canViewAll,
            'relationshipManagers' => RelationshipManager::where(
                fn ($q) => $q->where('is_active', true)->orWhere('id', $request->relationship_manager_id)
            )->orderBy('name')->get(['id', 'name']),
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

        $query = Payment::with(['brand', 'stripeAccount', 'revolutAccount', 'squareAccount', 'vivaAccount', 'user', 'relationshipManager'])
            ->orderByDesc('created_at');

        if (! $canViewAll) {
            $query->where('user_id', $user->id);
        }

        return $query
            ->when($request->brand_id, fn ($q, $v) => $q->where('brand_id', $v))
            ->when($request->stripe_account_id, fn ($q, $v) => $q->where('stripe_account_id', $v))
            ->when($request->provider, fn ($q, $v) => $q->where('provider', $v))
            ->when($request->relationship_manager_id, fn ($q, $v) => $q->where('relationship_manager_id', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($request->search, fn ($q, $v) => $q->where(function ($inner) use ($v): void {
                $inner->where('client_name', 'LIKE', "%{$v}%")
                    ->orWhere('client_email', 'LIKE', "%{$v}%")
                    ->orWhere('uuid', 'LIKE', strtolower($v).'%');

                $refSearch = trim($v);
                // Strip account prefix (e.g. "SPER-001254" → "001254")
                if (str_contains($refSearch, '-')) {
                    $refSearch = substr($refSearch, strpos($refSearch, '-') + 1);
                }
                $refSearch = ltrim(ltrim($refSearch, '#'), '0');
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
     * Load the brand / payment account / relationship manager options for the
     * create and edit forms. Agents are restricted to their assigned resources;
     * returns a RedirectResponse if the agent has no payment accounts at all,
     * or no brands/RMs.
     *
     * The account list is a union of active Stripe, Revolut, Square and Viva
     * accounts, each tagged with its provider — the selector implies the
     * provider on submit. Agents don't use this list (they're routed by
     * currency); instead they get agentCurrencies, one entry per
     * SupportedCurrency flagging whether that currency is usable.
     *
     * Inactive relationship managers are hidden from selection, except the
     * payment's currently-assigned RM ($currentRmId) which is always included
     * so historical records remain editable.
     *
     * @return array{brands: mixed, accounts: mixed, agentCurrencies: mixed, isAccountLocked: bool, relationshipManagers: mixed}|RedirectResponse
     */
    private function formOptions(User $user, ?int $currentRmId = null): array|RedirectResponse
    {
        if ($user->hasRole('agent')) {
            if ($user->paymentAccounts->isEmpty()) {
                Inertia::flash('toast', ['type' => 'error', 'message' => 'No payment account assigned. Contact an admin.']);

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

            $accounts = [];
            $agentCurrencies = $this->agentCurrencyOptions($user);
            $isAccountLocked = true;
        } else {
            $accounts = $this->activeAccountOptions();
            $agentCurrencies = [];
            $isAccountLocked = false;
            $brands = Brand::orderBy('name')->get(['id', 'name']);
            $relationshipManagers = RelationshipManager::where(fn ($q) => $q->where('is_active', true)->orWhere('id', $currentRmId))
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        return [
            'brands' => $brands,
            'accounts' => $accounts,
            'agentCurrencies' => $agentCurrencies,
            'isAccountLocked' => $isAccountLocked,
            'relationshipManagers' => $relationshipManagers,
        ];
    }

    /**
     * Per-currency availability for an agent's payment-currency selector:
     * one entry per SupportedCurrency flagging whether it's usable and, if
     * not, why (unconfigured vs. the configured account being deactivated).
     *
     * @return Collection<int, array{currency: string, enabled: bool, reason: ?string}>
     */
    private function agentCurrencyOptions(User $user): Collection
    {
        return collect(SupportedCurrency::cases())->map(function (SupportedCurrency $currency) use ($user) {
            $account = $user->paymentAccounts->firstWhere('currency', $currency->value);

            if ($account === null) {
                return ['currency' => $currency->value, 'enabled' => false, 'reason' => 'No account configured for this currency.'];
            }

            $table = ProviderAccountTable::for($account->provider->value);
            $isActive = DB::table($table)->where('id', $account->account_id)->where('is_active', true)->exists();

            if (! $isActive) {
                return ['currency' => $currency->value, 'enabled' => false, 'reason' => 'Account inactive, contact an admin.'];
            }

            return ['currency' => $currency->value, 'enabled' => true, 'reason' => null];
        })->values();
    }

    /**
     * Union of active Stripe + Revolut + Square accounts as
     * { id, account_name, provider }.
     *
     * @return Collection<int, array{id: int, account_name: string, provider: string}>
     */
    private function activeAccountOptions(): Collection
    {
        $stripe = StripeAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
            ->map(fn (StripeAccount $a) => ['id' => $a->id, 'account_name' => $a->account_name, 'provider' => 'stripe']);

        $revolut = RevolutAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
            ->map(fn (RevolutAccount $a) => ['id' => $a->id, 'account_name' => $a->account_name, 'provider' => 'revolut']);

        $square = SquareAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name', 'currency'])
            ->map(fn (SquareAccount $a) => ['id' => $a->id, 'account_name' => $a->account_name, 'provider' => 'square', 'currency' => $a->currency]);

        $viva = VivaAccount::where('is_active', true)->orderBy('account_name')->get(['id', 'account_name'])
            ->map(fn (VivaAccount $a) => ['id' => $a->id, 'account_name' => $a->account_name, 'provider' => 'viva', 'currency' => 'gbp']);

        return $stripe->concat($revolut)->concat($square)->concat($viva)->values();
    }

    /**
     * Resolve an agent's locked provider/account FK columns for the given
     * currency from their per-currency assignment. Returns null if the agent
     * has no account configured for this currency, or the configured account
     * has since been deactivated — the caller must reject the request; this
     * is a race-condition defense, the real block is StorePaymentRequest /
     * UpdatePaymentRequest's currency validation.
     *
     * Every branch is explicit (including the Square case, which previously sat
     * in an implicit else) so adding a 5th provider later can't silently land in
     * whichever branch happens to be last — see CLAUDE.md's warning about the
     * two Square CSV export bugs caused by exactly this pattern.
     *
     * @return array{provider: string, stripe_account_id: ?int, revolut_account_id: ?int, square_account_id: ?int, viva_account_id: ?int}|null
     */
    private function agentAccountData(User $user, string $currency): ?array
    {
        $account = $user->paymentAccounts()->where('currency', $currency)->first();

        if ($account === null) {
            return null;
        }

        $provider = $account->provider->value;
        $table = ProviderAccountTable::for($provider);
        $isActive = DB::table($table)->where('id', $account->account_id)->where('is_active', true)->exists();

        if (! $isActive) {
            return null;
        }

        return [
            'provider' => $provider,
            'stripe_account_id' => $provider === 'stripe' ? $account->account_id : null,
            'revolut_account_id' => $provider === 'revolut' ? $account->account_id : null,
            'square_account_id' => $provider === 'square' ? $account->account_id : null,
            'viva_account_id' => $provider === 'viva' ? $account->account_id : null,
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
            $accountData = $this->agentAccountData($user, $data['currency']);

            abort_if($accountData === null, 422, 'No active payment account configured for this currency.');

            $data = [...$data, ...$accountData];
        }

        $payment = $this->createPaymentWithRetry([
            ...$data,
            'user_id' => $user->id,
            'status' => 'pending',
            'expires_at' => null,
        ]);

        return redirect()->route('payments.show', $payment);
    }

    /**
     * Create a payment inside a transaction so the reference_code lock
     * (Payment::creating) is held through the INSERT. Retries on the rare
     * residual reference_code collision, regenerating the code each attempt.
     */
    private function createPaymentWithRetry(array $attributes, int $maxAttempts = 5): Payment
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(fn () => Payment::create($attributes));
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= $maxAttempts || ! str_contains($e->getMessage(), 'payments_reference_code_unique')) {
                    throw $e;
                }
                unset($attributes['reference_code']); // force regeneration by the creating hook
            }
        }
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
                'provider' => $payment->provider->value,
                'account_id' => $payment->stripe_account_id ?? $payment->revolut_account_id ?? $payment->square_account_id ?? $payment->viva_account_id,
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
            $accountData = $this->agentAccountData($user, $data['currency']);

            abort_if($accountData === null, 422, 'No active payment account configured for this currency.');

            $data = [...$data, ...$accountData];
        }

        // status and user_id are never updated here. Provider transaction ids are only
        // ever cleared, never set — see clearStaleProviderTransactionIds().
        $data = [...$data, ...$this->clearStaleProviderTransactionIds($payment, $data)];

        $payment->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Payment updated.']);

        return redirect()->route('payments.show', $payment);
    }

    /**
     * A provider transaction (Stripe PaymentIntent, Revolut order, Square payment) is
     * scoped to the account that created it. When an edit moves the payment to another
     * account — or to another provider entirely — the stored id is left dangling and
     * every later lookup against the new account 404s. Null it so the client pay page
     * mints a fresh transaction on the account the payment now belongs to.
     *
     * @param  array<string, mixed>  $data  the incoming attributes, post agent lock-in
     * @return array<string, null>
     */
    private function clearStaleProviderTransactionIds(Payment $payment, array $data): array
    {
        $columns = [
            'stripe_account_id' => ['stripe_payment_intent_id'],
            'revolut_account_id' => ['revolut_order_id'],
            'square_account_id' => ['square_payment_id'],
            // Viva has two ids scoped to the account: the order code (set when the pay
            // page creates the order) and the transaction id (set by the webhook).
            'viva_account_id' => ['viva_order_code', 'viva_transaction_id'],
        ];

        $cleared = [];

        foreach ($columns as $accountColumn => $transactionColumns) {
            $incoming = $data[$accountColumn] ?? null;

            if ($payment->{$accountColumn} === $incoming) {
                continue;
            }

            foreach ($transactionColumns as $transactionColumn) {
                if ($payment->{$transactionColumn} !== null) {
                    $cleared[$transactionColumn] = null;
                }
            }
        }

        return $cleared;
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
        $payment->loadMissing(['brand', 'stripeAccount', 'revolutAccount', 'squareAccount', 'vivaAccount', 'relationshipManager']);

        $user = auth()->user();
        $canViewStripeAccount = $user->hasRole('admin') || $user->hasRole('account');

        return Inertia::render('payments/Show', [
            'isAdmin' => $user->hasRole('admin'),
            'canViewStripeAccount' => $canViewStripeAccount,
            'payment' => [
                'uuid' => $payment->uuid,
                'reference_code' => $payment->formattedReferenceCode(),
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'status' => $payment->status,
                'provider' => $payment->provider->value,
                'provider_label' => $payment->provider->label(),
                'client_name' => $payment->client_name,
                'client_email' => $payment->client_email,
                'service' => $payment->service,
                'package' => $payment->package,
                'note' => $payment->note,
                'brand_name' => $payment->brand->name,
                'account_name' => $canViewStripeAccount ? $payment->providerAccountName() : null,
                'relationship_manager_name' => $payment->relationshipManager?->name,
                'created_at' => $payment->created_at->toISOString(),
                'provider_reference' => match ($payment->provider) {
                    PaymentProvider::Stripe => $payment->stripe_payment_intent_id,
                    PaymentProvider::Revolut => $payment->revolut_order_id,
                    PaymentProvider::Square => $payment->square_payment_id,
                    PaymentProvider::Viva => $payment->viva_transaction_id ?? $payment->viva_order_code,
                },
                'paid_at' => $payment->paid_at?->toISOString(),
                'expires_at' => $payment->expires_at?->toISOString(),
            ],
        ]);
    }
}
