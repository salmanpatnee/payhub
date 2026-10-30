<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accept.js output only (spec 0007, AC-6). No ZIP is collected: the merchant AVS setting for
 * "no address" (B) must allow the charge. Never carries an amount, currency or account.
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
        ];
    }
}
