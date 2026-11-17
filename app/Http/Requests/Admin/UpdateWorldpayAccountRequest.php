<?php

namespace App\Http\Requests\Admin;

use App\Models\WorldpayAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateWorldpayAccountRequest extends FormRequest
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
            // Blank = keep the saved value.
            'api_username' => ['nullable', 'string', 'max:255'],
            'api_password' => ['nullable', 'string', 'max:255'],
            'entity_ref' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'webhook_key_id' => ['nullable', 'string', 'max:64'],
            'webhook_secret' => ['nullable', 'string', 'max:512'],
        ];
    }

    /**
     * The environment and entity are locked once a payment uses the account, and the webhook key ID
     * and secret must come together (spec 0014, AC-1).
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $account = $this->route('worldpay_account');

            if (! $account instanceof WorldpayAccount) {
                return;
            }

            $changed = $this->input('environment') !== $account->environment
                || $this->input('entity_ref') !== $account->entity_ref;

            if ($changed && $account->payments()->exists()) {
                $validator->errors()->add(
                    $this->input('environment') !== $account->environment ? 'environment' : 'entity_ref',
                    'This account already has payments. Create a new account to change the environment or entity.',
                );
            }

            $hasKeyId = $this->filled('webhook_key_id');
            $hasSecret = $this->filled('webhook_secret') || ! empty($account->webhook_secret);

            if ($hasKeyId && ! $hasSecret) {
                $validator->errors()->add('webhook_secret', 'Enter both the webhook key ID and the webhook secret, or leave both blank.');
            }

            if (! $hasKeyId && $this->filled('webhook_secret')) {
                $validator->errors()->add('webhook_key_id', 'Enter both the webhook key ID and the webhook secret, or leave both blank.');
            }
        }];
    }
}
