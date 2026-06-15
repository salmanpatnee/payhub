<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRevolutAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'account_name' => ['required', 'string', 'max:255'],
            'public_key' => ['nullable', 'string', 'max:255'],
            // blank = keep existing
            'secret_key' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => [
                'nullable',
                'string',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return; // blank = preserve existing; valid
                    }
                    // Revolut webhook signing secrets are prefixed wsk_.
                    if (! str_starts_with($value, 'wsk_')) {
                        $fail('The webhook signing secret must begin with wsk_.');
                    }
                },
            ],
        ];
    }
}
