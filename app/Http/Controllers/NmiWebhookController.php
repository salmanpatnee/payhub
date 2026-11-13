<?php

namespace App\Http\Controllers;

use App\Jobs\HandleNmiWebhookJob;
use App\Models\NmiAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * NMI webhook endpoint (spec 0012, AC-14). A signal only: it verifies the HMAC signature on the raw
 * body and queues a job that looks the transaction up at NMI. NMI's signature has no timestamp, so
 * replay safety rests on the event id dedupe in the job. The body is never trusted for status.
 */
class NmiWebhookController extends Controller
{
    public function handle(Request $request, NmiAccount $nmiAccount): Response
    {
        $key = $nmiAccount->webhook_signing_key;

        // No signing key stored: the account relies on the status lookup and the sweep.
        if ($key === null || $key === '') {
            return response('Webhook not enabled for this account', 401);
        }

        if (! preg_match('/^t=([^,]+),s=([0-9a-fA-F]+)$/', trim((string) $request->header('Webhook-Signature', '')), $m)) {
            return response('Invalid signature', 401);
        }

        $expected = hash_hmac('sha256', $m[1].'.'.$request->getContent(), $key);

        if (! hash_equals($expected, strtolower($m[2]))) {
            return response('Invalid signature', 401);
        }

        $payload = json_decode($request->getContent(), true);
        $type = is_array($payload) ? ($payload['event_type'] ?? null) : null;

        if (! is_array($payload) || ! is_string($type) || ! str_starts_with($type, 'transaction.sale.')) {
            return response('', 200); // nothing we act on; acknowledge so NMI stops retrying
        }

        $eventId = $payload['event_id'] ?? null;
        $orderId = $payload['event_body']['order_id'] ?? null;

        // Answer quickly; the job does the lookup.
        HandleNmiWebhookJob::dispatch(
            $nmiAccount->id,
            is_string($eventId) ? $eventId : null,
            is_string($orderId) ? $orderId : null,
        );

        return response('', 200);
    }
}
