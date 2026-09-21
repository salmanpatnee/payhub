<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ZelleAccount;

class ZelleAccountPolicy
{
    public function create(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('account');
    }

    public function update(User $user, ZelleAccount $zelleAccount): bool
    {
        return $user->hasRole('admin') || $user->hasRole('account');
    }

    public function delete(User $user, ZelleAccount $zelleAccount): bool
    {
        return $user->hasRole('admin') || $user->hasRole('account');
    }
}
