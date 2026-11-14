<?php

namespace App\Services\Nmi;

use App\Enums\PaymentProvider;
use App\Jobs\SendPaymentNotification;
use App\Models\Payment;
use App\Services\CardLock\CardLockGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The only code allowed to set an NMI payment to `completed`, and the only writer of
 * `payments.nmi_transaction_id` (spec 0012, AC-9). Shared by the charge endpoint, the status
 * endpoint, the webhook job, the queued lookup and the sweep.
 *
 * Nothing the browser, the sale reply or the webhook body says counts on its own: this class reads
 * the transaction back from NMI's `query.php` and completes the payment only for exactly one
 * approved sale whose order id, amount and currency match the PayHub record. A decline never sets
 * `failed` (v1 never does).
 */
class NmiChargeResolver
{
    public const OUTCOME_PAID = 'paid';

    public const OUTCOME_DECLINED = 'declined';

    public const OUTCOME_PENDING = 'pending';

    /** NMI has no sale for this order (yet). The sweep gives up on it after a while. */
    public const OUTCOME_NOT_FOUND = 'not_found';

    public const OUTCOME_MISMATCH = 'mismatch';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_ERROR = 'error';

    /** Conditions of a sale NMI has accepted. */
    private const APPROVED_CONDITIONS = ['pendingsettlement', 'complete'];

    /** Conditions of a sale that did not go through. */
    private const NOT_APPROVED_CONDITIONS = ['failed', 'canceled', 'abandoned'];

    /**
     * Look the payment's order id up and apply what NMI says. Returns null when no lookup was
     * possible (no order id yet, or no account).
     */
    public function resolve(Payment $payment): ?string
    {
        if ($payment->nmi_order_id === null) {
            return null;
        }

        return $this->lookup($payment, fn (NmiClient $client) => $client->queryByOrderId($payment->nmi_order_id));
    }

    /**
     * One lookup, only for a pending NMI payment that has been charged at least once, and at most one
     * every few seconds per payment. Shared by the status endpoint and the pay page load (AC-10).
     */
    public function resolveGuarded(Payment $payment): ?string
    {
        if ($payment->provider !== PaymentProvider::Nmi
            || $payment->status !== 'pending'
            || $payment->nmi_first_attempt_at === null) {
            return null;
        }

        if (! Cache::add("nmi:lookup:{$payment->id}", 1, (int) config('services.nmi.poll_lookup_seconds', 5))) {
            return null;
        }

        return $this->resolve($payment);
    }

    /** Same, by the transaction id NMI named in a sale reply (read back before any status change). */
    public function resolveTransaction(Payment $payment, string $transactionId): ?string
    {
        return $this->lookup($payment, fn (NmiClient $client) => $client->queryByTransactionId($transactionId));
    }

    /**
     * @param  callable(NmiClient): NmiQueryReply  $query
     */
    private function lookup(Payment $payment, callable $query): ?string
    {
        $account = $payment->loadMissing('nmiAccount')->nmiAccount;

        if ($account === null) {
            return null;
        }

        try {
            $reply = $query(NmiClient::forAccount($account));
        } catch (ConnectionException) {
            return self::OUTCOME_ERROR; // a transport problem: the next lookup tries again
        }

        return $this->apply($payment, $reply);
    }

    public function apply(Payment $payment, NmiQueryReply $reply): string
    {
        $payment->refresh();

        if (! $reply->ok) {
            Log::warning('NMI lookup failed', ['payment_id' => $payment->id, 'error' => $reply->error]);

            return self::OUTCOME_ERROR;
        }

        $sales = array_values(array_filter($reply->transactions, fn (array $t): bool => $t['sale']));
        $approved = array_values(array_filter(
            $sales,
            fn (array $t): bool => $t['sale_success'] && in_array($t['condition'], self::APPROVED_CONDITIONS, true),
        ));

        if ($approved === []) {
            return $this->noApproval($payment, $sales);
        }

        if (count(array_unique(array_column($approved, 'transaction_id'))) > 1) {
            Log::error('NMI returned more than one approved sale for one order', [
                'payment_id' => $payment->id,
                'transaction_ids' => array_column($approved, 'transaction_id'),
            ]);

            return self::OUTCOME_DUPLICATE;
        }

        $sale = $approved[0];

        if ($sale['order_id'] !== (string) $payment->nmi_order_id
            || $sale['amount_cents'] !== $payment->amount
            || $sale['currency'] !== strtoupper((string) $payment->currency)) {
            Log::error('NMI approved sale does not match the payment', [
                'payment_id' => $payment->id,
                'transaction_id' => $sale['transaction_id'],
                'amount_matches' => $sale['amount_cents'] === $payment->amount,
                'currency_matches' => $sale['currency'] === strtoupper((string) $payment->currency),
                'order_matches' => $sale['order_id'] === (string) $payment->nmi_order_id,
            ]);

            return self::OUTCOME_MISMATCH;
        }

        return $this->approve($payment, $sale);
    }

