<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\StripeAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        $brand = Brand::factory()->create();

        return [
            'uuid'                      => Str::uuid(),
            'brand_id'                  => $brand->id,
            'stripe_account_id'         => StripeAccount::factory()->create(['brand_id' => $brand->id])->id,
            'user_id'                   => User::factory(),
            'amount'                    => $this->faker->numberBetween(500, 100000),
            'currency'                  => $this->faker->randomElement(['usd', 'gbp']),
            'description'               => $this->faker->sentence(),
            'status'                    => 'pending',
            'client_email'              => $this->faker->safeEmail(),
            'stripe_payment_intent_id'  => null,
            'expires_at'                => null,
            'paid_at'                   => null,
        ];
    }
}
