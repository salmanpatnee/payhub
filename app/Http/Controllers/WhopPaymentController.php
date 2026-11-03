<?php

namespace App\Http\Controllers;

use App\Enums\PaymentProvider;
use App\Models\Payment;
use App\Models\WhopAccount;
use App\Services\Whop\WhopClient;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whop Checkout pay thread (spec 0009, AC-5, AC-6, AC-8).
 *
 * The server creates one Whop checkout configuration per payment from the amount on the
 * Payment record. The browser never sends an amount, currency or account, and nothing
 * here marks a payment paid: only the signed webhook does (HandleWhopWebhookJob).
 */
class WhopPaymentController extends Controller
{
    public function checkout(Payment $payment): JsonResponse
    {
        $payment->loadMissing('whopAccount');

        if (! $this->payable($payment)) {
            return $this->unprocessable();
        }

        // Consent is enforced on the server, not only in the page (AC-5).
        if (! $payment->consents()->exists()) {
            return $this->unprocessable();
        }

        $account = $payment->whopAccount;

        if ($payment->whop_checkout_configuration_id !== null) {
            return $this->reply($payment, $account);
        }

        $lock = Cache::lock('whop:checkout:'.$payment->id, (int) config('services.whop.lock_seconds', 60));

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            return response()->json(['message' => 'busy'], 409);
        }

        try {
            // A caller that waited for the lock reuses what the first caller saved.
            $payment->refresh()->loadMissing('whopAccount');

            if (! $this->payable($payment)) {
                return $this->unprocessable();
            }

            if ($payment->whop_checkout_configuration_id === null) {
                $failure = $this->createConfiguration($payment, $account);

                if ($failure !== null) {
                    return $failure;
                }
            }

            return $this->reply($payment, $account);
        } finally {
            $lock->release();
        }
    }

    /** Polled by the waiting state. Reads Payment.status only, never calls Whop. */
    public function status(Payment $payment): JsonResponse
    {
        abort_unless($payment->provider === PaymentProvider::Whop, 404);

        return response()->json([
            'state' => match ($payment->status) {
                'completed' => 'completed',
                'pending' => 'waiting',
                default => 'unavailable',
            },
        ]);
    }

    private function payable(Payment $payment): bool
    {
        return $payment->provider === PaymentProvider::Whop
            && $payment->status === 'pending'
            && $payment->whopAccount?->is_active === true;
    }

    private function unprocessable(): JsonResponse
    {
        return response()->json(['message' => 'unavailable'], 422);
    }

    private function createConfiguration(Payment $payment, WhopAccount $account): ?JsonResponse
    {
        $client = app()->make(WhopClient::class, [
            'apiKey' => $account->api_key,
            'companyId' => $account->company_id,
            'environment' => $account->environment,
        ]);

        try {
            $created = $client->createCheckoutConfiguration(
                amountCents: $payment->amount,
                currency: $payment->currency,
                planTitle: $payment->formattedReferenceCode(),
                metadata: [
                    'payment_id' => $payment->uuid,
                    'reference' => $payment->formattedReferenceCode(),
                ],
                redirectUrl: route('pay.show', $payment),
            );
        } catch (Throwable $e) {
            // Operators only: raw Whop text goes to the log, never to the client (AC-7).
            Log::error('Whop checkout configuration failed', [
                'payment_id' => $payment->uuid,
                'whop_account_id' => $account->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => 'failed'], 502);
        }

        $payment->forceFill([
            'whop_checkout_configuration_id' => $created['id'],
            'whop_purchase_url' => $client->purchaseUrl($created['purchase_path']),
            // From here amount, currency, account and brand are locked and cancelling is blocked.
            'whop_charge_started_at' => $payment->whop_charge_started_at ?? now(),
        ])->save();

        return null;
    }

    private function reply(Payment $payment, WhopAccount $account): JsonResponse
    {
        return response()->json([
            // The account's current mode wins over the mode the page loaded with.
            'mode' => $account->checkout_mode,
            'checkout_configuration_id' => $payment->whop_checkout_configuration_id,
            'purchase_url' => $payment->whop_purchase_url,
        ]);
    }
}
