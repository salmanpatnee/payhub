<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
    public function create(User $user): bool
    {
        // The read-only `account` role is the only role barred from creating
        // payments; admins and agents (and any non-account user) may create.
        return ! $user->hasRole('account');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->hasRole('admin') || $payment->user_id === $user->id;
    }

    public function update(User $user, Payment $payment): bool
    {
        return ($user->hasRole('admin') || $payment->user_id === $user->id)
            && $payment->status === 'pending';
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->hasRole('admin');
    }
}
