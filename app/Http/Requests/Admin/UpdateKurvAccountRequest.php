<?php

namespace App\Http\Requests\Admin;

use App\Models\KurvAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateKurvAccountRequest extends FormRequest
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
            'environment' => ['required', Rule::in(['sandbox', 'production'])],
            // Blank = keep the stored key.
            'api_key' => ['nullable', 'string', 'max:512'],
        ];
    }

    /**
     * The environment is checked against the incoming key or, when it is blank, the
     * stored key (spec 0010, AC-1).
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $account = $this->route('kurv_account');
            $key = $this->filled('api_key') ? (string) $this->input('api_key') : ($account instanceof KurvAccount ? $account->api_key : null);

            if (is_string($key) && $key !== ''
                && ! KurvAccount::keyMatchesEnvironment($key, (string) $this->input('environment'))) {
                $validator->errors()->add($this->filled('api_key') ? 'api_key' : 'environment', 'The key does not match the environment. Use a kp_test_ key with Sandbox and a kp_live_ key with Production.');
            }
        }];
    }
}
