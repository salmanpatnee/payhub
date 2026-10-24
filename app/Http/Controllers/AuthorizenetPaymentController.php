<?php

namespace App\Http\Controllers;

use App\Enums\PaymentProvider;
use App\Http\Requests\ChargeAuthorizenetRequest;
use App\Jobs\ResolveAuthorizenetCharge;
use App\Models\AuthorizenetAccount;
use App\Models\AuthorizenetChargeAttempt;
use App\Models\Payment;
use App\Services\Authorizenet\AuthorizenetChargeClassifier;
use App\Services\Authorizenet\AuthorizenetChargeReply;
use App\Services\Authorizenet\AuthorizenetChargeResolver;
use App\Services\Authorizenet\AuthorizenetClient;
use App\Services\Authorizenet\AuthorizenetInvoice;
use App\Services\Authorizenet\AuthorizenetInvoiceLookup;
use App\Services\CardLock\CardLockGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Authorize.net Accept.js pay thread (spec 0007, AC-6 to AC-11).
 *
 * The browser turns the card into a one time code with Accept.js; this controller
 * charges that code server side and reads the transaction back before any status
 * changes (AuthorizenetChargeResolver). Nothing the browser sends decides the
 * amount, account or result. The data value and the gateway request are never
 * stored or logged.
 */
class AuthorizenetPaymentController extends Controller
{
    public function charge(ChargeAuthorizenetRequest $request, Payment $payment): JsonResponse
    {
        $account = $this->payableAccount($payment);

        if ($account === null) {
            return $this->notPayable();
        }

        if (app(CardLockGuard::class)->isLocked($payment)) {
            return app(CardLockGuard::class)->lockedResponse($payment);
        }

        if (Cache::has($this->pauseKey($payment))) {
            return $this->outcomeResponse(AuthorizenetChargeReply::UNCLEAR, 409);
        }

        $lock = Cache::lock("authorizenet-charge:{$payment->id}", 60);

        if (! $lock->get()) {
            return $this->outcomeResponse(AuthorizenetChargeReply::UNCLEAR, 409);
        }

        try {
            // Another request may have finished while this one waited for the lock.
            $payment->refresh();

            if ($this->payableAccount($payment) === null) {
                return $this->notPayable();
            }

            return $this->runCharge($request, $payment, $account);
        } finally {
            $lock->release();
        }
    }

    /**
     * Polled by the pay page after a held or unclear outcome. Runs a guarded server
     * side lookup, then reports the payment state with fixed words (never gateway text).
     */
    public function status(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->provider === PaymentProvider::Authorizenet, 404);

        $payment->loadMissing('authorizenetAccount');

        app(AuthorizenetInvoiceLookup::class)->lookup($payment);

        $payment->refresh();

