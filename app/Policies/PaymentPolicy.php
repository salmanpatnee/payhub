<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
{
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
