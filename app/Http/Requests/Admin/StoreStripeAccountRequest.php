<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreStripeAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $isProduction = app()->environment('production');

        return [
            'account_name'    => ['required', 'string', 'max:255'],
            'prefix'          => ['nullable', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'publishable_key' => [
                'required',
                'string',
                'starts_with:pk_',
                function (string $attribute, mixed $value, \Closure $fail) use ($isProduction): void {
                    if ($isProduction && str_starts_with($value, 'pk_test_')) {
                        $fail('Test keys are not allowed in production. Use a live-mode publishable key.');
                    }
                },
            ],
            'secret_key' => [
                'required',
                'string',
                'starts_with:sk_',
                function (string $attribute, mixed $value, \Closure $fail) use ($isProduction): void {
                    if ($isProduction && str_starts_with($value, 'sk_test_')) {
                        $fail('Test keys are not allowed in production. Use a live-mode secret key.');
                    }
                },
            ],
        ];
    }
}
