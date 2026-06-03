<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Split the merged "payment_account" dropdown value ("{provider}:{id}") into
     * a provider discriminator plus the correct account FK. Agents are locked to
     * their assigned account server-side — client input for payment_account is ignored.
     */
    protected function prepareForValidation(): void
    {
        $user = $this->user();

        if ($user !== null && $user->hasRole('agent')) {
            if ($user->stripe_account_id) {
                $this->merge(['payment_account' => 'stripe:'.$user->stripe_account_id]);
            } elseif ($user->square_account_id) {
                $this->merge(['payment_account' => 'square:'.$user->square_account_id]);
            }
        }

        $value = (string) $this->input('payment_account');

        if (str_contains($value, ':')) {
            [$provider, $id] = explode(':', $value, 2);
            $this->merge([
                'provider' => $provider,
                'stripe_account_id' => $provider === 'stripe' ? $id : null,
                'square_account_id' => $provider === 'square' ? $id : null,
            ]);
        }
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
            'payment_account' => ['required', 'string'],
            'provider' => ['required', 'string', 'in:stripe,square'],
            'stripe_account_id' => ['nullable', 'required_if:provider,stripe', 'integer',
                Rule::exists('stripe_accounts', 'id')->where('is_active', true)],
            'square_account_id' => ['nullable', 'required_if:provider,square', 'integer',
                Rule::exists('square_accounts', 'id')->where('is_active', true)],
            'currency' => ['required', 'string', 'in:usd,gbp'],
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
        // passedValidation()/merge() only updates the input bag, not the validator snapshot.
        // Overriding validated() is the only reliable way to guarantee the controller
        // never sees a raw decimal amount.
        $data = parent::validated();
        $data['amount'] = (int) round($data['amount'] * 100);

        // payment_account is the merged dropdown value only — never persisted.
        unset($data['payment_account']);

        if ($key !== null) {
            return data_get($data, $key, $default);
        }

        return $data;
    }
}
