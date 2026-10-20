<?php

namespace App\Http\Controllers;

use App\Jobs\HandleMpgsWebhookJob;
use App\Models\MpgsAccount;
use App\Models\MpgsChargeAttempt;
use App\Models\ProcessedMpgsEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * MPGS notification endpoint (spec 0005, AC-9). A signal only: it checks the
 * per account secret header, works out which attempt the delivery is about, and
 * queues a job that re-reads the order at the gateway. The body is never
 * trusted for status, amount or anything else.
 *
 * PROVISIONAL (Open question 5): the body field names read below (order.id,
 * transaction.id, result) come from the integration handoff, not a verified
 * sandbox. They are read in one place, parsePayload(), so a spike correction
 * lands there.
 */
class MpgsWebhookController extends Controller
{
    public function handle(Request $request, MpgsAccount $mpgsAccount): Response
    {
        $storedSecret = $mpgsAccount->webhook_secret;

        // No secret stored: the account relies on the pay response and the
        // resolver, so every delivery is refused.
        if ($storedSecret === null || $storedSecret === '') {
            return response('Webhook not enabled for this account', 403);
        }

        $received = (string) $request->header('X-Notification-Secret', '');

        if ($received === '' || ! hash_equals($storedSecret, $received)) {
            return response('Invalid secret', 401);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return response('Invalid payload', 400);
        }

        [$orderId, $transactionId, $result] = $this->parsePayload($payload);

        if ($orderId === null) {
            return response('', 200); // nothing we can act on; acknowledge so the gateway stops retrying
        }

        // The delivery must be about an attempt that belongs to THIS account's
        // payments. An unknown or foreign order is acknowledged and ignored, so
        // the endpoint does not reveal which order ids exist.
        $attempt = MpgsChargeAttempt::query()
            ->where('order_id', $orderId)
            ->whereHas('payment', fn ($query) => $query->where('mpgs_account_id', $mpgsAccount->id))
            ->first();

        if (! $attempt || $attempt->status !== 'pending') {
            return response('', 200); // unknown here, or already in a final state
        }

        // A 3DS payment sends two notifications that differ by transaction id or
        // result, so they key separately; a repeat of the same one is dropped.
        $eventKey = $orderId.':'.($transactionId ?? '').':'.($result ?? '');

        try {
            ProcessedMpgsEvent::create(['event_key' => $eventKey]);
        } catch (UniqueConstraintViolationException) {
            return response('', 200); // duplicate delivery
        }

        HandleMpgsWebhookJob::dispatch($attempt->id);

        return response('', 200);
    }

    /**
     * The only place the notification body is read.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: ?string, 1: ?string, 2: ?string} order id, transaction id, result
     */
    private function parsePayload(array $payload): array
    {
        $string = fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        return [
            $string($payload['order']['id'] ?? null),
            $string($payload['transaction']['id'] ?? null),
            $string($payload['result'] ?? null),
        ];
    }
}
