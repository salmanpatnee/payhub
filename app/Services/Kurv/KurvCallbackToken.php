<?php

namespace App\Services\Kurv;

use App\Models\KurvAccount;
use App\Models\Payment;

/**
 * The token on Kurv's callback URL (spec 0010, AC-8). Kurv's callback is unsigned, so the
 * token only decides whether a lookup is queued, never what a payment's status is.
 *
 * It is an HMAC of the payment UUID keyed with APP_KEY. The UUID is the one value known
 * when the link is built (the Kurv request id does not exist yet).
 */
class KurvCallbackToken
{
    public static function for(string $paymentUuid): string
    {
        return hash_hmac('sha256', $paymentUuid, (string) config('app.key'));
    }

    public static function matches(string $paymentUuid, ?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals(self::for($paymentUuid), $token);
    }

    /** The full callback URL given to Kurv as `response_url`. */
    public static function urlFor(KurvAccount $account, Payment $payment): string
    {
        return route('webhook.kurv', ['kurvAccount' => $account->id]).'?'.http_build_query([
            'payment' => $payment->uuid,
            'token' => self::for($payment->uuid),
        ]);
    }

    /** Safe to log: never the whole token. */
    public static function mask(?string $token): string
    {
        return $token === null || $token === '' ? '' : substr($token, 0, 6).'...';
    }
}
