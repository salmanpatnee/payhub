<?php

namespace App\Services\Worldpay;

use App\Jobs\SendPaymentNotification;
use App\Models\Payment;
use App\Models\WorldpayLookupAttempt;
use App\Models\WorldpayPage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only code allowed to set a Worldpay payment to `completed`, and the only writer of
 * `payments.worldpay_payment_id` (spec 0014, AC-8, AC-9, AC-11).
 *
 * Nothing the browser, the return address, the webhook body or the checkout reply says counts.
 * This class always reads the payment rows from Worldpay's own Payment Queries API and
 * completes the payment only when exactly one paid row exists for one of this payment's page
 * references, with the exact amount in GBP. A refusal, a pending sale, an API error and an
 * unknown event leave `Payment.status` unchanged, and an unknown value never fails a payment.
 */
class WorldpayChargeResolver
{
    public const SOURCE_RETURN = 'return';

    public const SOURCE_POLL = 'poll';

    public const SOURCE_SWEEP = 'sweep';

    public const SOURCE_WEBHOOK = 'webhook';

    public const SOURCE_CHECKOUT = 'checkout';

    public const OUTCOME_PAID = 'paid';

    public const OUTCOME_DECLINED = 'declined';

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_CLOSED = 'closed';

    public const OUTCOME_MISMATCH = 'mismatch';

    public const OUTCOME_DUPLICATE = 'duplicate';

    public const OUTCOME_ERROR = 'error';

    public const OUTCOME_UNKNOWN = 'unknown';

    /** Best outcome first: what resolve() returns when pages disagree. */
    private const PRIORITY = [
        self::OUTCOME_PAID, self::OUTCOME_DUPLICATE, self::OUTCOME_MISMATCH, self::OUTCOME_DECLINED,
        self::OUTCOME_CLOSED, self::OUTCOME_PENDING, self::OUTCOME_ERROR, self::OUTCOME_UNKNOWN,
    ];

    /**
     * Run the lookup for the payment. Returns the best outcome, or null when no lookup was made
     * (no usable page yet) or another lookup holds the lock (a recheck is queued for the holder).
     */
    public function resolve(Payment $payment, string $source): ?string
    {
        if (! $payment->worldpayPages()->usable()->exists()) {
            return null;
        }

        $lock = Cache::lock('worldpay:lookup:'.$payment->id, (int) config('services.worldpay.lookup_lock_seconds', 60));

        if (! $lock->get()) {
            // The holder runs once more before it lets go, so this trigger is not lost.
            Cache::put('worldpay:recheck:'.$payment->id, 1, 60);

            return null;
        }

        try {
            $outcome = $this->lookup($payment->id, $source);

            if (Cache::pull('worldpay:recheck:'.$payment->id)) {
                $outcome = $this->lookup($payment->id, $source) ?? $outcome;
            }

            return $outcome;
        } finally {
            $lock->release();
        }
    }

