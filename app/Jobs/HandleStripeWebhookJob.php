<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class HandleStripeWebhookJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $stripeAccountId,
        public readonly string $eventType,
        public readonly array $eventData,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Wave 2 implementation: HandleStripeWebhookJob (06-02)
    }
}
