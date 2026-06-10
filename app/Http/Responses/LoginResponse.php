<?php

namespace App\Http\Responses;

use App\Support\Navigation;
use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * Redirect users to a role-appropriate landing page after login.
     */
    public function toResponse($request): RedirectResponse
    {
        return redirect()->intended(Navigation::homePathFor($request->user()));
    }
}
