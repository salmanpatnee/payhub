<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreRevolutAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'account_name' => ['required', 'string', 'max:255'],
            // Revolut's Card Field is driven by the per-order token, so the public
            // key is optional. Kept for parity / future SDK init paths.
            'public_key' => ['nullable', 'string', 'max:255'],
            // Revolut secret key prefixes are not stable across environments, so
            // format is intentionally not enforced — only presence.
            'secret_key' => ['required', 'string', 'max:255'],
        ];
    }
}
