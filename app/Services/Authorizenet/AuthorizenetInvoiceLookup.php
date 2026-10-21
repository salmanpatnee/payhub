<?php

namespace App\Services\Authorizenet;

use App\Models\AuthorizenetAccount;
use App\Models\Payment;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Finds a payment's transaction at Authorize.net by invoice number and hands it
 * to the shared resolver (spec 0006, AC-6, AC-11). Read only: it never creates a
 * charge. Used by the status endpoint, the pay page `show`, the sweep and the webhook job.
 */
class AuthorizenetInvoiceLookup
{
    private const PAGE_SIZE = 100;

    private const MAX_PAGES = 5;

    /** Minutes of clock slack when comparing gateway times to the token time. */
    private const SLACK_MINUTES = 5;

    public function __construct(private readonly AuthorizenetChargeResolver $resolver) {}

    public function client(AuthorizenetAccount $account): AuthorizenetClient
    {
        return app()->make(AuthorizenetClient::class, [
            'apiLoginId' => $account->api_login_id,
            'transactionKey' => $account->transaction_key,
            'environment' => $account->environment,
        ]);
    }

    /**
     * Guarded lookup for one payment (public status endpoint and returning client).
     * A caller who loses a guard simply returns; the caller reads the current state.
     */
    public function lookup(Payment $payment, ?string $hintTransactionId = null): void
    {
        if (! $this->mayLookup($payment)) {
            return;
        }

        $account = $payment->authorizenetAccount;
        if (! $account?->is_active) {
            return;
        }

        try {
            $client = $this->client($account);

            if ($hintTransactionId !== null && preg_match('/^\d{1,20}$/', $hintTransactionId)) {
                $this->resolveTransaction($payment, $client, $hintTransactionId);

                return;
            }

            if ($payment->authorizenet_transaction_id !== null) {
                $this->resolveTransaction($payment, $client, $payment->authorizenet_transaction_id);

                return;
            }

            if (! Cache::add("authorizenet:account:{$account->id}", 1, now()->addSecond())) {
                return;
            }

            $invoice = AuthorizenetInvoice::for($payment);
            $since = $payment->authorizenet_token_issued_at->copy()->subMinutes(self::SLACK_MINUTES);

            foreach ($this->matchingRows($client, [$invoice], $since)[$invoice] ?? [] as $row) {
                $this->resolveTransaction($payment, $client, (string) $row['transId']);
            }
        } catch (Throwable $e) {
            Log::warning('Authorize.net lookup failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Sweep one account: one paged list pass for every candidate that has no
     * transaction id yet, and a direct read for those that do (AC-11).
     *
     * @param  Collection<int, Payment>  $payments
     */
    public function sweepAccount(AuthorizenetAccount $account, Collection $payments): void
    {
        $client = $this->client($account);

        [$known, $unknown] = $payments->partition(fn (Payment $p) => $p->authorizenet_transaction_id !== null);

        foreach ($known as $payment) {
            $this->resolveTransaction($payment, $client, $payment->authorizenet_transaction_id);
        }

        if ($unknown->isEmpty()) {
            return;
        }

        $byInvoice = $unknown->keyBy(fn (Payment $p) => AuthorizenetInvoice::for($p));
        $since = $unknown->min('authorizenet_token_issued_at')->copy()->subMinutes(self::SLACK_MINUTES);

        foreach ($this->matchingRows($client, $byInvoice->keys()->all(), $since) as $invoice => $invoiceRows) {
            $payment = $byInvoice[$invoice];
            $paymentSince = $payment->authorizenet_token_issued_at->copy()->subMinutes(self::SLACK_MINUTES);

            foreach ($invoiceRows as $row) {
                if (Carbon::parse($row['submitTimeUTC'])->lt($paymentSince)) {
                    continue;
                }
                $this->resolveTransaction($payment, $client, (string) $row['transId']);
            }
        }
    }

    /**
     * Read one transaction, classify it and apply it. Used by every path.
     */
    public function resolveTransaction(Payment $payment, AuthorizenetClient $client, string $transactionId): void
    {
        $result = $client->getTransactionDetails($transactionId);
        $transaction = $result['body']['transaction'] ?? null;

        if (! AuthorizenetClient::isOk($result) || ! is_array($transaction)) {
            Log::warning('Authorize.net transaction read failed', [
                'payment_id' => $payment->id,
                'transaction_id' => $transactionId,
                'http_status' => $result['status'],
            ]);

            return;
        }

        $bucket = AuthorizenetChargeClassifier::classify($transaction, $payment);

        $this->resolver->apply($payment, $bucket, $transactionId, $transaction);
    }

    /**
     * @return bool whether every AC-6 guard allows a gateway lookup right now
     */
    private function mayLookup(Payment $payment): bool
    {
        $issuedAt = $payment->authorizenet_token_issued_at;

        if ($payment->status !== 'pending' || $issuedAt === null || $issuedAt->lt(now()->subMinutes(60))) {
            return false;
        }

        $countKey = "authorizenet:lookup-count:{$payment->id}";
        if ((int) Cache::get($countKey, 0) >= 120) {
            return false;
        }

        $seconds = $issuedAt->gt(now()->subMinutes(15)) ? 5 : 60;
        if (! Cache::add("authorizenet:lookup:{$payment->id}", 1, now()->addSeconds($seconds))) {
            return false;
        }

        Cache::add($countKey, 0, now()->addHour());
        Cache::increment($countKey);

        return true;
    }

    /**
     * Paged list pass (newest first). Returns rows grouped by wanted invoice number,
     * skipping rows the list already shows as declined.
     *
     * @param  list<string>  $invoices
     * @return array<string, list<array<string, mixed>>>
     */
    private function matchingRows(AuthorizenetClient $client, array $invoices, DateTimeInterface $since): array
    {
        $found = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $result = $client->getUnsettledTransactionList(self::PAGE_SIZE, $page);

            if (! AuthorizenetClient::isOk($result)) {
                Log::warning('Authorize.net list call failed', ['http_status' => $result['status']]);

                break;
            }

            $rows = $result['body']['transactions'] ?? [];

            foreach ($rows as $row) {
                $invoice = (string) ($row['invoiceNumber'] ?? '');

                if (in_array($invoice, $invoices, true)
                    && ($row['transactionStatus'] ?? '') !== 'declined'
                    && Carbon::parse($row['submitTimeUTC'])->gte(Carbon::instance($since))) {
                    $found[$invoice][] = $row;
                }
            }

            $last = end($rows);
            if (count($rows) < self::PAGE_SIZE
                || ($last !== false && Carbon::parse($last['submitTimeUTC'])->lt(Carbon::instance($since)))) {
                break;
            }
        }

        return $found;
    }
}
