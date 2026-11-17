<?php

namespace Database\Factories;

use App\Models\WorldpayAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorldpayAccount>
 */
class WorldpayAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_name' => $this->faker->company(),
            'prefix' => null,
            'api_username' => 'wp-user-placeholder',
            'api_password' => 'wp-pass-placeholder',
            'entity_ref' => 'PO0000000001',
            'environment' => 'try',
            'webhook_key_id' => null,
            'webhook_secret' => null,
            'is_active' => true,
        ];
    }

    public function withWebhook(): static
    {
        return $this->state(['webhook_key_id' => 'key1', 'webhook_secret' => 'whsec-placeholder']);
    }
}
