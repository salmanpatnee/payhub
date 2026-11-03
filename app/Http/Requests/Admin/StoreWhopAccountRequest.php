<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWhopAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'account_name' => ['required', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            // Two active accounts cannot share one company (spec 0009, AC-1).
            'company_id' => [
                'required', 'string', 'max:255',
                Rule::unique('whop_accounts', 'company_id')->where('is_active', true),
            ],
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            'api_key' => ['required', 'string', 'max:512'],
            // Optional on save: whop:register-webhook sets it (AC-12).
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
