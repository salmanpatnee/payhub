<?php

namespace App\Http\Requests\Admin;

use App\Models\WorldpayAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreWorldpayAccountRequest extends FormRequest
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
            'environment' => ['required', Rule::in(WorldpayAccount::ENVIRONMENTS)],
            'api_username' => ['required', 'string', 'max:255'],
            'api_password' => ['required', 'string', 'max:255'],
            'entity_ref' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'webhook_key_id' => ['nullable', 'string', 'max:64'],
            'webhook_secret' => ['nullable', 'string', 'max:512'],
            'mode' => ['nullable', Rule::in(WorldpayAccount::MODES)],
            'checkout_id' => ['nullable', 'required_if:mode,card_fields', 'uuid'],
            'google_pay_enabled' => ['nullable', 'boolean'],
            'google_pay_gateway_merchant_id' => ['nullable', 'required_if:google_pay_enabled,true,1', 'string', 'max:128', 'regex:/^[A-Za-z0-9_.:-]+$/'],
        ];
    }

    /** The webhook key ID and secret come together or not at all (spec 0014, AC-1). */
    public function after(): array
    {
        return [function (Validator $validator): void {
            // Google Pay is a card fields feature only (spec 0015, AC-1).
            if ($this->boolean('google_pay_enabled') && ($this->input('mode') ?? 'hosted') !== 'card_fields') {
                $validator->errors()->add('google_pay_enabled', 'Google Pay needs the card fields mode.');
            }

            if ($this->filled('webhook_key_id') xor $this->filled('webhook_secret')) {
                $validator->errors()->add(
                    $this->filled('webhook_key_id') ? 'webhook_secret' : 'webhook_key_id',
                    'Enter both the webhook key ID and the webhook secret, or leave both blank.',
                );
            }
        }];
    }
}
