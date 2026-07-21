<?php

namespace Database\Factories;

use App\Models\StripeAccount;
use App\Models\User;
use App\Models\UserPaymentAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserPaymentAccount>
 */
class UserPaymentAccountFactory extends Factory
{
    protected $model = UserPaymentAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'currency' => 'usd',
            'provider' => 'stripe',
            'account_id' => StripeAccount::factory(),
        ];
    }
}
