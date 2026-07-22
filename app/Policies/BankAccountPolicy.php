<?php

namespace App\Policies;

use App\Models\BankAccount;
use App\Models\User;

class BankAccountPolicy
{
    public function create(User $user): bool
    {
        return $user->hasRole('admin') || $user->hasRole('account');
    }

    public function update(User $user, BankAccount $bankAccount): bool
    {
        return $user->hasRole('admin') || $user->hasRole('account');
    }

    public function delete(User $user, BankAccount $bankAccount): bool
    {
        return $user->hasRole('admin') || $user->hasRole('account');
    }
}
