<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\RevolutAccount;
use App\Models\SquareAccount;
use App\Models\StripeAccount;
use App\Models\User;
use App\Models\VivaAccount;
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
            'revolut_account_id' => null,
            'square_account_id' => null,
            'viva_account_id' => null,
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
            'revolut_order_id' => null,
            'square_payment_id' => null,
            'viva_transaction_id' => null,
            'viva_order_code' => null,
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

    /**
     * Square-provider payment: nulls the Stripe account and attaches a Square account.
     */
    public function square(): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => 'square',
            'stripe_account_id' => null,
            'square_account_id' => SquareAccount::factory(),
        ]);
    }

    /**
     * Viva-provider payment: nulls the Stripe account and attaches a Viva
     * account. Viva is GBP-only — currency is forced to 'gbp' to match the
     * platform rule enforced in Store/UpdatePaymentRequest.
     */
    public function viva(): static
    {
        return $this->state(fn (array $attributes): array => [
            'provider' => 'viva',
            'stripe_account_id' => null,
            'viva_account_id' => VivaAccount::factory(),
            'currency' => 'gbp',
        ]);
    }
}
