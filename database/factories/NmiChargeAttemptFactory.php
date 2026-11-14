<?php

namespace Database\Factories;

use App\Models\NmiChargeAttempt;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NmiChargeAttempt>
 */
class NmiChargeAttemptFactory extends Factory
{
    protected $model = NmiChargeAttempt::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'sequence' => 1,
            'method' => 'card',
            'status' => 'sent',
        ];
    }
}
