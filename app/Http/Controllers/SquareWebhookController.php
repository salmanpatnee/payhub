<?php

namespace App\Http\Controllers;

use App\Jobs\HandleSquareWebhookJob;
use App\Models\Payment;
use App\Models\ProcessedSquareEvent;
use App\Models\SquareAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Square\Utils\WebhooksHelper;

class SquareWebhookController extends Controller
{
    private const HANDLED_EVENTS = [
        'payment.updated',
    ];

    public function handle(Request $request, SquareAccount $squareAccount): Response
    {
        if (! $squareAccount->is_active) {
            return response('', 200); // Acknowledge to Square; skip processing
        }

        $payload = $request->getContent();
        $signature = $request->header('x-square-hmacsha256-signature', '');

        // The notification URL must byte-match the Square subscription's configured URL.
        try {
            $valid = WebhooksHelper::verifySignature(
                $payload,
                $signature,
                (string) $squareAccount->webhook_signature_key,
                route('webhook.square', $squareAccount, true),
            );
        } catch (\Throwable) {
            $valid = false; // empty signature key / notification url
        }

        if (! $valid) {
            return response('Invalid signature', 403);
        }

        $event = json_decode($payload, true);
        $eventId = $event['event_id'] ?? null;
        $type = $event['type'] ?? null;

        if (! in_array($type, self::HANDLED_EVENTS, true)) {
            return response('', 200);
        }

        // Idempotency: reject replayed events by persisting processed event IDs
        if (! $eventId || ProcessedSquareEvent::where('square_event_id', $eventId)->exists()) {
            return response('', 200);
        }

        ProcessedSquareEvent::create([
            'square_event_id' => $eventId,
            'processed_at' => now(),
        ]);

        $squarePayment = $event['data']['object']['payment'] ?? null;
        $squarePaymentId = $squarePayment['id'] ?? null;
        $squareStatus = $squarePayment['status'] ?? null;

        $payment = $squarePaymentId
            ? Payment::where('square_payment_id', $squarePaymentId)->first()
            : null;

        // Status guard: skip only if already completed — failed can still transition
        // to completed on a successful retry, so it must be allowed through.
        if ($payment && $payment->status === 'completed') {
            return response('', 200);
        }

        HandleSquareWebhookJob::dispatch(
            $squareAccount->id,
            $squarePaymentId,
            $squareStatus,
        );

        return response('', 200);
    }
}
