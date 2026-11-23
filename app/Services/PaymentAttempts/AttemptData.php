<?php

namespace App\Services\PaymentAttempts;

use Carbon\CarbonInterface;

/**
 * The shared shape every extractor produces (spec 0016).
 */
final readonly class AttemptData
{
    /**
     * @param  'succeeded'|'failed'|'cancelled'|'pending'  $status
     * @param  array<string, mixed>|null  $request
     * @param  array<string, mixed>|null  $response
     */
    public function __construct(
        public string $event,
        public string $status,
        public ?string $eventKey = null,
        public ?string $reference = null,
        public ?string $errorCode = null,
        public ?string $reason = null,
        public ?int $amount = null,
        public ?string $currency = null,
        public bool $amountReported = false,
        public ?array $request = null,
        public ?array $response = null,
        public ?CarbonInterface $occurredAt = null,
        public string $source = 'webhook',
    ) {}
}