    private function lookup(int $paymentId, string $source): ?string
    {
        $payment = Payment::with('worldpayAccount')->find($paymentId);

        if ($payment === null) {
            return null;
        }

        $pages = $payment->worldpayPages()->usable()
            ->orderByDesc('sequence')
            ->limit(max(1, (int) config('services.worldpay.max_pages', 1)))
            ->get();

        if ($pages->isEmpty()) {
            return null;
        }

        Payment::whereKey($payment->id)->update(['worldpay_last_lookup_at' => now()]);

        $account = $payment->worldpayAccount;

        if ($account === null) {
            $this->record($payment, $pages->first(), $source, self::OUTCOME_ERROR, null, 'The Worldpay account is missing.');

            return self::OUTCOME_ERROR;
        }

        $client = app()->make(WorldpayClient::class, [
            'username' => $account->api_username,
            'password' => $account->api_password,
            'environment' => $account->environment,
        ]);

        $allRefs = $payment->worldpayPages()->pluck('transaction_reference')->all();

        // A completed payment only needs the pages other than the one that paid, to find a late second sale.
        $skipPageId = $payment->status === 'completed'
            ? WorldpayLookupAttempt::where('payment_id', $payment->id)->where('outcome', self::OUTCOME_PAID)
                ->whereNotNull('page_id')->orderByDesc('id')->value('page_id')
            : null;

        $deadline = microtime(true) + (int) config('services.worldpay.resolver_deadline_seconds', 45);

        /** @var list<array{page: WorldpayPage, event: ?string, outcome: string, reason: ?string, paymentId: ?string}> $results */
        $results = [];
        /** @var list<array{page: WorldpayPage, row: WorldpayLookupReply}> $paidRows */
        $paidRows = [];

        foreach ($pages as $page) {
            if ($skipPageId !== null && $page->id === $skipPageId) {
                continue;
            }

            if ($results !== [] && microtime(true) >= $deadline) {
                // The pages not reached are looked up on the next run.
                Cache::put('worldpay:recheck:'.$payment->id, 1, 60);
                break;
            }

            try {
                $rows = $client->queryPayments($page->transaction_reference);
            } catch (RequestException $e) {
                Log::error('Worldpay lookup failed', $this->context($payment, $source) + ['http_status' => $e->response->status()]);
                $results[] = $this->result($page, null, self::OUTCOME_ERROR, 'Worldpay answered with HTTP '.$e->response->status().'.');

                continue;
            } catch (ConnectionException) {
                Log::error('Worldpay lookup could not connect', $this->context($payment, $source));
                $results[] = $this->result($page, null, self::OUTCOME_ERROR, 'Could not reach Worldpay.');

                continue;
            }

            // A row whose reference is not one of this payment's pages is ignored.
            $rows = array_values(array_filter($rows, fn (WorldpayLookupReply $row) => in_array($row->transactionReference, $allRefs, true)));

            $newest = $this->newest($rows);
            $event = $newest?->lastEvent;

            if ($page->last_event !== $event) {
                $page->update(['last_event' => $event]);
            }

            foreach ($rows as $row) {
                if (in_array($row->lastEvent, $this->events('paid_events'), true)) {
                    $paidRows[] = ['page' => $page, 'row' => $row];
                }
            }

            $results[] = $this->classifyPage($page, $rows, $event);
        }

        if ($results === []) {
            return null;
        }

        $results = $this->settle($payment, $source, $results, $paidRows);

        foreach ($results as $r) {
            $this->record($payment, $r['page'], $source, $r['outcome'], $r['event'], $r['reason'], $r['paymentId']);
        }

        $outcomes = array_column($results, 'outcome');

        foreach (self::PRIORITY as $outcome) {
            if (in_array($outcome, $outcomes, true)) {
                return $outcome;
            }
        }

        return self::OUTCOME_UNKNOWN;
    }

