<?php

namespace App\Http\Controllers;

use App\Enums\PaymentProvider;
use App\Jobs\HandleStripeWebhookJob;
use App\Models\Payment;
use App\Models\ProcessedStripeEvent;
use App\Models\StripeAccount;
use App\Services\PaymentAttempts\PaymentAttemptLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

class StripeWebhookController extends Controller
{
    private const HANDLED_EVENTS = [
        'payment_intent.succeeded',
        'payment_intent.payment_failed',
    ];

    public function handle(Request $request, StripeAccount $stripeAccount, PaymentAttemptLogger $logger): Response
    {
        if (! $stripeAccount->is_active) {
            return response('', 200); // Acknowledge to Stripe; skip processing
        }

        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature', '');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $stripeAccount->webhook_secret);
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            return response('Invalid signature or payload', 400);
        }

        if (! in_array($event->type, self::HANDLED_EVENTS)) {
            return response('', 200);
        }

        // Idempotency: reject replayed events by persisting processed event IDs
        if (ProcessedStripeEvent::where('stripe_event_id', $event->id)->exists()) {
            return response('', 200);
        }

        ProcessedStripeEvent::create([
            'stripe_event_id' => $event->id,
            'processed_at' => now(),
        ]);

        $piId = $event->data->object->id ?? null;
        $payment = $piId ? Payment::where('stripe_payment_intent_id', $piId)->first() : null;

        // Diagnostic log (spec 0016): runs before the completed guard so late events are kept. Never throws.
        $loggedPayment = $piId
            ? Payment::where('stripe_payment_intent_id', $piId)->where('stripe_account_id', $stripeAccount->id)->first()
            : null;
        $logger->record(PaymentProvider::Stripe, $loggedPayment, $event->data->object->toArray(), [
            'event_id' => $event->id,
            'created' => $event->created,
            'type' => $event->type,
        ]);

        // Status guard: skip only if already completed — failed can still transition
        // to completed on a successful retry, so it must be allowed through.
        if ($payment && $payment->status === 'completed') {
            return response('', 200);
        }

        HandleStripeWebhookJob::dispatch(
            $stripeAccount->id,
            $event->type,
            $event->data->object->toArray(),
        );

        return response('', 200);
    }
}
