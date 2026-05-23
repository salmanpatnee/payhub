<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $userId = $this->route('user')->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', 'string', Password::default()],
            'role' => ['required', 'string', 'in:admin,agent'],
            'stripe_account_id' => [
                Rule::requiredIf(fn () => $this->input('role') === 'agent'),
                'nullable',
                'integer',
                Rule::exists('stripe_accounts', 'id')->where('is_active', true),
            ],
        ];
    }
}
