<?php

namespace App\Http\Requests\Admin;

use App\Enums\NmiEnvironment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreNmiAccountRequest extends FormRequest
{
    /** The dashed public tokenization key format, confirmed in the sandbox (spec 0012, AC-1). */
    public const PUBLIC_KEY_FORMAT = '/^[A-Za-z0-9]{6}(-[A-Za-z0-9]{6}){3}$/';

    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'account_name' => ['required', 'string', 'max:255'],
            'prefix' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'environment' => ['required', Rule::in(NmiEnvironment::values())],
            'currency' => ['required', Rule::in(['usd', 'gbp'])],
            'private_key' => ['required', 'string', 'max:512'],
            // Unique across all accounts, active or not.
            'public_tokenization_key' => ['required', 'string', 'regex:'.self::PUBLIC_KEY_FORMAT, Rule::unique('nmi_accounts', 'public_tokenization_key')],
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

    /** A public key equal to the private key means the wrong key was pasted. */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $public = $this->input('public_tokenization_key');
            $private = $this->input('private_key');

            if (is_string($public) && $public !== '' && $public === $private) {
                $validator->errors()->add('public_tokenization_key', 'This looks like a private key. Paste the public tokenization key.');
            }
        }];
    }
}
