<?php

namespace App\Http\Controllers;

use App\Enums\PaymentProvider;
use App\Jobs\HandleSquareWebhookJob;
use App\Models\Payment;
use App\Models\ProcessedSquareEvent;
use App\Models\SquareAccount;
use App\Services\PaymentAttempts\PaymentAttemptLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Square\Utils\WebhooksHelper;

class SquareWebhookController extends Controller
{
    private const HANDLED_EVENTS = [
        'payment.updated',
    ];

    public function handle(Request $request, SquareAccount $squareAccount, PaymentAttemptLogger $logger): Response
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

        // Diagnostic log (spec 0016): runs before the completed guard so late events are kept. Never throws.
        if (is_array($squarePayment)) {
            $logger->record(
                PaymentProvider::Square,
                $this->paymentForLog($squareAccount, $squarePayment),
                $squarePayment,
                ['event_id' => $eventId, 'created_at' => $event['created_at'] ?? null],
            );
        }

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

    /**
     * The payment for the diagnostic log: by Square payment id, else (a declined charge throws before
     * the id is saved) by the reference code PayHub sent as `reference_id`. Always scoped to this account.
     *
     * @param  array<string, mixed>  $squarePayment
     */
    private function paymentForLog(SquareAccount $squareAccount, array $squarePayment): ?Payment
    {
        try {
            $id = $squarePayment['id'] ?? null;

            if (is_string($id) && $id !== '') {
                $found = Payment::where('square_payment_id', $id)->where('square_account_id', $squareAccount->id)->first();

                if ($found !== null) {
                    return $found;
                }
            }

            $referenceId = $squarePayment['reference_id'] ?? null;

            if (is_string($referenceId) && preg_match('/(\d{1,10})$/', $referenceId, $m)) {
                return Payment::where('provider', PaymentProvider::Square->value)
                    ->where('square_account_id', $squareAccount->id)
                    ->where('reference_code', (int) $m[1])
                    ->first();
            }
        } catch (\Throwable) {
            // The log must never break the webhook.
        }

        return null;
    }
}
