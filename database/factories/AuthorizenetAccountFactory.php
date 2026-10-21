<?php

namespace Database\Factories;

use App\Enums\AuthorizenetEnvironment;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuthorizenetAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_name' => $this->faker->company(),
            'api_login_id' => $this->faker->regexify('[A-Za-z0-9]{10}'),
            'transaction_key' => 'placeholder_transaction_key_for_dev_only',
            'signature_key' => null,
            'environment' => AuthorizenetEnvironment::Sandbox,
            'currency' => 'usd',
            'is_active' => true,
        ];
    }
}
