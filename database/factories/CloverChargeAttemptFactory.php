<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CloverChargeAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory()->clover(),
            'idempotency_key' => (string) Str::uuid(),
            'clover_charge_id' => $this->faker->regexify('[A-Z0-9]{13}'),
            'status' => 'approved',
            'decline_reason' => null,
            'attempts_count' => 0,
            'last_checked_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'pending',
            'clover_charge_id' => null,
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'declined',
            'decline_reason' => 'Card declined',
        ]);
    }

    public function hardError(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'hard_error',
            'clover_charge_id' => null,
        ]);
    }

    public function abandoned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'abandoned',
            'clover_charge_id' => null,
            'attempts_count' => 5,
            'last_checked_at' => now(),
        ]);
    }
}
