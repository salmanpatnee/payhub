<?php

namespace Database\Factories;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Factories\Factory;

class StripeAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'brand_id'        => Brand::factory(),
            'account_name'    => $this->faker->company(),
            'publishable_key' => 'pk_test_' . $this->faker->regexify('[a-zA-Z0-9]{24}'),
            'secret_key'      => 'sk_test_placeholder_for_dev_only',
            'webhook_secret'  => 'whsec_placeholder_for_dev_only',
            'is_active'       => true,
        ];
    }
}
