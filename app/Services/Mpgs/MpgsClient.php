<?php

namespace App\Services\Mpgs;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the MPGS (Mastercard Gateway) REST API for one merchant.
 *
 * Built per MpgsAccount with that account's decrypted credentials, never a
 * global client. Resolve via
 * app()->make(MpgsClient::class, ['merchantId' => ..., 'integrationPassword' => ..., 'apiVersion' => ..., 'gatewayHost' => ...])
 * so tests can fake the HTTP layer (Http::fake).
 *
 * The gateway host is the account's own (gatewayHost), falling back to config (services.mpgs.host). Auth is HTTP
 * Basic as `merchant.{MID}`.
 *
 * PROVISIONAL (spec 0005, AC-13): the request and response field names here
 * come from the integration handoff, not a verified sandbox. The sandbox spike
 * must confirm them before the pay thread (build plan step 4) relies on them;
 * keep every field name in this class so a correction lands in one place.
 *
 * pay() and retrieveOrder() deliberately do NOT ->throw() on a non-2xx so the
 * caller can classify a real decline or hard error instead of every non-2xx
 * collapsing into "transport failed". A transport failure (timeout, DNS) still
 * throws, which the caller treats as unknown.
 */
class MpgsClient
{
    public function __construct(
        private readonly string $merchantId,
        private readonly string $integrationPassword,
        private readonly int $apiVersion,
        private readonly ?string $gatewayHost = null,
    ) {}

    /**
     * Create a Hosted Session. Returns the session id the browser's hosted
     * fields attach to.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function createSession(): array
    {
        // authenticationLimit caps how many transaction requests the payer's
        // browser may submit on this session. The gateway default is 5 and 3DS
        // retries count against it; the documentation asks for 25 (its maximum).
        return $this->result($this->request()->post('/session', [
            'session' => ['authenticationLimit' => 25],
        ]));
    }

    /**
     * Write the amount and currency onto a session so the gateway holds the
     * server side figures. Amount is integer minor units, converted with
     * integer math; currency an ISO 4217 code.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function updateSession(string $sessionId, string $orderId, int $amount, string $currency, string $redirectResponseUrl): array
    {
        return $this->result($this->request()->put("/session/{$sessionId}", [
            'order' => [
                'id' => $orderId,
                'amount' => MpgsAmount::toGateway($amount),
                'currency' => strtoupper($currency),
            ],
            // 3DS: the payer is on a browser, and the bank's ACS sends them back
            // to this PayHub URL after a challenge.
            'authentication' => [
                'channel' => 'PAYER_BROWSER',
                'redirectResponseUrl' => $redirectResponseUrl,
            ],
        ]));
    }

    /**
     * Send PAY for an attempt. The amount and currency come from the Payment
     * record, the session id and 3DS transaction id from the attempt row,
     * never from the browser.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function pay(
        string $orderId,
        string $transactionId,
        string $sessionId,
        string $authTransactionId,
        int $amount,
        string $currency,
        string $notificationUrl,
        ?string $reference = null,
    ): array {
        $order = [
            'amount' => MpgsAmount::toGateway($amount),
            'currency' => strtoupper($currency),
            'notificationUrl' => $notificationUrl,
        ];

        if ($reference !== null) {
            $order['reference'] = $reference;
        }

        return $this->result($this->request()->put("/order/{$orderId}/transaction/{$transactionId}", [
            'apiOperation' => 'PAY',
            'order' => $order,
            'session' => ['id' => $sessionId],
            'authentication' => ['transactionId' => $authTransactionId],
            'sourceOfFunds' => ['type' => 'CARD'],
        ]));
    }

    /**
     * Read an order and its transactions. Read only: used by the resolver, the
     * webhook job and the sweep, never to send anything.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function retrieveOrder(string $orderId): array
    {
        return $this->result($this->request()->get("/order/{$orderId}"));
    }

    /**
     * One authenticated call to confirm the stored credentials work: create a
     * session and discard it. Touches no payment.
     *
     * @throws RequestException on auth/HTTP error
     */
    public function verifyCredentials(): void
    {
        $this->request()->post('/session')->throw();
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function result(Response $response): array
    {
        return [
            'status' => $response->status(),
            'body' => $response->json() ?? [],
        ];
    }

    private function request(): PendingRequest
    {
        $host = rtrim($this->gatewayHost ?: (string) config('services.mpgs.host'), '/');

        return Http::baseUrl("{$host}/api/rest/version/{$this->apiVersion}/merchant/{$this->merchantId}")
            ->withBasicAuth("merchant.{$this->merchantId}", $this->integrationPassword)
            ->acceptJson()
            ->asJson();
    }
}
