<?php

namespace Database\Factories;

use App\Enums\NmiEnvironment;
use App\Models\NmiAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NmiAccount>
 */
class NmiAccountFactory extends Factory
{
    protected $model = NmiAccount::class;

    public function definition(): array
    {
        return [
            'account_name' => $this->faker->company(),
            'environment' => NmiEnvironment::Sandbox,
            'currency' => 'usd',
            'private_key' => 'placeholder_private_key_for_dev_only',
            'public_tokenization_key' => implode('-', array_map(fn () => Str::random(6), range(1, 4))),
            'webhook_signing_key' => null,
            'three_ds_enabled' => false,
            'google_pay_enabled' => false,
            'is_active' => true,
        ];
    }
}
