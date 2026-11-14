<?php

namespace App\Http\Controllers;

use App\Enums\NmiEnvironment;
use App\Enums\PaymentProvider;
use App\Http\Requests\ChargeNmiRequest;
use App\Jobs\ResolveNmiCharge;
use App\Models\NmiAccount;
use App\Models\NmiChargeAttempt;
use App\Models\Payment;
use App\Services\CardLock\CardLockGuard;
use App\Services\Nmi\NmiAmount;
use App\Services\Nmi\NmiChargeResolver;
use App\Services\Nmi\NmiClient;
use App\Services\Nmi\NmiSaleReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * NMI pay thread (spec 0012, AC-7, AC-8, AC-10 to AC-12).
 *
 * The browser turns the card or Google Pay into a one time token with NMI's Payment Component; this
 * controller charges that token server side and reads the transaction back before any status
 * changes (NmiChargeResolver). Nothing the browser sends decides the amount, account or result.
 * The token and the gateway request are never stored or logged.
 */
class NmiPaymentController extends Controller
{
    public function charge(ChargeNmiRequest $request, Payment $payment): JsonResponse
    {
        $account = $this->payableAccount($payment);

        if ($account === null) {
            return $this->notPayable();
        }

        // Consent is enforced on the server, not only in the page (AC-5).
        if (! $payment->consents()->exists()) {
            return response()->json(['message' => 'Please accept the payment terms first.'], 422);
        }

        if ($request->boolean('three_ds_failed')) {
            return $this->reportThreeDsFailure($request, $payment);
        }

        if ($account->three_ds_enabled && $request->validated('method') === 'card'
            && (blank($request->validated('cavv')) || blank($request->validated('eci')))) {
            return $this->notPayable();
        }

        if (app(CardLockGuard::class)->isLocked($payment)) {
            return app(CardLockGuard::class)->lockedResponse($payment);
        }

        if (Cache::has($this->pauseKey($payment))) {
            return $this->outcomeResponse(NmiSaleReply::UNCLEAR, 409);
        }

        $lock = Cache::lock("nmi-charge:{$payment->id}", 60);

        if (! $lock->get()) {
            return $this->outcomeResponse(NmiSaleReply::UNCLEAR, 409);
        }

        try {
            // Another request may have finished while this one waited for the lock.
            $payment->refresh();
            $account = $this->payableAccount($payment);

            if ($account === null) {
                return $this->notPayable();
            }

            return $this->runCharge($request, $payment, $account);
        } finally {
            $lock->release();
        }
    }

    /**
     * Polled by the pay page after an unclear outcome. Runs a guarded server side lookup, then
     * reports the payment state with fixed words (never gateway text).
     */
    public function status(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->provider === PaymentProvider::Nmi, 404);

        app(NmiChargeResolver::class)->resolveGuarded($payment);

        $payment->refresh();

