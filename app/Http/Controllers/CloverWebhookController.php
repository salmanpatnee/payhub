<?php

namespace App\Http\Controllers;

use App\Jobs\HandleCloverWebhookJob;
use App\Models\CloverAccount;
use App\Models\Payment;
use App\Models\ProcessedCloverEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class CloverWebhookController extends Controller
{
    /**
     * Clover event types we act on. Clover's docs describe payment events
     * with Type "PAYMENT" and Status "APPROVED"/"DECLINED" only in prose (no
     * published JSON schema) — see CloverClient's docstring and spec 0001's
     * Follow-up. Confirm the exact field names/casing against a live sandbox
     * pass before this is trusted with real money.
     */
    private const HANDLED_TYPE = 'PAYMENT';

    private const HANDLED_STATUSES = ['APPROVED', 'DECLINED'];

    public function handle(Request $request, CloverAccount $cloverAccount): Response
    {
        if (! $cloverAccount->is_active) {
            return response('', 200); // Acknowledge; skip processing
        }

        $payload = $request->getContent();

        if (! $this->hasValidSignature($request, $payload, (string) $cloverAccount->webhook_secret)) {
            return response('Invalid signature', 401);
        }

        $data = json_decode($payload, true);

        if (! is_array($data)) {
            return response('Invalid payload', 400);
        }

        if (($data['Type'] ?? null) !== self::HANDLED_TYPE) {
            return response('', 200);
        }

        $status = $data['Status'] ?? null;

        if (! in_array($status, self::HANDLED_STATUSES, true)) {
            return response('', 200);
        }

        $checkoutSessionId = isset($data['Data']) ? (string) $data['Data'] : null;
        $cloverPaymentId = isset($data['Id']) ? (string) $data['Id'] : null;

        if (! $checkoutSessionId) {
            return response('', 200);
        }

        $payment = Payment::where('clover_checkout_session_id', $checkoutSessionId)
            ->where('clover_account_id', $cloverAccount->id)
            ->first();

        if (! $payment) {
            return response('', 200);
        }

        // Computed server-side from our own Payment id + the checkout session id —
        // never trusted verbatim from the payload (Clover publishes no confirmed
        // stable per-delivery event id).
        $eventKey = "{$payment->id}:{$checkoutSessionId}";

        if (ProcessedCloverEvent::where('event_key', $eventKey)->exists()) {
            return response('', 200);
        }

        // Status guard: a payment already paid or failed is terminal — a delayed
        // decline from an earlier, abandoned session must never overwrite a
        // genuine approval (AC-6, AC-7).
        if (in_array($payment->status, ['completed', 'failed'], true)) {
            ProcessedCloverEvent::firstOrCreate(['event_key' => $eventKey], ['processed_at' => now()]);

            return response('', 200);
        }

        // A webhook must ALWAYS return 2xx to Clover. On a sync queue, dispatch()
        // runs the job inline, so a failure inside it would otherwise 500 the
        // response. Guard it: on failure, don't record the event as processed so
        // a retry can re-attempt, and never propagate the exception.
        try {
            HandleCloverWebhookJob::dispatch($payment->id, $status, $cloverPaymentId);
            ProcessedCloverEvent::firstOrCreate(['event_key' => $eventKey], ['processed_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('clover.webhook.processing_failed', [
                'clover_account_id' => $cloverAccount->id,
                'checkout_session_id' => $checkoutSessionId,
                'clover_payment_id' => $cloverPaymentId,
                'error' => $e->getMessage(),
            ]);
        }

        return response('', 200);
    }

    /**
     * Verify the Clover-Signature header: "t={timestamp},v1={hex hmac}", where
     * the hmac is HMAC-SHA256 of "{timestamp}.{rawBody}" using the account's
     * webhook secret. Confirmed against Clover's own docs (docs.clover.com).
     */
    private function hasValidSignature(Request $request, string $payload, string $webhookSecret): bool
    {
        if ($webhookSecret === '') {
            return false;
        }

        $header = $request->header('Clover-Signature', '');

        $parts = [];
        foreach (explode(',', $header) as $segment) {
            [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key] = $value;
            }
        }

        $timestamp = $parts['t'] ?? null;
        $signature = $parts['v1'] ?? null;

        if (! $timestamp || ! $signature) {
            return false;
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$payload}", $webhookSecret);

        return hash_equals($expected, $signature);
    }
}
