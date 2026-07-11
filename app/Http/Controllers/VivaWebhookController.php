<?php

namespace App\Http\Controllers;

use App\Jobs\HandleVivaWebhookJob;
use App\Models\Payment;
use App\Models\ProcessedVivaEvent;
use App\Models\VivaAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

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
        // Diagnostic entry log at WARNING level (staging/prod often set
        // LOG_LEVEL=error/warning, which would swallow info logs). Proves the
        // POST actually reached Laravel and shows the raw body Viva delivered.
        Log::warning('viva.webhook.hit', [
            'viva_account_id' => $vivaAccount->id,
            'raw' => $request->getContent(),
        ]);

        if (! $vivaAccount->is_active) {
            return response('', 200); // Acknowledge; skip processing
        }

        // Viva's payment webhooks are NOT per-message signed — the one-time URL
        // verification handshake (the shared Key) is the whole of Viva's own
        // security model. So this endpoint cannot authenticate the POST itself;
        // it treats the event only as a nudge. The authoritative check lives in
        // HandleVivaWebhookJob, which re-fetches the transaction from Viva's API
        // and refuses to mark a payment paid unless Viva confirms it succeeded.
        $payload = $request->getContent();

        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return response('Invalid payload', 400);
        }

        $eventTypeId = (int) ($data['EventTypeId'] ?? 0);

        // Diagnostic: log every inbound Viva webhook (payloads carry only order/
        // transaction ids, no card data or secrets — safe to log). Lets us confirm
        // the real EventTypeId + EventData shape against a live delivery instead of
        // guessing, and surfaces events (e.g. "Order Updated") we currently ignore.
        Log::warning('viva.webhook.received', [
            'viva_account_id' => $vivaAccount->id,
            'event_type_id' => $eventTypeId,
            'handled' => in_array($eventTypeId, self::HANDLED_EVENT_TYPE_IDS, true),
            'payload' => $data,
        ]);

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

        // Status guard: skip only if already completed — failed can still transition
        // to completed on a successful retry, so it must be allowed through.
        $payment = Payment::where('viva_order_code', $orderCode)
            ->where('viva_account_id', $vivaAccount->id)
            ->first();

        if ($payment && $payment->status === 'completed') {
            ProcessedVivaEvent::firstOrCreate(['event_key' => $eventKey], ['processed_at' => now()]);

            return response('', 200);
        }

        // A webhook must ALWAYS return 2xx to Viva — otherwise Viva marks the
        // delivery failed and retries hourly. On a sync queue, dispatch() runs
        // the job inline, so a Viva API error inside it would otherwise 500 the
        // response. Guard it: on failure, don't record the event as processed so
        // Viva's retry can re-attempt, and never propagate the exception.
        try {
            HandleVivaWebhookJob::dispatch($vivaAccount->id, $orderCode, $transactionId);
            ProcessedVivaEvent::firstOrCreate(['event_key' => $eventKey], ['processed_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('viva.webhook.processing_failed', [
                'viva_account_id' => $vivaAccount->id,
                'order_code' => $orderCode,
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
        }

        return response('', 200);
    }
}
