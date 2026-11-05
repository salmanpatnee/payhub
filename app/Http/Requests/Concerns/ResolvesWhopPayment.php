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
        return $this->resolvesToProvider('whop');
    }

    /**
     * Same check for Kurv (spec 0010, AC-2): the Kurv pay page never asks for an email either,
     * and Kurv needs one to create the payment request.
     */
    private function resolvesToKurv(): bool
    {
        return $this->resolvesToProvider('kurv');
    }

    private function resolvesToProvider(string $provider): bool
    {
        $user = $this->user();

        if ($user !== null && $user->hasRole('agent')) {
            return UserPaymentAccount::where('user_id', $user->id)
                ->where('currency', $this->input('currency'))
                ->where('provider', $provider)
                ->exists();
        }

        return $this->input('provider') === $provider;
    }
}
