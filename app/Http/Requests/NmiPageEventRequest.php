<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A diagnostic report from the NMI pay page (spec 0012): the card fields did not load, the widget
 * could not mount, or 3DS failed or went silent. It carries no card value, token, name or amount.
 */
class NmiPageEventRequest extends FormRequest
{
    public const EVENTS = ['fields_not_loaded', 'mount_failed', 'three_ds_failed', 'three_ds_timeout', 'three_ds_error'];

    public function authorize(): bool
    {
        return true; // public, scoped by the payment UUID
    }

    public function rules(): array
    {
        return [
            'event' => ['required', Rule::in(self::EVENTS)],
            'code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.:-]{1,40}$/'],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }
}
