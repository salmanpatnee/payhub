<?php

namespace App\Http\Controllers;

use App\Jobs\ResolveWorldpayPayment;
use App\Models\WorldpayAccount;
use App\Models\WorldpayPage;
use App\Services\Worldpay\WorldpayChargeResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Worldpay's optional signed webhook (spec 0014, AC-12). It always answers 200 with a short body and
 * never lets the body decide anything: a good signature only finds the payment and queues one lookup,
 * run after the response is sent. WorldpayChargeResolver alone decides the payment's status.
 *
 * The Event-Signature header holds comma separated `keyId/hashFunction/signature` entries. The entry
 * whose key ID equals the account's `webhook_key_id` is checked with an HMAC of the raw body.
 */
class WorldpayWebhookController extends Controller
{
    private const HASHES = ['sha256', 'sha512'];

    public function handle(Request $request, string $worldpayAccount): Response
    {
        $reply = response('ok', 200);

        // Per IP limit (60 a minute). Over it, still 200, no lookup.
        if (! RateLimiter::attempt('worldpay-webhook-ip:'.$request->ip(), 60, fn () => true, 60)) {
            Log::warning('Worldpay webhook over the IP limit');

            return $reply;
        }

        $account = ctype_digit($worldpayAccount) ? WorldpayAccount::find((int) $worldpayAccount) : null;

        if ($account === null) {
            Log::warning('Worldpay webhook for an unknown account');

            return $reply;
        }

        if (blank($account->webhook_secret) || blank($account->webhook_key_id)) {
            Log::info('Worldpay webhook ignored, no secret saved', ['worldpay_account_id' => $account->id]);

            return $reply;
        }

        if (! $this->signatureIsValid($request, $account)) {
            // Never log the header value.
            Log::warning('Worldpay webhook signature refused', ['worldpay_account_id' => $account->id]);

            return $reply;
        }

        // The reference is used only to find the payment, never to decide a status.
        $reference = $request->json('eventDetails.transactionReference');
        $page = is_string($reference)
            ? WorldpayPage::where('transaction_reference', $reference)
                ->whereHas('payment', fn ($q) => $q->where('worldpay_account_id', $account->id))
                ->first()
            : null;

        if ($page === null) {
            Log::warning('Worldpay webhook for a page that is not known', ['worldpay_account_id' => $account->id]);

            return $reply;
        }

        // One webhook lookup per payment every 10 seconds.
        if (! Cache::add('worldpay:webhook:'.$page->payment_id, 1, 10)) {
            return $reply;
        }

        ResolveWorldpayPayment::dispatch($page->payment_id, WorldpayChargeResolver::SOURCE_WEBHOOK)->afterResponse();

        return $reply;
    }

    private function signatureIsValid(Request $request, WorldpayAccount $account): bool
    {
        $header = (string) $request->header('Event-Signature', '');
        $body = $request->getContent();

        foreach (explode(',', $header) as $entry) {
            $parts = explode('/', trim($entry), 3);

            if (count($parts) !== 3 || $parts[0] !== (string) $account->webhook_key_id) {
                continue;
            }

            [, $hash, $signature] = $parts;
            $hash = strtolower($hash);

            if (! in_array($hash, self::HASHES, true) || $signature === '') {
                continue;
            }

            $expected = hash_hmac($hash, $body, (string) $account->webhook_secret, true);

            // Worldpay's encoding is not proven yet (step 12), so hex and base64 of the same HMAC are both accepted.
            if (hash_equals(bin2hex($expected), strtolower($signature)) || hash_equals(base64_encode($expected), $signature)) {
                return true;
            }
        }

        return false;
    }
}
