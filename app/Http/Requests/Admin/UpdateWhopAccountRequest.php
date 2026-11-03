<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWhopAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $account = $this->route('whop_account');

        return [
            'account_name' => ['required', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'company_id' => [
                'required', 'string', 'max:255',
                Rule::unique('whop_accounts', 'company_id')->where('is_active', true)->ignore($account),
            ],
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            'checkout_mode' => ['sometimes', Rule::in(['embed', 'redirect'])],
            // Blank = keep the stored value.
            'api_key' => ['nullable', 'string', 'max:512'],
            'webhook_secret' => ['nullable', 'string', 'max:512'],
        ];
    }

    public function messages(): array
    {
        return [
            'company_id.unique' => 'Another active Whop account already uses this company ID.',
        ];
    }
}
