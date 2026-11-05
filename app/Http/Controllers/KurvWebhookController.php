<?php

namespace App\Http\Controllers;

use App\Jobs\ResolveKurvPayment;
use App\Models\KurvAccount;
use App\Models\Payment;
use App\Services\Kurv\KurvCallbackToken;
use App\Services\Kurv\KurvChargeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Kurv's unsigned callback (spec 0010, AC-8). It always answers 200 with a short body and
 * never reads the body to decide anything: a good token only queues one lookup, run after
 * the response is sent, and KurvChargeResolver alone decides the payment's status.
 */
class KurvWebhookController extends Controller
{
    public function handle(Request $request, string $kurvAccount): Response
    {
        $reply = response('ok', 200);

        // Per IP limit (60 a minute). Over it, still 200, no lookup.
        if (! RateLimiter::attempt('kurv-callback-ip:'.$request->ip(), 60, fn () => true, 60)) {
            Log::warning('Kurv callback over the IP limit');

            return $reply;
        }

        $account = ctype_digit($kurvAccount) ? KurvAccount::find((int) $kurvAccount) : null;
        $uuid = (string) $request->query('payment', '');
        $token = $request->query('token');
        $token = is_string($token) ? $token : null;

        if ($account === null || ! KurvCallbackToken::matches($uuid, $token)) {
            Log::warning('Kurv callback refused', [
                'kurv_account' => $kurvAccount,
                'token' => KurvCallbackToken::mask($token),
            ]);

            return $reply;
        }

        $payment = Payment::where('uuid', $uuid)->first();

        if ($payment === null || $payment->provider->value !== 'kurv' || (int) $payment->kurv_account_id !== $account->id) {
            Log::warning('Kurv callback for an unknown payment or another account', [
                'kurv_account_id' => $account->id,
                'token' => KurvCallbackToken::mask($token),
            ]);

            return $reply;
        }

        // The body is only logged as key names, for diagnosis. It never decides anything.
        Log::info('Kurv callback received', [
            'payment_id' => $payment->id,
            'body_keys' => array_keys($request->json()->all()),
        ]);

        // One callback lookup per payment every 10 seconds.
        if (! Cache::add('kurv:callback:'.$payment->id, 1, 10)) {
            return $reply;
        }

        ResolveKurvPayment::dispatch($payment->id, KurvChargeResolver::SOURCE_CALLBACK)->afterResponse();

        return $reply;
    }
}
