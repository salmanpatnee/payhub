<?php

namespace App\Http\Requests;

use App\Models\SquareAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // The account selector implies the provider; account_id is validated
            // against whichever provider's table was chosen (must be active).
            'provider' => ['required', 'string', 'in:stripe,revolut,square,viva'],
            'account_id' => ['required', 'integer',
                Rule::exists($this->accountTable(), 'id')->where('is_active', true)],
            'currency' => ['required', 'string', 'in:usd,gbp',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($this->input('provider') !== 'square') {
                        return;
                    }

                    $account = SquareAccount::find($this->input('account_id'));

                    if ($account?->currency && $account->currency !== $value) {
                        $fail("This Square account only accepts {$account->currency} payments.");
                    }
                },
                // Viva is GBP-only as a flat platform rule (no per-account variability,
                // unlike Square's per-account currency lock above) — see CLAUDE.md.
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($this->input('provider') === 'viva' && $value !== 'gbp') {
                        $fail('Viva payments must be in GBP.');
                    }
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
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mapAccountColumns(array $data): array
    {
        $provider = $data['provider'] ?? 'stripe';
        $accountId = (int) ($data['account_id'] ?? 0);
        unset($data['account_id']);

        $data['stripe_account_id'] = $provider === 'stripe' ? $accountId : null;
        $data['revolut_account_id'] = $provider === 'revolut' ? $accountId : null;
        $data['square_account_id'] = $provider === 'square' ? $accountId : null;
        $data['viva_account_id'] = $provider === 'viva' ? $accountId : null;

        return $data;
    }

    private function accountTable(): string
    {
        return match ($this->input('provider')) {
            'revolut' => 'revolut_accounts',
            'square' => 'square_accounts',
            'viva' => 'viva_accounts',
            default => 'stripe_accounts',
        };
    }
}
