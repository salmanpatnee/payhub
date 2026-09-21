<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Unique;

class UpdateZelleAccountRequest extends StoreZelleAccountRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('zelle_account'));
    }

    protected function uniqueEmailRule(): Unique
    {
        return parent::uniqueEmailRule()->ignore($this->route('zelle_account'));
    }
}
