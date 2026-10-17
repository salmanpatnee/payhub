<?php

namespace App\Http\Controllers;

use App\Enums\PaymentProvider;
use App\Jobs\ResolveMpgsChargeAttempt;
use App\Models\MpgsAccount;
use App\Models\MpgsChargeAttempt;
use App\Models\Payment;
use App\Services\Mpgs\MpgsChargeClassifier;
use App\Services\Mpgs\MpgsChargeResolver;
use App\Services\Mpgs\MpgsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MPGS Hosted Session pay thread (spec 0005, build plan step 4 and 5).
 *
 * session(): server side prepare. Creates the gateway session, writes the
 * amount and currency from the Payment onto it, and records an attempt row
 * (status prepared) before the browser ever sees a session id.
 * pay(): sends PAY with the stored session id and 3DS transaction id, then
 * applies the classified result through MpgsChargeResolver.
 * status(): read only poll of Payment.status.
 *
 * PROVISIONAL (AC-13): the gateway field names live in MpgsClient and
 * MpgsChargeClassifier, not here, so the sandbox spike corrects them in one
 * place each.
 */
class MpgsPaymentController extends Controller
{
    public function session(Payment $payment): JsonResponse
    {
        $account = $this->payableAccount($payment);

        if (! $account) {
            return response()->json(['error' => 'This payment cannot be processed.'], 422);
        }

        $lock = Cache::lock("mpgs-attempt:{$payment->id}", 60);

        if (! $lock->get()) {
            return response()->json(['error' => 'A payment attempt is already in progress. Please wait a moment.'], 409);
        }

        try {
            if ($payment->mpgsChargeAttempts()->where('status', 'pending')->exists()) {
                return response()->json(['error' => 'A payment attempt is already in progress. Please wait a moment.'], 409);
            }

            // Only the newest prepared session is usable: retire earlier ones so
            // a reload or a retry never leaves several live sessions behind.
            $payment->mpgsChargeAttempts()->where('status', 'prepared')->get()
                ->each(fn (MpgsChargeAttempt $old) => $old->update(['status' => 'abandoned', 'session_id' => null]));

            $sequence = ((int) $payment->mpgsChargeAttempts()->max('sequence')) + 1;
            $orderId = "{$payment->id}-{$sequence}";
            $client = $this->client($account);

            try {
                $created = $client->createSession();
                $sessionId = $created['body']['session']['id'] ?? null;

                if (! $this->isSuccessful($created['status']) || ! is_string($sessionId) || $sessionId === '') {
                    return $this->prepareFailed($payment, 'create', $created);
                }

                $updated = $client->updateSession($sessionId, $orderId, $payment->amount, $payment->currency, route('pay.mpgs.return', $payment));

                if (! $this->isSuccessful($updated['status'])) {
                    return $this->prepareFailed($payment, 'update', $updated);
                }
            } catch (\Throwable $e) {
                Log::warning('MPGS session transport failure', [
                    'payment_uuid' => $payment->uuid,
                    'error' => $e->getMessage(),
                ]);

                return response()->json(['error' => 'Could not reach the payment processor. Please try again.'], 502);
            }

            $attempt = MpgsChargeAttempt::create([
                'payment_id' => $payment->id,
                'sequence' => $sequence,
                'order_id' => $orderId,
                'auth_transaction_id' => Str::random(16),
                'transaction_id' => Str::random(16),
                'session_id' => $sessionId,
                'status' => 'prepared',
            ]);
        } finally {
            $lock->release();
        }

        return response()->json([
            'session_id' => $sessionId,
            'order_id' => $attempt->order_id,
            'auth_transaction_id' => $attempt->auth_transaction_id,
            'attempt_id' => $attempt->id,
        ]);
    }

