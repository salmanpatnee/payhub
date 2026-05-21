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

    public function rules(): array
    {
        return [
            'brand_id' => ['required', 'integer', 'exists:brands,id'],
            'relationship_manager_id' => ['required', 'integer', 'exists:relationship_managers,id'],
            'stripe_account_id' => ['required', 'integer',
                Rule::exists('stripe_accounts', 'id')
                    ->where('is_active', true)],
            'currency' => ['required', 'string', 'in:usd,gbp'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'client_name' => ['required', 'string', 'max:255'],
            'client_email' => ['required', 'email', 'max:255'],
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

        if ($key !== null) {
            return data_get($data, $key, $default);
        }

        return $data;
    }
}
