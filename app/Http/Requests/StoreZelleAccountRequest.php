<?php

namespace App\Http\Requests;

use App\Enums\SupportedCurrency;
use App\Models\User;
use App\Models\ZelleAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

class StoreZelleAccountRequest extends FormRequest
{
    /**
     * Runs before validation, so agents get a 403 rather than a 422 that leaks the rules.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', ZelleAccount::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'account_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', $this->uniqueEmailRule()],
            'mobile_number' => ['nullable', 'string', 'max:20', 'regex:/^[0-9\s+\-()]+$/'],
            'currency' => ['required', 'string', Rule::in(SupportedCurrency::values())],
            'is_active' => ['boolean'],
            'user_ids' => ['array'],
            'user_ids.*' => ['integer', Rule::in(User::role('agent')->pluck('id')->all())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'mobile_number.regex' => 'The mobile number may only contain digits, spaces, +, -, ( and ).',
            'user_ids.*.in' => 'Only users with the agent role can be assigned.',
        ];
    }

    protected function uniqueEmailRule(): Unique
    {
        return Rule::unique('zelle_accounts', 'email')->whereNull('deleted_at');
    }
}
