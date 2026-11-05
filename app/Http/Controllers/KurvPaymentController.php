<?php

namespace App\Http\Controllers;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Services\Kurv\KurvCallbackToken;
use App\Services\Kurv\KurvChargeResolver;
use App\Services\Kurv\KurvClient;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Kurv hosted payment page pay thread (spec 0010, AC-5, AC-6, AC-9).
 *
 * The server creates one Kurv payment request per payment from the amount on the Payment
 * record. The browser never sends an amount, currency or account, and nothing here marks a
 * payment paid: only KurvChargeResolver does, from Kurv's own API.
 */
class KurvPaymentController extends Controller
{
    public function checkout(Payment $payment): JsonResponse
    {
        $payment->loadMissing('kurvAccount');

        if (! $this->payable($payment)) {
            return $this->unprocessable();
        }

        // Consent is enforced on the server, not only in the page (AC-5).
        if (! $payment->consents()->exists()) {
            return $this->unprocessable();
        }

        // A saved link is reused with no Kurv call, and a dead link is never recreated.
        if ($payment->kurv_payment_url !== null) {
            return $this->reply($payment);
        }

        $lock = Cache::lock('kurv:checkout:'.$payment->id, (int) config('services.kurv.lock_seconds', 60));

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'busy'], 409);
        }

        try {
            // A caller that waited for the lock reuses what the first caller saved.
            $payment->refresh()->loadMissing('kurvAccount');

            if (! $this->payable($payment)) {
                return $this->unprocessable();
            }

            if ($payment->kurv_payment_url === null) {
                $failure = $this->createRequest($payment);

                if ($failure !== null) {
                    return $failure;
                }
            }

            return $this->reply($payment);
        } finally {
            $lock->release();
        }
    }

    /**
     * Polled by the waiting state. Reads Payment.status and, at most once every few seconds
     * per payment, asks the resolver to look at Kurv. Returns the state only (AC-9).
     */
    public function status(Payment $payment): JsonResponse
    {
        abort_unless($payment->provider === PaymentProvider::Kurv, 404);

        $payment->loadMissing('kurvAccount');

        if ($payment->status === 'pending'
            && $payment->kurv_request_id !== null
            && $payment->kurv_link_closed_at === null
            && Cache::add('kurv:poll:'.$payment->id, 1, (int) config('services.kurv.poll_lookup_seconds', 10))) {
            app(KurvChargeResolver::class)->resolve($payment, KurvChargeResolver::SOURCE_POLL);
            $payment->refresh()->loadMissing('kurvAccount');
        }

        return response()->json(['state' => $this->state($payment)]);
    }

    private function state(Payment $payment): string
    {
        if ($payment->status === 'completed') {
            return 'completed';
        }

        if ($payment->status !== 'pending'
            || $payment->kurv_link_closed_at !== null
            || $payment->kurvAccount?->is_active !== true) {
            return 'unavailable';
        }

        // A decline seen in the last minute sends the client back to Pay now on the same link.
        $declined = $payment->kurvLookupAttempts()
            ->where('outcome', KurvChargeResolver::OUTCOME_DECLINED)
            ->where('created_at', '>=', now()->subSeconds(60))
            ->exists();

        $latest = $payment->kurvLookupAttempts()->latest('id')->value('outcome');

        return $declined && $latest === KurvChargeResolver::OUTCOME_DECLINED ? 'retry' : 'waiting';
    }

    private function payable(Payment $payment): bool
    {
        return $payment->provider === PaymentProvider::Kurv
            && $payment->status === 'pending'
            && $payment->kurv_link_closed_at === null
            && $payment->kurvAccount?->is_active === true;
    }

    private function unprocessable(): JsonResponse
    {
        return response()->json(['message' => 'unavailable'], 422);
    }

    private function createRequest(Payment $payment): ?JsonResponse
    {
        $account = $payment->kurvAccount;

        $client = app()->make(KurvClient::class, [
            'apiKey' => $account->api_key,
            'environment' => $account->environment,
        ]);

        $payUrl = route('pay.show', $payment);

        try {
            $created = $client->createPaymentRequest(
                amountCents: $payment->amount,
                currency: $payment->currency,
                customerName: (string) $payment->client_name,
                email: (string) $payment->client_email,
                referenceNumber: $payment->formattedReferenceCode(),
                responseUrl: KurvCallbackToken::urlFor($account, $payment),
                redirectUrl: $payUrl.'?kurv_return=1',
                cancelUrl: $payUrl,
            );
        } catch (Throwable $e) {
            // Operators only: raw Kurv text goes to the log, never to the client (AC-11).
            Log::error('Kurv payment request failed', [
                'payment_id' => $payment->id,
                'kurv_account_id' => $account->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'failed'], 502);
        }

        $payment->forceFill([
            'kurv_request_id' => $created->requestId,
            'kurv_payment_url' => $created->url,
            // From here amount, currency, account and brand are locked and deleting is blocked.
            'kurv_charge_started_at' => $payment->kurv_charge_started_at ?? now(),
        ])->save();

        return null;
    }

    private function reply(Payment $payment): JsonResponse
    {
        return response()->json(['url' => $payment->kurv_payment_url]);
    }
}
