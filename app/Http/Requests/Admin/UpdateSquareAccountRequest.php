<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSquareAccountRequest extends FormRequest
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
            'application_id' => ['required', 'string', 'max:255'],
            'location_id' => ['required', 'string', 'max:255'],
            'environment' => ['required', 'string', 'in:sandbox,production'],
            // access_token: blank = preserve existing (mirror Stripe secret_key).
            'access_token' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail) use ($isProduction): void {
                    if ($value === null || $value === '') {
                        return; // blank = keep existing; valid
                    }
                    if ($isProduction && $this->input('environment') === 'sandbox') {
                        $fail('Sandbox credentials are not allowed in production. Use a production access token and environment.');
                    }
                },
            ],
            // webhook_signature_key: blank = preserve existing.
            'webhook_signature_key' => ['nullable', 'string'],
        ];
    }
}
