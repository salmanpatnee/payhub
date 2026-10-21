<?php

namespace App\Http\Requests\Admin;

use App\Enums\AuthorizenetEnvironment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAuthorizenetAccountRequest extends FormRequest
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
            'api_login_id' => ['required', 'string', 'max:255'],
            'transaction_key' => ['required', 'string'],
            'signature_key' => ['nullable', 'string'],
            'environment' => ['required', Rule::enum(AuthorizenetEnvironment::class)],
        ];
    }
}
