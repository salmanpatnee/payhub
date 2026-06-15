<?php

namespace App\Http\Controllers;

use App\Jobs\HandleRevolutWebhookJob;
use App\Models\Payment;
use App\Models\ProcessedRevolutEvent;
use App\Models\RevolutAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RevolutWebhookController extends Controller
{
    private const HANDLED_EVENTS = [
        'ORDER_COMPLETED',
        'ORDER_PAYMENT_FAILED',
    ];

    /** Reject webhooks whose timestamp is older than this, to limit replay. */
    private const TOLERANCE_SECONDS = 300;

    public function handle(Request $request, RevolutAccount $revolutAccount): Response
    {
        if (! $revolutAccount->is_active) {
            return response('', 200); // Acknowledge; skip processing
        }

        $payload = $request->getContent();
        $timestamp = $request->header('Revolut-Request-Timestamp', '');
        $signature = $request->header('Revolut-Signature', '');

        if (! $this->signatureIsValid($payload, $timestamp, $signature, $revolutAccount->webhook_secret)) {
            return response('Invalid signature', 400);
        }

        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return response('Invalid payload', 400);
        }

        $eventType = $data['event'] ?? null;
        $orderId = $data['order_id'] ?? null;

        if (! in_array($eventType, self::HANDLED_EVENTS, true) || ! $orderId) {
            return response('', 200);
        }

        // Revolut payloads carry no unique event id; key idempotency on order + event.
        $eventKey = $orderId.':'.$eventType;

        if (ProcessedRevolutEvent::where('event_key', $eventKey)->exists()) {
            return response('', 200);
        }

        ProcessedRevolutEvent::create([
            'event_key' => $eventKey,
            'processed_at' => now(),
        ]);

        // Status guard: skip only if already completed — failed can still transition
        // to completed on a successful retry, so it must be allowed through.
        $payment = Payment::where('revolut_order_id', $orderId)->first();

        if ($payment && $payment->status === 'completed') {
            return response('', 200);
        }

        HandleRevolutWebhookJob::dispatch($revolutAccount->id, $eventType, $orderId);

        return response('', 200);
    }

    /**
     * Verify the Revolut webhook signature.
     *
     * Signed payload is "v1.{timestamp}.{rawBody}", HMAC-SHA256 with the webhook
     * signing secret. The Revolut-Signature header carries one or more "v1=<hex>"
     * values (comma-separated); any match is accepted.
     */
    private function signatureIsValid(string $payload, string $timestamp, string $signatureHeader, ?string $secret): bool
    {
        if ($secret === null || $secret === '' || $timestamp === '' || $signatureHeader === '') {
            return false;
        }

        // Timestamp is epoch milliseconds; reject stale deliveries (replay protection).
        $timestampMs = (int) $timestamp;
        $nowMs = (int) round(microtime(true) * 1000);

        if ($timestampMs <= 0 || abs($nowMs - $timestampMs) > self::TOLERANCE_SECONDS * 1000) {
            return false;
        }

        $expected = 'v1='.hash_hmac('sha256', 'v1.'.$timestamp.'.'.$payload, $secret);

        foreach (explode(',', $signatureHeader) as $candidate) {
            if (hash_equals($expected, trim($candidate))) {
                return true;
            }
        }

        return false;
    }
}
