<?php

namespace App\Http\Requests\Admin;

use App\Models\KurvAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreKurvAccountRequest extends FormRequest
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
            'api_key' => ['required', 'string', 'max:512'],
        ];
    }

    /** A kp_test_ key only with sandbox, a kp_live_ key only with production (spec 0010, AC-1). */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $key = $this->input('api_key');

            if (is_string($key) && $key !== ''
                && ! KurvAccount::keyMatchesEnvironment($key, (string) $this->input('environment'))) {
                $validator->errors()->add('api_key', 'This key does not match the environment. Use a kp_test_ key with Sandbox and a kp_live_ key with Production.');
            }
        }];
    }
}
