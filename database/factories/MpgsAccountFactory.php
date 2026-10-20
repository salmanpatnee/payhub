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
            'api_version' => 100,
            'supports_pkr' => false,
            'is_active' => true,
        ];
    }

    public function supportsPkr(): static
    {
        return $this->state(fn () => ['supports_pkr' => true]);
    }
}