    public function pay(Request $request, Payment $payment): JsonResponse
    {
        $account = $this->payableAccount($payment);

        if (! $account) {
            return response()->json(['error' => 'This payment cannot be processed.'], 422);
        }

        $validated = $request->validate([
            'attempt_id' => ['required', 'integer'],
        ]);

        // One lock for prepare and pay: at most one PAY is ever in flight per
        // payment, so a double click gets 409 and never a second charge.
        $lock = Cache::lock("mpgs-attempt:{$payment->id}", 60);

        if (! $lock->get()) {
            return response()->json(['error' => 'A payment attempt is already in progress. Please wait a moment.'], 409);
        }

        try {
            $attempt = $payment->mpgsChargeAttempts()->whereKey($validated['attempt_id'])->first();

            if (! $attempt
                || $attempt->status !== 'prepared'
                || $attempt->created_at->addMinutes((int) config('services.mpgs.session_lifetime_minutes'))->isPast()) {
                return response()->json(['error' => 'This payment session is no longer valid. Please refresh and try again.'], 422);
            }

            // Guarded transition: only one caller can move prepared to pending.
            $moved = MpgsChargeAttempt::whereKey($attempt->id)->where('status', 'prepared')->update(['status' => 'pending']);

            if (! $moved) {
                return response()->json(['error' => 'A payment attempt is already in progress. Please wait a moment.'], 409);
            }

            $attempt->refresh();
            $client = $this->client($account);

            try {
                $result = $client->pay(
                    orderId: $attempt->order_id,
                    transactionId: $attempt->transaction_id,
                    sessionId: (string) $attempt->session_id,
                    authTransactionId: $attempt->auth_transaction_id,
                    amount: $payment->amount,
                    currency: $payment->currency,
                    notificationUrl: route('webhook.mpgs', $account),
                    reference: $payment->formattedReferenceCode(),
                );
                $body = $result['body'];
                $classification = MpgsChargeClassifier::classify($result['status'], $body, $payment->amount, $payment->currency);
            } catch (\Throwable $e) {
                // Timeout or connection error before a classifiable response
                // arrived: resolved later by the read only resolver job.
                Log::warning('MPGS pay transport failure', [
                    'attempt_id' => $attempt->id,
                    'payment_uuid' => $payment->uuid,
                    'error' => $e->getMessage(),
                ]);
                $body = [];
                $classification = 'unknown';
            }

            app(MpgsChargeResolver::class)->resolve($attempt, $classification, $body);
        } finally {
            $lock->release();
        }

        if ($classification === 'unknown') {
            ResolveMpgsChargeAttempt::dispatch($attempt->id)->delay(now()->addSeconds(15));
        }

        if ($classification === 'hard_error') {
            return response()->json(['error' => 'Could not reach the payment processor. Please try again.'], 502);
        }

        return response()->json([
            'outcome' => $classification, // approved | declined | unknown
            'message' => match ($classification) {
                'approved' => 'Your payment was successful.',
                'declined' => 'Your card was declined. Please try a different card.',
                default => 'We could not confirm your payment yet.',
            },
        ]);
    }

    /**
     * Polled by the client only while the latest attempt is unresolved.
     * Read only: Payment.status is the single source of truth.
     */
    public function status(Payment $payment): JsonResponse
    {
        abort_unless($payment->provider === PaymentProvider::Mpgs, 404);

        return response()->json(['status' => $payment->status]);
    }

    /**
     * Where the bank's ACS sends the payer back after a 3DS challenge
     * (authentication.redirectResponseUrl). It renders inside the challenge
     * frame on the pay page, so SecurityHeaders sends SAMEORIGIN for this route.
     * It takes no payment data and changes no status: it only tells the pay page
     * the challenge ended, and the server decides the outcome on PAY.
     *
     * PROVISIONAL (AC-13): the POST field names are the documented callback
     * names; the sandbox spike confirms what the gateway actually sends.
     */
    public function threeDsReturn(Request $request, Payment $payment): Response
    {
        abort_unless($payment->provider === PaymentProvider::Mpgs, 404);

        return response()->view('mpgs-3ds-return', [
            'uuid' => $payment->uuid,
            'orderId' => Str::limit((string) $request->input('orderId', ''), 100, ''),
            'transactionId' => Str::limit((string) $request->input('transactionId', ''), 100, ''),
            'recommendation' => Str::limit((string) $request->input('gatewayRecommendation', ''), 100, ''),
            'result' => Str::limit((string) $request->input('result', ''), 100, ''),
        ]);
    }

    private function payableAccount(Payment $payment): ?MpgsAccount
    {
        $payment->loadMissing('mpgsAccount');

        if ($payment->provider !== PaymentProvider::Mpgs
            || ! in_array($payment->status, ['pending', 'failed'], true)
            || ! $payment->mpgsAccount
            || ! $payment->mpgsAccount->is_active) {
            return null;
        }

        return $payment->mpgsAccount;
    }

    private function client(MpgsAccount $account): MpgsClient
    {
        return app()->make(MpgsClient::class, [
            'merchantId' => $account->merchant_id,
            'integrationPassword' => $account->integration_password,
            'apiVersion' => $account->api_version,
            'gatewayHost' => $account->gatewayHost(),
        ]);
    }

    private function isSuccessful(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * @param  array{status: int, body: array<string, mixed>}  $result
     */
    private function prepareFailed(Payment $payment, string $step, array $result): JsonResponse
    {
        // The gateway's error block only: it never carries the session id or
        // credentials.
        Log::error('MPGS session prepare failed', [
            'payment_uuid' => $payment->uuid,
            'step' => $step,
            'http_status' => $result['status'],
            'error' => $result['body']['error'] ?? null,
        ]);

        return response()->json(['error' => 'Could not reach the payment processor. Please try again.'], 502);
    }
}
