<?php

namespace App\Jobs;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Models\ProcessedNmiEvent;
use App\Services\Nmi\NmiChargeResolver;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Handles an NMI sale notification (spec 0012, AC-14). The webhook body is only a hint: this job finds the
 * payment by the order id within the route's account and runs a lookup through the resolver. The dedupe
 * row is written only after the job succeeds, so a failed job can still be retried.
 */
class HandleNmiWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $accountId,
        public readonly ?string $eventId,
        public readonly ?string $orderId,
    ) {}

    public function handle(NmiChargeResolver $resolver): void
    {
        if ($this->eventId !== null && ProcessedNmiEvent::where('event_id', $this->eventId)->exists()) {
            return; // this delivery was already handled
        }

        $payment = $this->orderId === null || $this->orderId === '' ? null : Payment::query()
            ->where('provider', PaymentProvider::Nmi->value)
            ->where('nmi_account_id', $this->accountId)
            ->where('nmi_order_id', $this->orderId)
            ->first();

        if ($payment === null) {
            Log::warning('NMI webhook order id matches no payment of this account', ['account_id' => $this->accountId]);
        } elseif ($resolver->resolve($payment) === NmiChargeResolver::OUTCOME_ERROR) {
            // Let the queue retry; the status lookup and the sweep also cover a miss.
            throw new RuntimeException('NMI lookup for a webhook could not be completed.');
        }

        $this->markDone();
    }

    private function markDone(): void
    {
        if ($this->eventId === null) {
            return;
        }

        try {
            ProcessedNmiEvent::create(['event_id' => $this->eventId]);
        } catch (UniqueConstraintViolationException) {
            // A parallel delivery finished first.
        }
    }
}
