<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVivaAccountRequest extends FormRequest
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
            // client_secret: blank = preserve existing (mirror Square's access_token).
            'client_secret' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($isProduction): void {
                    if ($value === null || $value === '') {
                        return; // blank = keep existing; valid
                    }
                    if ($isProduction && $this->input('environment') === 'demo') {
                        $fail('Demo credentials are not allowed in production. Use production credentials and environment.');
                    }
                },
            ],
            'merchant_id' => ['required', 'string', 'max:255'],
            // api_key: blank = preserve existing.
            'api_key' => ['nullable', 'string'],
            'source_code' => ['required', 'string', 'max:10'],
        ];
    }
}
