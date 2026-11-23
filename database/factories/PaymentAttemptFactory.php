<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAttempt>
 */
class PaymentAttemptFactory extends Factory
{
    protected $model = PaymentAttempt::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'provider' => 'stripe',
            'sequence' => 1,
            'event_key' => fake()->unique()->uuid(),
            'event' => 'payment_intent.succeeded',
            'status' => 'succeeded',
            'amount' => 10000,
            'currency' => 'usd',
            'amount_reported' => true,
            'source' => 'webhook',
            'occurred_at' => now(),
        ];
    }
}
