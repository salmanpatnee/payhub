<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SquareAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_name' => $this->faker->company(),
            'application_id' => 'sandbox-sq0idb-'.$this->faker->regexify('[a-zA-Z0-9]{22}'),
            'location_id' => $this->faker->regexify('[A-Z0-9]{13}'),
            'access_token' => 'EAAA_placeholder_for_dev_only',
            'webhook_signature_key' => null,
            'environment' => 'sandbox',
            'is_active' => true,
        ];
    }
}
