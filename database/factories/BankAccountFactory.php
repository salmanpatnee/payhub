<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class BankAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'bank_name' => $this->faker->company().' Bank',
            'account_name' => $this->faker->company(),
            'account_number' => $this->faker->numerify('########'),
            'currency' => $this->faker->randomElement(['usd', 'gbp']),
            'sort_code' => $this->faker->numerify('##-##-##'),
            'routing_number' => null,
            'iban' => null,
            'swift_bic' => null,
            'bank_address' => null,
            'bank_country' => null,
            'is_active' => true,
        ];
    }
}
