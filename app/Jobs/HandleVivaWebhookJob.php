<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\VivaAccount;
use App\Services\Viva\VivaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class HandleVivaWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [1, 5, 10];

    public function __construct(
        public readonly int $vivaAccountId,
        public readonly string $orderCode,
        public readonly ?string $transactionId,
    ) {}

    public function handle(): void
    {
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

        // statusId 'F' = payment finished successfully. Any other status (pending,
        // error, refused, refunded, cancelled) must not complete the payment.
        if (($transaction['statusId'] ?? null) !== 'F') {
            return;
        }

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
