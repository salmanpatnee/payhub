<?php

namespace App\Http\Requests\Concerns;

use App\Models\UserPaymentAccount;

/**
 * Shared by StorePaymentRequest and UpdatePaymentRequest (spec 0009, AC-4).
 */
trait ResolvesWhopPayment
{
    /**
     * True when this request will end up on a Whop account: an admin or account user
     * picked Whop, or an agent's slot for the chosen currency is a Whop account.
     * Whop payments need a client email because the pay page never asks for one.
     */
    private function resolvesToWhop(): bool
    {
        $user = $this->user();

        if ($user !== null && $user->hasRole('agent')) {
            return UserPaymentAccount::where('user_id', $user->id)
                ->where('currency', $this->input('currency'))
                ->where('provider', 'whop')
                ->exists();
        }

        return $this->input('provider') === 'whop';
    }
}
