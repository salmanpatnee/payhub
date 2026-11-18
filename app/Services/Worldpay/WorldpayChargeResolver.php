<?php

namespace App\Services\Worldpay;

use App\Jobs\SendPaymentNotification;
use App\Models\Payment;
use App\Models\WorldpayChargeAttempt;
use App\Models\WorldpayLookupAttempt;
use App\Models\WorldpayPage;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The only code allowed to set a Worldpay payment to `completed`, and the only writer of
 * `payments.worldpay_payment_id` (spec 0014, AC-8, AC-9, AC-11; spec 0015, AC-9).
 *
 * Nothing the browser, the return address, the webhook body or the checkout or charge reply says
 * counts. This class always reads the payment rows from Worldpay's own Payment Queries API and
 * completes the payment only when exactly one paid row exists for one of this payment's
 * references (a hosted page reference or a card charge attempt reference), with the exact
 * amount in GBP. A refusal, a pending sale, an API error and an unknown event leave
 * `Payment.status` unchanged, and an unknown value never fails a payment.
 *
 * A "subject" below is either a WorldpayPage (hosted mode) or a WorldpayChargeAttempt (card
 * fields mode): both have one `transaction_reference` that is looked up.
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

    /** Best outcome first: what resolve() returns when subjects disagree. */
    private const PRIORITY = [
        self::OUTCOME_PAID, self::OUTCOME_DUPLICATE, self::OUTCOME_MISMATCH, self::OUTCOME_DECLINED,
        self::OUTCOME_CLOSED, self::OUTCOME_PENDING, self::OUTCOME_ERROR, self::OUTCOME_UNKNOWN,
    ];

    /** Attempt outcomes a lookup may still move forward (spec 0015, AC-9). Final ones never move, except not_found. */
    private const MOVABLE = [
        WorldpayChargeAttempt::OUTCOME_APPROVED,
        WorldpayChargeAttempt::OUTCOME_UNCLEAR,
        WorldpayChargeAttempt::OUTCOME_NOT_FOUND,
        WorldpayChargeAttempt::OUTCOME_CHALLENGE_REQUIRED,
    ];

    /**
     * Run the lookup for the payment. Returns the best outcome, or null when no lookup was made
     * (nothing to look up yet) or another lookup holds the lock (a recheck is queued for the holder).
     */
    public function resolve(Payment $payment, string $source): ?string
    {
        if (! $payment->worldpayPages()->usable()->exists() && $this->attemptsToLookUp($payment, $source)->isEmpty()) {
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

    /**
     * The charge attempts to look up now: the not final ones on every run, the final ones only once,
     * from the sweep, near the end of its window so a late paid row is still caught (spec 0015, AC-9).
     * Page report rows have no reference and are never looked up.
     *
     * @return \Illuminate\Support\Collection<int, WorldpayChargeAttempt>
     */
    private function attemptsToLookUp(Payment $payment, string $source)
    {
        $attempts = $payment->worldpayChargeAttempts()->real()->whereNotNull('transaction_reference')->orderBy('sequence')->get();

        $lateAfter = (int) config('services.worldpay.card_final_lookup_after_hours', 60);

        return $attempts->filter(function (WorldpayChargeAttempt $a) use ($source, $lateAfter) {
            if (in_array($a->outcome, WorldpayChargeAttempt::NON_FINAL, true)) {
                return true;
            }

            if ($source !== self::SOURCE_SWEEP || $a->created_at->copy()->addHours($lateAfter)->isFuture()) {
                return false;
            }

            return ! $a->lookups()->where('source', self::SOURCE_SWEEP)
                ->where('created_at', '>=', $a->created_at->copy()->addHours($lateAfter))->exists();
        })->values();
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

        $attempts = $this->attemptsToLookUp($payment, $source);

        /** @var list<WorldpayPage|WorldpayChargeAttempt> $subjects */
        $subjects = [...$pages->all(), ...$attempts->all()];

        if ($subjects === []) {
            return null;
        }

        Payment::whereKey($payment->id)->update(['worldpay_last_lookup_at' => now()]);

        $account = $payment->worldpayAccount;

        if ($account === null) {
            $this->record($payment, $subjects[0], $source, self::OUTCOME_ERROR, null, 'The Worldpay account is missing.');

            return self::OUTCOME_ERROR;
        }

        $client = app()->make(WorldpayClient::class, [
            'username' => $account->api_username,
            'password' => $account->api_password,
            'environment' => $account->environment,
        ]);

        $allRefs = [
            ...$payment->worldpayPages()->pluck('transaction_reference')->all(),
            ...$payment->worldpayChargeAttempts()->whereNotNull('transaction_reference')->pluck('transaction_reference')->all(),
        ];

        // A completed payment only needs the subjects other than the one that paid, to find a late second sale.
        $paidLookup = $payment->status === 'completed'
            ? WorldpayLookupAttempt::where('payment_id', $payment->id)->where('outcome', self::OUTCOME_PAID)->orderByDesc('id')->first()
            : null;

        $deadline = microtime(true) + (int) config('services.worldpay.resolver_deadline_seconds', 45);

        /** @var list<array{subject: WorldpayPage|WorldpayChargeAttempt, event: ?string, outcome: string, reason: ?string, paymentId: ?string, notFound?: bool}> $results */
        $results = [];
        /** @var list<array{subject: WorldpayPage|WorldpayChargeAttempt, row: WorldpayLookupReply}> $paidRows */
        $paidRows = [];

        foreach ($subjects as $subject) {
            if ($paidLookup !== null && $this->wasPaidBy($paidLookup, $subject)) {
                continue;
            }

            if ($results !== [] && microtime(true) >= $deadline) {
                // The subjects not reached are looked up on the next run.
                Cache::put('worldpay:recheck:'.$payment->id, 1, 60);
                break;
            }

            try {
                $rows = $client->queryPayments($subject->transaction_reference);
            } catch (RequestException $e) {
                Log::error('Worldpay lookup failed', $this->context($payment, $source) + ['http_status' => $e->response->status()]);
                $results[] = $this->result($subject, null, self::OUTCOME_ERROR, 'Worldpay answered with HTTP '.$e->response->status().'.');

                continue;
            } catch (ConnectionException) {
                Log::error('Worldpay lookup could not connect', $this->context($payment, $source));
                $results[] = $this->result($subject, null, self::OUTCOME_ERROR, 'Could not reach Worldpay.');

                continue;
            }

            // A row whose reference is not one of this payment's references is ignored.
            $rows = array_values(array_filter($rows, fn (WorldpayLookupReply $row) => in_array($row->transactionReference, $allRefs, true)));

            $newest = $this->newest($rows);
            $event = $newest?->lastEvent;

            if ($subject->last_event !== $event) {
                $subject->update(['last_event' => $event]);
            }

            foreach ($rows as $row) {
                if (in_array($row->lastEvent, $this->events('paid_events'), true)) {
                    $paidRows[] = ['subject' => $subject, 'row' => $row];
                }
            }

            $results[] = $subject instanceof WorldpayChargeAttempt
                ? $this->classifyAttempt($subject, $rows, $event)
                : $this->classifyPage($subject, $rows, $event);
        }

        if ($results === []) {
            return null;
        }

        $results = $this->settle($payment, $source, $results, $paidRows);

        foreach ($results as $r) {
            $this->record($payment, $r['subject'], $source, $r['outcome'], $r['event'], $r['reason'], $r['paymentId']);

            if ($r['subject'] instanceof WorldpayChargeAttempt) {
                $this->moveAttemptForward($r['subject'], $r);
            }
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
     * exact paid sale exists. Returns the results with their outcomes adjusted.
     *
     * @param  list<array{subject: WorldpayPage|WorldpayChargeAttempt, event: ?string, outcome: string, reason: ?string, paymentId: ?string}>  $results
     * @param  list<array{subject: WorldpayPage|WorldpayChargeAttempt, row: WorldpayLookupReply}>  $paidRows
     * @return list<array{subject: WorldpayPage|WorldpayChargeAttempt, event: ?string, outcome: string, reason: ?string, paymentId: ?string}>
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
                    $results = $this->mark($results, $paid['subject'], self::OUTCOME_PAID, 'Already completed.', $paid['row']->paymentId);

                    continue;
                }

                Log::error('Second Worldpay sale for a completed payment, refund the extra payment in Worldpay', $context + [
                    'recorded_worldpay_payment_id' => $payment->worldpay_payment_id,
                    'extra_worldpay_payment_id' => $paid['row']->paymentId,
                ]);
                $this->alertOnce('worldpay:alert:duplicate:'.$payment->id, 'Worldpay: a second sale exists for a completed payment', $context);
                $results = $this->mark($results, $paid['subject'], self::OUTCOME_DUPLICATE, 'A second sale was made. Refund it in Worldpay.', $paid['row']->paymentId);
            }

            return $results;
        }

        if ($payment->status === 'cancelled') {
            Log::error('Worldpay sale exists for a cancelled payment, refund it in Worldpay', $context + [
                'worldpay_payment_ids' => array_map(fn ($p) => $p['row']->paymentId, $paidRows),
            ]);

            foreach ($paidRows as $paid) {
                $results = $this->mark($results, $paid['subject'], self::OUTCOME_UNKNOWN, 'Paid on Worldpay, but this payment is cancelled in PayHub.', $paid['row']->paymentId);
            }

            return $results;
        }

        if (count($paidRows) > 1) {
            Log::error('More than one paid Worldpay sale for one payment, refund the extra payment in Worldpay', $context + [
                'worldpay_payment_ids' => array_map(fn ($p) => $p['row']->paymentId, $paidRows),
            ]);
            $this->alertOnce('worldpay:alert:duplicate:'.$payment->id, 'Worldpay: more than one paid sale for one payment', $context);

            foreach ($paidRows as $paid) {
                $results = $this->mark($results, $paid['subject'], self::OUTCOME_DUPLICATE, 'More than one paid sale was found. Nothing was changed.', $paid['row']->paymentId);
            }

            return $results;
        }

        $paid = $paidRows[0];
        $row = $paid['row'];

        if ($row->paymentId === null) {
            Log::error('Worldpay shows a paid row without a payment id', $context);

            return $this->mark($results, $paid['subject'], self::OUTCOME_UNKNOWN, 'Worldpay shows a paid sale but gave no payment id.', null);
        }

        if ($row->amountCents !== (int) $payment->amount || $row->currency !== 'GBP') {
            Log::error('Worldpay sale amount or currency mismatch', $context + [
                'worldpay_cents' => $row->amountCents,
                'worldpay_currency' => $row->currency,
                'payment_cents' => (int) $payment->amount,
                'payment_currency' => $payment->currency,
                'worldpay_payment_id' => $row->paymentId,
            ]);

            return $this->mark($results, $paid['subject'], self::OUTCOME_MISMATCH, 'The amount or currency does not match this payment.', $row->paymentId);
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

        return $this->mark($results, $paid['subject'], self::OUTCOME_PAID, null, $row->paymentId);
    }

    /**
     * What one page's newest row says, from the config lists. Never fails a payment.
     *
     * @param  list<WorldpayLookupReply>  $rows
     * @return array{subject: WorldpayPage, event: ?string, outcome: string, reason: ?string, paymentId: ?string}
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
     * What one card charge attempt's newest row says (spec 0015, AC-8, AC-9). Closing and
     * page expiry do not apply to a charge. A row in Payment Queries always wins over `not_found`.
     *
     * @param  list<WorldpayLookupReply>  $rows
     * @return array{subject: WorldpayChargeAttempt, event: ?string, outcome: string, reason: ?string, paymentId: ?string, notFound?: bool}
     */
    private function classifyAttempt(WorldpayChargeAttempt $attempt, array $rows, ?string $event): array
    {
        if ($rows === []) {
            if ($this->notFoundDue($attempt)) {
                return $this->result($attempt, null, self::OUTCOME_PENDING, 'Worldpay has no record of this charge after repeated checks.') + ['notFound' => true];
            }

            return $this->result($attempt, null, self::OUTCOME_PENDING, 'Worldpay shows no payment for this charge yet.');
        }

        $paidHere = collect($rows)->first(fn (WorldpayLookupReply $r) => in_array($r->lastEvent, $this->events('paid_events'), true));

        if ($paidHere !== null) {
            return $this->result($attempt, $paidHere->lastEvent, self::OUTCOME_PAID, null, $paidHere->paymentId);
        }

        if (in_array($event, $this->events('pending_events'), true)) {
            $this->alertIfStuck($attempt);

            return $this->result($attempt, $event, self::OUTCOME_PENDING, 'Worldpay shows the sale as not finished: '.$event.'.');
        }

        return match (true) {
            in_array($event, $this->events('declined_events'), true) => $this->result($attempt, $event, self::OUTCOME_DECLINED, 'The payment was not accepted: '.$event.'.'),
            in_array($event, $this->events('error_events'), true) => $this->result($attempt, $event, self::OUTCOME_ERROR, 'Worldpay reports a problem with this payment: '.$event.'.'),
            in_array($event, $this->events('closed_events'), true) => $this->result($attempt, $event, self::OUTCOME_CLOSED, 'Worldpay reports this payment as '.$event.'.'),
            in_array($event, $this->events('refund_events'), true) => $this->unknown($attempt, $event, 'Worldpay shows a refund state PayHub never saw paid: '.$event.'.'),
            default => $this->unknown($attempt, $event, 'Worldpay returned the event "'.$event.'".'),
        };
    }

    /**
     * `unclear` or `approved` with no row becomes `not_found` only after N lookups spread over at
     * least M minutes since the attempt (this lookup counts as one).
     */
    private function notFoundDue(WorldpayChargeAttempt $attempt): bool
    {
        if (! in_array($attempt->outcome, [WorldpayChargeAttempt::OUTCOME_UNCLEAR, WorldpayChargeAttempt::OUTCOME_APPROVED], true)) {
            return false;
        }

        $minutes = (int) config('services.worldpay.card_not_found_minutes', 10);

        if ($attempt->created_at->copy()->addMinutes($minutes)->isFuture()) {
            return false;
        }

        $lookups = (int) $attempt->lookups()->sum('checks') + 1;

        return $lookups >= (int) config('services.worldpay.card_not_found_lookups', 3);
    }

    /** An attempt stuck `approved` with no paid row past the limit alerts once (spec 0015, AC-8). */
    private function alertIfStuck(WorldpayChargeAttempt $attempt): void
    {
        if ($attempt->outcome !== WorldpayChargeAttempt::OUTCOME_APPROVED) {
            return;
        }

        if ($attempt->created_at->copy()->addHours((int) config('services.worldpay.card_stuck_hours', 24))->isFuture()) {
            return;
        }

        $this->alertOnce('worldpay:alert:stuck:'.$attempt->id, 'Worldpay: a charge has not settled', [
            'payment_id' => $attempt->payment_id,
            'attempt_id' => $attempt->id,
        ]);
    }

    /**
     * Writes the attempt's `outcome` forward only: `approved`, `unclear`, `not_found` and
     * `challenge_required` can move to `refused` once Worldpay shows a refusal, to `approved`
     * once a paid row exists, or to `not_found`; a final outcome never goes backward.
     *
     * @param  array{outcome: string, event: ?string, paymentId: ?string, notFound?: bool}  $r
     */
    private function moveAttemptForward(WorldpayChargeAttempt $attempt, array $r): void
    {
        if (! in_array($attempt->outcome, self::MOVABLE, true)) {
            return;
        }

        $changes = [];

        if ($r['outcome'] === self::OUTCOME_PAID && $r['paymentId'] !== null) {
            $changes = ['outcome' => WorldpayChargeAttempt::OUTCOME_APPROVED, 'worldpay_payment_id' => $r['paymentId']];
        } elseif ($r['outcome'] === self::OUTCOME_DECLINED) {
            $changes = ['outcome' => WorldpayChargeAttempt::OUTCOME_REFUSED];
        } elseif (($r['notFound'] ?? false) && $r['outcome'] === self::OUTCOME_PENDING) {
            $changes = ['outcome' => WorldpayChargeAttempt::OUTCOME_NOT_FOUND];
        }

        if ($changes === []) {
            return;
        }

        if ($changes['outcome'] !== WorldpayChargeAttempt::OUTCOME_APPROVED || $attempt->outcome !== WorldpayChargeAttempt::OUTCOME_CHALLENGE_REQUIRED) {
            // A finished attempt no longer holds a 3D Secure link.
            $attempt->three_ds_state = null;
        }

        $attempt->forceFill($changes + ['last_event' => $r['event'] ?? $attempt->last_event])->save();
    }

    /**
     * @return array{subject: WorldpayPage, event: ?string, outcome: string, reason: ?string, paymentId: ?string}
     */
    private function closePage(WorldpayPage $page, ?string $event): array
    {
        if ($page->closed_at === null) {
            $page->update(['closed_at' => now()]);
        }

        return $this->result($page, $event, self::OUTCOME_CLOSED, 'Worldpay reports this page as '.$event.'.');
    }

    /**
     * @return array{subject: WorldpayPage|WorldpayChargeAttempt, event: ?string, outcome: string, reason: ?string, paymentId: ?string}
     */
    private function unknown(WorldpayPage|WorldpayChargeAttempt $subject, ?string $event, string $reason): array
    {
        Log::warning('Worldpay returned an event PayHub does not recognize', [
            'subject' => $subject::class === WorldpayPage::class ? 'page' : 'charge_attempt',
            'subject_id' => $subject->id,
            'payment_id' => $subject->payment_id,
            'last_event' => $event,
        ]);

        return $this->result($subject, $event, self::OUTCOME_UNKNOWN, $reason);
    }

    /**
     * @return array{subject: WorldpayPage|WorldpayChargeAttempt, event: ?string, outcome: string, reason: ?string, paymentId: ?string}
     */
    private function result(WorldpayPage|WorldpayChargeAttempt $subject, ?string $event, string $outcome, ?string $reason, ?string $paymentId = null): array
    {
        return ['subject' => $subject, 'event' => $event, 'outcome' => $outcome, 'reason' => $reason, 'paymentId' => $paymentId];
    }

    /**
     * @param  list<array{subject: WorldpayPage|WorldpayChargeAttempt, event: ?string, outcome: string, reason: ?string, paymentId: ?string}>  $results
     * @return list<array{subject: WorldpayPage|WorldpayChargeAttempt, event: ?string, outcome: string, reason: ?string, paymentId: ?string}>
     */
    private function mark(array $results, WorldpayPage|WorldpayChargeAttempt $subject, string $outcome, ?string $reason, ?string $paymentId): array
    {
        foreach ($results as $i => $r) {
            if ($r['subject']::class === $subject::class && $r['subject']->id === $subject->id) {
                $results[$i]['outcome'] = $outcome;
                $results[$i]['reason'] = $reason;
                $results[$i]['paymentId'] = $paymentId;
            }
        }

        return $results;
    }

    private function wasPaidBy(WorldpayLookupAttempt $paidLookup, WorldpayPage|WorldpayChargeAttempt $subject): bool
    {
        return $subject instanceof WorldpayChargeAttempt
            ? $paidLookup->charge_attempt_id === $subject->id
            : $paidLookup->page_id === $subject->id;
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
     * One lookup row per subject per run. A repeat with the same outcome and event as that subject's
     * newest row only adds to `checks` (spec 0014, AC-9). A card attempt's row records the attempt
     * it searched through in `charge_attempt_id` (spec 0015, AC-9).
     */
    private function record(Payment $payment, WorldpayPage|WorldpayChargeAttempt $subject, string $source, string $outcome, ?string $event, ?string $reason, ?string $worldpayPaymentId = null): void
    {
        $isAttempt = $subject instanceof WorldpayChargeAttempt;

        $latest = WorldpayLookupAttempt::where($isAttempt ? 'charge_attempt_id' : 'page_id', $subject->id)->orderByDesc('id')->first();

        if ($latest !== null && $latest->outcome === $outcome && $latest->last_event === $event) {
            $latest->update(['checks' => $latest->checks + 1, 'last_checked_at' => now()]);

            return;
        }

        WorldpayLookupAttempt::create([
            'payment_id' => $payment->id,
            'page_id' => $isAttempt ? null : $subject->id,
            'charge_attempt_id' => $isAttempt ? $subject->id : null,
            'source' => $source,
            'outcome' => $outcome,
            'last_event' => $event,
            'reason' => $reason === null ? null : mb_substr($reason, 0, 500),
            'worldpay_payment_id' => $worldpayPaymentId,
            'checks' => 1,
            'last_checked_at' => now(),
        ]);
    }

    /** One Sentry message per key, ever (the key is per payment or per attempt). */
    private function alertOnce(string $key, string $message, array $context): void
    {
        if (! Cache::add($key, 1, now()->addDays(30))) {
            return;
        }

        if (class_exists(\Sentry\SentrySdk::class)) {
            \Sentry\withScope(function (\Sentry\State\Scope $scope) use ($message, $context) {
                $scope->setContext('worldpay', $context);
                \Sentry\captureMessage($message);
            });
        }
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
