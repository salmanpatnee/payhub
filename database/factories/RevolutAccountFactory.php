<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class RevolutAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_name' => $this->faker->company(),
            'public_key' => 'pk_'.$this->faker->regexify('[a-zA-Z0-9]{24}'),
            'secret_key' => 'sk_test_placeholder_for_dev_only',
            'webhook_secret' => null,
            'is_active' => true,
        ];
    }
}
