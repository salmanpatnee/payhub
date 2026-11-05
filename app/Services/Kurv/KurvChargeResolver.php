<?php

namespace App\Services\Kurv;

use App\Jobs\SendPaymentNotification;
use App\Models\KurvLookupAttempt;
use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only code allowed to set a Kurv payment to `completed` (spec 0010, AC-7, AC-11, AC-12).
 *
 * Kurv's callback is unsigned, so nothing the browser or the callback body says counts.
 * This class always reads the payment request from Kurv's own API and completes the
 * payment only when Kurv reports a successful sale for the saved transaction id with the
 * exact amount and currency of the PayHub record. A decline, a pending result, an API
 * error and an unknown status leave `Payment.status` unchanged.
 */
class KurvChargeResolver
{
    public const SOURCE_CALLBACK = 'callback';

    public const SOURCE_POLL = 'poll';

    public const SOURCE_SWEEP = 'sweep';

    public const OUTCOME_PAID = 'paid';

    public const OUTCOME_DECLINED = 'declined';

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_MISMATCH = 'mismatch';

    public const OUTCOME_CLOSED = 'closed';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_ERROR = 'error';

    public const OUTCOME_UNKNOWN = 'unknown';

    /**
     * Run one lookup for the payment. Returns the outcome, or null when no lookup was
     * made (no request id yet, nothing to do) or another lookup holds the lock (a
     * recheck is queued for the lock holder).
     */
    public function resolve(Payment $payment, string $source): ?string
    {
        if ($payment->kurv_request_id === null) {
            return null;
        }

        $lock = Cache::lock('kurv:lookup:'.$payment->id, (int) config('services.kurv.lock_seconds', 60));

        if (! $lock->get()) {
            // The holder runs once more before it lets go, so this trigger is not lost.
            Cache::put('kurv:recheck:'.$payment->id, 1, 60);

            return null;
        }

        try {
            $outcome = $this->lookup($payment->id, $source);

            if (Cache::pull('kurv:recheck:'.$payment->id)) {
                $outcome = $this->lookup($payment->id, $source) ?? $outcome;
            }

            return $outcome;
        } finally {
            $lock->release();
        }
    }

    private function lookup(int $paymentId, string $source): ?string
    {
        $payment = Payment::with('kurvAccount')->find($paymentId);

        if ($payment === null || $payment->kurv_request_id === null) {
            return null;
        }

        // A completed payment only needs a second look when a callback arrives (a possible
        // second payment), never a status lookup.
        if ($payment->status === 'completed' && $source !== self::SOURCE_CALLBACK) {
            return null;
        }

        Payment::whereKey($payment->id)->update(['kurv_last_lookup_at' => now()]);

        $account = $payment->kurvAccount;

        if ($account === null) {
            return $this->record($payment, $source, self::OUTCOME_ERROR, null, 'The Kurv account is missing.');
        }

        $client = app()->make(KurvClient::class, [
            'apiKey' => $account->api_key,
            'environment' => $account->environment,
        ]);

        try {
            $reply = $client->getPaymentRequest($payment->kurv_request_id);
        } catch (RequestException $e) {
            Log::error('Kurv lookup failed', $this->context($payment, $source) + ['http_status' => $e->response->status()]);

            return $this->record($payment, $source, self::OUTCOME_ERROR, null, 'Kurv answered with HTTP '.$e->response->status().'.');
        } catch (ConnectionException) {
            Log::error('Kurv lookup could not connect', $this->context($payment, $source));

            return $this->record($payment, $source, self::OUTCOME_ERROR, null, 'Could not reach Kurv.');
        }

        if ($reply === null) {
            Log::error('Kurv lookup reply was unreadable', $this->context($payment, $source));

            return $this->record($payment, $source, self::OUTCOME_ERROR, null, 'Kurv sent a reply PayHub could not read.');
        }

        if ($payment->status === 'completed') {
            return $this->checkForSecondPayment($payment, $client, $source, $reply);
        }

        return match ($reply->state) {
            KurvLookupReply::STATE_SUCCESS => $this->handleSuccess($payment, $client, $source, $reply),
            KurvLookupReply::STATE_PENDING => $this->handlePending($payment, $client, $source, $reply),
            KurvLookupReply::STATE_CLOSED => $this->handleClosed($payment, $source, $reply),
            default => $this->handleUnknown($payment, $source, $reply),
        };
    }

