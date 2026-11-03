<?php

namespace App\Http\Controllers;

use App\Jobs\HandleWhopWebhookJob;
use App\Models\WhopAccount;
use App\Services\Whop\WhopWebhookSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhopWebhookController extends Controller
{
    /**
     * An inactive account is still processed (a payment may be mid flight).
     * Missing account row: 404 from route model binding.
     */
    public function handle(Request $request, WhopAccount $whopAccount): Response
    {
        if ($whopAccount->webhook_secret === null || $whopAccount->webhook_secret === '') {
            return response('Webhook secret not set', 403);
        }

        $rawBody = $request->getContent();
        $webhookId = (string) $request->header('webhook-id', '');

        if (! WhopWebhookSignature::isValid(
            $rawBody,
            $webhookId,
            (string) $request->header('webhook-timestamp', ''),
            (string) $request->header('webhook-signature', ''),
            $whopAccount->webhook_secret,
        )) {
            return response('Invalid signature', 401);
        }

        HandleWhopWebhookJob::dispatch($whopAccount->id, $webhookId, $rawBody);

        return response('', 200);
    }
}