    /**
     * Decide what the paid rows mean for the payment and write the one guarded update when one
     * exact paid sale exists. Returns the page results with their outcomes adjusted.
     *
     * @param  list<array{page: WorldpayPage, event: ?string, outcome: string, reason: ?string, paymentId: ?string}>  $results
     * @param  list<array{page: WorldpayPage, row: WorldpayLookupReply}>  $paidRows
     * @return list<array{page: WorldpayPage, event: ?string, outcome: string, reason: ?string, paymentId: ?string}>
     */
    private function settle(Payment $payment, string $source, array $results, array $paidRows): array
    {
        if ($paidRows === []) {
            return $results;
        }

        $context = $this->context($payment, $source);

        if ($payment->status === 'completed') {
            foreach ($paidRows as $paid) {
                if ($paid['row']->paymentId !== null && $paid['row']->paymentId === $payment->worldpay_payment_id) {
                    $results = $this->mark($results, $paid['page'], self::OUTCOME_PAID, 'Already completed.', $paid['row']->paymentId);

                    continue;
                }

                Log::error('Second Worldpay sale for a completed payment, refund the extra payment in Worldpay', $context + [
                    'recorded_worldpay_payment_id' => $payment->worldpay_payment_id,
                    'extra_worldpay_payment_id' => $paid['row']->paymentId,
                ]);
                $results = $this->mark($results, $paid['page'], self::OUTCOME_DUPLICATE, 'A second sale was made. Refund it in Worldpay.', $paid['row']->paymentId);
            }

            return $results;
        }

        if ($payment->status === 'cancelled') {
            Log::error('Worldpay sale exists for a cancelled payment, refund it in Worldpay', $context + [
                'worldpay_payment_ids' => array_map(fn ($p) => $p['row']->paymentId, $paidRows),
            ]);

            foreach ($paidRows as $paid) {
                $results = $this->mark($results, $paid['page'], self::OUTCOME_UNKNOWN, 'Paid on Worldpay, but this payment is cancelled in PayHub.', $paid['row']->paymentId);
            }

            return $results;
        }

        if (count($paidRows) > 1) {
            Log::error('More than one paid Worldpay sale for one payment, refund the extra payment in Worldpay', $context + [
                'worldpay_payment_ids' => array_map(fn ($p) => $p['row']->paymentId, $paidRows),
            ]);

            foreach ($paidRows as $paid) {
                $results = $this->mark($results, $paid['page'], self::OUTCOME_DUPLICATE, 'More than one paid sale was found. Nothing was changed.', $paid['row']->paymentId);
            }

            return $results;
        }

        $paid = $paidRows[0];
        $row = $paid['row'];

        if ($row->paymentId === null) {
            Log::error('Worldpay shows a paid row without a payment id', $context);

            return $this->mark($results, $paid['page'], self::OUTCOME_UNKNOWN, 'Worldpay shows a paid sale but gave no payment id.', null);
        }

        if ($row->amountCents !== (int) $payment->amount || $row->currency !== 'GBP') {
            Log::error('Worldpay sale amount or currency mismatch', $context + [
                'worldpay_cents' => $row->amountCents,
                'worldpay_currency' => $row->currency,
                'payment_cents' => (int) $payment->amount,
                'payment_currency' => $payment->currency,
                'worldpay_payment_id' => $row->paymentId,
            ]);

            return $this->mark($results, $paid['page'], self::OUTCOME_MISMATCH, 'The amount or currency does not match this payment.', $row->paymentId);
        }

        $updated = DB::transaction(fn () => Payment::where('id', $payment->id)
            ->whereIn('status', ['pending', 'failed'])
            ->update([
                'status' => 'completed',
                'paid_at' => $row->timestamp ?? now(),
                'worldpay_payment_id' => $row->paymentId,
            ]));

        if ($updated > 0) {
            SendPaymentNotification::dispatch($payment->fresh());
        }

        return $this->mark($results, $paid['page'], self::OUTCOME_PAID, null, $row->paymentId);
    }

    /**
     * What one page's newest row says, from the config lists. Never fails a payment.
     *
     * @param  list<WorldpayLookupReply>  $rows
     * @return array{page: WorldpayPage, event: ?string, outcome: string, reason: ?string, paymentId: ?string}
     */
    private function classifyPage(WorldpayPage $page, array $rows, ?string $event): array
    {
        if ($rows === []) {
            // Page age alone never sets closed_at, only an expiry or closed event does.
            return $page->expires_at->isPast()
                ? $this->result($page, null, self::OUTCOME_CLOSED, 'No payment was made before the page expired.')
                : $this->result($page, null, self::OUTCOME_PENDING, 'No payment started on this page yet.');
        }

        $paidHere = collect($rows)->first(fn (WorldpayLookupReply $r) => in_array($r->lastEvent, $this->events('paid_events'), true));

        if ($paidHere !== null) {
            return $this->result($page, $paidHere->lastEvent, self::OUTCOME_PAID, null, $paidHere->paymentId);
        }

        return match (true) {
            in_array($event, $this->events('pending_events'), true) => $this->result($page, $event, self::OUTCOME_PENDING, 'Worldpay shows the sale as not finished: '.$event.'.'),
            in_array($event, $this->events('declined_events'), true) => $this->result($page, $event, self::OUTCOME_DECLINED, 'The payment was not accepted: '.$event.'.'),
            in_array($event, $this->events('closed_events'), true) => $this->closePage($page, $event),
            in_array($event, $this->events('error_events'), true) => $this->result($page, $event, self::OUTCOME_ERROR, 'Worldpay reports a problem with this payment: '.$event.'.'),
            in_array($event, $this->events('refund_events'), true) => $this->unknown($page, $event, 'Worldpay shows a refund state PayHub never saw paid: '.$event.'.'),
            default => $this->unknown($page, $event, 'Worldpay returned the event "'.$event.'".'),
        };
    }

