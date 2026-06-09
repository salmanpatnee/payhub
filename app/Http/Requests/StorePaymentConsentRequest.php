<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StorePaymentConsentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Public payment flow — no auth, matching the existing /pay routes (CLIENT-01).
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'accepted' => ['required', 'accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'accepted.required' => 'You must agree to the Terms & Conditions, Refund Policy, and Privacy Policy before proceeding.',
            'accepted.accepted' => 'You must agree to the Terms & Conditions, Refund Policy, and Privacy Policy before proceeding.',
        ];
    }
}
