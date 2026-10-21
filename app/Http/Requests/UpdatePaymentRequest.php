<?php

namespace App\Http\Requests;

use App\Enums\PaymentProvider;
use App\Enums\SupportedCurrency;
use App\Models\AuthorizenetAccount;
use App\Models\MpgsAccount;
use App\Models\Payment;
use App\Models\SquareAccount;
use App\Models\User;
use App\Models\UserPaymentAccount;
use App\Support\CurrencySupportResolver;
use App\Support\ProviderAccountTable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Pending-only + ownership gate is enforced in the controller via
        // Gate::authorize('update', $payment) using PaymentPolicy::update.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $user = $this->user();
        $isAgent = $user !== null && $user->hasRole('agent');

        return [
            // Agents may only reference brands/RMs mapped to them (prevents
            // horizontal escalation via a crafted request body).
            'brand_id' => ['required', 'integer', $isAgent
                ? Rule::exists('brand_user', 'brand_id')->where('user_id', $user->id)
                : 'exists:brands,id'],
            'relationship_manager_id' => ['required', 'integer', $isAgent
                ? Rule::exists('relationship_manager_user', 'relationship_manager_id')->where('user_id', $user->id)
                : 'exists:relationship_managers,id'],
            // Non-agents pick the account explicitly, which implies the provider.
            // Agents are routed server-side by currency (see agentAccountData()) so
            // neither field is required from them.
            'provider' => [Rule::requiredIf(! $isAgent), 'nullable', 'string', 'in:stripe,revolut,square,viva,clover,mpgs,authorizenet'],
            'account_id' => [
                Rule::requiredIf(! $isAgent),
                'nullable',
                'integer',
                Rule::exists($this->accountTable(), 'id')->where('is_active', true),
            ],
            'currency' => ['required', 'string', 'in:'.implode(',', SupportedCurrency::values()),
                function (string $attribute, mixed $value, \Closure $fail) use ($isAgent, $user): void {
                    if ($isAgent) {
                        if ($user === null || ! $this->agentHasActiveAccountForCurrency($user, $value)) {
                            $fail('No active payment account configured for this currency. Contact an admin.');
                        }

                        return;
                    }

                    $provider = $this->input('provider');
                    $accountCurrency = match ($provider) {
                        'square' => SquareAccount::find($this->input('account_id'))?->currency,
                        'authorizenet' => AuthorizenetAccount::find($this->input('account_id'))?->currency,
                        default => null,
                    };

                    $acceptsPkr = $provider === 'mpgs'
                        && (bool) MpgsAccount::find($this->input('account_id'))?->supports_pkr;

                    if (CurrencySupportResolver::supports($provider, $accountCurrency, $value, $acceptsPkr)) {
                        return;
                    }

                    if ($value === SupportedCurrency::PKR->value) {
                        $fail('PKR is only supported on MPGS accounts that accept PKR.');

                        return;
                    }

                    $fail(match ($provider) {
                        'viva' => 'Viva payments must be in GBP.',
                        'clover' => 'This Clover account only accepts USD payments.',
                        'authorizenet' => "This Authorize.net account only accepts {$accountCurrency} payments.",
                        default => "This Square account only accepts {$accountCurrency} payments.",
                    });
                },
            ],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'service' => ['nullable', 'string', 'max:255'],
            'package' => ['nullable', 'string',
                'in:basic,standard,premium,platinum,diamond'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Once an Authorize.net token was issued the client may already be paying
     * the old values, so amount, currency and account become read-only (spec 0006, AC-10).
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $payment = $this->route('payment');

            if (! $payment instanceof Payment
                || $payment->provider !== PaymentProvider::Authorizenet
                || $payment->authorizenet_first_attempt_at === null) {
                return;
            }

            $changed = (int) round(((float) $this->input('amount')) * 100) !== $payment->amount
                || $this->input('currency') !== $payment->currency
                || ($this->input('account_id') !== null
                    && (int) $this->input('account_id') !== $payment->authorizenet_account_id)
                || ($this->input('provider') !== null && $this->input('provider') !== 'authorizenet');

            if ($changed) {
                $validator->errors()->add('amount', 'This payment link was already opened by the client, so the amount, currency and account can no longer be changed.');
            }
        }];
    }

    public function validated($key = null, $default = null): mixed
    {
        // SEC-02: Override so validated() always returns amount as integer cents.
        $data = parent::validated();
        $data['amount'] = (int) round($data['amount'] * 100);
        $data = $this->mapAccountColumns($data);

        if ($key !== null) {
            return data_get($data, $key, $default);
        }

        return $data;
    }

    /**
     * Map the unified provider/account_id pair onto the concrete FK columns.
     * provider/account_id may be null here for an agent (locked server-side
     * by the controller via agentAccountData()) — every FK simply lands null.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapAccountColumns(array $data): array
    {
        $provider = $data['provider'] ?? null;
        $accountId = isset($data['account_id']) ? (int) $data['account_id'] : null;
        unset($data['account_id']);

        $data['stripe_account_id'] = $provider === 'stripe' ? $accountId : null;
        $data['revolut_account_id'] = $provider === 'revolut' ? $accountId : null;
        $data['square_account_id'] = $provider === 'square' ? $accountId : null;
        $data['viva_account_id'] = $provider === 'viva' ? $accountId : null;
        $data['clover_account_id'] = $provider === 'clover' ? $accountId : null;
        $data['mpgs_account_id'] = $provider === 'mpgs' ? $accountId : null;
        $data['authorizenet_account_id'] = $provider === 'authorizenet' ? $accountId : null;

        return $data;
    }

    /**
     * Authoritative "can't submit a currency you don't have" check for agents:
     * the user must have an active user_payment_accounts row for the currency.
     */
    private function agentHasActiveAccountForCurrency(User $user, string $currency): bool
    {
        $account = UserPaymentAccount::where('user_id', $user->id)->where('currency', $currency)->first();

        if ($account === null) {
            return false;
        }

        $table = ProviderAccountTable::for($account->provider->value);

        return DB::table($table)->where('id', $account->account_id)->where('is_active', true)->exists();
    }

    private function accountTable(): string
    {
        return ProviderAccountTable::for($this->input('provider'));
    }
}
