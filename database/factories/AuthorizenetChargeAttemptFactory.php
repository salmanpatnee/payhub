<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuthorizenetChargeAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory()->authorizenet(),
            'sequence' => 1,
            'status' => 'declined',
            'response_code' => '2',
            'error_code' => '2',
            'reason' => 'Card declined',
            'transaction_id' => null,
            'avs_result' => null,
            'cvv_result' => null,
            'cavv_result' => null,
        ];
    }
}
