<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMpgsAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'account_name' => ['required', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'merchant_id' => ['required', 'string', 'max:255'],
            // integration_password / webhook_secret: blank = preserve existing (mirror Clover's private_token).
            'integration_password' => ['nullable', 'string'],
            'webhook_secret' => ['nullable', 'string'],
            'api_version' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }
}
