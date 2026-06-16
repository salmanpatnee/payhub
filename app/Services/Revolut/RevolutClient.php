<?php

namespace App\Services\Revolut;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Revolut Merchant API for a single merchant account.
 *
 * Mirrors the per-account Stripe pattern: build one instance per RevolutAccount
 * with that account's decrypted secret key — never a global, shared credential.
 * Resolve via app()->make(RevolutClient::class, ['secretKey' => $account->secret_key])
 * so tests can fake the HTTP layer (Http::fake) or bind a substitute.
 */
class RevolutClient
{
    public function __construct(
        private readonly string $secretKey,
        private readonly ?string $environment = null,
        private readonly ?string $apiVersion = null,
    ) {}

    /**
     * Create a Merchant API order.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createOrder(array $data): array
    {
        return $this->request()->post('/orders', $data)->throw()->json();
    }

    /**
     * Retrieve an existing order (used to decide reuse vs. recreate).
     *
     * @return array<string, mixed>
     */
    public function retrieveOrder(string $orderId): array
    {
        return $this->request()->get("/orders/{$orderId}")->throw()->json();
    }

    /**
     * Make a lightweight authenticated call to confirm the secret key works.
     * Revolut has no Stripe-style balance endpoint, so we list orders.
     *
     * @throws RequestException on auth/HTTP error
     */
    public function verifyKey(): void
    {
        $this->request()->get('/orders')->throw();
    }

    /**
     * Register a webhook. The returned `signing_secret` is shown ONCE on
     * creation — store it on the account for signature verification.
     *
     * @param  list<string>  $events
     * @return array<string, mixed>
     */
    public function createWebhook(string $url, array $events): array
    {
        return $this->request()->post('/webhooks', [
            'url' => $url,
            'events' => $events,
        ])->throw()->json();
    }

    /**
     * List the registered webhooks (used to clear stale duplicates before
     * re-registering, since the signing secret is only returned on create).
     *
     * @return list<array<string, mixed>>
     */
    public function listWebhooks(): array
    {
        return $this->request()->get('/webhooks')->throw()->json();
    }

    /**
     * Delete a webhook by id.
     */
    public function deleteWebhook(string $webhookId): void
    {
        $this->request()->delete("/webhooks/{$webhookId}")->throw();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->withToken($this->secretKey)
            ->withHeaders([
                'Revolut-Api-Version' => $this->apiVersion ?? config('services.revolut.api_version'),
            ])
            ->acceptJson()
            ->asJson();
    }

    private function baseUrl(): string
    {
        $environment = $this->environment ?? config('services.revolut.environment', 'sandbox');

        return $environment === 'prod'
            ? 'https://merchant.revolut.com/api'
            : 'https://sandbox-merchant.revolut.com/api';
    }
}