    /**
     * @param  array{transaction_id: string, avs: ?string, cvv: ?string}  $sale
     */
    private function approve(Payment $payment, array $sale): string
    {
        $transactionId = $sale['transaction_id'];

        $updated = Payment::whereKey($payment->id)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status' => 'completed',
                'paid_at' => now(),
                'nmi_transaction_id' => $transactionId,
            ]);

        if ($updated > 0) {
            $this->syncAttempt($payment, $transactionId, 'approved', $sale);
            Cache::forget($this->pauseKey($payment));
            SendPaymentNotification::dispatch($payment->fresh());

            return self::OUTCOME_PAID;
        }

        if ($payment->status === 'completed' && $payment->nmi_transaction_id === $transactionId) {
            $this->syncAttempt($payment, $transactionId, 'approved', $sale);

            return self::OUTCOME_PAID; // the same transaction seen again
        }

        // A second approved sale for a paid payment, or one for a cancelled payment. Refunds are out
        // of scope, so this is only made visible to operators (it reaches Sentry).
        Log::error('NMI approved sale was not applied', [
            'payment_id' => $payment->id,
            'payment_status' => $payment->status,
            'stored_transaction_id' => $payment->nmi_transaction_id,
            'transaction_id' => $transactionId,
        ]);

        return self::OUTCOME_DUPLICATE;
    }

    /**
     * @param  array<int, array{transaction_id: string, condition: string}>  $sales
     */
    private function noApproval(Payment $payment, array $sales): string
    {
        $declined = array_values(array_filter(
            $sales,
            fn (array $t): bool => in_array($t['condition'], self::NOT_APPROVED_CONDITIONS, true),
        ));

        if ($declined === []) {
            // Nothing at all, or a sale that is still pending: look again later.
            return $sales === [] ? self::OUTCOME_NOT_FOUND : self::OUTCOME_PENDING;
        }

        // A decline found late (lookup, webhook) brings the attempt row in line and counts toward the card lock.
        foreach ($declined as $sale) {
            $this->syncAttempt($payment, $sale['transaction_id'], 'declined');
        }

        app(CardLockGuard::class)->recordFailure($payment);

        return self::OUTCOME_DECLINED; // the payment stays pending
    }

    /**
     * Brings the attempt record in line with what NMI now says about this transaction: the row that
     * already carries the id, else the latest charge that got no clear answer. Diagnostic only;
     * it never touches the payment.
     *
     * @param  array{avs?: ?string, cvv?: ?string}  $detail
     */
    private function syncAttempt(Payment $payment, string $transactionId, string $status, array $detail = []): void
    {
        $attempts = $payment->nmiChargeAttempts();

        $attempt = (clone $attempts)->where('transaction_id', $transactionId)->first()
            ?? (clone $attempts)->whereIn('status', ['sent', 'unclear'])->whereNull('transaction_id')->orderByDesc('sequence')->first();

        if ($attempt === null || $attempt->status === $status) {
            return;
        }

        $attempt->update([
            'status' => $status,
            'transaction_id' => $transactionId,
            'reason' => $status === 'approved' ? null : $attempt->reason,
            'avs_result' => $attempt->avs_result ?? ($detail['avs'] ?? null),
            'cvv_result' => $attempt->cvv_result ?? ($detail['cvv'] ?? null),
        ]);
    }

    private function pauseKey(Payment $payment): string
    {
        return "nmi:pause:{$payment->id}";
    }
}
