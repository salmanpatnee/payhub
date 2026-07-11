<?php

namespace App\Services\Viva;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Viva.com Payment API for a single merchant account.
 *
 * Mirrors the per-account Revolut pattern: build one instance per VivaAccount
 * with that account's decrypted credentials — never a global, shared client.
 * Resolve via app()->make(VivaClient::class, ['clientId' => ..., 'clientSecret' => ...])
 * so tests can fake the HTTP layer (Http::fake) or bind a substitute.
 *
 * Unlike Stripe/Revolut/Square (static per-account secrets with no expiry),
 * Viva uses an OAuth2 client_credentials grant with a ~1hr Bearer token —
 * getAccessToken() caches and refreshes it. Viva also spans three distinct
 * hostnames per environment (accounts / api / checkout), unlike the other
 * providers' single base URL.
 */
class VivaClient
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $merchantId,
        private readonly string $apiKey,
        private readonly ?string $sourceCode = null,
        private readonly ?string $environment = null,
    ) {}

    /**
     * Fetch (and cache) an OAuth2 client_credentials Bearer token.
     *
     * Cached per credential set (keyed on client id, which is unique per
     * account) with a TTL of the token's own expires_in minus a 60s safety
     * margin, so the cache self-adjusts if Viva ever changes the expiry
     * instead of relying on a hardcoded ~1hr assumption.
     */
    public function getAccessToken(): string
    {
        $cacheKey = "viva:access_token:{$this->clientId}";

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::baseUrl($this->accountsBaseUrl())
            ->asForm()
            ->withBasicAuth($this->clientId, $this->clientSecret)
            ->acceptJson()
            ->post('/connect/token', ['grant_type' => 'client_credentials'])
            ->throw()
            ->json();

        $token = $response['access_token'];
        $expiresIn = (int) ($response['expires_in'] ?? 3600);

        Cache::put($cacheKey, $token, now()->addSeconds(max($expiresIn - 60, 60)));

        return $token;
    }

    /**
     * Create a Smart Checkout payment order. Uses legacy Basic Auth
     * (merchant id / api key) — a separate auth mechanism from the OAuth2
     * Bearer token used by the rest of this client.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createOrder(array $data): array
    {
        return Http::baseUrl($this->checkoutBaseUrl())
            ->withBasicAuth($this->merchantId, $this->apiKey)
            ->acceptJson()
            ->asJson()
            ->post('/api/orders', $data)
            ->throw()
            ->json();
    }

    /**
     * Retrieve a transaction by id (OAuth2 Bearer).
     *
     * @return array<string, mixed>
     */
    public function retrieveTransaction(string $transactionId): array
    {
        return $this->request()->get("/checkout/v2/transactions/{$transactionId}")->throw()->json();
    }

    /**
     * Make a lightweight authenticated call to confirm the credentials work.
     * Viva has no Stripe-style balance endpoint, so a token fetch itself
     * (which fails on bad client id/secret) is used as the connectivity check.
     *
     * @throws RequestException on auth/HTTP error
     */
    public function verifyCredentials(): void
    {
        $this->getAccessToken();
    }

    /**
     * Register a webhook subscription and capture the verification key.
     *
     * TODO: exact endpoint/request/response shape is unconfirmed against
     * live Viva docs — this is the "confirm against sandbox" item flagged in
     * .planning/research/VIVA_PAYMENTS.md. Finalize during Phase 5 webhook
     * testing before relying on this in production.
     *
     * @param  list<int>  $eventTypeIds
     * @return array<string, mixed>
     */
    public function registerWebhook(string $url, array $eventTypeIds): array
    {
        return $this->request()->post('/webhooks', [
            'url' => $url,
            'eventTypeIds' => $eventTypeIds,
        ])->throw()->json();
    }

    /**
     * Build the Smart Checkout redirect URL for a given order code.
     *
     * $brandColor, if given, is passed as the `color` query param to recolor
     * Viva's hosted checkout page to match the brand — the leading `#` is
     * stripped here (Viva expects a bare hex) so callers can pass through
     * Brand.primary_color unmodified.
     */
    public function checkoutUrl(string $orderCode, ?string $brandColor = null): string
    {
        $url = "{$this->checkoutBaseUrl()}/web/checkout?ref={$orderCode}";

        if ($brandColor !== null && $brandColor !== '') {
            $url .= '&color='.ltrim($brandColor, '#');
        }

        return $url;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->apiBaseUrl())
            ->withToken($this->getAccessToken())
            ->acceptJson()
            ->asJson();
    }

    private function isProduction(): bool
    {
        return ($this->environment ?? config('services.viva.environment', 'demo')) === 'production';
    }

    private function accountsBaseUrl(): string
    {
        return $this->isProduction()
            ? 'https://accounts.vivapayments.com'
            : 'https://demo-accounts.vivapayments.com';
    }

    private function apiBaseUrl(): string
    {
        return $this->isProduction()
            ? 'https://api.vivapayments.com'
            : 'https://demo-api.vivapayments.com';
    }

    private function checkoutBaseUrl(): string
    {
        return $this->isProduction()
            ? 'https://vivapayments.com'
            : 'https://demo.vivapayments.com';
    }
}
