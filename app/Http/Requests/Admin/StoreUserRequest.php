<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    /**
     * Split the merged "payment_account" value ("{provider}:{id}") into the correct
     * account FK. An agent is assigned a single account (Stripe OR Square) for failover.
     */
    protected function prepareForValidation(): void
    {
        $value = (string) $this->input('payment_account');

        if (str_contains($value, ':')) {
            [$provider, $id] = explode(':', $value, 2);
            $this->merge([
                'stripe_account_id' => $provider === 'stripe' ? $id : null,
                'square_account_id' => $provider === 'square' ? $id : null,
            ]);
        } else {
            $this->merge(['stripe_account_id' => null, 'square_account_id' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'password' => ['required', 'string', Password::default()],
            'role' => ['required', 'string', 'in:admin,agent'],
            'payment_account' => [
                Rule::requiredIf(fn () => $this->input('role') === 'agent'),
                'nullable',
                'string',
            ],
            'stripe_account_id' => [
                'nullable',
                'integer',
                Rule::exists('stripe_accounts', 'id')->where('is_active', true),
            ],
            'square_account_id' => [
                'nullable',
                'integer',
                Rule::exists('square_accounts', 'id')->where('is_active', true),
            ],
            'brand_ids' => [
                Rule::requiredIf(fn () => $this->input('role') === 'agent'),
                'array',
                Rule::when($this->input('role') === 'agent', ['min:1']),
            ],
            'brand_ids.*' => ['integer', 'exists:brands,id'],
            'relationship_manager_ids' => [
                Rule::requiredIf(fn () => $this->input('role') === 'agent'),
                'array',
                Rule::when($this->input('role') === 'agent', ['min:1']),
            ],
            'relationship_manager_ids.*' => ['integer', 'exists:relationship_managers,id'],
        ];
    }
}
