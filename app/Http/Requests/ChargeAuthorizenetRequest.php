<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accept.js output plus ZIP (spec 0007, AC-6). Never carries an amount, currency or account.
 */
class ChargeAuthorizenetRequest extends FormRequest
{
    public const DATA_DESCRIPTOR = 'COMMON.ACCEPT.INAPP.PAYMENT';

    public function authorize(): bool
    {
        return true; // public, scoped by the payment UUID
    }

    public function rules(): array
    {
        return [
            'data_descriptor' => ['required', 'string', 'in:'.self::DATA_DESCRIPTOR],
            'data_value' => ['required', 'string', 'max:2048'],
            'postal_code' => ['required', 'string', 'regex:/^[A-Za-z0-9 -]{3,10}$/'],
        ];
    }
}
