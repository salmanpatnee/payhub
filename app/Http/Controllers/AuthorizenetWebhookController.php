<?php

namespace App\Http\Controllers;

use App\Jobs\HandleAuthorizenetWebhookJob;
use App\Models\AuthorizenetAccount;
use App\Models\ProcessedAuthorizenetEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Authorize.net webhook endpoint (spec 0006, AC-8). A signal only: it verifies
 * the HMAC signature on the raw body and queues a job that re-reads the
 * transaction at the gateway. The body is never trusted for status.
 *
 * UNVERIFIED (spec 0006, Open question 3): the header format (`sha512=` prefix,
 * letter case of the hex) comes from documentation. Both are tolerated below;
 * confirm with the first live sandbox delivery.
 */
class AuthorizenetWebhookController extends Controller
{
    private const EVENTS = [
        'net.authorize.payment.authcapture.created',
        'net.authorize.payment.fraud.approved',
        'net.authorize.payment.fraud.declined',
        'net.authorize.payment.fraud.held',
    ];

    public function handle(Request $request, AuthorizenetAccount $authorizenetAccount): Response
    {
        $key = $authorizenetAccount->signature_key;

        // No signature key stored: the account relies on the status lookup and the sweep.
        if ($key === null || $key === '') {
            return response('Webhook not enabled for this account', 403);
        }

        $received = strtolower((string) preg_replace('/^sha512=/i', '', (string) $request->header('X-ANET-Signature', '')));
        $expected = hash_hmac('sha512', $request->getContent(), $key);

        if ($received === '' || ! hash_equals($expected, $received)) {
            return response('Invalid signature', 401);
        }

        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload)) {
            return response('Invalid payload', 400);
        }

        $notificationId = $payload['notificationId'] ?? null;
        $transactionId = $payload['payload']['id'] ?? null;

        if (! in_array($payload['eventType'] ?? null, self::EVENTS, true)
            || ! is_string($notificationId) || $notificationId === ''
            || (! is_string($transactionId) && ! is_int($transactionId))) {
            return response('', 200); // nothing we act on; acknowledge so the gateway stops retrying
        }

        try {
            ProcessedAuthorizenetEvent::create(['event_key' => $notificationId]);
        } catch (UniqueConstraintViolationException) {
            return response('', 200); // duplicate delivery
        }

        HandleAuthorizenetWebhookJob::dispatch($authorizenetAccount->id, (string) $transactionId);

        return response('', 200);
    }
}
