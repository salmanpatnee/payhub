<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStripeAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $isProduction = app()->environment('production');

        return [
            'account_name' => ['required', 'string', 'max:255'],
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
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($isProduction): void {
                    // Only validate format and env if a value was provided
                    if ($value === null || $value === '') {
                        return; // blank = keep existing; valid
                    }
                    if (! str_starts_with($value, 'sk_')) {
                        $fail('The secret key must begin with sk_.');

                        return;
                    }
                    if ($isProduction && str_starts_with($value, 'sk_test_')) {
                        $fail('Test keys are not allowed in production. Use a live-mode secret key.');
                    }
                },
            ],
            'webhook_secret' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return; // blank = preserve existing; valid (D-04)
                    }
                    if (! str_starts_with($value, 'whsec_')) {
                        $fail('The webhook secret must begin with whsec_.');
                    }
                },
            ],
        ];
    }
}
