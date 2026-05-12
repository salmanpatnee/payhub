<?php

namespace App\Http\Controllers;

use App\Jobs\HandleStripeWebhookJob;
use App\Models\Payment;
use App\Models\StripeAccount;
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

    public function handle(Request $request, StripeAccount $stripeAccount): Response
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

        $piId = $event->data->object->id ?? null;
        $payment = $piId ? Payment::where('stripe_payment_intent_id', $piId)->first() : null;

        // Idempotency gate 1: skip if already in terminal state
        if ($payment && in_array($payment->status, ['completed', 'failed'])) {
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
