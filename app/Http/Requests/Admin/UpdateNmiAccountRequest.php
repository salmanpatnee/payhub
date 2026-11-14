<?php

namespace App\Http\Requests\Admin;

use App\Enums\NmiEnvironment;
use App\Models\NmiAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateNmiAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $account = $this->route('nmi_account');

        return [
            'account_name' => ['required', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'environment' => ['required', Rule::in(NmiEnvironment::values())],
            'currency' => ['required', Rule::in(['usd', 'gbp'])],
            // Blank = keep the stored value.
            'private_key' => ['nullable', 'string', 'max:512'],
            'public_tokenization_key' => [
                'required', 'string', 'regex:'.StoreNmiAccountRequest::PUBLIC_KEY_FORMAT,
                Rule::unique('nmi_accounts', 'public_tokenization_key')->ignore($account instanceof NmiAccount ? $account->id : null),
            ],
            'webhook_signing_key' => ['nullable', 'string', 'max:512'],
            'three_ds_enabled' => ['boolean'],
            'google_pay_enabled' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'public_tokenization_key.regex' => 'This is not a public tokenization key. It looks like xxxxxx-xxxxxx-xxxxxx-xxxxxx.',
            'public_tokenization_key.unique' => 'This public tokenization key is already used by another account.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $account = $this->route('nmi_account');
            $private = $this->filled('private_key') ? (string) $this->input('private_key') : ($account instanceof NmiAccount ? $account->private_key : null);
            $public = $this->input('public_tokenization_key');

            if (is_string($public) && $public !== '' && $public === $private) {
                $validator->errors()->add('public_tokenization_key', 'This looks like a private key. Paste the public tokenization key.');
            }
        }];
    }
}
