<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CloverAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_name' => $this->faker->company(),
            'merchant_id' => $this->faker->regexify('[A-Z0-9]{13}'),
            'api_access_key' => $this->faker->regexify('[a-z0-9]{32}'),
            'private_token' => 'placeholder_private_token_for_dev_only',
            'currency' => 'usd',
            'environment' => 'sandbox',
            'is_active' => true,
        ];
    }
}
