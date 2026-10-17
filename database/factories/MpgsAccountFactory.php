<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class MpgsAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_name' => $this->faker->company(),
            'merchant_id' => $this->faker->regexify('[A-Z0-9]{12}'),
            'integration_password' => 'placeholder_integration_password_for_dev_only',
            'webhook_secret' => null,
            'api_version' => 66,
            'is_active' => true,
        ];
    }
}
