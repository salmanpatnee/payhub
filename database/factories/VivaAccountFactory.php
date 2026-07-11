<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class VivaAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_name' => $this->faker->company(),
            'client_id' => $this->faker->regexify('[a-zA-Z0-9]{22}'),
            'client_secret' => 'placeholder_client_secret_for_dev_only',
            'merchant_id' => $this->faker->regexify('[a-f0-9]{32}'),
            'api_key' => 'placeholder_api_key_for_dev_only',
            'source_code' => $this->faker->numerify('####'),
            'webhook_verification_key' => null,
            'environment' => 'demo',
            'is_active' => true,
        ];
    }
}
