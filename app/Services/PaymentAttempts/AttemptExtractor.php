<?php

namespace App\Services\PaymentAttempts;

use App\Models\Payment;

/**
 * One class per provider: the only place that knows that provider's field names for logging (spec 0016).
 * Return null when the event is not one that gets logged.
 */
interface AttemptExtractor
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function extract(array $payload, array $context, Payment $payment): ?AttemptData;
}
