<?php

namespace App\Jobs;

use App\Models\AuthorizenetAccount;
use App\Models\Payment;
use App\Services\Authorizenet\AuthorizenetClient;
use App\Services\Authorizenet\AuthorizenetInvoice;
use App\Services\Authorizenet\AuthorizenetInvoiceLookup;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Handles an Authorize.net notification (spec 0006, AC-8). The webhook body is
 * only a hint: this job reads the transaction at the gateway, finds its payment
 * by invoice number and applies it through the shared resolver.
 */
class HandleAuthorizenetWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public readonly int $accountId,
        public readonly string $transactionId,
    ) {}

    public function handle(AuthorizenetInvoiceLookup $lookup): void
    {
        $account = AuthorizenetAccount::find($this->accountId);

        if (! $account) {
            return;
        }

        $client = $lookup->client($account);
        $result = $client->getTransactionDetails($this->transactionId);

        if (! AuthorizenetClient::isOk($result)) {
            // Let the queue retry; the status lookup and the sweep also cover a miss.
            throw new \RuntimeException('Authorize.net transaction '.$this->transactionId.' could not be read.');
        }

        $invoice = (string) ($result['body']['transaction']['order']['invoiceNumber'] ?? '');
        $payment = $this->paymentForInvoice($invoice, $account);

        if (! $payment) {
            Log::warning('Authorize.net webhook transaction matches no payment of this account', [
                'account_id' => $account->id,
                'transaction_id' => $this->transactionId,
            ]);

            return;
        }

        $lookup->resolveTransaction($payment, $client, $this->transactionId);
    }

    private function paymentForInvoice(string $invoice, AuthorizenetAccount $account): ?Payment
    {
        // Soft deleted payments are excluded on purpose.
        $query = fn () => Payment::query()->where('authorizenet_account_id', $account->id);

        // Current format: the reference code ("AUTH-001020" or "001020").
        $reference = AuthorizenetInvoice::referenceFromInvoice($invoice);
        $payment = $reference !== null ? $query()->where('reference_code', $reference)->first() : null;

        // Legacy format: 6+ digit zero padded id followed by 8 hex characters of the UUID.
        if (! $payment && preg_match('/^(\d{6,})[0-9a-f]{8}$/', $invoice, $m)) {
            $payment = $query()->find((int) $m[1]);
        }

        return $payment && AuthorizenetInvoice::matches($payment, $invoice) ? $payment : null;
    }
}
