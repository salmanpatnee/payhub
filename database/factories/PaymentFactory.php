<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\RevolutAccount;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => Str::uuid(),
            'provider' => 'stripe',
            'brand_id' => Brand::factory(),
            'stripe_account_id' => StripeAccount::factory(),
            'user_id' => User::factory(),
            'amount' => $this->faker->numberBetween(500, 100000),
            'currency' => $this->faker->randomElement(['usd', 'gbp']),
            'client_name' => $this->faker->name(),
            'client_email' => $this->faker->safeEmail(),
            'service' => $this->faker->sentence(3),
            'package' => $this->faker->randomElement([
                'basic', 'standard', 'premium',
                'platinum', 'diamond', null,
            ]),
            'note' => null,
            'status' => 'pending',
            'stripe_payment_intent_id' => null,
            'expires_at' => null,
            'paid_at' => null,
        ];
    }

    /**
     * A payment routed through Revolut instead of Stripe.
     */
    public function revolut(): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => 'revolut',
            'stripe_account_id' => null,
            'revolut_account_id' => RevolutAccount::factory(),
        ]);
    }
}