    private function handleSuccess(Payment $payment, KurvClient $client, string $source, KurvLookupReply $reply): string
    {
        $context = $this->context($payment, $source);

        if ($reply->transactionId !== $payment->kurv_request_id) {
            Log::error('Kurv reported a different transaction id than the saved request', $context + ['reply_transaction_id' => $reply->transactionId]);

            return $this->record($payment, $source, self::OUTCOME_MISMATCH, $reply, 'The transaction id does not match.');
        }

        $paymentId = $reply->paymentId;
        $paidAt = $reply->paidAt;

        if ($paymentId === null) {
            // A paid request without a payment id: find the history row of this transaction.
            $row = $this->findHistoryRow($payment, $client, KurvLookupReply::STATE_SUCCESS);
            $paymentId = $row?->paymentId;
            $paidAt ??= $row?->paidAt;
        }

        if ($paymentId === null) {
            Log::error('Kurv request is paid but no payment id was found', $context);

            return $this->record($payment, $source, self::OUTCOME_UNKNOWN, $reply, 'Kurv shows the request as paid but gave no payment id.');
        }

        if ($reply->amountCents !== (int) $payment->amount
            || $reply->currency !== strtolower((string) $payment->currency)
            || ! $reply->isSale()) {
            Log::error('Kurv payment amount, currency or type mismatch', $context + [
                'kurv_cents' => $reply->amountCents,
                'kurv_currency' => $reply->currency,
                'kurv_payment_type' => $reply->paymentType,
                'payment_cents' => (int) $payment->amount,
                'payment_currency' => $payment->currency,
            ]);

            return $this->record($payment, $source, self::OUTCOME_MISMATCH, $reply, 'The amount, currency or payment type does not match this payment.', $paymentId);
        }

        if ($payment->status === 'cancelled') {
            Log::error('Kurv payment succeeded for a cancelled payment', $context + ['kurv_payment_id' => $paymentId]);

            return $this->record($payment, $source, self::OUTCOME_UNKNOWN, $reply, 'Paid on Kurv, but this payment is cancelled in PayHub.', $paymentId);
        }

        $updated = DB::transaction(fn () => Payment::where('id', $payment->id)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status' => 'completed',
                'paid_at' => $paidAt ?? now(),
                'kurv_payment_id' => $paymentId,
            ]));

        if ($updated > 0) {
            SendPaymentNotification::dispatch($payment->fresh());
        }

        return $this->record($payment, $source, self::OUTCOME_PAID, $reply, null, $paymentId);
    }

    private function handlePending(Payment $payment, KurvClient $client, string $source, KurvLookupReply $reply): string
    {
        // A decline leaves the request pending (spec 0010, Step 0): it only shows as a failed
        // history row. Only look when a client may be waiting, never in the background sweep.
        if ($source !== self::SOURCE_SWEEP) {
            $decline = $this->newDecline($payment, $client);

            if ($decline !== null) {
                return $this->record($payment, $source, self::OUTCOME_DECLINED, $reply, $decline->reason ?? 'Kurv declined the card.');
            }
        }

        return $this->record($payment, $source, self::OUTCOME_PENDING, $reply, null);
    }

    private function handleClosed(Payment $payment, string $source, KurvLookupReply $reply): string
    {
        Payment::where('id', $payment->id)->whereNull('kurv_link_closed_at')->update(['kurv_link_closed_at' => now()]);

        Log::warning('Kurv reports the payment link as closed', $this->context($payment, $source) + ['kurv_status' => $reply->status]);

        return $this->record($payment, $source, self::OUTCOME_CLOSED, $reply, 'Kurv reports this link as '.$reply->status.'.');
    }

    private function handleUnknown(Payment $payment, string $source, KurvLookupReply $reply): string
    {
        Log::warning('Kurv returned a status PayHub does not recognize', $this->context($payment, $source) + ['kurv_status' => $reply->status]);

        return $this->record($payment, $source, self::OUTCOME_UNKNOWN, $reply, 'Kurv returned the status "'.$reply->status.'".');
    }

    /**
     * A callback for a payment that is already completed: look at the history once. A second
     * successful payment under the same transaction id is only logged, staff refund it by hand.
     */
    private function checkForSecondPayment(Payment $payment, KurvClient $client, string $source, KurvLookupReply $reply): string
    {
        $context = $this->context($payment, $source);

        try {
            $rows = $client->searchPayments($payment->formattedReferenceCode());
        } catch (RequestException|ConnectionException) {
            return $this->record($payment, $source, self::OUTCOME_ERROR, $reply, 'The history lookup failed.');
        }

        foreach ($rows as $row) {
            if ($row->transactionId === $payment->kurv_request_id
                && $row->state === KurvLookupReply::STATE_SUCCESS
                && $row->paymentId !== null
                && $payment->kurv_payment_id !== null
                && $row->paymentId !== $payment->kurv_payment_id) {
                Log::error('Second Kurv payment for a completed payment, refund the extra payment in Kurv', $context + [
                    'recorded_kurv_payment_id' => $payment->kurv_payment_id,
                    'extra_kurv_payment_id' => $row->paymentId,
                ]);

                return $this->record($payment, $source, self::OUTCOME_DUPLICATE, $reply, 'A second payment was made. Refund it in Kurv.', $row->paymentId);
            }
        }

        return $this->record($payment, $source, self::OUTCOME_PAID, $reply, 'Already completed.', $payment->kurv_payment_id);
    }

    /**
     * The newest failed history row of this transaction that is newer than the last decline
     * we already recorded, so one decline is reported once and a retry is judged on its own.
     */
    private function newDecline(Payment $payment, KurvClient $client): ?KurvLookupReply
    {
        $lastDeclineAt = KurvLookupAttempt::where('payment_id', $payment->id)
            ->where('outcome', self::OUTCOME_DECLINED)
            ->max('created_at');

        try {
            $rows = $client->searchPayments($payment->formattedReferenceCode());
        } catch (RequestException|ConnectionException) {
            return null;
        }

        $failed = array_filter($rows, fn (KurvLookupReply $row) => $row->transactionId === $payment->kurv_request_id
            && $row->state === KurvLookupReply::STATE_FAILED
            && ($lastDeclineAt === null || ($row->paidAt !== null && $row->paidAt->greaterThan($lastDeclineAt))));

        usort($failed, fn (KurvLookupReply $a, KurvLookupReply $b) => ($b->paidAt?->getTimestamp() ?? 0) <=> ($a->paidAt?->getTimestamp() ?? 0));

        return $failed[0] ?? null;
    }

    private function findHistoryRow(Payment $payment, KurvClient $client, string $state): ?KurvLookupReply
    {
        try {
            $rows = $client->searchPayments($payment->formattedReferenceCode());
        } catch (RequestException|ConnectionException) {
            return null;
        }

        foreach ($rows as $row) {
            if ($row->transactionId === $payment->kurv_request_id && $row->state === $state) {
                return $row;
            }
        }

        return null;
    }

    private function record(Payment $payment, string $source, string $outcome, ?KurvLookupReply $reply, ?string $reason, ?string $kurvPaymentId = null): string
    {
        KurvLookupAttempt::create([
            'payment_id' => $payment->id,
            'source' => $source,
            'outcome' => $outcome,
            'kurv_status' => $reply?->status,
            'result_code' => $reply?->resultCode,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'kurv_request_id' => $payment->kurv_request_id,
            'kurv_payment_id' => $kurvPaymentId ?? $reply?->paymentId,
        ]);

        return $outcome;
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Payment $payment, string $source): array
    {
        return [
            'payment_id' => $payment->id,
            'kurv_account_id' => $payment->kurv_account_id,
            'kurv_request_id' => $payment->kurv_request_id,
            'source' => $source,
        ];
    }
}
