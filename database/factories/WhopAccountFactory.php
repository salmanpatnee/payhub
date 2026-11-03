<?php

namespace Database\Factories;

use App\Models\WhopAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WhopAccount>
 */
class WhopAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'account_name' => $this->faker->company(),
            'prefix' => null,
            'company_id' => 'biz_'.$this->faker->regexify('[a-zA-Z0-9]{14}'),
            'api_key' => 'apik_placeholder_for_dev_only',
            'webhook_secret' => null,
            'environment' => 'sandbox',
            'is_active' => true,
        ];
    }
}
