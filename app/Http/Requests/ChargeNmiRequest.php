<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payment Component output only (spec 0012, AC-7). Never carries an amount, currency or account.
 * Names are cut to 50 characters by the controller, not rejected here.
 */
class ChargeNmiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // public, scoped by the payment UUID
    }

    public function rules(): array
    {
        $failed = $this->boolean('three_ds_failed');

        return [
            'three_ds_failed' => ['sometimes', 'boolean'],
            // A 3DS failure report has no token: nothing is charged.
            'payment_token' => [$failed ? 'nullable' : 'required', 'string', 'max:4096'],
            'method' => ['required', Rule::in(['card', 'google_pay'])],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'cavv' => ['nullable', 'string', 'max:255'],
            'eci' => ['nullable', 'string', 'max:8'],
            'xid' => ['nullable', 'string', 'max:255'],
            'card_holder_auth' => ['nullable', 'string', 'max:255'],
            'three_ds_version' => ['nullable', 'string', 'max:16'],
            'directory_server_id' => ['nullable', 'string', 'max:255'],
        ];
    }
}
