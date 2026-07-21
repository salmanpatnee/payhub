<?php

namespace App\Http\Requests\Admin;

use App\Enums\SupportedCurrency;
use App\Support\CurrencySupportResolver;
use App\Support\ProviderAccountTable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

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
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($userId)],
            'password' => ['nullable', 'string', Password::default()],
            'role' => ['required', 'string', 'in:admin,agent,account'],
            // Agents may be assigned at most one payment account per currency.
            // Not required — zero-currency agents are allowed (provisioning
            // in progress), but must be visually flagged elsewhere.
            'payment_accounts' => ['array'],
            'payment_accounts.*.currency' => [
                'required', 'string', 'distinct', 'in:'.implode(',', SupportedCurrency::values()),
            ],
            'payment_accounts.*.provider' => ['required', 'string', 'in:stripe,revolut,square,viva'],
            'payment_accounts.*.account_id' => ['required', 'integer'],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validatePaymentAccounts($validator);
        });
    }

    /**
     * Defense in depth beyond the UI's own filtering: each payment_accounts
     * entry's account must be active and must actually support the currency
     * it's being assigned to.
     */
    private function validatePaymentAccounts(Validator $validator): void
    {
        foreach ($this->input('payment_accounts', []) as $index => $entry) {
            $provider = $entry['provider'] ?? null;
            $accountId = $entry['account_id'] ?? null;
            $currency = $entry['currency'] ?? null;

            if ($provider === null || $accountId === null || $currency === null) {
                continue;
            }

            $account = DB::table(ProviderAccountTable::for($provider))
                ->where('id', $accountId)
                ->where('is_active', true)
                ->first();

            if ($account === null) {
                $validator->errors()->add("payment_accounts.{$index}.account_id", 'The selected account is invalid or inactive.');

                continue;
            }

            $accountCurrency = $provider === 'square' ? $account->currency : null;

            if (! CurrencySupportResolver::supports($provider, $accountCurrency, $currency)) {
                $validator->errors()->add("payment_accounts.{$index}.currency", 'This account does not support the selected currency.');
            }
        }
    }
}
