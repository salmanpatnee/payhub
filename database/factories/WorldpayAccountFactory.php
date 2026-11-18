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
            'mode' => 'hosted',
            'checkout_id' => null,
            'google_pay_enabled' => false,
            'google_pay_gateway_merchant_id' => null,
            'webhook_key_id' => null,
            'webhook_secret' => null,
            'is_active' => true,
        ];
    }

    public function cardFields(): static
    {
        return $this->state(['mode' => 'card_fields', 'checkout_id' => '4c1b8b1e-3d0e-4d5b-9a53-2f4c6f0a1b11']);
    }

    public function withWebhook(): static
    {
        return $this->state(['webhook_key_id' => 'key1', 'webhook_secret' => 'whsec-placeholder']);
    }
}
