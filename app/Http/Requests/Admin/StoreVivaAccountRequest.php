<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreVivaAccountRequest extends FormRequest
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
            'environment' => ['required', 'string', 'in:demo,production'],
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => [
                'required',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($isProduction): void {
                    if ($isProduction && $this->input('environment') === 'demo') {
                        $fail('Demo credentials are not allowed in production. Use production credentials and environment.');
                    }
                },
            ],
            'merchant_id' => ['required', 'string', 'max:255'],
            'api_key' => ['required', 'string'],
            'source_code' => ['required', 'string', 'max:10'],
        ];
    }
}
