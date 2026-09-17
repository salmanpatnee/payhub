<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreCloverAccountRequest extends FormRequest
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
            'prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'environment' => ['required', 'string', 'in:sandbox,production'],
            'merchant_id' => ['required', 'string', 'max:255'],
            'private_token' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($isProduction): void {
                    if ($isProduction && $this->input('environment') === 'sandbox') {
                        $fail('Sandbox credentials are not allowed in production. Use production credentials and environment.');
                    }
                },
            ],
            'webhook_secret' => ['required', 'string'],
        ];
    }
}
