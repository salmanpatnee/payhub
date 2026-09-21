<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ZelleAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_name' => $this->faker->company(),
            'email' => $this->faker->unique()->safeEmail(),
            'mobile_number' => $this->faker->numerify('+1 ### ### ####'),
            'currency' => $this->faker->randomElement(['usd', 'gbp']),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
