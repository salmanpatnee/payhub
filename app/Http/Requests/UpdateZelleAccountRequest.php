<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Unique;

class UpdateZelleAccountRequest extends StoreZelleAccountRequest
{
    protected function uniqueEmailRule(): Unique
    {
        return parent::uniqueEmailRule()->ignore($this->route('zelle_account'));
    }
}
