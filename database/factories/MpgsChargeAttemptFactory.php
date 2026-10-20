<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MpgsChargeAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory()->mpgs(),
            'sequence' => 1,
            'order_id' => fn (array $attributes) => $attributes['payment_id'].'-'.$attributes['sequence'],
            'auth_transaction_id' => Str::random(12),
            'transaction_id' => Str::random(12),
            'session_id' => null,
            'status' => 'approved',
            'auth_status' => null,
            'gateway_code' => null,
            'decline_reason' => null,
            'receipt' => null,
            'attempts_count' => 0,
            'last_checked_at' => null,
        ];
    }

    public function prepared(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'prepared',
            'session_id' => 'SESSION'.Str::random(24),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'pending',
            'session_id' => 'SESSION'.Str::random(24),
        ]);
    }

    public function declined(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'declined',
            'decline_reason' => 'DECLINED',
        ]);
    }

    public function hardError(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'hard_error',
        ]);
    }

    public function abandoned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'abandoned',
            'attempts_count' => 5,
            'last_checked_at' => now(),
        ]);
    }
}
