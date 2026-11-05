<?php

namespace Database\Factories;

use App\Models\KurvAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KurvAccount>
 */
class KurvAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_name' => $this->faker->company(),
            'prefix' => null,
            'api_key' => 'kp_test_placeholder_for_dev_only',
            'environment' => 'sandbox',
            'is_active' => true,
        ];
    }
}
