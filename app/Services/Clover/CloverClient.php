<?php

namespace App\Services\Clover;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over Clover's Ecommerce Hosted Checkout API for a single
 * merchant account.
 *
 * Mirrors the per-account pattern used by every other provider client: build
 * one instance per CloverAccount with that account's decrypted credentials —
 * never a global, shared client. Resolve via
 * app()->make(CloverClient::class, ['merchantId' => ..., 'privateToken' => ...])
 * so tests can fake the HTTP layer (Http::fake) or bind a substitute.
 *
 * Confirmed against Clover's own developer docs (docs.clover.com):
 * createCheckoutSession() endpoint, auth headers, and response shape
 * (href/checkoutSessionId/expirationTime) are documented. getCharge() and the
 * exact webhook payload field names are NOT — Clover publishes no session
 * status lookup endpoint and no full webhook JSON schema (only field names in
 * prose). See spec 0001's Follow-up: confirm both against a live sandbox pass
 * before this is trusted with real money.
 */
class CloverClient
{
    public function __construct(
        private readonly string $merchantId,
        private readonly string $privateToken,
        private readonly ?string $environment = null,
    ) {}

    /**
     * Create a Hosted Checkout session. Returns Clover's raw response:
     * { href, checkoutSessionId, createdTime, expirationTime }.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createCheckoutSession(array $data): array
    {
        return $this->request()
            ->post('/invoicingcheckoutservice/v1/checkouts', $data)
            ->throw()
            ->json();
    }

    /**
     * Reconciliation backstop: look up the Clover payment created against a
     * given checkout session, for a pending payment that never received a
     * webhook. Clover publishes no direct "session status" endpoint, so this
     * queries the Payments API filtered by external payment id — assumed
     * (not confirmed in Clover's docs) to be populated with the Hosted
     * Checkout session id. Confirm against a live sandbox pass before relying
     * on this for anything beyond a best-effort backstop.
     *
     * @return array<string, mixed>|null
     */
    public function getCharge(string $checkoutSessionId): ?array
    {
        $response = $this->request()
            ->get("/v3/merchants/{$this->merchantId}/payments", [
                'filter' => "externalPaymentId={$checkoutSessionId}",
            ])
            ->throw()
            ->json();

        return $response['elements'][0] ?? null;
    }

    /**
     * Make a lightweight authenticated call to confirm the credentials work.
     *
     * @throws RequestException on auth/HTTP error
     */
    public function verifyCredentials(): void
    {
        $this->request()->get("/v3/merchants/{$this->merchantId}")->throw();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->privateToken)
            ->withHeaders(['X-Clover-Merchant-Id' => $this->merchantId])
            ->acceptJson()
            ->asJson();
    }

    private function isProduction(): bool
    {
        return ($this->environment ?? 'sandbox') === 'production';
    }

    private function baseUrl(): string
    {
        return $this->isProduction()
            ? 'https://api.clover.com'
            : 'https://apisandbox.dev.clover.com';
    }
}
