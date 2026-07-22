<?php

namespace App\Http\Requests;

use App\Enums\SupportedCurrency;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBankAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin') || $this->user()->hasRole('account');
    }

    public function rules(): array
    {
        return [
            'bank_name' => ['required', 'string', 'max:255'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:255'],
            'currency' => ['required', 'string', 'in:'.implode(',', SupportedCurrency::values())],
            'sort_code' => ['nullable', 'string', 'max:255'],
            'routing_number' => ['nullable', 'string', 'max:255'],
            'iban' => ['nullable', 'string', 'max:255'],
            'swift_bic' => ['nullable', 'string', 'max:255'],
            'bank_address' => ['nullable', 'string', 'max:255'],
            'bank_country' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'user_ids' => ['array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('sort_code') && ! $this->filled('routing_number') && ! $this->filled('iban')) {
                $validator->errors()->add('sort_code', 'At least one of sort code, routing number, or IBAN is required.');
            }
        });
    }
}