        return response()->json(['status' => $payment->status]);
    }

    /**
     * A failed or cancelled 3DS check: only an attempt row is written. No lock, no gateway call,
     * no card lock count (AC-7, AC-11).
     */
    private function reportThreeDsFailure(ChargeNmiRequest $request, Payment $payment): JsonResponse
    {
        $this->startAttempt($payment, (string) $request->validated('method'), '3ds_failed', 'Customer failed or cancelled 3D Secure');

        return response()->json([
            'outcome' => '3ds_failed',
            'message' => 'We could not verify your card. Please try again or use a different card.',
        ]);
    }

    private function runCharge(ChargeNmiRequest $request, Payment $payment, NmiAccount $account): JsonResponse
    {
        $method = (string) $request->validated('method');
        $client = NmiClient::forAccount($account);

        // The pause is set BEFORE the sale call: a sale that times out while still in flight at NMI
        // can never be sent twice. It is cleared below only on a clear outcome.
        Cache::put($this->pauseKey($payment), 1, now()->addSeconds((int) config('services.nmi.unclear_pause_seconds', 120)));

        $payment->forceFill([
            'nmi_order_id' => $payment->nmi_order_id ?? (string) Str::uuid(),
            'nmi_first_attempt_at' => $payment->nmi_first_attempt_at ?? now(),
            'nmi_last_attempt_at' => now(),
        ])->save();

        // Written before the call so a timeout or crash still leaves a record.
        $attempt = $this->startAttempt($payment, $method, 'sent');
        $reply = $this->sendSale($request, $payment, $client, $account);

        if ($reply->outcome === NmiSaleReply::APPROVED) {
            $reply = $this->readBack($payment, $reply);
        }

        $this->recordAttempt($attempt, $reply);

        // Clear outcomes end the pause. An unclear one keeps it until the lookup settles it.
        if ($reply->outcome !== NmiSaleReply::UNCLEAR) {
            Cache::forget($this->pauseKey($payment));
        }

        if (in_array($reply->outcome, [NmiSaleReply::DECLINED, NmiSaleReply::CHECK_DETAILS], true)) {
            $guard = app(CardLockGuard::class);
            $guard->recordFailure($payment);

            if ($guard->isLocked($payment)) {
                return $guard->lockedResponse($payment);
            }
        }

        return match ($reply->outcome) {
            NmiSaleReply::UNCLEAR => $this->unclear($payment),
            NmiSaleReply::ERROR => $this->gatewayError($payment, $reply),
            default => $this->outcomeResponse($reply->outcome),
        };
    }

    private function sendSale(ChargeNmiRequest $request, Payment $payment, NmiClient $client, NmiAccount $account): NmiSaleReply
    {
        [$first, $last] = $this->names($request, $payment);

        $sale = [
            'amount' => NmiAmount::centsToDecimal($payment->amount), // from the record, never the request
            'currency' => strtoupper((string) $payment->currency),
            'payment_details' => ['payment_token' => $request->validated('payment_token')],
            'order_details' => ['id' => $payment->nmi_order_id, 'ip_address' => (string) $request->ip()],
            'billing_address' => array_filter([
                'first_name' => $first,
                'last_name' => $last,
                // NMI sandbox accounts reject any email but the merchant's own, so a sandbox account sends none.
                'email' => $account->environment === NmiEnvironment::Production ? Str::limit((string) $payment->client_email, 255, '') : '',
            ], fn (string $v): bool => $v !== ''),
        ];

        // NMI v5 cardholder_auth: `status` ("verified" or "attempted") and `cavv` are required, the ECI is derived
        // from the status, so no eci key is sent (browser run 2026-11-14: eci and cardholder_auth keys gave
        // E_INVALID_SUBMISSION).
        $auth = array_filter([
            'status' => $request->validated('card_holder_auth'),
            'cavv' => $request->validated('cavv'),
            'xid' => $request->validated('xid'),
            'three_ds_version' => $request->validated('three_ds_version'),
            'directory_server_id' => $request->validated('directory_server_id'),
        ], fn (mixed $v): bool => filled($v));

        if ($auth !== [] && $request->validated('method') === 'card') {
            $sale['cardholder_auth'] = $auth;
        }

        try {
            return NmiSaleReply::fromResult($client->sale($sale));
        } catch (Throwable $e) {
            // Class only: a transport error message must never carry request data.
            Log::warning('NMI charge call failed', ['payment_id' => $payment->id, 'exception' => $e::class]);

            return NmiSaleReply::unclear();
        }
    }

    /**
     * Read the transaction the sale reply named and apply it through the resolver. Anything that is
     * not settled as `completed` is unclear (the AC-10 path takes over). The transaction id stays
     * on the attempt row; only the resolver writes it onto the payment.
     */
    private function readBack(Payment $payment, NmiSaleReply $reply): NmiSaleReply
    {
        try {
            $outcome = app(NmiChargeResolver::class)->resolveTransaction($payment, (string) $reply->transactionId);
        } catch (Throwable $e) {
            Log::warning('NMI read back failed', ['payment_id' => $payment->id, 'exception' => $e::class]);

            return $reply->asUnclear();
        }

        $payment->refresh();

        return $outcome === NmiChargeResolver::OUTCOME_PAID && $payment->status === 'completed'
            ? $reply
            : $reply->asUnclear();
    }

    /**
     * The attempt row for this call, or null when it could not be written. Diagnostic only: a failure
     * here must never stop a payer from paying.
     */
    private function startAttempt(Payment $payment, string $method, string $status, ?string $reason = null): ?NmiChargeAttempt
    {
        try {
            return $payment->nmiChargeAttempts()->create([
                'sequence' => ((int) $payment->nmiChargeAttempts()->max('sequence')) + 1,
                'method' => $method,
                'status' => $status,
                'reason' => $reason,
            ]);
        } catch (Throwable $e) {
            Log::warning('NMI attempt row could not be created', ['payment_id' => $payment->id, 'exception' => $e::class]);

            return null;
        }
    }

    /**
     * Saves what NMI answered on this charge's attempt row. Diagnostic only: it never changes the
     * payment, and a failure here must not turn a charge into an error.
     */
    private function recordAttempt(?NmiChargeAttempt $attempt, NmiSaleReply $reply): void
    {
        if ($attempt === null) {
            return;
        }

        try {
            $attempt->update([
                'status' => $reply->outcome,
                'response_code' => $reply->detail['response_code'] ?? ($reply->detail['error_code'] ?? null),
                'reason' => $reply->reason(),
                'transaction_id' => $reply->transactionId,
                'avs_result' => $reply->detail['avs'] ?? null,
                'cvv_result' => $reply->detail['cvv'] ?? null,
            ]);
        } catch (Throwable $e) {
            Log::warning('NMI attempt record failed', ['attempt_id' => $attempt->id, 'exception' => $e::class]);
        }
    }

    /**
     * PayHub cannot tell whether the card was charged: the pause set before the sale stays, and a
     * lookup is queued to settle it (AC-10).
     */
    private function unclear(Payment $payment): JsonResponse
    {
        ResolveNmiCharge::dispatch($payment->id)->delay(now()->addSeconds(15));

        return $this->outcomeResponse(NmiSaleReply::UNCLEAR);
    }

    private function gatewayError(Payment $payment, NmiSaleReply $reply): JsonResponse
    {
        Log::error('NMI rejected the charge request', [
            'payment_id' => $payment->id,
            'response_code' => $reply->detail['response_code'] ?? null,
            'error_code' => $reply->detail['error_code'] ?? null,
            'error_message' => $reply->detail['error_message'] ?? null,
        ]);

        return $this->outcomeResponse(NmiSaleReply::ERROR, 502);
    }

    private function outcomeResponse(string $outcome, int $status = 200): JsonResponse
    {
        return response()->json([
            'outcome' => $outcome,
            'message' => NmiSaleReply::message($outcome),
        ], $status);
    }

    private function notPayable(): JsonResponse
    {
        return response()->json(['message' => 'This payment can no longer be paid.'], 422);
    }

    private function pauseKey(Payment $payment): string
    {
        return "nmi:pause:{$payment->id}";
    }

    /**
     * The account when this payment can take a new charge: NMI, pending, account active, and no
     * attempt row that already says approved (AC-7).
     */
    private function payableAccount(Payment $payment): ?NmiAccount
    {
        if ($payment->provider !== PaymentProvider::Nmi || $payment->status !== 'pending') {
            return null;
        }

        $account = $payment->nmiAccount;

        if (! $account?->is_active || $this->hasPossibleCharge($payment)) {
            return null;
        }

        return $account;
    }

    /**
     * True when money may already have moved: an attempt row says approved, or it is unclear but NMI
     * named a transaction (an approved sale the read back could not settle). A new sale must wait for
     * the lookup to settle that one, or the payer could be charged twice. An unclear row with no
     * transaction id (a plain timeout) is only held back by the short pause.
     */
    private function hasPossibleCharge(Payment $payment): bool
    {
        return $payment->nmiChargeAttempts()
            ->where(fn ($q) => $q->where('status', 'approved')
                ->orWhere(fn ($q) => $q->where('status', 'unclear')->whereNotNull('transaction_id')))
            ->exists();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function names(ChargeNmiRequest $request, Payment $payment): array
    {
        $first = trim((string) $request->validated('first_name'));
        $last = trim((string) $request->validated('last_name'));

        if ($first === '' && $last === '') {
            $parts = preg_split('/\s+/', trim((string) $payment->client_name), 2) ?: [''];
            $first = $parts[0];
            $last = $parts[1] ?? $parts[0];
        }

        return [Str::limit($first, 50, ''), Str::limit($last !== '' ? $last : $first, 50, '')];
    }
}
