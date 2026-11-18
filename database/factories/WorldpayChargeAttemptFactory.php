<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\WorldpayChargeAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorldpayChargeAttempt>
 */
class WorldpayChargeAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'sequence' => 1,
            'transaction_reference' => fn () => 'ref-'.fake()->unique()->uuid(),
            'method' => 'card',
            'outcome' => WorldpayChargeAttempt::OUTCOME_APPROVED,
            'step' => 'charge',
        ];
    }

    public function outcome(string $outcome): static
    {
        return $this->state(['outcome' => $outcome]);
    }
}
