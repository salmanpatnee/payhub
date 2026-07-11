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

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')],
            'password' => ['required', 'string', Password::default()],
            'role' => ['required', 'string', 'in:admin,agent,account'],
            // Agents are assigned one payment account; the selector implies provider.
            'provider' => [
                Rule::requiredIf(fn () => $this->input('role') === 'agent'),
                'nullable',
                'string',
                'in:stripe,revolut,square,viva',
            ],
            'account_id' => [
                Rule::requiredIf(fn () => $this->input('role') === 'agent'),
                'nullable',
                'integer',
                Rule::exists($this->accountTable(), 'id')->where('is_active', true),
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
