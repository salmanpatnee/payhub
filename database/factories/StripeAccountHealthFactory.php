<?php

namespace Database\Factories;

use App\Enums\StripeHealthStatus;
use App\Models\StripeAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class StripeAccountHealthFactory extends Factory
{
    public function definition(): array
    {
        return [
            'stripe_account_id' => StripeAccount::factory(),
            'stripe_remote_id' => 'acct_'.$this->faker->regexify('[A-Za-z0-9]{16}'),
            'status' => StripeHealthStatus::Healthy,
            'charges_enabled' => true,
            'payouts_enabled' => true,
            'details_submitted' => true,
            'requirements' => ['currently_due' => [], 'past_due' => [], 'eventually_due' => [], 'pending_verification' => [], 'current_deadline' => null],
            'country' => 'GB',
            'default_currency' => 'gbp',
            'status_changed_at' => now(),
            'last_checked_at' => now(),
        ];
    }
}
