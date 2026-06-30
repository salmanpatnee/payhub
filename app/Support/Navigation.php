<?php

namespace App\Support;

use App\Models\User;

class Navigation
{
    /**
     * Resolve the post-login landing path for a user based on their role.
     *
     * Admins and read-only finance (account) users land on the analytics
     * dashboard; everyone else (agents) lands on the payments list.
     */
    public static function homePathFor(?User $user): string
    {
        if ($user !== null && $user->hasAnyRole(['admin', 'account'])) {
            return '/dashboard';
        }

        return '/payments';
    }
}