    /**
     * @return array{page: WorldpayPage, event: ?string, outcome: string, reason: ?string, paymentId: ?string}
     */
    private function closePage(WorldpayPage $page, ?string $event): array
    {
        if ($page->closed_at === null) {
            $page->update(['closed_at' => now()]);
        }

        return $this->result($page, $event, self::OUTCOME_CLOSED, 'Worldpay reports this page as '.$event.'.');
    }

    /**
     * @return array{page: WorldpayPage, event: ?string, outcome: string, reason: ?string, paymentId: ?string}
     */
    private function unknown(WorldpayPage $page, ?string $event, string $reason): array
    {
        Log::warning('Worldpay returned an event PayHub does not recognize', ['page_id' => $page->id, 'payment_id' => $page->payment_id, 'last_event' => $event]);

        return $this->result($page, $event, self::OUTCOME_UNKNOWN, $reason);
    }

    /**
     * @return array{page: WorldpayPage, event: ?string, outcome: string, reason: ?string, paymentId: ?string}
     */
    private function result(WorldpayPage $page, ?string $event, string $outcome, ?string $reason, ?string $paymentId = null): array
    {
        return ['page' => $page, 'event' => $event, 'outcome' => $outcome, 'reason' => $reason, 'paymentId' => $paymentId];
    }

    /**
     * @param  list<array{page: WorldpayPage, event: ?string, outcome: string, reason: ?string, paymentId: ?string}>  $results
     * @return list<array{page: WorldpayPage, event: ?string, outcome: string, reason: ?string, paymentId: ?string}>
     */
    private function mark(array $results, WorldpayPage $page, string $outcome, ?string $reason, ?string $paymentId): array
    {
        foreach ($results as $i => $r) {
            if ($r['page']->id === $page->id) {
                $results[$i]['outcome'] = $outcome;
                $results[$i]['reason'] = $reason;
                $results[$i]['paymentId'] = $paymentId;
            }
        }

        return $results;
    }

    /**
     * @param  list<WorldpayLookupReply>  $rows
     */
    private function newest(array $rows): ?WorldpayLookupReply
    {
        if ($rows === []) {
            return null;
        }

        usort($rows, fn (WorldpayLookupReply $a, WorldpayLookupReply $b) => ($b->timestamp?->getTimestamp() ?? 0) <=> ($a->timestamp?->getTimestamp() ?? 0));

        return $rows[0];
    }

    /**
     * @return list<string>
     */
    private function events(string $key): array
    {
        return array_values((array) config('services.worldpay.'.$key, []));
    }

    /**
     * One attempt row per page per run. A repeat with the same outcome and event as that page's
     * newest row only adds to `checks` (spec 0014, AC-9).
     */
    private function record(Payment $payment, WorldpayPage $page, string $source, string $outcome, ?string $event, ?string $reason, ?string $worldpayPaymentId = null): void
    {
        $latest = WorldpayLookupAttempt::where('page_id', $page->id)->orderByDesc('id')->first();

        if ($latest !== null && $latest->outcome === $outcome && $latest->last_event === $event) {
            $latest->update(['checks' => $latest->checks + 1, 'last_checked_at' => now()]);

            return;
        }

        WorldpayLookupAttempt::create([
            'payment_id' => $payment->id,
            'page_id' => $page->id,
            'source' => $source,
            'outcome' => $outcome,
            'last_event' => $event,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'worldpay_payment_id' => $worldpayPaymentId,
            'checks' => 1,
            'last_checked_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Payment $payment, string $source): array
    {
        return [
            'payment_id' => $payment->id,
            'worldpay_account_id' => $payment->worldpay_account_id,
            'source' => $source,
        ];
    }
}