        return response()->json([
            'status' => $payment->status,
            'state' => $this->state($payment),
        ]);
    }

    private function runCharge(ChargeAuthorizenetRequest $request, Payment $payment, AuthorizenetAccount $account): JsonResponse
    {
        [$first, $last] = $this->splitName((string) $payment->client_name);
        $client = app(AuthorizenetInvoiceLookup::class)->client($account);

        $payment->forceFill([
            'authorizenet_first_attempt_at' => $payment->authorizenet_first_attempt_at ?? now(),
            'authorizenet_last_attempt_at' => now(),
        ])->save();

        // Written before the call so a timeout or crash still leaves a record.
        $attempt = $this->startAttempt($payment);

        try {
            $reply = AuthorizenetChargeReply::fromResult($client->createTransaction([
                'invoice' => AuthorizenetInvoice::for($payment),
                'description' => $payment->formattedReferenceCode(),
                'amount' => AuthorizenetInvoice::centsToDecimal($payment->amount), // from the record, never the request
                'data_descriptor' => $request->validated('data_descriptor'),
                'data_value' => $request->validated('data_value'),
                'email' => Str::limit((string) $payment->client_email, 255, ''),
                'first_name' => $first,
                'last_name' => $last,
                'zip' => $request->validated('postal_code'),
                'ip' => (string) $request->ip(),
                'duplicate_window' => config('services.authorizenet.duplicate_window_seconds'),
            ]));
        } catch (Throwable $e) {
            // Class only: a transport error message must never carry request data.
            Log::warning('Authorize.net charge call failed', ['payment_id' => $payment->id, 'exception' => $e::class]);
            $reply = AuthorizenetChargeReply::unclear();
        }

        if (in_array($reply->outcome, [AuthorizenetChargeReply::APPROVED, AuthorizenetChargeReply::HELD], true)) {
            $reply = $this->readBack($payment, $client, $reply);
        }

        $this->recordAttempt($attempt, $reply);

        if (in_array($reply->outcome, [AuthorizenetChargeReply::DECLINED, AuthorizenetChargeReply::CHECK_DETAILS], true)) {
            $guard = app(CardLockGuard::class);
            $guard->recordFailure($payment);

            if ($guard->isLocked($payment)) {
                return $guard->lockedResponse($payment);
            }
        }

        return match ($reply->outcome) {
            AuthorizenetChargeReply::UNCLEAR => $this->unclear($payment),
            AuthorizenetChargeReply::ERROR => $this->gatewayError($payment, $reply),
            default => $this->outcomeResponse($reply->outcome),
        };
    }

    /**
     * The attempt row for this charge, or null when it could not be written. Diagnostic
     * only: a failure here must never stop a payer from paying.
     */
    private function startAttempt(Payment $payment): ?AuthorizenetChargeAttempt
    {
        try {
            return $payment->authorizenetChargeAttempts()->create([
                'sequence' => ((int) $payment->authorizenetChargeAttempts()->max('sequence')) + 1,
                'status' => 'sent',
            ]);
        } catch (Throwable $e) {
            Log::warning('Authorize.net attempt row could not be created', ['payment_id' => $payment->id, 'exception' => $e::class]);

            return null;
        }
    }

    /**
     * Saves what the gateway answered on this charge's attempt row. Diagnostic only: it
     * never changes the payment, and a failure here must not turn a charge into an error.
     */
    private function recordAttempt(?AuthorizenetChargeAttempt $attempt, AuthorizenetChargeReply $reply): void
    {
        if ($attempt === null) {
            return;
        }

        try {
            $attempt->update([
                'status' => $reply->outcome,
                'response_code' => $reply->detail['response_code'] ?? null,
                'error_code' => $reply->gatewayCode,
                'reason' => $reply->reason(),
                'transaction_id' => $reply->transactionId,
                'avs_result' => $reply->detail['avs'] ?? null,
                'cvv_result' => $reply->detail['cvv'] ?? null,
                'cavv_result' => $reply->detail['cavv'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::warning('Authorize.net attempt record failed', ['attempt_id' => $attempt->id, 'exception' => $e::class]);
        }
    }

    /**
     * Read the transaction the charge reply named and apply it through the resolver.
     * Anything that is not clearly approved or held for this payment is unclear.
     */
    private function readBack(Payment $payment, AuthorizenetClient $client, AuthorizenetChargeReply $reply): AuthorizenetChargeReply
    {
        try {
            $result = $client->getTransactionDetails((string) $reply->transactionId);
        } catch (Throwable $e) {
            Log::warning('Authorize.net read back failed', ['payment_id' => $payment->id, 'exception' => $e::class]);

            return $reply->asUnclear();
        }

        $transaction = $result['body']['transaction'] ?? null;

        if (! AuthorizenetClient::isOk($result) || ! is_array($transaction)) {
            return $reply->asUnclear();
        }

        $bucket = AuthorizenetChargeClassifier::classify($transaction, $payment);

        if (! in_array($bucket, [AuthorizenetChargeClassifier::APPROVED, AuthorizenetChargeClassifier::HELD], true)) {
            Log::error('Authorize.net charge read back did not match the payment', [
                'payment_id' => $payment->id,
                'transaction_id' => $reply->transactionId,
                'bucket' => $bucket,
            ]);

            return $reply->asUnclear();
        }

        app(AuthorizenetChargeResolver::class)->apply($payment, $bucket, (string) $reply->transactionId, $transaction);

        $payment->refresh();

        if ($bucket === AuthorizenetChargeClassifier::HELD) {
            return $reply->withOutcome(AuthorizenetChargeReply::HELD);
        }

        // A cancelled payment is not completed by the resolver (it logs at error level).
        return $payment->status === 'completed'
            ? $reply->withOutcome(AuthorizenetChargeReply::APPROVED)
            : $reply->asUnclear();
    }

    /**
     * PayHub cannot tell whether the card was charged: stop new charges for a while
     * and let the invoice lookup settle it (AC-9).
     */
    private function unclear(Payment $payment): JsonResponse
    {
        Cache::put($this->pauseKey($payment), 1, now()->addSeconds((int) config('services.authorizenet.unclear_pause_seconds')));

        ResolveAuthorizenetCharge::dispatch($payment->id)->delay(now()->addSeconds(15));

        return $this->outcomeResponse(AuthorizenetChargeReply::UNCLEAR);
    }

    private function gatewayError(Payment $payment, AuthorizenetChargeReply $reply): JsonResponse
    {
        Log::error('Authorize.net rejected the charge request', [
            'payment_id' => $payment->id,
            'gateway_code' => $reply->gatewayCode,
        ]);

        return $this->outcomeResponse(AuthorizenetChargeReply::ERROR, 502);
    }

    private function outcomeResponse(string $outcome, int $status = 200): JsonResponse
    {
        return response()->json([
            'outcome' => $outcome,
            'message' => AuthorizenetChargeReply::message($outcome),
        ], $status);
    }

    private function notPayable(): JsonResponse
    {
        return response()->json(['message' => 'This payment can no longer be paid.'], 422);
    }

    private function pauseKey(Payment $payment): string
    {
        return "authorizenet:pause:{$payment->id}";
    }

    private function state(Payment $payment): string
    {
        return match (true) {
            $payment->status === 'completed' => 'completed',
            $payment->status === 'failed' => 'failed',
            $payment->status !== 'pending' => 'unavailable',
            $payment->authorizenet_transaction_id !== null => 'held',
            default => 'waiting',
        };
    }

    private function payableAccount(Payment $payment): ?AuthorizenetAccount
    {
        if ($payment->provider !== PaymentProvider::Authorizenet
            || $payment->status !== 'pending'
            || $payment->authorizenet_transaction_id !== null) {
            return null;
        }

        $account = $payment->authorizenetAccount;

        return $account?->is_active && $account->public_client_key ? $account : null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $name = trim($name);
        $parts = preg_split('/\s+/', $name, 2) ?: [''];
        $first = $parts[0];
        $last = $parts[1] ?? $parts[0];

        return [Str::limit($first, 50, ''), Str::limit($last, 50, '')];
    }
}
