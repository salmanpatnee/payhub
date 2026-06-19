<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class StoreBrandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'name'            => ['required', 'string', 'max:255'],
            'website_url'     => ['nullable', 'url', 'max:255'],
            'logo'            => [
                'nullable',
                File::types(['jpg', 'jpeg', 'png', 'webp', 'svg'])
                    ->max(2 * 1024),
            ],
            'primary_color'   => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'max:7', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }
}
