<?php

namespace App\Http\Controllers;

use App\Enums\PaymentProvider;
use App\Models\AuthorizenetAccount;
use App\Models\Payment;
use App\Services\Authorizenet\AuthorizenetClient;
use App\Services\Authorizenet\AuthorizenetInvoice;
use App\Services\Authorizenet\AuthorizenetInvoiceLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

/**
 * Authorize.net Accept Hosted pay thread (spec 0006, AC-4, AC-5, AC-6, AC-9).
 *
 * Card data never reaches PayHub: the client types it into Authorize.net's own
 * form in an iframe. Paid status only ever comes from a server side read of the
 * transaction (AuthorizenetInvoiceLookup); nothing the browser sends is trusted.
 */
class AuthorizenetPaymentController extends Controller
{
    /**
     * Create a one time hosted form token. The token is returned once and never
     * stored or logged.
     */
    public function token(Payment $payment): JsonResponse
    {
        $account = $this->payableAccount($payment);

        if ($account === null) {
            return response()->json(['message' => 'This payment can no longer be paid.'], 422);
        }

        [$first, $last] = $this->splitName((string) $payment->client_name);

        try {
            $result = app(AuthorizenetInvoiceLookup::class)->client($account)->getHostedPaymentPage([
                'invoice' => AuthorizenetInvoice::for($payment),
                'amount' => AuthorizenetInvoice::centsToDecimal($payment->amount), // from the record, never the request
                'email' => Str::limit((string) $payment->client_email, 255, ''),
                'first_name' => $first,
                'last_name' => $last,
                'button_text' => 'Pay $'.number_format($payment->amount / 100, 2),
                'communicator_url' => secure_url(route('pay.authorizenet.communicator', [], false)),
            ]);
        } catch (Throwable $e) {
            Log::warning('Authorize.net token request failed', ['payment_id' => $payment->id, 'error' => $e->getMessage()]);

            return $this->gatewayFailure();
        }

        $token = $result['body']['token'] ?? null;

        if (! AuthorizenetClient::isOk($result) || ! is_string($token) || $token === '') {
            Log::warning('Authorize.net token request rejected', [
                'payment_id' => $payment->id,
                'http_status' => $result['status'],
                'code' => $result['body']['messages']['message'][0]['code'] ?? null,
                'text' => $result['body']['messages']['message'][0]['text'] ?? null,
            ]);

            return $this->gatewayFailure();
        }

        $payment->forceFill([
            'authorizenet_token_issued_at' => $payment->authorizenet_token_issued_at ?? now(),
            'authorizenet_token_last_issued_at' => now(),
        ])->save();

        return response()->json([
            'token' => $token,
            'form_url' => config("services.authorizenet.hosts.{$account->environment->value}.form").'/payment/payment',
        ]);
    }

    /**
     * Polled by the pay page. Runs a guarded server side lookup, then reports
     * the payment state with fixed words (never the gateway's text).
     */
    public function status(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->provider === PaymentProvider::Authorizenet, 404);

        $payment->loadMissing('authorizenetAccount');

        $hint = $request->query('trans_id');

        app(AuthorizenetInvoiceLookup::class)->lookup($payment, is_string($hint) ? $hint : null);

        $payment->refresh();

        return response()->json([
            'status' => $payment->status,
            'state' => $this->state($payment),
        ]);
    }

    /** The tiny page the hosted form frames to relay messages (best effort, spec 0006 AC-5). */
    public function communicator(): View
    {
        return view('authorizenet-communicator');
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

        return $account?->is_active ? $account : null;
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

    private function gatewayFailure(): JsonResponse
    {
        return response()->json(['message' => 'We could not start the payment form. Please try again.'], 502);
    }
}
