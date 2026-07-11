<?php

namespace App\Http\Controllers;

use App\Jobs\HandleVivaWebhookJob;
use App\Models\Payment;
use App\Models\ProcessedVivaEvent;
use App\Models\VivaAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VivaWebhookController extends Controller
{
    /**
     * Viva event type ids we act on. 1796 = TransactionPaymentCreated (payment
     * succeeded). TransactionFailed's numeric EventTypeId is not confirmed
     * against public docs — see .planning/research/VIVA_PAYMENTS.md — so a
     * failed Viva payment simply stays pending until the customer retries or
     * an admin intervenes, until that id is confirmed and added here.
     */
    private const HANDLED_EVENT_TYPE_IDS = [1796];

    /**
     * Handshake endpoint Viva GETs once when a webhook URL is registered,
     * before any signed POSTs are delivered. Contract per public docs: Viva
     * expects a JSON body echoing a verification key.
     *
     * TODO: exact response field name/casing is unconfirmed against a live
     * sandbox — verify and adjust before relying on this in production (see
     * .planning/research/VIVA_PAYMENTS.md §2).
     */
    public function verify(Request $request, VivaAccount $vivaAccount): JsonResponse
    {
        return response()->json([
            'Key' => (string) $vivaAccount->webhook_verification_key,
        ]);
    }

    public function handle(Request $request, VivaAccount $vivaAccount): Response
    {
        if (! $vivaAccount->is_active) {
            return response('', 200); // Acknowledge; skip processing
        }

        $payload = $request->getContent();
        $signature = $request->header('Viva-Signature', '');

        if (! $this->signatureIsValid($payload, $signature, $vivaAccount->webhook_verification_key)) {
            return response('Invalid signature', 400);
        }

        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return response('Invalid payload', 400);
        }

        $eventTypeId = (int) ($data['EventTypeId'] ?? 0);

        if (! in_array($eventTypeId, self::HANDLED_EVENT_TYPE_IDS, true)) {
            return response('', 200);
        }

        $eventData = $data['EventData'] ?? [];
        $orderCode = isset($eventData['OrderCode']) ? (string) $eventData['OrderCode'] : null;
        $transactionId = $eventData['TransactionId'] ?? null;

        if (! $orderCode) {
            return response('', 200);
        }

        // Prefer Viva's own delivery id when present; fall back to a
        // composite key (mirrors ProcessedRevolutEvent) since Viva's
        // per-delivery event id shape is unconfirmed — see migration comment
        // on processed_viva_events.
        $eventKey = $data['MessageId'] ?? ($orderCode.':'.$eventTypeId);

        if (ProcessedVivaEvent::where('event_key', $eventKey)->exists()) {
            return response('', 200);
        }

        ProcessedVivaEvent::create([
            'event_key' => $eventKey,
            'processed_at' => now(),
        ]);

        // Status guard: skip only if already completed — failed can still transition
        // to completed on a successful retry, so it must be allowed through.
        $payment = Payment::where('viva_order_code', $orderCode)
            ->where('viva_account_id', $vivaAccount->id)
            ->first();

        if ($payment && $payment->status === 'completed') {
            return response('', 200);
        }

        HandleVivaWebhookJob::dispatch($vivaAccount->id, $orderCode, $transactionId);

        return response('', 200);
    }

    /**
     * Verify the Viva webhook signature.
     *
     * TODO: header name and signed-payload shape are unconfirmed against a
     * live sandbox — see .planning/research/VIVA_PAYMENTS.md §2. Assumes
     * HMAC-SHA256 of the raw body using the stored verification key, mirrored
     * on the same isolated-method pattern as RevolutWebhookController so the
     * header/algorithm can be corrected in one place once confirmed.
     */
    private function signatureIsValid(string $payload, string $signatureHeader, ?string $secret): bool
    {
        if ($secret === null || $secret === '' || $signatureHeader === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
