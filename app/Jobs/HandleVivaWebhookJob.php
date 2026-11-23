<?php

namespace App\Jobs;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Models\VivaAccount;
use App\Services\PaymentAttempts\PaymentAttemptLogger;
use App\Services\Viva\VivaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HandleVivaWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    /**
     * Not promoted constructor properties: PHP skips the constructor when it unserializes a job,
     * so jobs already on the queue keep working with these left null (spec 0016).
     */
    public ?string $eventKey = null;

    public ?int $eventTypeId = null;

    public function __construct(
        public readonly int $vivaAccountId,
        public readonly string $orderCode,
        public readonly ?string $transactionId,
        ?string $eventKey = null,
        ?int $eventTypeId = null,
    ) {
        $this->eventKey = $eventKey;
        $this->eventTypeId = $eventTypeId;
    }

    public function handle(?PaymentAttemptLogger $logger = null): void
    {
        $logger ??= app(PaymentAttemptLogger::class);

        // Viva payment webhooks are unsigned, so the POST that triggered this job
        // is untrusted (see VivaWebhookController). Re-fetch the transaction from
        // Viva's API — the source of truth — and refuse to mark anything paid
        // unless Viva itself reports the transaction succeeded. A forged webhook
        // can't fake this: the attacker can't make Viva's API return statusId 'F'
        // for a transaction that didn't actually complete.
        if ($this->transactionId === null) {
            Log::warning('Viva webhook has no TransactionId; cannot verify — skipping', [
                'viva_account_id' => $this->vivaAccountId,
                'viva_order_code' => $this->orderCode,
            ]);

            return;
        }

        $account = VivaAccount::find($this->vivaAccountId);

        if (! $account) {
            return;
        }

        $client = new VivaClient(
            $account->client_id,
            $account->client_secret,
            $account->merchant_id,
            $account->api_key,
            $account->source_code,
            $account->environment,
        );

        $transaction = $client->retrieveTransaction($this->transactionId);

        // Order-code cross-check: the retrieved transaction must belong to the
        // order named in the webhook (defends against a webhook pointing our
        // completion at the wrong payment). Skipped only if Viva omits the field.
        $txOrderCode = isset($transaction['orderCode']) ? (string) $transaction['orderCode'] : null;

        if ($txOrderCode !== null && $txOrderCode !== $this->orderCode) {
            Log::warning('Viva transaction orderCode does not match webhook — skipping', [
                'viva_account_id' => $this->vivaAccountId,
                'webhook_order_code' => $this->orderCode,
                'transaction_order_code' => $txOrderCode,
            ]);

            return;
        }

        // Diagnostic log (spec 0016): only here, after Viva itself confirmed the transaction, so a forged
        // webhook can never write history. Never throws and never changes a status.
        $logger->record(
            PaymentProvider::Viva,
            Payment::where('viva_order_code', $this->orderCode)->where('viva_account_id', $this->vivaAccountId)->first(),
            $transaction,
            [
                'event_key' => $this->eventKey,
                'event_type_id' => $this->eventTypeId ?? 1796,
                'transaction_id' => $this->transactionId,
            ],
        );

        // 1798 (failed) is unconfirmed against a live sandbox and is for logging only: it never reaches the status update.
        if (($this->eventTypeId ?? 1796) === 1798) {
            return;
        }

        // statusId 'F' = payment finished successfully. Any other status (pending,
        // error, refused, refunded, cancelled) must not complete the payment.
        if (($transaction['statusId'] ?? null) !== 'F') {
            return;
        }

        // Allows pending→completed (first payment) and failed→completed (retry).
        $payment = Payment::where('viva_order_code', $this->orderCode)
            ->where('viva_account_id', $this->vivaAccountId)
            ->whereIn('status', ['pending', 'failed'])
            ->first();

        // Null means no such payment or it is already terminal (idempotent no-op).
        if (! $payment) {
            return;
        }

        $payment->update([
            'status' => 'completed',
            'paid_at' => now(),
            'viva_transaction_id' => $this->transactionId,
        ]);

        SendPaymentNotification::dispatch($payment->fresh(['brand', 'vivaAccount']));
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error('HandleVivaWebhookJob failed', [
            'viva_account_id' => $this->vivaAccountId,
            'viva_order_code' => $this->orderCode,
            'transaction_id' => $this->transactionId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
